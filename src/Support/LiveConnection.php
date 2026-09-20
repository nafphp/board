<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Auth\Auth;
use Naf\Board\Support\Settings\PreferenceStore;

use function Naf\app;
use function Naf\config;
use function Naf\route;

/**
 * What a browser needs in order to hear a board change.
 *
 * Three things: where to connect, a token naming the channels it may join, and
 * where to ask for another one once that has expired. The page is handed all
 * three while it renders, and the endpoint that renews them hands out the same
 * set -- both through here, because a channel list decided in two places is two
 * answers to "may this person hear this", and the second one is the one nobody
 * checked.
 *
 * Nothing here decides *whether* somebody may. That is the caller's question,
 * and both callers ask Access before they ask this. This only says what hearing
 * a board is called, and whether it is wanted at all.
 */
final class LiveConnection
{
    /**
     * Whether this installation offers live updates at all.
     *
     * The package is optional, so its functions may not be there to call; an
     * installation that has it can still have it switched off, or be without the
     * key that makes a token worth anything.
     */
    public static function running(): bool
    {
        return function_exists('Naf\Websocket\live') && \Naf\Websocket\live();
    }

    /**
     * Whether the person being served right now wants one.
     *
     * Asked here rather than passed in, so that the page, the status beside it
     * and the endpoint that renews a token cannot come to different conclusions
     * about the same person. A board that rearranges itself while you read it is
     * not what everybody wants from a board, and that is a decision each person
     * makes once in their preferences.
     */
    public static function wanted(): bool
    {
        if (!self::running()) {
            return false;
        }

        $user = self::viewer();

        return $user !== null
            && (bool) app()->container()->get(PreferenceStore::class)->user($user)['live_updates'];
    }

    /** @return list<string> */
    public static function channels(int $project): array
    {
        return ['project:' . $project];
    }

    /**
     * @return array{url: string, token: string, refresh: string}|null
     *  Null when there is nothing to connect to, or nobody who wants it, which
     *  is the ordinary case and not a failure: every page works without one.
     */
    public static function forProject(int $project): ?array
    {
        $user = self::viewer();
        if ($user === null || !self::wanted()) {
            return null;
        }

        $url   = (string) config('websocket:url', '');
        $token = \Naf\Websocket\token((string) $user, self::channels($project));
        if ($url === '' || $token === '') {
            return null;
        }

        return [
            'url'     => $url,
            'token'   => $token,
            'refresh' => route('board.socket', ['project' => $project]),
        ];
    }

    /** The signed-in person, or null where there is none. */
    private static function viewer(): ?int
    {
        $id = app()->container()->get(Auth::class)->id();

        return $id === null ? null : (int) $id;
    }
}
