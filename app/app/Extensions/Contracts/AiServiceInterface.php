<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * The tool catalogue of the local AI chat and its authorized execution.
 */
interface AiServiceInterface
{
    public function definitions(?int $project): array;

    public function call(?int $project, array $data): mixed;
}
