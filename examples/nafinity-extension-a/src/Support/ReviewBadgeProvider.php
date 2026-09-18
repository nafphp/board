<?php

declare(strict_types=1);

namespace Example\ExtensionA\Support;

use Nafinity\Contracts\UiDataProviderInterface;
use Nafinity\Support\UiContext;

/**
 * The board badge.
 *
 * The board already loaded every card's metadata in one query, so the badge
 * takes what is there instead of asking again per card.
 */
final class ReviewBadgeProvider implements UiDataProviderInterface
{
    public function data(UiContext $context): array
    {
        return [];
    }
}
