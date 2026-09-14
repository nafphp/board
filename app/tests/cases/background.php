<?php

use Naf\Queue\Drivers\PDODriver;
use Naf\Queue\Core\Queue;
use App\Support\AttachmentStorage;
use Nyholm\Psr7\{Stream,UploadedFile};

$auth->setIdentity($users['alice']);
$privateRoot = BASE_PATH.'/storage/test-attachments-'.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$store = new AttachmentStorage(new \Naf\Storage\Storage(new \Naf\Storage\Adapters\LocalAdapter($privateRoot)), $privateRoot);
$c->set(AttachmentStorage::class, $store);
$attachments = $c->make(\App\Services\AttachmentService::class);
$upload = fn (string $body, string $name = 'note.txt') => new UploadedFile(Stream::create($body), strlen($body), UPLOAD_ERR_OK, $name, 'application/octet-stream');
test('private attachment upload download and delete', function () use ($attachments, $a, $id, $upload, $auth, $users, $store) {
    $attachments->upload($a, $id, $upload('Private attachment test.'));
    $file = (int)scalar('SELECT MAX(id) FROM attachments');
    $record = $attachments->download($a, $id, $file);
    check(stream_get_contents($record['stream']) === 'Private attachment test.', 'wrong file bytes');
    fclose($record['stream']);
    $auth->setIdentity($users['bob']);
    denied(404, fn () => $attachments->download($a, $id, $file));
    denied(404, fn () => $attachments->remove($a, $id, $file));
    $auth->setIdentity($users['alice']);
    $attachments->remove($a, $id, $file);
    denied(404, fn () => $attachments->download($a, $id, $file));
    check((int)scalar('SELECT COUNT(*) FROM attachments') === 0, 'attachment metadata survived delete');
});
test('upload type size path and orphan boundaries', function () use ($store, $upload) {
    foreach ([fn () => $store->stage($upload('not a png', 'evil.png')),fn () => $store->stage($upload('<?php echo 1;', 'evil.php')),fn () => $store->stage($upload(str_repeat('a', 100)), 10),fn () => $store->open('../secret')] as $fn) {
        try {
            $fn();
            throw new RuntimeException('Invalid file accepted');
        } catch (InvalidArgumentException) {
        }
    }$file = $store->stage($upload('Orphan text.'));
    check($store->cleanup(fn () => true, time() + 1) === 0, 'referenced file removed');
    check($store->cleanup(fn () => false, time() + 1) === 1, 'orphan retained');
});
test('PDO queue rollback recovery lease fencing and deadletter', function () use ($pdo) {
    $pdo->exec('DELETE FROM naf_queue_jobs');
    $driver = new PDODriver($pdo, 30);
    $pdo->beginTransaction();
    $driver->enqueue('NeverRuns', []);
    $pdo->rollBack();
    check($driver->reserve() === null, 'enqueue escaped rollback');
    $driver->enqueue('TestJob', ['_job_id' => 'same']);
    $driver->enqueue('TestJob', ['_job_id' => 'same']);
    $one = $driver->reserve();
    check($driver->reserve() === null, 'concurrent claim');
    $pdo->exec('UPDATE naf_queue_jobs SET lease_until=0');
    $two = $driver->reserve();
    try {
        $driver->acknowledge($one);
        throw new RuntimeException('Stale worker ack succeeded');
    } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), 'lease was lost'), 'wrong stale error');
    }$driver->release($two, new RuntimeException('test failure'), 2, 0);
    check($driver->reserve() === null, 'deadletter executed');
    check($driver->retryFailed() === 1, 'failed job missing');
    $retry = $driver->reserve();
    check($retry['attempts'] === 1, 'retry counter retained');
    $driver->acknowledge($retry);
});
test('notification visibility rechecks membership and preferences', function () use ($auth, $users, $c, $projects, $a, $tickets, $data, $current) {
    $projects->member($a, ['email' => 'viewer@example.test','role' => 'viewer']);
    $tickets->create($a, $data + $current());
    $notifications = $c->make(\App\Services\NotificationService::class);
    $auth->setIdentity($users['viewer']);
    check(count($notifications->list()) > 0, 'no notification');
    $prefs = $c->make(\App\Services\PreferenceService::class);
    $prefs->mute($a, true);
    check($notifications->list() === [], 'muted project visible');
    $prefs->mute($a, false);
    $auth->setIdentity($users['alice']);
    $projects->member($a, ['email' => 'viewer@example.test','role' => 'remove']);
    $auth->setIdentity($users['viewer']);
    check($notifications->list() === [], 'revoked notifications visible');
    $auth->setIdentity($users['alice']);
});
