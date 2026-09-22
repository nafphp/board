<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Example\ExtensionA\Export\StatusForExternalSystem;
use Naf\Board\Export\ExportFinished;
use Naf\Board\Export\ExportOptions;
use Naf\Board\Export\ExportStarted;
use Naf\Board\Modules\Providers\ExportSectionProvider;
use Naf\Board\Services\ExportService;
use Naf\Board\Support\Resolver;
use Naf\Board\Support\UiContext;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\event;

/**
 * The case the export was built around, run by a package the board never heard of.
 *
 * A plugin keeps a state of its own on a ticket. An external system has two
 * words for a ticket and knows nothing about that state, so on the way out -- and
 * only into that one format -- a ticket in that state is reported as done.
 *
 * What is held here is the whole promise of the design. That a plugin can bring
 * a format by registering it. That it can change what an export says by
 * listening to one documented event. That the change reaches only the format it
 * was meant for, so the spreadsheet the team reads still tells the truth. And
 * that the stored ticket does not move -- because an export that edited the
 * board on its way past would be the worst possible way to find out.
 *
 * No transformer registry, no export hook manager, no second extension API.
 */
final class ExportContributionTest extends ExtensionInstalledTestCase
{
    private ExportService $exports;
    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        // Built rather than fetched: the service is not bound, and get() only
        // answers for what is. The controller reaches it the same way, through
        // the container's autowiring.
        $this->exports = Resolver::build(app()->container(), ExportService::class);
        $this->ticket  = $this->createTicket();
    }

    /** A format nobody in naf/board has heard of, offered beside the two that ship. */
    public function testAPackageCanBringItsOwnFormat(): void
    {
        $exporters = extensions()->exporters()->all();

        $this->assertArrayHasKey(StatusForExternalSystem::FORMAT, $exporters);
        $this->assertArrayHasKey('csv', $exporters, 'the contributed format displaced a built-in one');
        $this->assertArrayHasKey('json', $exporters);
    }

    /** The plugin's own field is a column, without the export having heard of it. */
    public function testAContributedTicketFieldBecomesAColumn(): void
    {
        $columns = $this->exports->columns($this->access->project($this->project));

        $this->assertArrayHasKey('example.external_id', $columns);
        $this->assertArrayHasKey('key', $columns, 'the ticket\'s own columns went missing');
    }

    /**
     * The transformation itself: reported as done to the far end, open on the board.
     */
    public function testAReviewedTicketIsReportedDoneAndStaysOpen(): void
    {
        $this->markReviewed();

        $this->assertStringContainsString(
            'status=done',
            $this->exported(StatusForExternalSystem::FORMAT),
            'the plugin did not get to say how its state is reported',
        );

        $this->assertSame(
            'open',
            $this->storedStatus(),
            'the export changed the ticket it was reading',
        );
    }

    /** And the spreadsheet the team reads is not touched by somebody else's mapping. */
    public function testTheOtherFormatsStillSayWhatTheBoardSays(): void
    {
        $this->markReviewed();

        foreach (['csv', 'json'] as $format) {
            $this->assertStringNotContainsString(
                'done',
                $this->exported($format),
                'a mapping meant for one format reached ' . $format,
            );
        }
    }

    /** A ticket the plugin has no opinion about is reported as it stands. */
    public function testATicketThePluginIsSilentAboutIsUnchanged(): void
    {
        $this->assertStringContainsString(
            'status=open',
            $this->exported(StatusForExternalSystem::FORMAT),
        );
    }

    /**
     * The frame around the records, and the count only the export knows.
     *
     * A package writing into somebody else's system opens something before the
     * first record and closes it after the last. Counting the lines itself to
     * report how many there were would mean listening to every one of them for
     * a number that was already to hand.
     */
    public function testAnExportAnnouncesItsBeginningAndItsEnd(): void
    {
        $seen = [];
        event()->listen(ExportStarted::class, static function (ExportStarted $run) use (&$seen): void {
            $seen['started'] = $run->exporter;
            $seen['columns'] = array_key_exists('example.external_id', $run->columns);
        });
        event()->listen(ExportFinished::class, static function (ExportFinished $run) use (&$seen): void {
            $seen['finished'] = $run->exporter;
            $seen['written']  = $run->written;
        });

        $this->exported('csv');

        $this->assertSame('csv', $seen['started'] ?? null, 'the export began without saying so');
        $this->assertSame('csv', $seen['finished'] ?? null, 'the export ended without saying so');
        $this->assertTrue($seen['columns'] ?? false, 'the frame did not carry the columns of this run');
        $this->assertGreaterThan(0, $seen['written'] ?? 0, 'the count came back empty');
    }

    /** The count is the records, not the lines: a heading is not a record. */
    public function testTheCountIsWhatWasExportedAndNotWhatWasWritten(): void
    {
        $written = null;
        event()->listen(
            ExportFinished::class,
            static function (ExportFinished $run) use (&$written): void {
                $written = $run->written;
            },
        );

        $rows = substr_count(trim($this->exported('csv')), "\r\n");

        $this->assertSame($rows, $written, 'the count disagrees with the file it counted');
    }

    public function testTheSettingsAndFilteredExportKeepContributedFormats(): void
    {
        $provider = Resolver::build(app()->container(), ExportSectionProvider::class);
        $section  = extensions()->settingSections()->get('project_export');
        $data     = $provider->data($section, new UiContext((int) $this->access->actor(), $this->access->project($this->project)), []);
        $this->assertArrayHasKey(StatusForExternalSystem::FORMAT, $data['exportFormats']);
        $this->markReviewed();
        $file = $this->exports->writeSelected([$this->project], StatusForExternalSystem::FORMAT, ExportOptions::fromInput([]), true);

        try {
            $this->assertStringContainsString('status=done', stream_get_contents($file['stream']));
        } finally {
            fclose($file['stream']);
        }
    }

    private function markReviewed(): void
    {
        $this->tickets->update($this->project, $this->ticket, [
            'metadata' => ['example.reviewed' => true],
            'version'  => $this->ticketVersion($this->ticket),
            ...$this->revision(),
        ]);
    }

    private function exported(string $format): string
    {
        $file = $this->exports->write($this->project, $format);

        return (string) stream_get_contents($file['stream']);
    }

    private function storedStatus(): string
    {
        $statement = $this->pdo->prepare('SELECT status FROM tickets WHERE project_id=? AND id=?');
        $statement->execute([$this->project, $this->ticket]);

        return (string) $statement->fetchColumn();
    }
}
