<?php

declare(strict_types=1);

namespace Naf\Board\Contracts;

use Closure;
use Naf\Board\Definition\BulkProperty;

/** Domain-specific authorization and persistence for an opted-in property. */
interface BulkPropertyHandlerInterface
{
    /** Authorize the actor and resolve current type options; never trust client choices. */
    public function options(BulkProperty $property, int $actor): array;

    /**
     * Runs inside the batch transaction, before any property is written.
     * Lock and authorize EVERY target, check expected versions where applicable,
     * and return a write on the same connection. Do not commit or perform external
     * side effects. Throwing, including from write listeners, rolls back the batch.
     *
     * @param list<int> $targets Sorted, distinct, explicit target ids (maximum 50)
     * @param mixed $value Validated and normalized by the registered field type
     * @return Closure(): void
     */
    public function prepare(BulkProperty $property, int $actor, array $targets, mixed $value): Closure;
}
