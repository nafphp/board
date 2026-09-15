<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AccountService;
use App\Services\AttachmentService;
use Naf\CLI\Core\Output;
use Naf\RateLimit\PdoLimiter;
use Naf\Schedule\Core\ScheduledJobInterface;

final class MaintenanceJob implements ScheduledJobInterface
{
    public function __construct(
        private AttachmentService $attachments,
        private PdoLimiter $limiter,
        private AccountService $accounts,
    ) {
    }

    public function getCronExpression(): string
    {
        return '* * * * *';
    }

    public function execute(Output $output): void
    {
        $this->attachments->cleanup();
        $this->limiter->cleanup();
        $this->accounts->cleanup();
        file_put_contents(
            BASE_PATH . '/storage/queue/maintenance-heartbeat',
            (string) time(),
            LOCK_EX,
        );
    }
}
