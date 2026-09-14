<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AttachmentService;
use Naf\CLI\Core\Output;
use Naf\Queue\Core\QueueJobInterface;

final class FinalizeAttachmentJob implements QueueJobInterface
{
    public function __construct(private int $attachmentId, private AttachmentService $attachments)
    {
    }
    public function execute(Output $output): void
    {
        $this->attachments->finalize($this->attachmentId);
    }
}
