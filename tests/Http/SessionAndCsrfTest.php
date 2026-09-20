<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a session is worth over the wire.
 *
 * The cookie is Secure, the token is the same in every tab of one session, and a
 * write without a valid one is refused whatever it dresses itself up as -- a
 * Bearer header is not an alternative to CSRF, it is just a header.
 */
final class SessionAndCsrfTest extends AcceptanceTestCase
{
    public function testTheSessionCookieIsSecure(): void
    {
        $this->assertMatchesRegularExpression(
            '/set-cookie:[^\n]*secure/i',
            $this->alice->loginHeaders(),
            'the session cookie is served over HTTPS without Secure',
        );
    }

    public function testTheTokenIsTheSameInEveryTabOfOneSession(): void
    {
        $board  = $this->alice->token($this->page($this->alice, '/projects/' . self::PROJECT));
        $ticket = $this->alice->token($this->page($this->alice, '/projects/' . self::PROJECT . '/tickets/NAF-1'));

        $this->assertSame($board, $ticket);
    }

    /**
     * @return array<string,array{string,list<string>,array<string,mixed>}>
     */
    public static function writesWithoutAValidToken(): array
    {
        return [
            'no token at all'            => ['POST', [], []],
            'a bearer header instead'    => ['PATCH', ['Authorization: Bearer invented'], []],
            'a token of the wrong shape' => ['POST', [], ['_csrf' => ['not-a-string']]],
        ];
    }

    /**
     * @param list<string>        $headers
     * @param array<string,mixed> $body
     */
    #[DataProvider('writesWithoutAValidToken')]
    public function testAWriteWithoutAValidTokenIsRefused(string $method, array $headers, array $body): void
    {
        $response = $this->alice->request(
            '/projects/' . self::PROJECT . '/tickets/NAF-1',
            $body,
            [...$headers, 'Content-Type: application/json', 'Accept: application/json'],
            $method,
        );

        $this->assertSame(400, $response['status']);
    }

    public function testAnotherAccountIsNotToldTheProjectExists(): void
    {
        // Bob holds no installation-wide grant, so the list is his membership
        // and nothing else. Alice administers every board and is therefore the
        // one account this cannot be asked with.
        $this->assertStringNotContainsString(
            '/projects/' . self::PROJECT,
            $this->page($this->bob, '/projects'),
            "another owner's project was listed",
        );
    }

    public function testAnAdministratorIsToldAboutEveryProject(): void
    {
        // The other half of the same rule: reaching a board and seeing it listed
        // are one right, so a list that hid what the next click opens would only
        // be a worse way to hold the permission.
        $this->assertStringContainsString(
            '/projects/' . self::OTHER_PROJECT,
            $this->page($this->alice, '/projects'),
            'the administrator was not shown a board they may administer',
        );
    }

    /**
     * The extra filters are a menu, not a section that grows.
     *
     * Their panel lies over the board. Without this attribute the sweep in
     * disclosure.js animates the element's own height instead, which pushed the
     * board down for the length of the animation -- 35px to 282px, and the
     * document with it -- and snapped it back when the animation ended.
     */
    public function testTheExtraFiltersOpenOverTheBoardRatherThanInsideIt(): void
    {
        $board = $this->page($this->alice, '/projects/' . self::PROJECT);

        $this->assertMatchesRegularExpression(
            '/<details[^>]*class="extra-filters"[^>]*\bdata-menu\b/',
            $board,
            'the extra filters would animate their own height and move the board',
        );
    }

    /**
     * The page says whose projects it is showing.
     *
     * An administrator is shown boards that are not theirs, so "Deine Projekte"
     * over that list would be a quiet untruth about half of it -- and the
     * heading is where somebody checks whether they are seeing everything.
     */
    public function testThePageIsCalledAfterWhatItActuallyLists(): void
    {
        $this->assertStringContainsString('<h1>Alle Projekte</h1>', $this->page($this->alice, '/projects'));
        $this->assertStringContainsString('<h1>Deine Projekte</h1>', $this->page($this->bob, '/projects'));
    }

    public function testTheTabAgreesWithTheHeading(): void
    {
        $this->assertStringContainsString(
            '<title>Alle Projekte · Nafinity</title>',
            $this->page($this->alice, '/projects'),
        );
        $this->assertStringContainsString(
            '<title>Deine Projekte · Nafinity</title>',
            $this->page($this->bob, '/projects'),
        );
    }

    /**
     * @return array<string,array{string}>
     */
    public static function projectPaths(): array
    {
        return [
            'the board'       => ['/projects/1'],
            'the settings'    => ['/projects/1/settings'],
            'the activity'    => ['/projects/1/activity'],
            'the board state' => ['/projects/1/state'],
            'a ticket'        => ['/projects/1/tickets/NAF-1'],
        ];
    }

    #[DataProvider('projectPaths')]
    public function testEveryPathOfAForeignProjectIsNotFound(string $path): void
    {
        $this->assertSame(404, $this->bob->request($path)['status']);
    }
}
