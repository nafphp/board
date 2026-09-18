<?php

declare(strict_types=1);

namespace Example\ExtensionA\Support;

use App\Domain\Change;
use Example\ExtensionA\Jobs\ReviewNoticeJob;
use Naf\Queue\Core\Queue;

/**
 * Reacts to a ticket whose review state changed.
 *
 * The change event runs inside the domain transaction, which is what makes this
 * reliable: the job is enqueued in the same transaction as the change itself, so
 * a rolled-back change leaves no job behind and a committed one always has its
 * job. Nothing external happens here — no mail, no webhook, no HTTP call. That
 * is the job's work, and the job only exists once the change is real.
 */
final class ReviewListener
{
    public function __construct(private Queue $queue)
    {
    }

    public function record(Change $change): void
    {
        $keys = $change->data['metadata'] ?? [];

        if (!is_array($keys) || !in_array('example.reviewed', $keys, true)) {
            return;
        }

        $this->queue->push(ReviewNoticeJob::class, [
            'projectId' => $change->projectId,
            'ticketId'  => $change->ticketId,
        ]);
    }
}
