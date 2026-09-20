<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * The installation's own history, and who may read it.
 *
 * A log that records who changed a role must not be readable by everyone whose
 * role was changed, so the page is behind a right of its own rather than behind
 * "can see a board". What it shows is deliberately not filtered by membership:
 * an installation-wide log that only showed your boards would answer a different
 * question than the one it is opened with.
 */
final class AuditOverHttpTest extends AcceptanceTestCase
{
    public function testSomebodyWithTheRightSeesTheWholeInstallation(): void
    {
        $page = $this->alice->request('/audit');

        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('audit-entry', $page['body'], 'nothing was recorded to show');
    }

    public function testSomebodyWithoutItIsRefused(): void
    {
        $this->assertSame(403, $this->viewer->request('/audit')['status']);
    }

    /** The filters narrow it; an unknown selection narrows it to nothing, not to everything. */
    public function testAScopeThatRecordedNothingShowsNothing(): void
    {
        $page = $this->alice->request('/audit?scope=' . rawurlencode('project:99999'));

        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('audit-entry', $page['body']);
    }

    /**
     * The search asks four places, because those are the four a person
     * remembers something from: who, which board, which ticket, and what the
     * entry said.
     */
    public function testTheSearchFindsWhatSomebodyWouldRemember(): void
    {
        $this->assertGreaterThan(0, $this->found('Alice'), 'a person nobody found');
        $this->assertSame(0, $this->found('gibtesnichtimprotokoll'));
    }

    /**
     * The payload holds `description`; the page shows "Beschreibung". Somebody
     * searching types what the page showed them, so both have to find the same
     * entries -- otherwise the search box means something other than it says.
     */
    public function testAWordFromTheScreenFindsWhatIsStoredUnderAnotherName(): void
    {
        // Made rather than hoped for: this used to lean on an entry that
        // happened to be in the database from something else, and a fresh seed
        // had none.
        $this->changeATitle();

        $shown  = $this->found('Titel');
        $stored = $this->found('title');

        $this->assertGreaterThan(0, $stored, 'the edit recorded nothing');
        $this->assertSame($stored, $shown);
    }

    /** One edit, through the application, so the log has something to find. */
    private function changeATitle(): void
    {
        $page = $this->alice->request('/projects/' . self::PROJECT . '/tickets/NAF-1')['body'];
        preg_match('/name="version" value="(\d+)"/', $page, $version);
        preg_match('/name="board_revision" value="(\d+)"/', $page, $revision);

        $response = $this->alice->request('/projects/' . self::PROJECT . '/tickets/NAF-1', [
            '_csrf'          => $this->alice->token($page),
            'version'        => $version[1] ?? '',
            'board_revision' => $revision[1] ?? '',
            'title'          => 'Umbenannt für das Protokoll ' . bin2hex(random_bytes(3)),
        ]);

        $this->assertContains($response['status'], [200, 303], 'the edit was refused');
    }

    /** A percent sign is a character somebody typed, not a pattern meaning "everything". */
    public function testAWildcardIsTakenLiterally(): void
    {
        $this->assertSame(0, $this->found('%'), 'a search for % matched the whole log');
    }

    public function testFilteringByPersonKeepsOnlyTheirs(): void
    {
        $everything = $this->alice->request('/audit')['body'];
        $this->assertStringContainsString('audit-entry', $everything);

        $mine = $this->alice->request('/audit?actor=99999')['body'];
        $this->assertStringNotContainsString('audit-entry', $mine, 'an actor nobody is matched something');
    }

    private function found(string $term): int
    {
        $page = $this->alice->request('/audit?q=' . rawurlencode($term));
        $this->assertSame(200, $page['status']);

        return substr_count($page['body'], 'audit-entry');
    }
}
