<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use InvalidArgumentException;
use Naf\Board\Tests\Support\AttachmentFixture;
use Naf\Board\Tests\Support\BoardTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Attachments are served by the application, never by the web server.
 *
 * They live outside the document root, so reaching one means passing the same
 * membership check as the ticket it hangs on. A stranger gets 404 rather than
 * 403, for the same reason a foreign project does.
 */
final class AttachmentTest extends BoardTestCase
{
    use AttachmentFixture;

    private int $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
        $this->ticket = $this->tickets->create($this->projectA, $this->ticketData());
    }

    public function testAnUploadedFileComesBackByteForByte(): void
    {
        $this->attachments->upload($this->projectA, $this->ticket, $this->upload('Private attachment test.'));
        $file = (int) $this->scalar('SELECT MAX(id) FROM attachments');

        $bytes = $this->readDownload($this->attachments->download($this->projectA, $this->ticket, $file));

        $this->assertSame('Private attachment test.', $bytes);

        $this->attachments->remove($this->projectA, $this->ticket, $file);
    }

    public function testANonMemberCanNeitherDownloadNorDeleteIt(): void
    {
        $this->attachments->upload($this->projectA, $this->ticket, $this->upload('Private attachment test.'));
        $file = (int) $this->scalar('SELECT MAX(id) FROM attachments');

        $this->actAs($this->bob);

        $this->assertDenied(404, fn() => $this->attachments->download($this->projectA, $this->ticket, $file));
        $this->assertDenied(404, fn() => $this->attachments->remove($this->projectA, $this->ticket, $file));

        $this->actAs($this->alice);
        $this->attachments->remove($this->projectA, $this->ticket, $file);
    }

    public function testDeletingTakesBothTheFileAndItsRecord(): void
    {
        $this->attachments->upload($this->projectA, $this->ticket, $this->upload('Private attachment test.'));
        $file = (int) $this->scalar('SELECT MAX(id) FROM attachments');

        $this->attachments->remove($this->projectA, $this->ticket, $file);

        $this->assertDenied(404, fn() => $this->attachments->download($this->projectA, $this->ticket, $file));
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM attachments'),
            'the record outlived the file',
        );
    }

    /**
     * What the store refuses before anything reaches disk. The first two are the
     * same trick twice: a name that promises one thing and content that is
     * another. The last one does not name a file in the store at all.
     *
     * @return array<string,array{string,string,int|null}>
     */
    public static function rejectedUploads(): array
    {
        return [
            'content is not the image it claims' => ['not a png', 'evil.png', null],
            'executable content'                 => ['<?php echo 1;', 'evil.php', null],
            'larger than the limit'              => [null, 'note.txt', 10],
        ];
    }

    #[DataProvider('rejectedUploads')]
    public function testTheStoreRefusesAFileItShouldNotHold(?string $body, string $name, ?int $limit): void
    {
        $file = $this->upload($body ?? str_repeat('a', 100), $name);

        $this->expectException(InvalidArgumentException::class);

        $limit === null ? $this->store->stage($file) : $this->store->stage($file, $limit);
    }

    public function testAPathThatClimbsOutOfTheStoreIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store->open('../secret');
    }

    /**
     * Sweeping orphans: a file whose record is gone has to go too, and a file
     * that is still referenced has to stay. The callback is how the store asks.
     */
    public function testTheSweepRemovesOnlyFilesNothingRefers(): void
    {
        $this->store->stage($this->upload('Orphan text.'));

        $this->assertSame(
            0,
            $this->store->cleanup(fn() => true, time() + 1),
            'a referenced file was swept away',
        );
        $this->assertSame(
            1,
            $this->store->cleanup(fn() => false, time() + 1),
            'an orphaned file was kept',
        );
    }
}
