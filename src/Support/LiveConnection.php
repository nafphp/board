<?php

declare(strict_types=1);

namespace Naf\Board\Support;

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
 * a board is called.
 */
final class LiveConnection
{
    /** @return list<string> */
    public static function channels(int $project): array
    {
        return ['project:' . $project];
    }

    /**
     * @return array{url: string, token: string, refresh: string}|null
     *  Null when this installation runs no socket server, which is the ordinary
     *  case and not a failure: every page works without one.
     */
    public static function forProject(int $project, string $subject): ?array
    {
        // The package is optional, so its functions may not be there at all.
        if (!function_exists('Naf\Websocket\live') || !\Naf\Websocket\live()) {
            return null;
        }

        $url   = (string) config('websocket:url', '');
        $token = \Naf\Websocket\token($subject, self::channels($project));
        if ($url === '' || $token === '') {
            return null;
        }

        return [
            'url'     => $url,
            'token'   => $token,
            'refresh' => route('board.socket', ['project' => $project]),
        ];
    }
}
