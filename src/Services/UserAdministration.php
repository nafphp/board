<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Auth\Auth;
use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Change;
use Naf\Board\Domain\Failure;
use Naf\Board\Jobs\AccountSecurityNoticeJob;
use Naf\Board\Mail\Messages;
use Naf\Board\Models\User;
use Naf\Board\Support\Input;
use Naf\Board\Support\PasswordRule;
use Naf\Mail\Core\Mailer;
use Naf\ORM\Core\EntityManager;
use Naf\Queue\Core\Queue;
use Naf\RateLimit\PdoLimiter;
use PDO;
use PDOException;
use SensitiveParameter;
use Throwable;

use function Naf\config;
use function Naf\event;
use function Naf\I18n\t;
use function Naf\Rbac\rbac;

/** Administrative account editing and one-use, credential-bound recovery links. @internal */
final class UserAdministration
{
    private const int RESET_TTL = 1800;

    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private AccessInterface $access,
        private UserAdministrationPolicy $policy,
        private UserDirectory $directory,
        private EntityManager $entityManager,
        private PasswordHasher $hasher,
        private PdoLimiter $limiter,
        private Mailer $mailer,
        private Queue $queue,
    ) {
    }

    public function editor(int $id): array
    {
        $person = $this->directory->person($id);
        $actor  = $this->access->actor();
        $manage = $this->permitted($actor, $id);

        return [
            'person'      => $person,
            'revision'    => self::revision($person),
            'canManage'   => $manage,
            'canReset'    => $manage && (bool) $person['active'] && (bool) $person['local_password'] && $this->permitted($actor, $id, true),
            'self'        => $actor === $id,
            'mailEnabled' => $this->mailEnabled(),
        ];
    }

    public function update(int $id, array $input): bool
    {
        $this->keys($input, ['name', 'email', 'active', 'revision']);
        $data = Input::validate($input, [
            'name'     => 'required|string|max:120',
            'email'    => 'required|string|email|max:190',
            'revision' => 'required|string|max:64',
        ]);
        if (!in_array($input['active'] ?? null, [0, 1, '0', '1'], true)) {
            throw new Failure(t('Bitte wähle einen gültigen Kontostatus.'), 422);
        }
        $name   = trim($data['name']);
        $email  = strtolower(trim($data['email']));
        $active = (int) $input['active'];
        if ($name === '') {
            throw new Failure(t('Ein Name wird benötigt.'), 422);
        }
        $actor = $this->access->actor();

        return $this->write($actor, $id, function (array $person) use ($actor, $id, $data, $name, $email, $active): bool {
            $this->policy->authorize($actor, $id);
            $this->assertRevision($person, $data['revision']);
            $emailChanged  = $email !== strtolower($person['email']);
            $statusChanged = $active !== (int) $person['active'];
            if ($emailChanged && empty($person['password_hash'])) {
                throw new Failure(t('Die E-Mail-Adresse dieses Kontos wird vom Anmeldedienst verwaltet.'), 422);
            }
            if ($statusChanged && $active === 0) {
                $this->assertCanDeactivate($actor, $id);
            }
            $fields = [];
            if ($name !== $person['name']) {
                $fields[] = 'name';
            }
            if ($emailChanged) {
                $fields[] = 'email';
            }
            if ($statusChanged) {
                $fields[] = 'active';
            }
            if ($fields === []) {
                return false;
            }
            $securityChanged = $emailChanged || $statusChanged;
            $statement       = $this->pdo->prepare('UPDATE users SET name=?,email=?,active=?,email_verified_at=?,security_version=security_version+? WHERE id=?');
            $statement->execute([$name, $email, $active, $emailChanged ? null : $person['email_verified_at'], (int) $securityChanged, $id]);
            if ($securityChanged) {
                $this->invalidatePending($id);
            }
            if ($emailChanged) {
                $this->notice($person['email'], 'email.changed');
            }
            event()->dispatch(Change::inInstallation($actor, 'account.updated', ['person' => $name, 'fields' => $fields]));

            return $actor === $id && $securityChanged;
        });
    }

    public function requestReset(int $id, array $input): array
    {
        $this->keys($input, ['revision', 'send_email']);
        $data = Input::validate($input, ['revision' => 'required|string|max:64']);
        if (!in_array($input['send_email'] ?? '0', [0, 1, '0', '1'], true)) {
            throw new Failure(t('Ungültige Versandoption.'), 422);
        }
        $send  = (int) ($input['send_email'] ?? 0) === 1;
        $actor = $this->access->actor();
        $this->policy->authorize($actor, $id, true);
        $this->limit('issue-actor:' . $actor, 20, 3600);
        $this->limit('issue-user:' . $id, 3, 900);
        if ($send && !$this->mailEnabled()) {
            throw new Failure(t('Der E-Mail-Versand ist nicht aktiviert. Erstelle einen Reset-Link zum Kopieren.'), 503);
        }

        return $this->write($actor, $id, function (array $person) use ($actor, $id, $data, $send): array {
            $this->policy->authorize($actor, $id, true);
            $this->assertRevision($person, $data['revision']);
            if (!(int) $person['active'] || empty($person['password_hash'])) {
                throw new Failure(t('Ein Reset-Link ist nur für aktive lokale Konten verfügbar.'), 409);
            }
            $base = rtrim((string) config('app:url'), '/');
            if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new Failure(t('Die öffentliche Adresse der Installation ist nicht konfiguriert.'), 503);
            }
            $token   = bin2hex(random_bytes(32));
            $url     = $base . '/password-reset/' . $token;
            $expires = time() + self::RESET_TTL;
            $this->pdo->prepare('INSERT INTO account_password_resets(user_id,requested_by,token_hash,email,security_version,expires_at)
                VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE requested_by=VALUES(requested_by),token_hash=VALUES(token_hash),email=VALUES(email),security_version=VALUES(security_version),expires_at=VALUES(expires_at),created_at=CURRENT_TIMESTAMP')
                ->execute([$id, $actor, hash('sha256', $token), $person['email'], $person['security_version'], $expires]);
            if ($send) {
                $this->sendReset($person['email'], $url);
            }
            event()->dispatch(Change::inInstallation($actor, 'account.password_reset_requested', ['person' => $person['name']]));

            return ['reset_url' => $url, 'expires_at' => $expires, 'sent' => $send];
        });
    }

    public function findReset(#[SensitiveParameter] string $token): array
    {
        $row       = $this->resetRow($token);
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id=? AND active=1');
        $statement->execute([$row['user_id']]);
        $this->assertResetMatches($row, $statement->fetch() ?: []);
        $issuer = $this->pdo->prepare('SELECT active FROM users WHERE id=?');
        $issuer->execute([$row['requested_by']]);
        if ((int) $issuer->fetchColumn() !== 1 || !$this->permitted((int) $row['requested_by'], (int) $row['user_id'], true)) {
            $this->invalidReset();
        }

        return ['expires_at' => (int) $row['expires_at']];
    }

    public function resetPassword(#[SensitiveParameter] string $token, #[SensitiveParameter] array $input): int
    {
        $this->limit('consume:' . hash('sha256', $token), 10, 900);
        $initial = $this->resetRow($token);
        $data    = Input::validate($input, ['password' => 'required|string|max:1024', 'password_confirmation' => 'required|string|max:1024']);
        if (null !== $complaint = PasswordRule::complaint($data['password'])) {
            throw new Failure(t($complaint), 422);
        }
        if (!hash_equals($data['password'], $data['password_confirmation'])) {
            throw new Failure(t('Die neuen Passwörter stimmen nicht überein.'), 422);
        }
        $id = (int) $initial['user_id'];

        return $this->write((int) $initial['requested_by'], $id, function (array $person) use ($token, $data, $id): int {
            $row = $this->resetRow($token, true);
            $this->assertResetMatches($row, $person);
            if (!$this->permitted((int) $row['requested_by'], $id, true)) {
                $this->invalidReset();
            }
            if ($this->hasher->verify($data['password'], $person['password_hash'])) {
                throw new Failure(t('Bitte wähle ein anderes Passwort als dein bisheriges.'), 422);
            }
            $this->pdo->prepare('UPDATE users SET password_hash=?,security_version=security_version+1 WHERE id=?')
                ->execute([$this->hasher->hash($data['password']), $id]);
            $this->invalidatePending($id);
            $this->notice($person['email'], 'password.changed');
            event()->dispatch(Change::inInstallation($id, 'account.password_changed', ['person' => $person['name']]));

            return $id;
        }, false);
    }

    public function cleanup(): void
    {
        $this->pdo->prepare('DELETE FROM account_password_resets WHERE expires_at<=?')->execute([time()]);
    }

    public static function revision(array $person): string
    {
        return hash('sha256', json_encode([$person['name'], strtolower($person['email']), (int) $person['active'], (int) $person['security_version']], JSON_THROW_ON_ERROR));
    }

    private function write(int $actor, int $target, callable $operation, bool $session = true): mixed
    {
        $this->entityManager->begin();

        try {
            $this->policy->lock();
            $ids = array_values(array_unique([$actor, $target]));
            sort($ids);
            $statement = $this->pdo->prepare('SELECT * FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE');
            $statement->execute($ids);
            $users = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $user) {
                $users[(int) $user['id']] = $user;
            }
            if (!isset($users[$actor], $users[$target]) || !(int) $users[$actor]['active']) {
                if (!$session) {
                    $this->invalidReset();
                }
                throw new Failure(t('Das Nutzerkonto ist nicht mehr verfügbar.'), 409);
            }
            if ($session) {
                if ($this->auth->providerName() !== null) {
                    $this->auth->reset();
                }
                $identity = $this->auth->user();
                if (!$identity instanceof User || (int) $identity->getId() !== $actor || $identity->securityVersion() !== (int) $users[$actor]['security_version']) {
                    throw new Failure(t('Dein Konto wurde inzwischen geändert. Bitte melde dich erneut an.'), 401);
                }
            }
            $result = $operation($users[$target]);
            $this->entityManager->commit();

            return $result;
        } catch (Throwable $failure) {
            $this->entityManager->rollback();
            if ($failure instanceof PDOException && ($failure->errorInfo[0] ?? '') === '23000' && (int) ($failure->errorInfo[1] ?? 0) === 1062) {
                throw new Failure(t('Diese E-Mail-Adresse ist nicht verfügbar.'), 409);
            }
            throw $failure;
        }
    }

    private function assertRevision(array $person, string $revision): void
    {
        if (!hash_equals(self::revision($person), $revision)) {
            throw new Failure(t('Dieses Nutzerkonto wurde inzwischen geändert. Bitte öffne es erneut.'), 409);
        }
    }

    private function assertCanDeactivate(int $actor, int $target): void
    {
        if ($actor === $target) {
            throw new Failure(t('Du kannst dein eigenes Konto nicht deaktivieren.'), 403);
        }
        if (!in_array('rbac.manage', rbac()->assignments->permissionsOf($target), true)) {
            return;
        }
        $statement = $this->pdo->prepare("SELECT DISTINCT u.id FROM users u JOIN rbac_user_roles ur ON ur.user_id=u.id JOIN rbac_role_permissions rp ON rp.role_id=ur.role_id WHERE u.active=1 AND u.id<>? AND ur.scope='' AND rp.permission IN ('rbac.manage','rbac.all') ORDER BY u.id FOR UPDATE");
        $statement->execute([$target]);
        if (!$statement->fetchColumn()) {
            throw new Failure(t('Mindestens ein aktives Konto muss die Rollenverwaltung behalten.'), 409);
        }
    }

    private function resetRow(#[SensitiveParameter] string $token, bool $locked = false): array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            $this->invalidReset();
        }
        $statement = $this->pdo->prepare('SELECT * FROM account_password_resets WHERE token_hash=?' . ($locked ? ' FOR UPDATE' : ''));
        $statement->execute([hash('sha256', $token)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['expires_at'] <= time()) {
            $this->invalidReset();
        }

        return $row;
    }

    private function assertResetMatches(array $row, array $person): void
    {
        if (!$person || !(int) $person['active'] || empty($person['password_hash']) || (int) $row['security_version'] !== (int) $person['security_version'] || !hash_equals($row['email'], $person['email'])) {
            $this->invalidReset();
        }
    }

    private function invalidReset(): never
    {
        throw new Failure(t('Dieser Reset-Link ist ungültig oder abgelaufen. Bitte fordere einen neuen Link bei deiner Administration an.'), 410);
    }

    private function permitted(int $actor, int $target, bool $reset = false): bool
    {
        try {
            $this->policy->authorize($actor, $target, $reset);

            return true;
        } catch (Failure $failure) {
            if ($failure->status !== 403) {
                throw $failure;
            }

            return false;
        }
    }

    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), [...$allowed, '_csrf']) !== []) {
            throw new Failure(t('Unbekannte Kontofelder.'), 422);
        }
    }

    private function invalidatePending(int $id): void
    {
        $this->pdo->prepare('DELETE FROM account_email_changes WHERE user_id=?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM account_password_resets WHERE user_id=?')->execute([$id]);
    }

    private function limit(string $key, int $maximum, int $seconds): void
    {
        if (!$this->limiter->consume('user-admin:' . $key, $maximum, $seconds)['allowed']) {
            throw new Failure(t('Zu viele Versuche. Bitte versuche es später erneut.'), 429);
        }
    }

    private function mailEnabled(): bool
    {
        return Messages::enabled();
    }

    private function sendReset(string $email, #[SensitiveParameter] string $url): void
    {
        $mail = Messages::create($this->mailer, 'password-reset', ['url' => $url])->addTo($email)->setSubject(t('Nafinity · Passwort zurücksetzen'))
            ->setTextContent(t('Deine Administration hat einen Passwort-Reset angefordert. Dieser Link gilt 30 Minuten und kann einmal verwendet werden.') . "\n\n" . $url);

        try {
            if (!$this->mailer->send($mail)) {
                throw new Failure(t('Versand fehlgeschlagen.'), 503);
            }
        } catch (Throwable) {
            throw new Failure(t('Der Reset-Link konnte nicht versendet werden. Bitte versuche es später erneut.'), 503);
        }
    }

    private function notice(string $recipient, string $event): void
    {
        $this->queue->push(AccountSecurityNoticeJob::class, ['recipient' => $recipient, 'event' => $event]);
    }
}
