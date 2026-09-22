<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;

/**
 * Getting a board out, and what a plugin may change on the way.
 *
 * The formats come from a registry and the rows from one service, which is what
 * these hold: that both formats describe the same board, that the format id in
 * the URL is looked up rather than branched on, and that somebody without the
 * permission is not handed the project in a file after being kept out of it on
 * the page.
 */
final class ExportOverHttpTest extends AcceptanceTestCase
{
    public function testTheSpreadsheetOpensWithItsHeadingsAndTheProjectsTickets(): void
    {
        $answer = $this->export($this->alice, 'csv');

        $this->assertSame(200, $answer['status']);
        $this->assertStringContainsString('text/csv', $answer['headers']);
        $this->assertStringContainsString(
            'attachment; filename="',
            $answer['headers'],
            'the export was served to be read rather than saved',
        );

        // The byte order mark, without which a German title reaches a
        // spreadsheet as mojibake.
        $this->assertStringStartsWith("\u{FEFF}", $answer['body']);

        $lines = explode("\r\n", trim($answer['body']));
        $this->assertGreaterThan(1, count($lines), 'the seeded board exported no tickets');
        $this->assertStringContainsString('"Ticket"', $lines[0]);
        $this->assertStringContainsString('"Titel"', $lines[0]);
        $this->assertStringContainsString('NAF-', $answer['body'], 'no ticket key in the export');
    }

    /** The same board, and the same count, said in the other format. */
    public function testBothFormatsDescribeTheSameBoard(): void
    {
        $rows = json_decode($this->export($this->alice, 'json')['body'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        $this->assertSame(
            count(explode("\r\n", trim($this->export($this->alice, 'csv')['body']))) - 1,
            count($rows),
            'the two formats disagree about how many tickets this board has',
        );

        // A type that a spreadsheet cell cannot keep, which is the reason this
        // format is offered beside it.
        $this->assertIsArray($rows[0]['labels']);
        $this->assertArrayHasKey('key', $rows[0]);
        $this->assertArrayHasKey('status', $rows[0]);
    }

    /**
     * A format nobody registered is a 404, not a branch that fell through.
     *
     * The id reaches the service as text from a URL, so the interesting answer
     * is the one for an id that looks like an attempt at something else.
     */
    public function testAnUnknownFormatIsNotServed(): void
    {
        foreach (['xlsx', 'csv2', 'txt'] as $format) {
            $this->assertSame(
                404,
                $this->export($this->alice, $format)['status'],
                'the export served an unregistered format: ' . $format,
            );
        }
    }

    /** Reading a board and taking it away are two permissions, and this is the second. */
    public function testSomebodyWithoutThePermissionIsNotGivenTheBoardInAFile(): void
    {
        $answer = $this->export($this->viewer, 'csv');

        $this->assertNotSame(200, $answer['status'], 'a viewer exported the whole board');
        $this->assertStringNotContainsString('NAF-', $answer['body']);
    }

    public function testAStrangerIsNotToldTheProjectExists(): void
    {
        $this->assertSame(404, $this->export($this->bob, 'csv')['status']);
    }

    /**
     * The menu is the registry, so what is offered is what answers.
     *
     * Held here rather than trusted, because the two used to be the same kind of
     * thing that drifts: a format hard-coded into a template is offered until
     * somebody remembers to remove it, and a format added to a service is
     * reachable long before anybody can click it.
     */
    public function testTheBoardOffersExactlyTheFormatsThatAnswer(): void
    {
        $board = $this->page($this->alice, '/projects/' . self::PROJECT . '/settings');
        $this->assertStringContainsString('data-settings-open="project_export"', $board);
        $this->assertStringNotContainsString('export-menu', $this->page($this->alice, '/projects/' . self::PROJECT));

        foreach (['csv', 'json'] as $format) {
            $link = 'value="' . $format . '"';

            $this->assertStringContainsString($link, $board, 'the board does not offer ' . $format);
            $this->assertSame(
                200,
                $this->export($this->alice, $format)['status'],
                'the board offers a format that does not answer: ' . $format,
            );
        }
    }

    /** No menu at all for somebody it would only answer 403 to. */
    public function testSomebodyWhoMayNotExportIsNotOfferedIt(): void
    {
        $this->assertStringNotContainsString(
            'data-settings-open="project_export"',
            $this->page($this->viewer, '/projects/' . self::PROJECT . '/settings'),
            'a viewer was offered downloads that would all be refused',
        );
    }

    public function testInstallationExportsOneOrAllBoardsWithTheirIdentity(): void
    {
        $settings = $this->page($this->alice, '/settings');
        $this->assertStringContainsString('data-settings-open="installation_export"', $settings);
        $this->assertStringContainsString('value="all"', $settings);
        $this->assertStringContainsString('action="/settings/export"', $settings);
        $all = $this->alice->request('/settings/export?project=all&format=json&archive=exclude');
        $this->assertSame(200, $all['status']);
        $rows = json_decode($all['body'], true, flags: JSON_THROW_ON_ERROR);
        $ids  = array_unique(array_column($rows, 'project_id'));
        $this->assertContains(self::PROJECT, $ids);
        $this->assertContains(self::OTHER_PROJECT, $ids);
        $this->assertNotEmpty($rows[0]['project']);

        $one = $this->alice->request('/settings/export?project=' . self::OTHER_PROJECT . '&format=json');
        $this->assertSame(200, $one['status']);
        $this->assertSame([self::OTHER_PROJECT], array_values(array_unique(array_column(json_decode($one['body'], true), 'project_id'))));
        $csv = $this->alice->request('/settings/export?project=all&format=csv&description=0&archive=exclude');
        $this->assertSame(200, $csv['status']);
        $this->assertSame(count($rows), count(explode("\r\n", trim($csv['body']))) - 1);
    }

    public function testBoardSettingsCannotBeBroadenedByTheQueryString(): void
    {
        $answer = $this->alice->request('/projects/' . self::PROJECT . '/export?format=json&project=all&description=1');
        $this->assertSame(200, $answer['status']);
        $rows = json_decode($answer['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertStringStartsWith('NAF-', $row['key']);
            $this->assertArrayHasKey('description', $row);
        }
    }

    public function testInstallationExportsRequireInstallationPermission(): void
    {
        foreach ([$this->bob, $this->viewer] as $client) {
            $this->assertSame(403, $client->request('/settings/export?project=all&format=json')['status']);
        }
        $this->assertSame(403, $this->viewer->request('/projects/' . self::PROJECT . '/export?format=json')['status']);
        $this->assertSame(404, $this->bob->request('/projects/' . self::PROJECT . '/export?format=json')['status']);
    }

    public function testMalformedOptionsDoNotDownloadFiles(): void
    {
        foreach (['format[]=csv', 'format=json&status=anything', 'format=json&archive[]=all',
            'format=json&updated_from=2026-02-30', 'format=json&updated_from=2026-09-22&updated_until=2026-09-21',
            'format=json&metadata=perhaps', 'format=json&description[]=1'] as $query) {
            $answer = $this->alice->request('/projects/' . self::PROJECT . '/export?' . $query);
            $this->assertSame(422, $answer['status'], $query);
            $this->assertStringNotContainsString('attachment; filename=', $answer['headers']);
        }
        $this->assertSame(422, $this->alice->request('/settings/export?project[]=all&format=json')['status']);
        $this->assertSame(404, $this->alice->request('/settings/export?project=all&format=missing')['status']);
    }

    /**
     * @return array{status:int, body:string, headers:string}
     */
    private function export(HttpClient $client, string $format): array
    {
        return $client->request('/projects/' . self::PROJECT . '/export/' . $format);
    }
}
