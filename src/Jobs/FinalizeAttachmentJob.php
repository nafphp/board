<?php

declare(strict_types=1);

namespace Naf\Board\Jobs;

use Naf\Board\Contracts\AttachmentServiceInterface;
use Naf\CLI\Core\Output;
use Naf\Queue\Core\QueueJobInterface;

/** @internal */
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
