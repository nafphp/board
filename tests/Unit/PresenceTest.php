<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\Presence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where somebody is, said so that another browser can read it back.
 *
 * Presence crosses from one person's page to another's, and the two may not be
 * reading this application in the same language. So a place travels as a place
 * and is turned into a word at the far end -- which only works while both ends
 * agree on the names, and this is where that agreement is written down.
 */
final class PresenceTest extends TestCase
{
    /** @return array<string, array{?string, string}> */
    public static function routes(): array
    {
        return [
            'the board itself' => ['board', 'board'],
            'its history'      => ['project.activity', 'activity'],
            'its settings'     => ['project.settings', 'settings'],
            'a ticket'         => ['ticket', 'ticket'],
            'writing one'      => ['ticket.new', 'new'],
            // Somewhere this vocabulary has no word for is still somewhere, and
            // saying so is better than saying nothing or inventing a name that
            // the page at the other end would not recognise either.
            'a page nobody mapped' => ['project.reports', Presence::ELSEWHERE],
            'rendered without one' => [null, Presence::ELSEWHERE],
        ];
    }

    #[DataProvider('routes')]
    public function testARouteBecomesAPlace(?string $route, string $expected): void
    {
        $this->assertSame($expected, Presence::place($route));
    }

    /** Every place a page can name has a word waiting for it at the other end. */
    public function testEveryPlaceCanBeSaid(): void
    {
        $words = Presence::words();

        foreach (self::routes() as $case) {
            $this->assertArrayHasKey(
                $case[1],
                $words,
                'a page can say it is somewhere this vocabulary cannot name',
            );
        }
    }

    /**
     * The names reach the browser keyed the way a subject arrives: as strings.
     *
     * Asserted on the text, because that is the only place the question has an
     * answer. A PHP array cannot hold a numeric string as a key -- it is stored
     * as an integer whatever it was cast to -- and decoding the JSON back into
     * one undoes the very thing being asked about. What the browser is handed is
     * a string, so a string is what this reads.
     */
    public function testPeopleReachTheBrowserKeyedTheWayASubjectArrives(): void
    {
        $this->assertSame(
            '{"7":"Alice Winter","8":"Robin Becker"}',
            json_encode(Presence::people([
                ['id' => 7, 'name' => 'Alice Winter'],
                ['id' => '8', 'name' => 'Robin Becker'],
            ]), JSON_THROW_ON_ERROR),
            'the ids did not survive the trip',
        );
    }

    /** Ids counted from nought are the case a JSON list would swallow whole. */
    public function testNobodyIsLostToAnInstallationThatCountsFromNought(): void
    {
        $this->assertSame(
            '{"0":"Alice Winter","1":"Robin Becker"}',
            json_encode(Presence::people([
                ['id' => 0, 'name' => 'Alice Winter'],
                ['id' => 1, 'name' => 'Robin Becker'],
            ]), JSON_THROW_ON_ERROR),
            'the names were handed over as a list, with the ids gone',
        );
    }
}
