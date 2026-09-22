<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Domain\Change;
use Naf\Board\ExtensionContext;
use Naf\Board\Jobs\PublishBoardChangeJob;
use Naf\Queue\Core\Queue;

use function Naf\app;
use function Naf\event;
use function Naf\Websocket\live;

/**
 * Tell whoever is watching a board that it moved.
 *
 * Hooked onto the change the board already announces rather than onto the five
 * places that raise a revision: one of those would be forgotten, and a board
 * that updates itself four times out of five is worse than one that never does,
 * because nobody can say which case they are looking at.
 *
 * What goes out is the project and the new revision -- never what changed. A
 * listener compares that revision with its own and asks the application for the
 * new state through the path that already decides what it may see. So this can
 * be wrong about who is listening without being able to show them anything.
 *
 * @internal
 */
final class CoreLive implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        // Only when the package is installed and an installation switched it on.
        if (!function_exists('Naf\\Websocket\\live') || !live()) {
            return;
        }

        event()->listen(Change::class, static function (Change $change): void {
            // The board binds Queue to the same PDO connection as its writes.
            // A rolled-back change therefore never reaches a worker or a socket.
            app()->container()->get(Queue::class)->push(PublishBoardChangeJob::class, [
                'projectId' => $change->projectId,
                'type'      => $change->type,
            ]);
        });
    }
}
