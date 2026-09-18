<?php

declare(strict_types=1);

namespace App\Jobs;

use Naf\CLI\Core\Output;
use Naf\Mail\Core\Mailer;
use Naf\Queue\Core\QueueJobInterface;
use Nafinity\Contracts\NotificationServiceInterface;

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
