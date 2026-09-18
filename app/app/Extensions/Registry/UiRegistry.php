<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use LogicException;
use Nafinity\Contracts\UiDataProviderInterface;
use Nafinity\Definition\UiContribution;
use Nafinity\Support\Resolver;
use Nafinity\Support\UiContext;

use function Naf\app;

/**
 * Every template contributed to a named slot.
 *
 * The three fixed ticket areas are reserved: a contribution may sit next to the
 * title, the description and the comments, but nothing removes or replaces them
 * through this registry.
 */
final class UiRegistry extends DefinitionRegistry
{
    /** Ticket areas that stay part of the product, not of the contribution list. */
    public const array RESERVED_IDS = [
        'core.ticket.title',
        'core.ticket.description',
        'core.ticket.comments',
    ];

    public function __construct()
    {
        parent::__construct(UiContribution::class, 'UI contribution');
    }

    public function get(string $id): ?UiContribution
    {
        return parent::get($id);
    }

    /**
     * The contributions of one slot the actor may actually see, with their data
     *
     * Permission and mode decide before a provider is resolved, so a hidden
     * contribution never runs code and never loads a record.
     *
     * @param string    $slot    Slot name
     * @param UiContext $context The authorized rendering context
     *
     * @return list<array{contribution: UiContribution, data: array}>
     */
    public function forSlot(string $slot, UiContext $context): array
    {
        $visible = [];

        foreach ($this->all() as $contribution) {
            if ($contribution->slot !== $slot || !$contribution->appearsIn($context->mode)) {
                continue;
            }

            if (!$context->allows($contribution->permission)) {
                continue;
            }

            $visible[] = [
                'contribution' => $contribution,
                'data'         => $this->resolveData($contribution, $context),
            ];
        }

        return $visible;
    }

    protected function guardReserved(string $id, ?object $definition): void
    {
        if (in_array($id, self::RESERVED_IDS, true)) {
            throw new LogicException(sprintf(
                'The ticket area "%s" is a fixed part of Nafinity and cannot be replaced or '
                . 'removed through the contribution registry.',
                $id,
            ));
        }
    }

    private function resolveData(UiContribution $contribution, UiContext $context): array
    {
        if ($contribution->provider === null) {
            return [];
        }

        $provider = Resolver::service(app()->container(), $contribution->provider);

        if (!$provider instanceof UiDataProviderInterface) {
            throw new LogicException(sprintf(
                'Provider "%s" of contribution "%s" is not a UiDataProviderInterface.',
                $contribution->provider,
                $contribution->id,
            ));
        }

        return $provider->data($context);
    }
}
