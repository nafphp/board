<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\UiDataProviderInterface;
use Naf\Board\Support\UiContext;

use function Naf\request;

/** The creation target is determined by the authorized page context. @internal */
final class WorkspaceToolsProvider implements UiDataProviderInterface
{
    public function __construct(private BoardQueryInterface $boards)
    {
    }

    public function data(UiContext $context): array
    {
        $targets = [];
        if ($context->mode !== UiContext::MODE_CREATE) {
            $targets = $context->scope === null
                ? $this->boards->transferTargets(0)
                : ($context->allows('write') ? [$context->scope->project] : []);
        }
        $query = $context->routeName === 'workspace.search' ? (request()->getQueryParams()['q'] ?? '') : '';

        return [
            'createTargets' => $targets,
            'chooseProject' => $context->scope === null,
            'searchQuery'   => is_string($query) ? $query : '',
        ];
    }
}
