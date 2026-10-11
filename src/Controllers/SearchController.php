<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Services\WorkspaceSearch;
use Psr\Http\Message\ResponseInterface;

use function Naf\json;
use function Naf\request;

/** @internal */
final class SearchController
{
    public function __construct(private WorkspaceSearch $search, private PageRendererInterface $pages)
    {
    }

    public function index(): ResponseInterface
    {
        return Respond::read(fn() => $this->pages->render('search', [
            'title' => 'Suche',
            ...$this->search->find(request()->getQueryParams()['q'] ?? ''),
        ]))->withHeader('Cache-Control', 'private, no-store');
    }

    public function suggestions(): ResponseInterface
    {
        return Respond::read(function () {
            $data = $this->search->find(request()->getQueryParams()['q'] ?? '', true);

            return json([
                ...$data,
                'html' => $this->pages->fragment('search/results', [...$data, 'suggestions' => true]),
            ]);
        })->withHeader('Cache-Control', 'private, no-store');
    }
}
