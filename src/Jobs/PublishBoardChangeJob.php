<?php

declare(strict_types=1);

namespace Naf\Board\Jobs;

use Naf\CLI\Core\Output;
use Naf\Queue\Core\QueueJobInterface;
use PDO;

use function Naf\Websocket\live;
use function Naf\Websocket\publisher;

/** @internal */
final class PublishBoardChangeJob implements QueueJobInterface
{
    public function __construct(private int $projectId, private string $type, private PDO $pdo)
    {
    }

    public function execute(Output $output): void
    {
        if (!function_exists('Naf\\Websocket\\live') || !live()) {
            return;
        }
        $revision = $this->pdo->prepare('SELECT revision FROM boards WHERE project_id=?');
        $revision->execute([$this->projectId]);
        $now = $revision->fetchColumn();
        if ($now === false) {
            return;
        }
        // Read committed state at delivery time, including increments after Change.
        publisher()->publish('project:' . $this->projectId, [
            'revision' => (string) $now,
            'type'     => $this->type,
        ]);
    }
}
