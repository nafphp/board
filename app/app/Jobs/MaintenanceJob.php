<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AttachmentService;
use Naf\CLI\Core\Output;
use Naf\Schedule\Core\ScheduledJobInterface;

final class MaintenanceJob implements ScheduledJobInterface
{
    public function __construct(private AttachmentService $attachments, private \Naf\RateLimit\PdoLimiter $limiter)
    {
    }
    public function getCronExpression(): string
    {
        return '* * * * *';
    }
    public function execute(Output $output): void
    {
        $this->attachments->cleanup();
        $this->limiter->cleanup();
        file_put_contents(BASE_PATH.'/storage/queue/maintenance-heartbeat', (string)time(), LOCK_EX);
    }
}
