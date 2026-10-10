<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Definition\SettingDefinition;
use Naf\Board\Domain\Change;
use Naf\Board\Domain\Failure;
use Naf\Board\Mail\Settings;
use Naf\Board\Rbac\Installation;
use Naf\Board\Support\Settings\FileConfigurationStore;
use Naf\Core\Config;
use Naf\Database\Core\Database;
use Naf\Mail\Core\Transport\SmtpSettings;
use Naf\Mail\Core\Transport\SmtpTransport;
use Naf\Mail\Core\TransportInterface;
use Naf\ORM\Core\EntityManager;
use Naf\Rbac\Repository\AssignmentRepository;
use PDO;
use RuntimeException;
use Throwable;

use function Naf\Board\extensions;
use function Naf\event;
use function Naf\I18n\t;
use function Naf\log;
use function Naf\Rbac\rbac;

/** Typed, authorized changes to the early application configuration layer. */
final class ApplicationSettings
{
    public function __construct(
        private Config $configuration,
        private AccessInterface $access,
        private FileConfigurationStore $store,
        private EntityManager $entityManager,
        private PDO $pdo,
    ) {
    }

    public function authorize(): void
    {
        if (!rbac()->allows($this->access->actor(), Installation::MANAGE_SETTINGS)) {
            throw new Failure(t('Diese Seite ist Administratoren vorbehalten.'), 403);
        }
    }

    /** Safe provenance; secret values never appear in this result. */
    public function describe(SettingDefinition $definition): array
    {
        $path   = $definition->configKey;
        $value  = $path === null ? $definition->default : $this->configuration->get($path);
        $raw    = $path === null ? null : $this->configuration->raw($path);
        $source = $path === null ? 'default' : $this->configuration->source($path);
        if ($source !== 'administration' && ($value === null || ($value === '' && $definition->type === 'integer'))) {
            $source = 'default';
            $value  = $definition->default;
        }

        return [
            'source'      => $source,
            'environment' => is_string($raw) && str_starts_with($raw, 'ENV:') ? substr($raw, 4) : null,
            'configured'  => $value !== null && $value !== '',
            'path'        => $path,
        ];
    }

    public function revision(): string
    {
        return $this->store->signature($this->configuration->overrides('administration'));
    }

    public function save(array $values, array $resetKeys, ?string $revision = null): void
    {
        $this->authorize();
        foreach ($resetKeys as $key) {
            if (!is_string($key)) {
                throw new Failure(t('Bitte prüfe die Eingaben.'), 422);
            }
        }
        if (array_intersect(array_keys($values), $resetKeys)) {
            throw new Failure(t('Ein Schlüssel kann nicht gleichzeitig gesetzt und zurückgesetzt werden.'), 422);
        }
        $normalized = [];
        $reset      = [];
        $errors     = [];
        $paths      = [];
        foreach ([...array_keys($values), ...$resetKeys] as $key) {
            $definition = is_string($key) ? extensions()->settings()->find('application', $key) : null;
            if ($definition === null || $definition->configKey === null) {
                throw new Failure(t('Diese Einstellung gibt es hier nicht.'), 422);
            }
            $paths[$key] = $definition->configKey;
        }
        foreach ($values as $key => $value) {
            $definition = extensions()->settings()->find('application', $key);
            $type       = extensions()->fieldTypes()->get($definition->type);
            $messages   = $type?->validate($value, $definition->options) ?? [t('Unbekannter Feldtyp.')];
            if ($messages !== []) {
                $errors[$key] = $messages;
                continue;
            }
            $normalized[$paths[$key]] = $type->normalize($value, $definition->options);
        }
        foreach ($resetKeys as $key) {
            $reset[] = $paths[$key];
        }
        if ($errors !== []) {
            throw new Failure(t('Bitte prüfe die Eingaben.'), 422, $errors);
        }
        $changed   = [];
        $overrides = $this->store->update(function (array $current) use ($normalized, $reset, $paths, &$changed): array {
            $before = $current;
            foreach ($reset as $path) {
                unset($current[$path]);
            }
            $candidate = array_replace($current, $normalized);
            foreach ($paths as $key => $path) {
                if (array_key_exists($path, $before) !== array_key_exists($path, $candidate)
                    || ($before[$path] ?? null) !== ($candidate[$path] ?? null)) {
                    $changed[] = $key;
                }
            }
            $configuration = new Config($this->configuration->raw());
            $configuration->overlay('administration', $candidate);
            $this->validateConfiguration($configuration, array_values($paths));

            return $candidate;
        }, $revision);
        $this->configuration->overlay('administration', $overrides);
        if ($changed !== []) {
            try {
                $this->entityManager->begin();
                event()->dispatch(Change::inInstallation($this->access->actor(), 'settings.changed', [
                    'keys'  => array_values(array_intersect(array_keys($values), $changed)),
                    'reset' => array_values(array_intersect($resetKeys, $changed)),
                ]));
                $this->entityManager->commit();
            } catch (Throwable) {
                try {
                    $this->entityManager->rollback();
                } catch (Throwable) {
                    // The configuration is already durable; retain the recovery path.
                }
                log()->warning('Application configuration saved, but its audit entry could not be recorded.');
            }
        }
    }

    private function validateConfiguration(Config $configuration, array $paths): void
    {
        // Do not save a database connection that has never connected successfully.
        $databaseChanged = false;
        foreach ($paths as $path) {
            if (str_starts_with($path, 'database:')
                && $configuration->get($path) !== $this->configuration->get($path)) {
                $databaseChanged = true;
            }
            $old = $this->configuration->get($path);
            $new = $configuration->get($path);
            if (is_string($old) && str_contains($old, '\\') && class_exists($old)
                && (!is_string($new) || !class_exists($new))) {
                throw new Failure(t('Die konfigurierte PHP-Klasse ist nicht verfügbar.'), 422);
            }
        }
        $mailChanged = array_filter($paths, static fn(string $path): bool => str_starts_with($path, 'mail:')) !== [];
        if ($mailChanged) {
            try {
                $mail      = $configuration->get('mail', []);
                $transport = $mail['transport'] ?? '';
                if (!is_string($transport) || !is_subclass_of($transport, TransportInterface::class)) {
                    throw new RuntimeException();
                }
                if ($transport === SmtpTransport::class
                    && class_exists(SmtpSettings::class)
                    && (filter_var($mail['notifications_enabled'] ?? false, FILTER_VALIDATE_BOOL) || ($mail['smtp']['host'] ?? '') !== '')) {
                    new SmtpSettings($mail);
                }
                if (class_exists(Settings::class)) {
                    Settings::fromMailConfiguration($mail);
                }
            } catch (Throwable) {
                throw new Failure(t('Die Mail-Konfiguration ist unvollständig oder ungültig. Es wurde nichts gespeichert.'), 422);
            }
        }
        if ($databaseChanged) {
            try {
                $database = $configuration->get('database', []);
                if (($database['driver'] ?? '') !== 'mysql') {
                    throw new RuntimeException();
                }
                $connection = (new Database($database))->getConnection();
                // A reachable empty database would still lock every administrator out.
                $connection->query('SELECT id FROM users LIMIT 1');
                $permissions = (new AssignmentRepository($connection, rbac()->permissions))
                    ->permissionsOf($this->access->actor());
                $user = $connection->prepare('SELECT email,password_hash FROM users WHERE id=? AND active=1');
                $user->execute([$this->access->actor()]);
                $identity = $user->fetch(PDO::FETCH_ASSOC);
                $original = $this->pdo->prepare('SELECT email,password_hash FROM users WHERE id=?');
                $original->execute([$this->access->actor()]);
                if ($identity === false || $identity !== $original->fetch(PDO::FETCH_ASSOC) || !in_array(Installation::MANAGE_SETTINGS, $permissions, true)) {
                    throw new RuntimeException();
                }
            } catch (Throwable) {
                throw new Failure(t('Die Datenbankkonfiguration konnte nicht bestätigt werden. Es wurde nichts gespeichert.'), 422);
            }
        }
    }
}
