<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Private ticket uploads, their quotas and their deferred finalization.
 */
interface AttachmentServiceInterface
{
    public function upload(int $project, int $ticket, UploadedFileInterface $upload): void;

    public function remove(int $project, int $ticket, int $id): void;

    public function download(int $project, int $ticket, int $id): array;

    public function finalize(int $id): void;

    public function cleanup(): int;
}
