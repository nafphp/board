<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Jobs\AccountSecurityNoticeJob;
use App\Models\User;
use App\Support\Input;
use Naf\Auth\Auth;
use Naf\Auth\Credentials\PasswordCredentials;
use Naf\Auth\Support\PasswordHasher;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Models\Mail;
use Naf\ORM\Core\EntityManager;
use Naf\Queue\Core\Queue;
use Naf\RateLimit\PdoLimiter;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\AccountServiceInterface;
use PDO;
use PDOException;
use SensitiveParameter;
use Throwable;

use function Naf\config;

final class AccountService implements AccountServiceInterface
{
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const CODE_TTL      = 900;
    private const MAX_ATTEMPTS  = 5;

    public function __construct(
        private PDO $pdo,
        private Auth $auth,
        private AccessInterface $access,
        private EntityManager $entityManager,
        private PasswordHasher $hasher,
        private PdoLimiter $limiter,
        private Mailer $mailer,
        private Queue $queue,
    ) {
    }

    public function authenticate(PasswordCredentials $credentials, string $provider): bool
    {
        // Serialize password verification/session publication with credential changes.
        $this->entityManager->begin();

        try {
            $statement = $this->pdo->prepare('SELECT id FROM users WHERE email=? FOR UPDATE');
            $statement->execute([$credentials->username]);
            $authenticated = $this->auth->authenticate($credentials, $provider);
            $this->entityManager->commit();

            return $authenticated;
        } catch (Throwable $exception) {
            $this->entityManager->rollback();
            $this->auth->logout();
            throw $exception;
        }
    }

    public function profile(): array
    {
        $id        = $this->access->actor();
        $statement = $this->pdo->prepare('SELECT name, email, password_hash, email_verified_at FROM users WHERE id=? AND active=1');
        $statement->execute([$id]);
        $user = $statement->fetch();
        if (!$user) {
            throw new Failure('Dein Konto ist nicht verfügbar.', 401);
        }

        return [
            'name'           => $user['name'],
            'email'          => $user['email'],
            'email_verified' => $user['email_verified_at'] !== null,
            'local_password' => !empty($user['password_hash']),
            'pending'        => $this->pending($id),
        ];
    }

    public function changePassword(#[SensitiveParameter] array $input): void
    {
        $id = $this->access->actor();
        $this->limit('credentials', $id, 10, 600);
        $data = Input::validate($input, [
            'current_password'      => 'required|string|max:1024',
            'password'              => 'required|string|max:1024',
            'password_confirmation' => 'required|string|max:1024',
        ]);
        $password = $data['password'];
        if (mb_strlen($password) < 15 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new Failure('Das neue Passwort braucht mindestens 15 Zeichen und darf höchstens 72 Bytes lang sein.');
        }
        if (!hash_equals($password, $data['password_confirmation'])) {
            throw new Failure('Die neuen Passwörter stimmen nicht überein.');
        }

        $this->write($id, function (array $user) use ($id, $data, $password): void {
            $this->verifyPassword($user, $data['current_password']);
            if ($this->hasher->verify($password, $user['password_hash'])) {
                throw new Failure('Bitte wähle ein anderes Passwort als dein bisheriges.');
            }
            $hash      = $this->hasher->hash($password);
            $statement = $this->pdo->prepare('UPDATE users SET password_hash=?, security_version=security_version+1 WHERE id=?');
            $statement->execute([$hash, $id]);
            $this->deletePending($id);
            $this->notice($user['email'], 'password.changed');
        });
    }

    public function requestEmail(#[SensitiveParameter] array $input): array
    {
        $id = $this->access->actor();
        $this->limit('credentials', $id, 10, 600);
        $this->limit('email-send', $id, 3, 900);
        $data = Input::validate($input, [
            'email'            => 'required|string|email|max:190',
            'current_password' => 'required|string|max:1024',
        ]);
        $email = strtolower(trim($data['email']));

        return $this->write($id, function (array $user) use ($id, $data, $email): array {
            $this->verifyPassword($user, $data['current_password']);
            if ($email === strtolower($user['email'])) {
                throw new Failure('Das ist bereits deine aktuelle E-Mail-Adresse.');
            }
            $this->availableEmail($email);
            $requestId = bin2hex(random_bytes(16));
            $code      = '';
            for ($index = 0; $index < 12; ++$index) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            $expires = time() + self::CODE_TTL;
            $this->deletePending($id);
            $statement = $this->pdo->prepare('INSERT INTO account_email_changes
                (user_id, request_id, email, code_hash, security_version, expires_at)
                VALUES(?, ?, ?, ?, ?, ?)');
            $statement->execute([$id, $requestId, $email, hash('sha256', $requestId . ':' . $code), $user['security_version'], $expires]);
            $displayCode = implode('-', str_split($code, 4));
            $mail        = (new Mail())
                ->setFrom(config('nafinity:mail_from'))
                ->addTo($email)
                ->setSubject('Nafinity · Neue E-Mail-Adresse bestätigen')
                ->setContent("Dein Bestätigungscode: $displayCode\n\nGib diesen Code im Profil von Nafinity ein. Er gilt 15 Minuten und kann nur einmal verwendet werden.\n\nFalls du keine Änderung angefordert hast, kannst du diese Nachricht ignorieren.\n", false);

            try {
                if (!$this->mailer->send($mail)) {
                    throw new Failure('Versand fehlgeschlagen.', 503);
                }
            } catch (Throwable) {
                // Roll back the pending request; never publish a successful dummy workflow.
                throw new Failure('Der Bestätigungscode konnte nicht versendet werden. Bitte versuche es später erneut.', 503);
            }
            $this->notice($user['email'], 'email.requested');

            return ['request_id' => $requestId, 'email' => $email, 'expires_at' => $expires];
        });
    }

    public function confirmEmail(#[SensitiveParameter] array $input): void
    {
        $id = $this->access->actor();
        $this->limit('email-confirm', $id, 20, 900);
        $data = Input::validate($input, [
            'request_id' => 'required|string|max:32',
            'code'       => 'required|string|max:32',
        ]);
        $code      = strtoupper(str_replace(['-', ' '], '', $data['code']));
        $confirmed = $this->write($id, function (array $user) use ($id, $data, $code): bool {
            $statement = $this->pdo->prepare('SELECT * FROM account_email_changes WHERE user_id=? FOR UPDATE');
            $statement->execute([$id]);
            $pending = $statement->fetch();
            if (!$pending || !hash_equals($pending['request_id'], $data['request_id'])) {
                return false;
            }
            if ((int) $pending['expires_at'] <= time() || (int) $pending['security_version'] !== (int) $user['security_version']) {
                $this->deletePending($id);

                return false;
            }
            $valid = hash_equals($pending['code_hash'], hash('sha256', $pending['request_id'] . ':' . $code));
            if (!$valid) {
                if ((int) $pending['attempts'] + 1 >= self::MAX_ATTEMPTS) {
                    $this->deletePending($id);
                } else {
                    $this->pdo->prepare('UPDATE account_email_changes SET attempts=attempts+1 WHERE user_id=?')->execute([$id]);
                }

                return false;
            }
            $this->availableEmail($pending['email']);
            $statement = $this->pdo->prepare('UPDATE users SET email=?, email_verified_at=?, security_version=security_version+1 WHERE id=?');
            $statement->execute([$pending['email'], gmdate('Y-m-d H:i:s'), $id]);
            $this->deletePending($id);
            $this->notice($user['email'], 'email.changed');

            return true;
        });
        // Failed attempts/expiry must commit instead of being rolled back with the error.
        if (!$confirmed) {
            throw new Failure('Der Code ist ungültig oder abgelaufen. Nach fünf Fehlversuchen brauchst du einen neuen Code.');
        }
    }

    public function cancelEmail(): void
    {
        $id = $this->access->actor();
        $this->write($id, fn() => $this->deletePending($id));
    }

    public function cleanup(): void
    {
        $this->pdo->prepare('DELETE FROM account_email_changes WHERE expires_at<=?')->execute([time()]);
    }

    private function pending(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT request_id, email, expires_at FROM account_email_changes WHERE user_id=? AND expires_at>?');
        $statement->execute([$id, time()]);
        $pending = $statement->fetch();

        return $pending ? [...$pending, 'expires_at' => (int) $pending['expires_at']] : null;
    }

    private function write(int $id, callable $operation): mixed
    {
        $this->entityManager->begin();

        try {
            $statement = $this->pdo->prepare('SELECT * FROM users WHERE id=? AND active=1 FOR UPDATE');
            $statement->execute([$id]);
            $user = $statement->fetch();
            // Recheck persisted authentication after locking: revocation may have
            // committed between NAF's session read and its identity lookup.
            if ($this->auth->providerName() !== null) {
                $this->auth->reset();
            }
            $identity = $this->auth->user();
            if (!$user || !$identity instanceof User || $identity->securityVersion() !== (int) $user['security_version']) {
                throw new Failure('Dein Konto wurde inzwischen geändert. Bitte melde dich erneut an.', 401);
            }
            $result = $operation($user);
            $this->entityManager->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->entityManager->rollback();
            if ($exception instanceof PDOException && in_array($exception->errorInfo[0] ?? '', ['23000', '23505'], true)) {
                throw new Failure('Diese E-Mail-Adresse ist nicht verfügbar.', 409);
            }
            throw $exception;
        }
    }

    private function verifyPassword(array $user, #[SensitiveParameter] string $password): void
    {
        if (!$this->hasher->verify($password, $user['password_hash'])) {
            throw new Failure('Das aktuelle Passwort stimmt nicht.', 403);
        }
    }

    private function availableEmail(string $email): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE email=?');
        $statement->execute([$email]);
        if ($statement->fetchColumn() !== false) {
            throw new Failure('Diese E-Mail-Adresse ist nicht verfügbar.', 409);
        }
    }

    private function deletePending(int $id): void
    {
        $this->pdo->prepare('DELETE FROM account_email_changes WHERE user_id=?')->execute([$id]);
    }

    private function limit(string $action, int $id, int $maximum, int $seconds): void
    {
        $limit = $this->limiter->consume('account:' . $action . ':' . $id, $maximum, $seconds);
        if (!$limit['allowed']) {
            throw new Failure('Zu viele Versuche. Bitte versuche es später erneut.', 429);
        }
    }

    private function notice(string $recipient, string $event): void
    {
        // The native PDO queue shares this transaction. Passwords/codes never enter it.
        $this->queue->push(AccountSecurityNoticeJob::class, ['recipient' => $recipient, 'event' => $event]);
    }
}
