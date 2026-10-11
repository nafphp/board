<?php

declare(strict_types=1);

namespace Naf\Board\Definition;

use InvalidArgumentException;
use Naf\Board\Contracts\BulkPropertyHandlerInterface;

/** An explicit opt-in to mass editing; never an arbitrary database column. */
final readonly class BulkProperty
{
    /** @param class-string<BulkPropertyHandlerInterface> $handler */
    public function __construct(
        public string $key,
        public string $resource,
        public string $label,
        public string $type,
        public string $handler,
        public int $index = 100,
        public array $options = [],
    ) {
        if ($key === '' || $resource === '' || str_contains($key, ':') || str_contains($resource, ':')) {
            throw new InvalidArgumentException('A bulk property needs a resource and key without colons.');
        }
    }

    public function id(): string
    {
        return $this->resource . ':' . $this->key;
    }
}
