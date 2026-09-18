<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Domain\ProjectScope;
use Nafinity\Contracts\FieldTypeInterface;
use Nafinity\Contracts\TicketMetadataStoreInterface;
use Nafinity\Definition\TicketFieldDefinition;

use function Naf\I18n\t;
use function Nafinity\extensions;

/**
 * Validates and stores the metadata part of a ticket change.
 *
 * It runs inside the transaction and the project lock TicketService has already
 * taken, so metadata, core fields, pivots and the recorded change either all
 * happen or none of them do.
 */
final class TicketMetadataWriter
{
    public function __construct(private TicketMetadataStoreInterface $store)
    {
    }

    /**
     * Read the payload into validated values, without touching the database
     *
     * Absent `metadata` means the request says nothing about metadata and
     * nothing changes. A key can be set or reset, never both.
     *
     * @param ProjectScope $scope    The authorized project
     * @param array        $data     The request payload
     * @param bool         $creating Whether this is a new ticket
     *
     * @return array{values: array<string, mixed>, resetKeys: list<string>}
     */
    public function prepare(ProjectScope $scope, array $data, bool $creating): array
    {
        $incoming = $data['metadata'] ?? null;
        $resets   = $data['metadata_reset'] ?? [];

        if ($incoming !== null && !is_array($incoming)) {
            throw new Failure('Metadaten werden als Objekt erwartet.', 422);
        }

        if (!is_array($resets)) {
            throw new Failure('metadata_reset wird als Liste erwartet.', 422);
        }

        $definitions = extensions()->ticketFields()->metadata();
        $values      = [];
        $errors      = [];
        $resetKeys   = [];

        foreach ($resets as $key) {
            $definition = $this->definition($definitions, $key);
            $this->requireWrite($scope, $definition);
            $resetKeys[] = $definition->key;
        }

        foreach ($incoming ?? [] as $key => $value) {
            $definition = $this->definition($definitions, $key);

            if (in_array($definition->key, $resetKeys, true)) {
                throw new Failure(
                    'Der Schlüssel "' . $definition->key
                    . '" kann nicht gleichzeitig gesetzt und zurückgesetzt werden.',
                    422,
                );
            }

            $this->requireWrite($scope, $definition);
            $messages = $this->validate($definition, $value);

            if ($messages !== []) {
                $errors['metadata[' . $definition->key . ']'] = $messages;
                continue;
            }

            $values[$definition->key] = $this->type($definition)
                ->normalize($value, $this->options($definition));
        }

        if ($creating) {
            $errors = [...$errors, ...$this->missingRequired($definitions, $values, $scope)];
        }

        if ($errors !== []) {
            throw new Failure('Bitte prüfe die Eingaben.', 422, $errors);
        }

        return ['values' => $values, 'resetKeys' => $resetKeys];
    }

    /**
     * @param int                                 $projectId The owning project
     * @param int                                 $ticketId  The ticket being written
     * @param array{values: array, resetKeys: array} $prepared  Result of prepare()
     */
    public function write(int $projectId, int $ticketId, array $prepared): void
    {
        if ($prepared['values'] === [] && $prepared['resetKeys'] === []) {
            return;
        }

        $this->store->write($projectId, $ticketId, $prepared['values'], $prepared['resetKeys']);
    }

    /**
     * The metadata of several tickets, reduced to what this actor may read
     *
     * @param ProjectScope $scope     The authorized project
     * @param int          $projectId The owning project
     * @param list<int>    $ticketIds Tickets to read in one query
     *
     * @return array<int, array<string, mixed>>
     */
    public function readable(ProjectScope $scope, int $projectId, array $ticketIds): array
    {
        $definitions = extensions()->ticketFields()->metadata();
        $readable    = [];

        foreach ($this->store->read($projectId, $ticketIds) as $ticketId => $values) {
            $allowed = [];

            foreach ($definitions as $key => $definition) {
                if (!array_key_exists($key, $values) || !$scope->allows($definition->readPermission)) {
                    continue;
                }

                $allowed[$key] = $this->type($definition)
                    ->normalize($values[$key], $this->options($definition));
            }

            $readable[$ticketId] = $allowed;
        }

        return $readable;
    }

    /**
     * Keys stored for a ticket that no installed definition explains
     *
     * @param int $projectId The owning project
     * @param int $ticketId  The ticket
     *
     * @return list<string>
     */
    public function unknownKeys(int $projectId, int $ticketId): array
    {
        $stored = $this->store->read($projectId, [$ticketId])[$ticketId] ?? [];

        return array_values(array_diff(
            array_keys($stored),
            array_keys(extensions()->ticketFields()->metadata()),
        ));
    }

    /**
     * @param array<string, TicketFieldDefinition> $definitions Registered metadata fields
     * @param mixed                                $key         The key the request used
     */
    private function definition(array $definitions, mixed $key): TicketFieldDefinition
    {
        if (!is_string($key) || !isset($definitions[$key])) {
            throw new Failure('Unbekanntes Ticketfeld: ' . (is_string($key) ? $key : '?'), 422);
        }

        return $definitions[$key];
    }

    private function requireWrite(ProjectScope $scope, TicketFieldDefinition $definition): void
    {
        if ($definition->readOnly) {
            throw new Failure(
                'Das Feld "' . $definition->key . '" kann nicht geändert werden.',
                403,
            );
        }

        if (!$scope->allows($definition->writePermission)) {
            throw new Failure('Du hast für dieses Feld keine Berechtigung.', 403);
        }
    }

    /**
     * @param array<string, TicketFieldDefinition> $definitions Registered metadata fields
     * @param array<string, mixed>                 $values      Values about to be written
     * @param ProjectScope                         $scope       The authorized project
     *
     * @return array<string, list<string>>
     */
    private function missingRequired(array $definitions, array $values, ProjectScope $scope): array
    {
        $errors = [];

        foreach ($definitions as $key => $definition) {
            if (!$definition->required || array_key_exists($key, $values)) {
                continue;
            }

            if (!$scope->allows($definition->writePermission)) {
                continue;
            }

            $messages = $this->validate($definition, $definition->default);

            if ($messages !== []) {
                $errors['metadata[' . $key . ']'] = $messages;
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validate(TicketFieldDefinition $definition, mixed $value): array
    {
        return $this->type($definition)->validate($value, $this->options($definition));
    }

    private function type(TicketFieldDefinition $definition): FieldTypeInterface
    {
        $type = extensions()->fieldTypes()->get($definition->type);

        if ($type === null) {
            throw new Failure(
                t('Für ":key" fehlt der Feldtyp ":type".', [
                    'key'  => $definition->key,
                    'type' => $definition->type,
                ]),
                500,
            );
        }

        return $type;
    }

    /**
     * The validator context of a field: its own options plus its declared flags
     *
     * @return array<string, mixed>
     */
    private function options(TicketFieldDefinition $definition): array
    {
        return [
            ...$definition->options,
            'nullable' => $definition->nullable,
            'required' => $definition->required,
        ];
    }
}
