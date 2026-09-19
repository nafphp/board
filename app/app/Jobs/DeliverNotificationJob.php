<?php

declare(strict_types=1);

namespace Naf\Board\Jobs;

use Naf\Board\Contracts\NotificationServiceInterface;
use Naf\CLI\Core\Output;
use Naf\Mail\Core\Mailer;
use Naf\Queue\Core\QueueJobInterface;

/** @internal */
final class DeliverNotificationJob implements QueueJobInterface
{
    public function __construct(
        private int $notificationId,
        private NotificationServiceInterface $notifications,
        private Mailer $mailer,
    ) {
    }

    public function execute(Output $output): void
    {
        $this->notifications->deliver($this->notificationId, $this->mailer);
    }
}
