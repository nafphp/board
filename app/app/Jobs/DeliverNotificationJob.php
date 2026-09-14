<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\NotificationService;
use Naf\Mail\Core\Mailer;
use Naf\CLI\Core\Output;
use Naf\Queue\Core\QueueJobInterface;

final class DeliverNotificationJob implements QueueJobInterface
{
    public function __construct(private int $notificationId, private NotificationService $notifications, private Mailer $mailer)
    {
    }
    public function execute(Output $output): void
    {
        $this->notifications->deliver($this->notificationId, $this->mailer);
    }
}
