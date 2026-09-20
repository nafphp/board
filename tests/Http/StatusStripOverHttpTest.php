<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Support\LiveConnection;
use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * The two things the corner of the tab row says.
 *
 * It used to repeat the role, which stands beside the person's own name in the
 * sidebar anyway. What nothing else on the page says is which build is serving
 * it and whether it is still hearing the server -- and a board that has quietly
 * stopped updating looks exactly like a board where nothing has happened.
 */
final class StatusStripOverHttpTest extends AcceptanceTestCase
{
    public function testTheBoardNamesTheVersionItIsRunning(): void
    {
        $this->assertMatchesRegularExpression(
            '/class="pill"[^>]*>\s*v\d+\.\d+/',
            $this->board(),
            'the page carries no version',
        );
    }

    public function testTheBoardSaysWhetherItIsHearingTheServer(): void
    {
        if (!LiveConnection::running()) {
            $this->markTestSkipped('This installation has no live updates to report on.');
        }

        $page = $this->board();

        $this->assertStringContainsString('data-live-status="waiting"', $page);
        // The colour is the whole message and a screen reader cannot see it, so
        // the sentence has to be in the markup for the module to keep current.
        $this->assertStringContainsString('data-live-text', $page);
    }

    /** What it replaced: the role, twice on one screen, once of them redundant. */
    public function testTheRoleIsNoLongerRepeatedInTheTabRow(): void
    {
        $this->assertStringNotContainsString('role-badge', $this->board());
    }

    private function board(): string
    {
        $response = $this->alice->request('/projects/' . self::PROJECT);
        $this->assertSame(200, $response['status']);

        return $response['body'];
    }
}
