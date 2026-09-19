<?php

use Naf\Board\Services\NotificationService;
use Naf\Board\Services\PreferenceService;
use Naf\Core\Config;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\Transport\DummyTransport;
use Naf\Mail\Core\TransportInterface;
use Naf\Mail\Models\Mail;

test('staged attachment survives promotion failure and recovers once', function () use (
    $attachments,
    $a,
    $id,
    $upload,
    $store,
    $privateRoot,
) {
    $ready  = $privateRoot . '/ready';
    $parked = $privateRoot . '/ready-test-parked';
    rename($ready, $parked);
    file_put_contents($ready, 'temporary promotion fault');

    try {
        $attachments->upload($a, $id, $upload('Recover this staged attachment.'));
        $file = (int) scalar('SELECT MAX(id) FROM attachments');
        check(
            scalar('SELECT state FROM attachments WHERE id=?', [$file]) === 'staged',
            'failed promotion lost staged metadata',
        );
        denied(404, fn() => $attachments->download($a, $id, $file));
    } finally {
        unlink($ready);
        rename($parked, $ready);
    }
    $before = (int) scalar("SELECT COUNT(*) FROM activities WHERE event_type='attachment.added'");
    $attachments->finalize($file);
    $attachments->finalize($file);
    $record = $attachments->download($a, $id, $file);
    check(
        stream_get_contents($record['stream']) === 'Recover this staged attachment.',
        'recovery changed bytes',
    );
    fclose($record['stream']);
    check(
        (int) scalar("SELECT COUNT(*) FROM activities WHERE event_type='attachment.added'")
            === $before + 1,
        'recovery duplicated activity',
    );
    $attachments->remove($a, $id, $file);
});

test('mail failure is visible retryable deduplicated and permission checked', function () use (
    $c,
    $pdo,
    $projects,
    $tickets,
    $a,
    $data,
    $current,
    $auth,
    $users,
) {
    $original                             = $c->get(Config::class);
    $settings                             = $original->all();
    $settings['nafinity']['mail_enabled'] = true;
    $c->set(Config::class, new Config($settings));

    try {
        $projects->member($a, ['email' => 'member@example.test', 'role' => 'member']);
        $auth->setIdentity($users['member']);
        $c->make(PreferenceService::class)->save([
            'theme'         => 'system',
            'locale'        => 'de',
            'timezone'      => 'Europe/Berlin',
            'notify_in_app' => '1',
            'notify_mail'   => '1',
        ]);
        $auth->setIdentity($users['alice']);
        $tickets->create($a, $data + $current());
        $notification = (int) scalar('SELECT MAX(notification_id) FROM notification_deliveries');
        check($notification > 0, 'mail delivery was not recorded with the change');
        $service = $c->make(NotificationService::class);
        $failed  = new class implements TransportInterface {
            public function sendMail(Mail $mail): bool
            {
                throw new RuntimeException('Simulated transport outage');
            }
        };

        try {
            $service->deliver($notification, new Mailer($failed));
            throw new RuntimeException('Transport failure was swallowed');
        } catch (RuntimeException $exception) {
            check($exception->getMessage() === 'Simulated transport outage', 'unexpected failure');
        }
        check(
            scalar('SELECT state FROM notification_deliveries WHERE notification_id=?', [
                $notification,
            ]) === 'failed',
            'failure not visible',
        );
        $dummy  = new DummyTransport();
        $mailer = new Mailer($dummy);
        $service->deliver($notification, $mailer);
        $service->deliver($notification, $mailer);
        check(count($dummy->getMessages()) === 1, 'normal retry duplicated delivery');
        check(
            (int) scalar('SELECT attempts FROM notification_deliveries WHERE notification_id=?', [
                $notification,
            ]) === 2,
            'attempts incorrect',
        );
        $tickets->create($a, $data + $current());
        $revoked = (int) scalar('SELECT MAX(notification_id) FROM notification_deliveries');
        $projects->member($a, ['email' => 'member@example.test', 'role' => 'remove']);
        $service->deliver($revoked, $mailer);
        check(count($dummy->getMessages()) === 1, 'revoked recipient received mail');
        check(
            scalar('SELECT state FROM notification_deliveries WHERE notification_id=?', [
                $revoked,
            ]) === 'skipped',
            'revocation not recorded',
        );
    } finally {
        $c->set(Config::class, $original);
        $auth->setIdentity($users['alice']);
    }
});
