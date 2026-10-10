<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\UiDataProviderInterface;
use Naf\Board\Support\UiContext;

use function Naf\request;

/** Keeps the workspace search query when viewing its result page. @internal */
final class WorkspaceToolsProvider implements UiDataProviderInterface
{
    public function data(UiContext $context): array
    {
        $query = $context->routeName === 'workspace.search' ? (request()->getQueryParams()['q'] ?? '') : '';

        return [
            'searchQuery' => is_string($query) ? $query : '',
        ];
    }
}
