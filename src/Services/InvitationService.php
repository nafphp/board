<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Auth\Auth;
use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\InvitationServiceInterface;
use Naf\Board\Contracts\ProjectAccessInterface;
use Naf\Board\Contracts\ProjectServiceInterface;
use Naf\Board\Domain\Change;
use Naf\Board\Domain\Failure;
use Naf\Board\Domain\ProjectScope;
use Naf\Board\Rbac\Grants;
use Naf\Board\Support\Input;
use Naf\Board\Support\MembershipRole;
use Naf\Board\Support\PasswordRule;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Models\Mail;
use Naf\ORM\Core\EntityManager;
use Naf\RateLimit\PdoLimiter;
use PDO;
use PDOException;
use SensitiveParameter;
use Throwable;

use function Naf\config;
use function Naf\event;
use function Naf\I18n\t;

/** @internal */
final class InvitationService implements InvitationServiceInterface
{
    private const int TTL = 7 * 86400;

    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private AccessInterface $access,
        private ProjectAccessInterface $projectAccess,
        private ProjectServiceInterface $projects,
        private BoardQueryInterface $query,
        private MembershipRole $roles,
        private EntityManager $entityManager,
        private PasswordHasher $hasher,
        private PdoLimiter $limiter,
        private Mailer $mailer,
    ) {
    }

    public function create(int $project, array $data): array
    {
        $this->access->project($project, 'members');
        $this->limit('create:' . $this->access->actor(), 30, 3600);
        $validated = Input::validate($data, ['email' => 'required|string|email|max:190']);
        $email     = strtolower(trim($validated['email']));
        $send      = in_array($data['send_email'] ?? false, [true, 1, '1'], true);
        if ($send && !config('nafinity:mail_enabled', false)) {
            throw new Failure(t('Der E-Mail-Versand ist nicht aktiviert. Du kannst einen Einladungslink erstellen.'), 503);
        }

        return $this->access->write($project, 'members', function (ProjectScope $scope) use ($project, $data, $email, $send): array {
            $role = $data['role'] ?? 'member';
            $this->roles->resolve($scope, $role);
            $existing = $this->pdo->prepare('SELECT id FROM users WHERE email=?');
            $existing->execute([$email]);
            if ($existing->fetchColumn() !== false) {
                throw new Failure(t('Für diese E-Mail-Adresse gibt es bereits ein Konto. Verwende die Kontosuche.'), 409);
            }
            $token   = bin2hex(random_bytes(32));
            $expires = time() + self::TTL;
            $base    = rtrim((string) config('app:url'), '/');
            if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new Failure(t('Die öffentliche Adresse der Installation ist nicht konfiguriert.'), 503);
            }
            $url = $base . '/invitations/' . $token;
            $this->pdo->prepare('INSERT INTO project_invitations(project_id,invited_by,email,role,token_hash,expires_at,created_at)
                VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE invited_by=VALUES(invited_by),role=VALUES(role),token_hash=VALUES(token_hash),expires_at=VALUES(expires_at),created_at=VALUES(created_at)')
                ->execute([$project, $this->access->actor(), $email, $role, hash('sha256', $token), $expires, gmdate('Y-m-d H:i:s')]);
            if ($send) {
                $mail = (new Mail())->setFrom(config('nafinity:mail_from'))->addTo($email)
                    ->setSubject(t('Nafinity · Einladung zu :name', ['name' => $scope->project['name']]))
                    ->setContent(t('Du wurdest zum Projekt :name eingeladen.', ['name' => $scope->project['name']]) . "\n\n" . $url . "\n\n" . t('Lege über diesen Link dein Konto an. Die Einladung gilt sieben Tage und kann nur einmal verwendet werden.') . "\n", false);

                try {
                    if (!$this->mailer->send($mail)) {
                        throw new Failure(t('Versand fehlgeschlagen.'), 503);
                    }
                } catch (Throwable) {
                    throw new Failure(t('Die Einladung konnte nicht versendet werden. Bitte versuche es erneut.'), 503);
                }
            }
            $this->projects->changed($project, 'project.invited', ['role' => $role]);

            return ['invitation_url' => $url, 'email' => $email, 'expires_at' => $expires, 'sent' => $send, 'project_id' => $project, 'project_name' => $scope->project['name']];
        });
    }

    public function find(#[SensitiveParameter] string $token): array
    {
        $row = $this->row($token);
        $this->sponsor($row);
        $user = $this->pdo->prepare('SELECT id,active FROM users WHERE email=?');
        $user->execute([$row['email']]);
        $existing = $user->fetch();

        return ['email' => $row['email'], 'project_id' => (int) $row['project_id'], 'project_name' => $row['project_name'], 'existing' => (bool) $existing];
    }

    public function accept(#[SensitiveParameter] string $token, #[SensitiveParameter] array $data): array
    {
        $this->limit('accept:' . hash('sha256', $token), 30, 900);
        $initial = $this->row($token);
        $this->entityManager->begin();

        try {
            // Same lock order as issuing, revoking and deleting the project.
            $this->pdo->prepare('SELECT id FROM projects WHERE id=? FOR UPDATE')->execute([$initial['project_id']]);
            $row       = $this->row($token, true);
            $scope     = $this->sponsor($row, true);
            $statement = $this->pdo->prepare('SELECT id,email,active FROM users WHERE email=? FOR UPDATE');
            $statement->execute([$row['email']]);
            $existing = $statement->fetch();
            $new      = !$existing;
            if ($existing) {
                if (!$this->auth->check() || (int) $this->auth->id() !== (int) $existing['id'] || !(int) $existing['active']) {
                    throw new Failure(t('Bitte melde dich mit dem eingeladenen Konto an.'), 403);
                }
                $user = (int) $existing['id'];
            } else {
                if ($this->auth->check()) {
                    throw new Failure(t('Diese Einladung gehört zu einer anderen E-Mail-Adresse. Bitte melde dich zuerst ab.'), 403);
                }
                $fields = Input::validate($data, ['name' => 'required|string|max:120', 'password' => 'required|string|max:1024', 'password_confirmation' => 'required|string|max:1024']);
                $name   = trim($fields['name']);
                if ($name === '') {
                    throw new Failure(t('Ein Name wird benötigt.'));
                }
                if (null !== $complaint = PasswordRule::complaint($fields['password'])) {
                    throw new Failure(t($complaint));
                }
                if (!hash_equals($fields['password'], $fields['password_confirmation'])) {
                    throw new Failure(t('Die neuen Passwörter stimmen nicht überein.'));
                }
                $this->pdo->prepare('INSERT INTO users(name,email,password_hash,created_at) VALUES(?,?,?,?)')
                    ->execute([$name, $row['email'], $this->hasher->hash($fields['password']), gmdate('Y-m-d H:i:s')]);
                $user = (int) $this->pdo->lastInsertId();
                // An invitation grants only its board. No installation role,
                // project creation permission or membership elsewhere is added.
                event()->dispatch(Change::inInstallation($user, 'account.created', ['person' => $name]));
            }
            $membership = $this->pdo->prepare('SELECT role,active,custom_role_id FROM project_members WHERE project_id=? AND user_id=?');
            $membership->execute([$row['project_id'], $user]);
            $old = $membership->fetch();
            if (!$old || !(int) $old['active']) {
                $resolved = $this->roles->resolve($scope, $row['role'], $old ?: []);
                $this->pdo->prepare('INSERT INTO project_members(project_id,user_id,role,custom_role_id) VALUES(?,?,?,?)
                    ON DUPLICATE KEY UPDATE role=VALUES(role),custom_role_id=VALUES(custom_role_id),active=1')
                    ->execute([$row['project_id'], $user, $resolved['role'], $resolved['custom_role_id']]);
                Grants::inProject($user, (int) $row['project_id'], $resolved['custom_role_id'] === null ? $resolved['role'] : null);
                $this->pdo->prepare('UPDATE boards SET revision=revision+1 WHERE project_id=?')->execute([$row['project_id']]);
                event()->dispatch(new Change((int) $row['project_id'], null, $user, 'project.member_changed', ['user_id' => (string) $user, 'role' => $row['role']]));
            }
            $this->pdo->prepare('DELETE FROM project_invitations WHERE id=?')->execute([$row['id']]);
            $this->entityManager->commit();

            return ['project_id' => (int) $row['project_id'], 'user_id' => $user, 'email' => $row['email'], 'created' => $new];
        } catch (Throwable $failure) {
            $this->entityManager->rollback();
            if ($failure instanceof PDOException && ($failure->errorInfo[0] ?? '') === '23000' && (int) ($failure->errorInfo[1] ?? 0) === 1062) {
                throw new Failure(t('Das Konto wurde inzwischen angelegt. Bitte melde dich an und öffne die Einladung erneut.'), 409);
            }
            throw $failure;
        }
    }

    public function revoke(int $project, int $invitation): void
    {
        $this->access->write($project, 'members', function () use ($project, $invitation): void {
            $statement = $this->pdo->prepare('DELETE FROM project_invitations WHERE project_id=? AND id=?');
            $statement->execute([$project, $invitation]);
            if ($statement->rowCount() === 0) {
                throw new Failure(t('Einladung nicht gefunden.'), 404);
            }
            $this->projects->changed($project, 'project.invitation_revoked');
        });
    }

    public function pending(int $project): array
    {
        $this->access->project($project, 'members');
        $statement = $this->pdo->prepare('SELECT id,email,role,expires_at FROM project_invitations WHERE project_id=? AND expires_at>? ORDER BY email');
        $statement->execute([$project, time()]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function search(int $project, string $query): array
    {
        $this->access->project($project, 'members');
        $this->limit('search:' . $this->access->actor(), 120, 60);
        $query = mb_substr(trim($query), 0, 100);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $like      = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
        $statement = $this->pdo->prepare("SELECT id,name,email FROM users WHERE active=1 AND (name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!') ORDER BY name,email LIMIT 20");
        $statement->execute([$like, $like]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function targets(): array
    {
        $targets = [];
        foreach ($this->query->projects() as $project) {
            $scope = $this->projectAccess->forUser((int) $project['id'], $this->access->actor());
            if ($scope->allows('members')) {
                $targets[(int) $project['id']] = $project['name'];
            }
        }

        return $targets;
    }

    private function row(#[SensitiveParameter] string $token, bool $locked = false): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw $this->unavailable();
        }
        $statement = $this->pdo->prepare('SELECT i.*,p.name AS project_name FROM project_invitations i JOIN projects p ON p.id=i.project_id WHERE token_hash=?' . ($locked ? ' FOR UPDATE' : ''));
        $statement->execute([hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!$row || (int) $row['expires_at'] <= time()) {
            throw $this->unavailable();
        }

        return $row;
    }

    private function sponsor(array $row, bool $locked = false): ProjectScope
    {
        try {
            $scope = $this->projectAccess->forUser((int) $row['project_id'], (int) $row['invited_by'], $locked);
            if (!$scope->allows('members')) {
                throw $this->unavailable();
            }
            $this->roles->resolve($scope, $row['role']);

            return $scope;
        } catch (Failure) {
            throw $this->unavailable();
        }
    }

    private function unavailable(): Failure
    {
        return new Failure(t('Diese Einladung ist ungültig oder abgelaufen. Bitte lass dich erneut einladen.'), 410);
    }

    private function limit(string $key, int $maximum, int $seconds): void
    {
        if (!$this->limiter->consume('invitation:' . $key, $maximum, $seconds)['allowed']) {
            throw new Failure(t('Zu viele Versuche. Bitte versuche es später erneut.'), 429);
        }
    }
}
