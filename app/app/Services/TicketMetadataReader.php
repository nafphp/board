<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\TicketMetadataReaderInterface;
use Nafinity\Contracts\TicketServiceInterface;

use function Nafinity\extensions;

/**
 * The authorized way to read ticket metadata.
 *
 * Membership, the ticket's project and the field's own read permission are
 * checked on every call. An unknown key is simply the caller's default; a known
 * key the actor may not see is a 403, because pretending it is absent would be
 * a different, wrong answer.
 */
final class TicketMetadataReader implements TicketMetadataReaderInterface
{
    public function __construct(
        private AccessInterface $access,
        private TicketServiceInterface $tickets,
        private TicketMetadataWriter $metadata,
    ) {
    }

    public function get(int $projectId, int $ticketId, string $key, mixed $default = null): mixed
    {
        $definition = extensions()->ticketFields()->get($key);

        if ($definition === null || !$definition->isMetadata()) {
            return $default;
        }

        $scope = $this->access->project($projectId);
        $this->tickets->ticket($projectId, $ticketId);

        if (!$scope->allows($definition->readPermission)) {
            throw new Failure('Du hast für dieses Feld keine Berechtigung.', 403);
        }

        $values = $this->metadata->readable($scope, $projectId, [$ticketId])[$ticketId] ?? [];

        return array_key_exists($key, $values) ? $values[$key] : $definition->default;
    }

    public function all(int $projectId, int $ticketId): array
    {
        $scope = $this->access->project($projectId);
        $this->tickets->ticket($projectId, $ticketId);

        $stored = $this->metadata->readable($scope, $projectId, [$ticketId])[$ticketId] ?? [];
        $values = [];

        foreach (extensions()->ticketFields()->metadata() as $key => $definition) {
            if (!$scope->allows($definition->readPermission)) {
                continue;
            }

            $values[$key] = array_key_exists($key, $stored) ? $stored[$key] : $definition->default;
        }

        return $values;
    }
}
