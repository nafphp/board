<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Domain\Failure;
use JsonException;
use Nafinity\Contracts\SettingsStoreInterface;
use Nafinity\Support\SettingsContext;
use PDO;

/**
 * Where contributed settings values live.
 *
 * One row per key, so a new field never needs a new column. A stored null and
 * a missing key are different things: the row exists or it does not. The store
 * joins the caller's transaction and never opens or commits one of its own.
 */
final class DatabaseSettingsStore implements SettingsStoreInterface
{
    /** JSON per value, matching the ticket metadata limit. */
    public const int MAX_BYTES = 16384;

    /** Scope to table and the columns that identify an owner. */
    private const array TABLES = [
        'user'         => ['user_settings', ['user_id']],
        'project'      => ['project_settings', ['project_id']],
        'project_user' => ['project_user_settings', ['project_id', 'user_id']],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function read(SettingsContext $context, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        [$table, $owner] = $this->target($context);
        $marks           = implode(',', array_fill(0, count($keys), '?'));
        $where           = implode(' AND ', array_map(
            static fn(string $column) => $column . '=?',
            array_keys($owner),
        ));

        $statement = $this->pdo->prepare(
            "SELECT setting_key, value_json FROM $table WHERE $where AND setting_key IN ($marks)",
        );
        $statement->execute([...array_values($owner), ...$keys]);

        $values = [];

        foreach ($statement->fetchAll() as $row) {
            $values[$row['setting_key']] = $this->decode($row['value_json'], $row['setting_key']);
        }

        return $values;
    }

    public function write(SettingsContext $context, array $values, array $resetKeys = []): void
    {
        [$table, $owner] = $this->target($context);
        $where           = implode(' AND ', array_map(
            static fn(string $column) => $column . '=?',
            array_keys($owner),
        ));

        if ($resetKeys !== []) {
            $marks = implode(',', array_fill(0, count($resetKeys), '?'));
            $this->pdo
                ->prepare("DELETE FROM $table WHERE $where AND setting_key IN ($marks)")
                ->execute([...array_values($owner), ...$resetKeys]);
        }

        if ($values === []) {
            return;
        }

        $columns = [...array_keys($owner), 'setting_key', 'value_json', 'updated_at'];
        $marks   = implode(',', array_fill(0, count($columns), '?'));
        $tail    = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)'
            : ' ON CONFLICT(' . implode(',', [...array_keys($owner), 'setting_key'])
                . ') DO UPDATE SET value_json=excluded.value_json,updated_at=excluded.updated_at';

        $statement = $this->pdo->prepare(
            "INSERT INTO $table(" . implode(',', $columns) . ") VALUES($marks)" . $tail,
        );
        $now = date('Y-m-d H:i:s');

        foreach ($values as $key => $value) {
            $statement->execute([
                ...array_values($owner),
                $key,
                $this->encode($value, (string) $key),
                $now,
            ]);
        }
    }

    /**
     * @param SettingsContext $context Scope and owner
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function target(SettingsContext $context): array
    {
        if (!isset(self::TABLES[$context->scope])) {
            throw new Failure('Für diesen Bereich gibt es keinen Wertespeicher.', 400);
        }

        [$table, $columns] = self::TABLES[$context->scope];
        $owner             = [];

        foreach ($columns as $column) {
            $owner[$column] = $column === 'user_id' ? $context->userId : $context->projectId;
        }

        return [$table, $owner];
    }

    private function encode(mixed $value, string $key): string
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new Failure(
                'Der Wert von "' . $key . '" lässt sich nicht speichern: ' . $exception->getMessage(),
                422,
            );
        }

        if (strlen($json) > self::MAX_BYTES) {
            throw new Failure('Der Wert von "' . $key . '" ist zu groß.', 422);
        }

        return $json;
    }

    private function decode(string $json, string $key): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new Failure(
                'Der gespeicherte Wert von "' . $key . '" ist beschädigt: ' . $exception->getMessage(),
                500,
            );
        }
    }
}
