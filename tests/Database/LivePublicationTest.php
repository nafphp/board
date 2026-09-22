<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Jobs\PublishBoardChangeJob;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\CLI\Core\Output;
use Naf\Database\Core\Database;
use Naf\Queue\Drivers\PDODriver;
use Naf\Websocket\Publisher;

use function Naf\app;
use function Naf\config;

final class LivePublicationTest extends BoardTestCase
{
    protected bool $transactional = false;

    public function testAWorkerCanOnlyPublishCommittedChanges(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->pdo->exec('DELETE FROM naf_queue_jobs');
        $workerPdo = (new Database(config('database')))->getConnection();
        $path      = sys_get_temp_dir() . '/board-live-' . bin2hex(random_bytes(6)) . '.sock';
        $socket    = stream_socket_server('unix://' . $path, $code, $error);
        $this->assertIsResource($socket);
        $previous = app()->container()->get(Publisher::class);
        app()->container()->set(Publisher::class, new Publisher($path));

        try {
            $this->pdo->beginTransaction();
            $this->tickets->update($this->projectA, $ticket, ['title' => 'Committed', 'version' => 1] + $this->revision());
            $this->assertSame(0, (int) $workerPdo->query('SELECT COUNT(*) FROM naf_queue_jobs')->fetchColumn());
            $this->pdo->commit();
            $driver = new PDODriver($workerPdo, 30);
            $job    = $driver->reserve();
            $this->assertNotNull($job);
            $this->assertSame(PublishBoardChangeJob::class, $job['class']);
            app()->container()->make($job['class'], $job['payload'])->execute(new Output());
            $driver->acknowledge($job);
            $client = stream_socket_accept($socket, 1);
            $this->assertIsResource($client);
            $message = json_decode(fgets($client), true);
            fclose($client);
            $this->assertSame('project:' . $this->projectA, $message['channel']);
            $this->assertSame((string) $this->tickets->board($this->projectA)['revision'], $message['message']['revision']);
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            app()->container()->set(Publisher::class, $previous);
            fclose($socket);
            unlink($path);
        }
    }
}
