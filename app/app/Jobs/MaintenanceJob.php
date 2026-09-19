<?php

declare(strict_types=1);

namespace Naf\Board\Jobs;

use Naf\Board\Contracts\AccountServiceInterface;
use Naf\Board\Contracts\AttachmentServiceInterface;
use Naf\CLI\Core\Output;
use Naf\RateLimit\PdoLimiter;
use Naf\Schedule\Core\ScheduledJobInterface;

/** @internal */
final class MaintenanceJob implements ScheduledJobInterface
{
    public function __construct(
        private AttachmentServiceInterface $attachments,
        private PdoLimiter $limiter,
        private AccountServiceInterface $accounts,
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
