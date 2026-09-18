<?php

declare(strict_types=1);

namespace App\Support\Ticket;

use App\Domain\Failure;
use JsonException;
use Nafinity\Contracts\TicketMetadataStoreInterface;
use PDO;

/**
 * Where contributed ticket metadata is persisted.
 *
 * Every statement names the project, and the write runs inside the domain
 * transaction the caller has already opened: it never begins or commits one, so
 * a failure further along still rolls the whole ticket change back.
 */
final class DatabaseTicketMetadataStore implements TicketMetadataStoreInterface
{
    /** JSON per value. */
    public const int MAX_VALUE_BYTES = 16384;

    /** JSON of all values of one ticket. */
    public const int MAX_TICKET_BYTES = 65536;

    /** Keys of one ticket, unknown ones included. */
    public const int MAX_KEYS = 100;

    public function __construct(private PDO $pdo)
    {
    }

    public function read(int $projectId, array $ticketIds): array
    {
        $ticketIds = array_values(array_unique(array_map('intval', $ticketIds)));

        if ($ticketIds === []) {
            return [];
        }

        $marks     = implode(',', array_fill(0, count($ticketIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT ticket_id, meta_key, value_json FROM ticket_metadata'
            . " WHERE project_id=? AND ticket_id IN ($marks) ORDER BY ticket_id, meta_key",
        );
        $statement->execute([$projectId, ...$ticketIds]);

        $values = array_fill_keys($ticketIds, []);

        foreach ($statement->fetchAll() as $row) {
            $values[(int) $row['ticket_id']][$row['meta_key']] = $this->decode(
                $row['value_json'],
                $row['meta_key'],
            );
        }

        return $values;
    }

    public function write(int $projectId, int $ticketId, array $values, array $resetKeys = []): void
    {
        if ($resetKeys !== []) {
            $marks = implode(',', array_fill(0, count($resetKeys), '?'));
            $this->pdo
                ->prepare(
                    'DELETE FROM ticket_metadata'
                    . " WHERE project_id=? AND ticket_id=? AND meta_key IN ($marks)",
                )
                ->execute([$projectId, $ticketId, ...$resetKeys]);
        }

        if ($values === []) {
            return;
        }

        $encoded = [];

        foreach ($values as $key => $value) {
            $encoded[(string) $key] = $this->encode($value, (string) $key);
        }

        $this->guardTotals($projectId, $ticketId, $encoded);

        $tail = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)'
            : ' ON CONFLICT(project_id,ticket_id,meta_key)'
                . ' DO UPDATE SET value_json=excluded.value_json,updated_at=excluded.updated_at';

        $statement = $this->pdo->prepare(
            'INSERT INTO ticket_metadata(project_id,ticket_id,meta_key,value_json,updated_at)'
            . ' VALUES(?,?,?,?,?)' . $tail,
        );
        $now = gmdate('Y-m-d H:i:s');

        foreach ($encoded as $key => $json) {
            $statement->execute([$projectId, $ticketId, $key, $json, $now]);
        }
    }

    /**
     * Keep one ticket's metadata within its documented limits
     *
     * Values of an absent plugin count too: they occupy the same ticket and the
     * limit is about the ticket, not about who happens to be installed.
     *
     * @param int                   $projectId The owning project
     * @param int                   $ticketId  The ticket being written
     * @param array<string, string> $incoming  Encoded values about to be stored
     */
    private function guardTotals(int $projectId, int $ticketId, array $incoming): void
    {
        $statement = $this->pdo->prepare(
            'SELECT meta_key, value_json FROM ticket_metadata WHERE project_id=? AND ticket_id=?',
        );
        $statement->execute([$projectId, $ticketId]);

        $stored = [];

        foreach ($statement->fetchAll() as $row) {
            $stored[$row['meta_key']] = $row['value_json'];
        }

        $combined = [...$stored, ...$incoming];

        if (count($combined) > self::MAX_KEYS) {
            throw new Failure(
                'Dieses Ticket hat bereits ' . self::MAX_KEYS . ' Metadatenfelder.',
                422,
            );
        }

        if (array_sum(array_map('strlen', $combined)) > self::MAX_TICKET_BYTES) {
            throw new Failure('Die Metadaten dieses Tickets sind insgesamt zu groß.', 422);
        }
    }

    private function encode(mixed $value, string $key): string
    {
        if (is_resource($value) || is_object($value)) {
            throw new Failure('Der Wert von "' . $key . '" lässt sich nicht speichern.', 422);
        }

        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            throw new Failure('Der Wert von "' . $key . '" ist keine gültige Zahl.', 422);
        }

        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new Failure(
                'Der Wert von "' . $key . '" lässt sich nicht speichern: ' . $exception->getMessage(),
                422,
            );
        }

        if (strlen($json) > self::MAX_VALUE_BYTES) {
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
