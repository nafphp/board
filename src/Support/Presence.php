<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use function Naf\I18n\t;

/**
 * Where somebody is, said in a way that survives the trip.
 *
 * Presence travels between two browsers, and the two may not be reading the
 * application in the same language. So what goes over the wire is a place, not
 * a word: `board`, `settings`, or `#NFNTY-12` for a ticket -- and the page that
 * receives it looks the word up in its own vocabulary, which the server
 * translated while rendering it. The same reason the status dot beside it is
 * handed its sentences rather than choosing them.
 *
 * A ticket needs no entry: its key is the same string in every language, and
 * showing it is more use than the word "Ticket" would be.
 *
 * The one place where a route name becomes a place, so that the page saying
 * where it is and the page reading that cannot drift apart.
 */
final class Presence
{
    /** Somewhere in this project the vocabulary has no better word for. */
    public const string ELSEWHERE = 'elsewhere';

    /** Route name to place. A route that is not here is simply somewhere else. */
    private const array WHERE = [
        'board'            => 'board',
        'project.activity' => 'activity',
        'project.settings' => 'settings',
        'ticket'           => 'ticket',
        'ticket.new'       => 'new',
    ];

    public static function place(?string $routeName): string
    {
        if ($routeName === null) {
            return self::ELSEWHERE;
        }

        return self::WHERE[$routeName] ?? self::ELSEWHERE;
    }

    /**
     * The project's people, by the subject the socket will name them with.
     *
     * An object rather than an array, and that is the whole point of the method.
     * A subject arrives at the browser as a string, so the names it looks them
     * up in have to be keyed by strings -- and a PHP array cannot hold one:
     * a numeric key is stored as an integer whatever it was cast to. What
     * decides the shape is json_encode, which renders a plain array of ids as a
     * JSON list and loses the ids altogether. Casting settles it here rather
     * than leaving it to whether the ids in some installation happen to start
     * at nought.
     *
     * The server's own roster went wrong in this exact way and was found by a
     * test; nothing on this side may assume the other end was careful.
     *
     * @param array $members Rows carrying id and name, as a board hands them over
     */
    public static function people(array $members): object
    {
        $people = [];
        foreach ($members as $member) {
            $people[(string) $member['id']] = (string) $member['name'];
        }

        return (object) $people;
    }

    /**
     * The words a browser needs to say any of that in the reader's language
     *
     * The last one is for the corner of a card, which has room for a face and
     * for nothing else -- so the sentence naming who is behind it is the title,
     * and it is written here like the rest of them rather than assembled in a
     * browser that has no business knowing how this language puts a list.
     *
     * @return array<string, string>
     */
    public static function words(): array
    {
        return [
            'board'         => t('am Board'),
            'activity'      => t('in der Aktivität'),
            'settings'      => t('in den Einstellungen'),
            'new'           => t('schreibt ein neues Ticket'),
            'ticket'        => t('in einem Ticket'),
            self::ELSEWHERE => t('im Projekt'),
            'someone'       => t('Jemand anderes'),
            'more'          => t(':count weitere'),
            'watching'      => t('Gerade geöffnet von :names'),
        ];
    }
}
