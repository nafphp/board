<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Services\MyTickets;
use Naf\Board\Services\SavedBoardViews;
use Naf\Board\Support\Input;
use Psr\Http\Message\ResponseInterface;

use function Naf\request;

/** @internal */
final class WorkspaceController
{
    public function __construct(
        private AccessInterface $access,
        private PageRendererInterface $pages,
        private SavedBoardViews $views,
        private MyTickets $tickets,
    ) {
    }

    public function mine(): ResponseInterface
    {
        return Respond::read(fn() => $this->pages->render('my-tickets', [
            'title' => 'Meine Tickets',
            ...$this->tickets->find(request()->getQueryParams()),
        ]))->withHeader('Cache-Control', 'private, no-store');
    }

    public function followed(): ResponseInterface
    {
        return Respond::read(fn() => $this->pages->render('my-tickets', [
            'title'    => 'Gefolgte Tickets',
            'followed' => true,
            ...$this->tickets->find(request()->getQueryParams(), followed: true),
        ]))->withHeader('Cache-Control', 'private, no-store');
    }

    public function saveView(string $project): ResponseInterface
    {
        return Respond::mutation($this->access, fn(array $data): array => [
            'url' => $this->views->save(Input::id($project), $data),
        ]);
    }
}
