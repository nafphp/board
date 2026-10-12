<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\UiDataProviderInterface;
use Naf\Board\Services\SavedBoardViews;
use Naf\Board\Support\UiContext;

/** Personal board bookmarks, including boards rendered by extensions. @internal */
final class SavedBoardViewsProvider implements UiDataProviderInterface
{
    public function __construct(private SavedBoardViews $views)
    {
    }

    public function data(UiContext $context): array
    {
        return ['savedViews' => $this->views->read($context->projectId())];
    }
}
