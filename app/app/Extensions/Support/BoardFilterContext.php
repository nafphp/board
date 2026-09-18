<?php

declare(strict_types=1);

namespace Nafinity\Support;

use App\Domain\ProjectScope;

/**
 * What a board filter may rely on while building its condition.
 */
final readonly class BoardFilterContext
{
    /**
     * @param int          $projectId The already authorized project
     * @param ProjectScope $scope     Rights of the current actor in that project
     * @param string       $alias     SQL alias of the tickets table
     */
    public function __construct(
        public int $projectId,
        public ProjectScope $scope,
        public string $alias = 't',
    ) {
    }
}
