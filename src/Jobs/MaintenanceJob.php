<?php

declare(strict_types=1);

namespace Naf\Board\Jobs;

use Naf\Board\Contracts\AccountServiceInterface;
use Naf\Board\Contracts\AttachmentServiceInterface;
use Naf\Board\Contracts\RememberStoreInterface;
use Naf\Board\Contracts\SettingsServiceInterface;
use Naf\Board\Services\AuditLog;
use Naf\Board\Support\SettingsContext;
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
        private RememberStoreInterface $remembered,
        private AuditLog $audit,
        private SettingsServiceInterface $settings,
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

        // A remembered sign-in that has expired does not work any more; it is
        // only taking up room. Nothing is decided here, which is why it needs no
        // setting: over is over.
        $this->remembered->prune();

        // The log is different. How long a history is kept is a policy somebody
        // answers for, so the installation states it and zero means forever.
        $this->audit->prune(
            (int) $this->settings->get(SettingsContext::application(), 'audit_retention_days', 0),
        );
        file_put_contents(
            BASE_PATH . '/storage/queue/maintenance-heartbeat',
            (string) time(),
            LOCK_EX,
        );
    }
}
