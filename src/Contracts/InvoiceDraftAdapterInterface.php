<?php

declare(strict_types=1);

namespace Naf\Board\Contracts;

use SensitiveParameter;

/** Optional delivery capability of an invoice-items exporter in the central registry. */
interface InvoiceDraftAdapterInterface
{
    /** Additional required invoice fields, keyed by name, with translated labels. */
    public function fields(): array;

    /** Validate an existing recipient through the provider, returning its display name. */
    public function customer(string $id, #[SensitiveParameter] array $credentials): string;

    /** Complete the existing writer's positions; must create a draft, never finalize or send. */
    public function payload(array $positions, array $input, string $reference): array;

    /** One attempt only. A missing or ambiguous response must throw, never retry. */
    public function create(array $payload, #[SensitiveParameter] array $credentials): string;
}
