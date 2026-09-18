<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Domain\ProjectScope;
use App\Support\Settings\PreferenceStore;
use Naf\ORM\Core\EntityManager;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\FieldTypeInterface;
use Nafinity\Contracts\ProjectServiceInterface;
use Nafinity\Contracts\SettingsServiceInterface;
use Nafinity\Contracts\SettingsStoreInterface;
use Nafinity\Definition\SettingDefinition;
use Nafinity\Support\SettingsContext;
use PDO;
use Throwable;

use function Naf\config;
use function Nafinity\extensions;

/**
 * Reading and writing declared settings, with their types and their rights.
 *
 * A value is whatever is stored; failing that, a declared configuration key;
 * failing that, the definition's own default. `null`, `false`, `0` and the
 * empty string are values, so nothing here falls back on emptiness.
 *
 * The existing personal, per-project and project fields keep their storage and
 * their services. This is a second way to reach them, never a second copy.
 */
final class SettingsService implements SettingsServiceInterface
{
    /** The project write permission that legacy project fields always need. */
    private const string LEGACY_PROJECT_PERMISSION = 'manage';

    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private EntityManager $entityManager,
        private SettingsStoreInterface $store,
        private PreferenceStore $preferences,
        private ProjectServiceInterface $projects,
    ) {
    }

    public function all(SettingsContext $context): array
    {
        $definitions = $this->readable($context);
        $values      = [];

        foreach ($this->resolve($context, $definitions) as $key => $value) {
            if ($definitions[$key]->sensitive) {
                continue;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    public function get(SettingsContext $context, string $key, mixed $default = null): mixed
    {
        $definition = extensions()->settings()->find($context->scope, $key);

        if ($definition === null) {
            return $default;
        }

        $this->requireRead($context, $definition);

        return $this->resolve($context, [$key => $definition])[$key];
    }

    public function has(SettingsContext $context, string $key): bool
    {
        $definition = extensions()->settings()->find($context->scope, $key);

        if ($definition === null) {
            return false;
        }

        try {
            $this->requireRead($context, $definition);
        } catch (Failure) {
            return false;
        }

        return true;
    }

    public function save(SettingsContext $context, array $values, array $resetKeys = []): void
    {
        if ($context->scope === 'application') {
            throw new Failure('Anwendungseinstellungen sind schreibgeschützt.', 403);
        }

        $definitions = $this->definitionsFor($context, [...array_keys($values), ...$resetKeys]);
        $overlap     = array_intersect(array_keys($values), $resetKeys);

        if ($overlap) {
            throw new Failure(
                'Ein Schlüssel kann nicht gleichzeitig gesetzt und zurückgesetzt werden: '
                . implode(', ', $overlap),
                422,
            );
        }

        $this->transaction($context, function (?ProjectScope $scope) use (
            $context,
            $definitions,
            $values,
            $resetKeys,
        ) {
            $normalized = [];
            $errors     = [];

            foreach ($values as $key => $value) {
                $definition = $definitions[$key];
                $this->requireWrite($context, $definition, $scope);
                $messages = $this->validate($definition, $value);

                if ($messages !== []) {
                    $errors[$key] = $messages;
                    continue;
                }

                $normalized[$key] = $this->type($definition)->normalize($value, $this->options($definition));
            }

            foreach ($resetKeys as $key) {
                $this->requireWrite($context, $definitions[$key], $scope);
            }

            if ($errors !== []) {
                throw new Failure('Bitte prüfe die Eingaben.', 422, $errors);
            }

            $this->persist($context, $definitions, $normalized, $resetKeys, $scope);
        });
    }

    /**
     * Definitions of this scope the actor may read
     *
     * @param SettingsContext $context Scope and owner
     *
     * @return array<string, SettingDefinition>
     */
    private function readable(SettingsContext $context): array
    {
        $definitions = [];

        foreach (extensions()->settings()->forScope($context->scope) as $key => $definition) {
            try {
                $this->requireRead($context, $definition);
            } catch (Failure) {
                continue;
            }

            $definitions[$key] = $definition;
        }

        return $definitions;
    }

    /**
     * @param SettingsContext                 $context     Scope and owner
     * @param array<string, SettingDefinition> $definitions Definitions to resolve
     *
     * @return array<string, mixed>
     */
    private function resolve(SettingsContext $context, array $definitions): array
    {
        $stored = $this->stored($context, $definitions);
        $values = [];

        foreach ($definitions as $key => $definition) {
            if (array_key_exists($key, $stored)) {
                $values[$key] = $this->type($definition)
                    ->normalize($stored[$key], $this->options($definition));
                continue;
            }

            $values[$key] = $this->declared($definition);
        }

        return $values;
    }

    /**
     * Everything actually stored for these definitions, legacy columns included
     *
     * @param SettingsContext                  $context     Scope and owner
     * @param array<string, SettingDefinition> $definitions Definitions to read
     *
     * @return array<string, mixed>
     */
    private function stored(SettingsContext $context, array $definitions): array
    {
        $contributed = [];
        $legacy      = [];

        foreach ($definitions as $key => $definition) {
            if ($this->legacy($definition) === null) {
                $contributed[] = $key;
                continue;
            }

            $legacy[$key] = $definition;
        }

        $values = $context->scope === 'application'
            ? []
            : $this->store->read($context, $contributed);

        foreach ($legacy as $key => $definition) {
            $row    = $this->legacyRow($context, $definition);
            $column = $this->legacy($definition)['column'];

            if (array_key_exists($column, $row)) {
                $values[$key] = $row[$column];
            }
        }

        return $values;
    }

    /**
     * The value a definition declares: its configuration key, else its default
     *
     * @param SettingDefinition $definition The definition to fall back on
     */
    private function declared(SettingDefinition $definition): mixed
    {
        if ($definition->configKey !== null) {
            $configured = config($definition->configKey);

            if ($configured !== null) {
                return $this->type($definition)->normalize($configured, $this->options($definition));
            }
        }

        return $definition->default;
    }

    /**
     * @param SettingsContext $context   Scope and owner
     * @param SettingDefinition $definition The definition being read
     */
    private function requireRead(SettingsContext $context, SettingDefinition $definition): void
    {
        if ($context->scope === 'application') {
            return;
        }

        if ($context->scope === 'user') {
            return;
        }

        $scope = $this->access->project((int) $context->projectId);

        if ($definition->readPermission !== null && !$scope->allows($definition->readPermission)) {
            throw new Failure('Du hast für diese Einstellung keine Berechtigung.', 403);
        }
    }

    /**
     * @param SettingsContext   $context    Scope and owner
     * @param SettingDefinition $definition The definition being written
     * @param ProjectScope|null $scope      The already authorized project, when there is one
     */
    private function requireWrite(
        SettingsContext $context,
        SettingDefinition $definition,
        ?ProjectScope $scope,
    ): void {
        if (in_array($context->scope, ['user', 'project_user'], true)) {
            if ($definition->writePermission === null) {
                return;
            }

            if ($scope === null || !$scope->allows($definition->writePermission)) {
                throw new Failure('Du hast für diese Einstellung keine Berechtigung.', 403);
            }

            return;
        }

        $required = $this->legacy($definition) !== null
            ? self::LEGACY_PROJECT_PERMISSION
            : $definition->writePermission ?? self::LEGACY_PROJECT_PERMISSION;

        if ($scope === null || !$scope->allows($required)) {
            throw new Failure('Du hast für diese Einstellung keine Berechtigung.', 403);
        }
    }

    /**
     * @param SettingsContext                  $context     Scope and owner
     * @param array<string, SettingDefinition> $definitions Every touched definition
     * @param array<string, mixed>             $values      Normalized values
     * @param list<string>                     $resetKeys   Keys to reset
     * @param ProjectScope|null                $scope       Authorized project, when there is one
     */
    private function persist(
        SettingsContext $context,
        array $definitions,
        array $values,
        array $resetKeys,
        ?ProjectScope $scope,
    ): void {
        $contributed = [];
        $removals    = [];
        $legacy      = [];

        foreach ($values as $key => $value) {
            if ($this->legacy($definitions[$key]) === null) {
                $contributed[$key] = $value;
                continue;
            }

            $legacy[$key] = $value;
        }

        foreach ($resetKeys as $key) {
            if ($this->legacy($definitions[$key]) === null) {
                $removals[] = $key;
                continue;
            }

            $legacy[$key] = $definitions[$key]->default;
        }

        if ($contributed !== [] || $removals !== []) {
            $this->store->write($context, $contributed, $removals);
        }

        if ($legacy !== []) {
            $this->writeLegacy($context, $definitions, $legacy, $scope);
        }
    }

    /**
     * @param SettingsContext                  $context     Scope and owner
     * @param array<string, SettingDefinition> $definitions Every touched definition
     * @param array<string, mixed>             $values      Normalized legacy values
     * @param ProjectScope|null                $scope       Authorized project, when there is one
     */
    private function writeLegacy(
        SettingsContext $context,
        array $definitions,
        array $values,
        ?ProjectScope $scope,
    ): void {
        $columns = [];

        foreach ($values as $key => $value) {
            $columns[$this->legacy($definitions[$key])['column']] = $value;
        }

        if ($context->scope === 'user') {
            $this->preferences->writeUser((int) $context->userId, $columns);

            return;
        }

        if ($context->scope === 'project_user') {
            $this->preferences->writeProjectUser(
                (int) $context->projectId,
                (int) $context->userId,
                $columns,
            );

            return;
        }

        // A project write goes through ProjectService, which validates the whole
        // record. Handing it only the changed fields would let it write defaults
        // over everything else, so the current values come along.
        $current = $scope?->project ?? [];
        $payload = [
            'name'             => $current['name'] ?? '',
            'description'      => $current['description'] ?? '',
            'color'            => $current['color'] ?? '#6366f1',
            'icon'             => $current['icon'] ?? 'N',
            'ticket_key'       => $current['ticket_key'] ?? '',
            'estimation_scale' => $current['estimation_scale'] ?? 'none',
            ...$columns,
        ];

        $this->projects->update((int) $context->projectId, $payload);
    }

    /**
     * Run a write inside one transaction, with the owning row locked
     *
     * @param SettingsContext $context   Scope and owner
     * @param callable        $operation fn(?ProjectScope): void
     */
    private function transaction(SettingsContext $context, callable $operation): void
    {
        $this->entityManager->begin();

        try {
            $scope = null;

            if (in_array($context->scope, ['project', 'project_user'], true)) {
                $lock = $this->pdo->prepare('SELECT id FROM projects WHERE id=? FOR UPDATE');
                $lock->execute([$context->projectId]);
                $scope = $this->access->project((int) $context->projectId, 'read', true);

                if ($scope->project['archived_at'] !== null) {
                    throw new Failure('Dieses Projekt ist archiviert.', 403);
                }
            }

            if (in_array($context->scope, ['user', 'project_user'], true)) {
                $lock = $this->pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
                $lock->execute([$context->userId]);
                $lock->fetchColumn();
            }

            $operation($scope);
            $this->entityManager->commit();
        } catch (Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }

    /**
     * @param SettingsContext $context Scope and owner
     * @param list<string>    $keys    Keys the request mentions
     *
     * @return array<string, SettingDefinition>
     */
    private function definitionsFor(SettingsContext $context, array $keys): array
    {
        $registry    = extensions()->settings();
        $definitions = [];

        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new Failure('Ungültiger Einstellungsschlüssel.', 422);
            }

            $definition = $registry->find($context->scope, $key);

            if ($definition === null) {
                throw new Failure('Unbekannte Einstellung: ' . $key, 422);
            }

            $definitions[$key] = $definition;
        }

        return $definitions;
    }

    /**
     * @param SettingDefinition $definition The definition being checked
     * @param mixed             $value      The raw value
     *
     * @return list<string>
     */
    private function validate(SettingDefinition $definition, mixed $value): array
    {
        return $this->type($definition)->validate($value, $this->options($definition));
    }

    private function type(SettingDefinition $definition): FieldTypeInterface
    {
        $type = extensions()->fieldTypes()->get($definition->type);

        if ($type === null) {
            throw new Failure(
                'Für "' . $definition->key . '" fehlt der Feldtyp "' . $definition->type . '".',
                500,
            );
        }

        return $type;
    }

    /**
     * @param SettingDefinition $definition The definition whose options are needed
     *
     * @return array<string, mixed>
     */
    private function options(SettingDefinition $definition): array
    {
        return $definition->options;
    }

    /**
     * Where a definition's value lives when it is not one of the new tables
     *
     * @param SettingDefinition $definition The definition to place
     *
     * @return array{store: string, column: string}|null
     */
    private function legacy(SettingDefinition $definition): ?array
    {
        $legacy = $definition->options['legacy'] ?? null;

        return is_array($legacy) ? $legacy : null;
    }

    /**
     * @param SettingsContext   $context    Scope and owner
     * @param SettingDefinition $definition A definition stored in an existing table
     *
     * @return array<string, mixed>
     */
    private function legacyRow(SettingsContext $context, SettingDefinition $definition): array
    {
        return match ($this->legacy($definition)['store']) {
            'user_preferences'    => $this->preferences->user((int) $context->userId),
            'project_preferences' => $this->preferences->projectUser(
                (int) $context->projectId,
                (int) $context->userId,
            ),
            'projects' => $this->access->project((int) $context->projectId)->project,
            default    => [],
        };
    }
}
