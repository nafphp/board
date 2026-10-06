<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Auth\Auth;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Support\CardContext;
use Naf\Board\Support\Input;
use Naf\Board\Support\LiveConnection;
use Naf\Board\Support\UiContext;
use Psr\Http\Message\ResponseInterface;

use function Naf\Board\partial;
use function Naf\Form\csrf;
use function Naf\json;
use function Naf\request;
use function Naf\route;

/**
 * A project's board and its history, as pages and as the data that keeps an
 * open page current.
 *
 * @internal
 */
final class BoardController
{
    public function __construct(
        private Auth $auth,
        private BoardQueryInterface $query,
        private TicketServiceInterface $tickets,
        private AccessInterface $access,
        private PageRendererInterface $pages,
    ) {
    }

    public function projects(): ResponseInterface
    {
        return $this->pages->render('projects', ['title' => 'Deine Projekte']);
    }

    public function board(string $project): ResponseInterface
    {
        return Respond::read(
            fn() => $this->pages->render('board', [
                'title' => 'Board',
                ...$this->query->board(Input::id($project), request()->getQueryParams()),
            ]),
        );
    }

    public function activity(string $project): ResponseInterface
    {
        return Respond::read(
            fn() => $this->pages->render('activity', [
                'title' => 'Aktivität',
                ...$this->query->board(Input::id($project)),
                'activity' => $this->query->activity(Input::id($project)),
            ]),
        );
    }

    /**
     * The board as data, with its cards already rendered.
     *
     * Placement is JSON because the client owns it: which cell a card sits in,
     * what the counters say, which revision this is. The card itself is HTML
     * because the server owns that -- the five extension slots on a card are
     * PHP, and so are the registries behind a priority symbol or an estimate.
     * Rendering a card in the browser would mean a second implementation of all
     * of it, and a card that means something different depending on how it
     * arrived.
     *
     * The query string comes along, so a board that is filtered answers
     * filtered: a live update cannot smuggle in a card the filter excludes.
     */
    public function cards(string $project): ResponseInterface
    {
        return Respond::read(function () use ($project) {
            $id    = Input::id($project);
            $board = $this->query->board($id, request()->getQueryParams());

            $context = CardContext::build([
                ...$board,
                'uiContext' => new UiContext(
                    (int) $this->auth->id(),
                    $board['scope'],
                    UiContext::MODE_PAGE,
                    route()->current(),
                ),
                'token' => csrf()->token(),
            ]);

            $cells   = [];
            $cards   = [];
            $columns = [];
            $lanes   = [];
            foreach ($board['cards'] as $card) {
                $cells[$card['column_id'] . ':' . $card['swimlane_id']][] = (string) $card['id'];
                $cards[(string) $card['id']]                              = partial(
                    'board/card',
                    ['card' => $card, 'board' => $context],
                );

                $column                     = (string) $card['column_id'];
                $columns[$column]['count']  = ($columns[$column]['count'] ?? 0) + 1;
                $columns[$column]['points'] = ($columns[$column]['points'] ?? 0)
                    + (int) $card['estimate_points'];
                $lane         = (string) $card['swimlane_id'];
                $lanes[$lane] = ($lanes[$lane] ?? 0) + 1;
            }

            return json([
                'revision' => (string) $board['board']['revision'],
                'total'    => (int) $board['total'],
                'limited'  => (int) $board['total'] > CardContext::LIMIT,
                'cells'    => $cells,
                'cards'    => $cards,
                'columns'  => $columns,
                'lanes'    => $lanes,
            ]);
        });
    }

    /** Which revision the board is at, for a page asking whether it is behind. */
    public function state(string $project): ResponseInterface
    {
        return Respond::read(function () use ($project) {
            $id = Input::id($project);
            $this->access->project($id);

            return json(['revision' => (string) $this->tickets->board($id)['revision']]);
        });
    }

    /**
     * Another token for this board, for a browser whose last one has expired.
     *
     * A token is short-lived on purpose -- it rides in a query string, and query
     * strings end up in logs -- so a connection dropped for longer than that
     * cannot come back with the one the page was rendered with. This is where it
     * asks for the next one, and the answer goes through the same membership
     * check as the board itself: somebody who has lost access quietly stops
     * being given tokens, and the socket they hold carries nothing anyway.
     *
     * An installation with no server answers without a token. That is not an
     * error either; it is how the client is told to stop asking.
     */
    public function socket(string $project): ResponseInterface
    {
        return Respond::read(function () use ($project) {
            $id = Input::id($project);
            $this->access->project($id);

            return json(LiveConnection::forProject($id) ?? ['live' => false]);
        });
    }

    /**
     * What has happened here since the page was drawn, already rendered.
     *
     * The socket says a board changed and nothing more, so a page watching the
     * history asks the same way the board does: through its own authorised path,
     * naming the last entry it holds. Answering "everything newer than this"
     * rather than "the newest fifty" means two people watching at once are not
     * handed each other's duplicates.
     */
    public function activityEntries(string $project): ResponseInterface
    {
        return Respond::read(function () use ($project) {
            $id          = Input::id($project);
            $board       = $this->query->board($id);
            $preferences = $this->query->preferences();
            $after       = (int) (request()->getQueryParams()['after'] ?? 0);

            $entries = [];
            foreach ($this->query->activitySince($id, $after) as $item) {
                $entries[(string) $item['id']] = partial('activity/entry', [
                    'item'        => $item,
                    'project'     => $board['project'],
                    'preferences' => $preferences,
                ]);
            }

            return json(['entries' => $entries]);
        });
    }
}
