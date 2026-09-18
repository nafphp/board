<?php

declare(strict_types=1);

namespace App\Jobs;

use Naf\CLI\Core\Output;
use Naf\Queue\Core\QueueJobInterface;
use Nafinity\Contracts\AttachmentServiceInterface;

final class FinalizeAttachmentJob implements QueueJobInterface
{
    public function __construct(
        private int $attachmentId,
        private AttachmentServiceInterface $attachments,
    ) {
    }

    public function execute(Output $output): void
    {
        $this->attachments->finalize($this->attachmentId);
    }
}
