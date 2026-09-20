<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\AttachmentFixture;
use Naf\Board\Tests\Support\BoardTestCase;

/**
 * An upload is two things that cannot be made atomic with one another: bytes on
 * a disk and a row in a database. So it is staged first and promoted second, and
 * the interesting case is the one where the promotion fails.
 *
 * What must hold then: the record survives as staged rather than vanishing, it
 * is not served while it is in that state, and finishing the job later works and
 * works only once -- a recovery that ran twice must not report the attachment
 * twice in the ticket's history.
 */
final class AttachmentRecoveryTest extends BoardTestCase
{
    use AttachmentFixture;

    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
        $this->ticket = $this->tickets->create($this->projectA, $this->ticketData());
    }

    public function testAFailedPromotionKeepsTheRecordStagedAndUnreadable(): void
    {
        $file = $this->uploadWithBrokenPromotion('Recover this staged attachment.');

        $this->assertSame(
            'staged',
            $this->scalar('SELECT state FROM attachments WHERE id=?', [$file]),
            'a failed promotion lost the staged record',
        );
        $this->assertDenied(
            404,
            fn() => $this->attachments->download($this->projectA, $this->ticket, $file),
            'a staged attachment was served',
        );
    }

    public function testFinishingTheJobLaterRecoversTheBytesAndRecordsItOnce(): void
    {
        $file   = $this->uploadWithBrokenPromotion('Recover this staged attachment.');
        $before = (int) $this->scalar(
            "SELECT COUNT(*) FROM activities WHERE event_type='attachment.added'",
        );

        // Twice on purpose: a recovery run is not a thing anyone counts.
        $this->attachments->finalize($file);
        $this->attachments->finalize($file);

        $this->assertSame(
            'Recover this staged attachment.',
            $this->readDownload($this->attachments->download($this->projectA, $this->ticket, $file)),
            'the recovery changed the bytes',
        );
        $this->assertSame(
            $before + 1,
            (int) $this->scalar("SELECT COUNT(*) FROM activities WHERE event_type='attachment.added'"),
            'the second recovery run recorded the attachment a second time',
        );

        $this->attachments->remove($this->projectA, $this->ticket, $file);
    }

    /**
     * Upload while promotion cannot succeed.
     *
     * The store promotes into a directory named `ready`; a plain file in its
     * place makes that fail the way a full or unwritable disk would, without
     * needing either. It is put back in every case, including a failing test.
     */
    private function uploadWithBrokenPromotion(string $body): int
    {
        $ready  = $this->privateRoot . '/ready';
        $parked = $this->privateRoot . '/ready-test-parked';
        // The store creates its directories when it first needs them, so on a
        // cleared root there may be nothing here yet to put aside.
        $existing = is_dir($ready) && rename($ready, $parked);
        file_put_contents($ready, 'temporary promotion fault');

        try {
            $this->attachments->upload($this->projectA, $this->ticket, $this->upload($body));

            return (int) $this->scalar('SELECT MAX(id) FROM attachments');
        } finally {
            unlink($ready);
            if ($existing) {
                rename($parked, $ready);
            }
        }
    }
}
