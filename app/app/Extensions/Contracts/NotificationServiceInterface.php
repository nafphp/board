<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use App\Domain\Change;
use Naf\Mail\Core\Mailer;

/**
 * In-app and mail notifications derived from recorded changes.
 */
interface NotificationServiceInterface
{
    public function record(Change $change, int $activity): void;

    public function list(): array;

    public function markRead(): void;

    public function deliver(int $id, Mailer $mailer): void;
}
