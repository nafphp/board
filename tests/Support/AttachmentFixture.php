<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use FilesystemIterator;
use Naf\Board\Services\AttachmentService;
use Naf\Board\Support\AttachmentStorage;
use Naf\Storage\Adapters\LocalAdapter;
use Naf\Storage\Storage;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function Naf\app;

/**
 * Attachments on disk, in a directory of this suite's own.
 *
 * Named after the driver, because the MariaDB and PostgreSQL runs are two passes
 * over the same tests and would otherwise share one directory and clean up after
 * each other.
 */
trait AttachmentFixture
{
    protected AttachmentStorage $store;
    protected AttachmentService $attachments;
    protected string $privateRoot;

    protected function setUpAttachments(): void
    {
        $this->privateRoot = BASE_PATH . '/storage/test-attachments-'
            . $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        // Emptied first. Tests that stage a file deliberately leave it there --
        // that is what the orphan sweep is for -- and a directory shared with the
        // previous test would hand it that test's leftovers to count.
        $this->clearPrivateRoot();
        $this->store = new AttachmentStorage(
            new Storage(new LocalAdapter($this->privateRoot)),
            $this->privateRoot,
        );
        app()->container()->set(AttachmentStorage::class, $this->store);
        $this->attachments = app()->container()->make(AttachmentService::class);
    }

    private function clearPrivateRoot(): void
    {
        if (!is_dir($this->privateRoot)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->privateRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }

    protected function upload(string $body, string $name = 'note.txt'): UploadedFile
    {
        return new UploadedFile(
            Stream::create($body),
            strlen($body),
            UPLOAD_ERR_OK,
            $name,
            'application/octet-stream',
        );
    }

    /** The bytes behind a download, with the stream closed again. */
    protected function readDownload(array $record): string
    {
        $bytes = stream_get_contents($record['stream']);
        fclose($record['stream']);

        return (string) $bytes;
    }
}
