<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\NotificationService;
use Naf\Board\Services\PreferenceService;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\Core\Config;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\Transport\DummyTransport;
use Naf\Mail\Core\TransportInterface;
use Naf\Mail\Models\Mail;
use RuntimeException;

use function Naf\app;

/**
 * Sending mail is the one step that cannot be rolled back, so it is deliberately
 * not attempted inside the transaction that caused it. The ledger row is, which
 * makes delivery at-least-once and every failure visible rather than silent.
 *
 * Three things follow, and all three are here: an outage must leave the row
 * marked failed instead of swallowing it; a retry must not send a second copy of
 * something already sent; and whether the recipient may still receive it is
 * decided when it is sent, not when it was queued.
 *
 * Not transactional: the delivery opens a transaction of its own, and PDO has no
 * second one to give.
 */
final class MailDeliveryTest extends BoardTestCase
{
    protected bool $transactional = false;

    private NotificationService $notifications;
    private Config $originalConfig;
    private DummyTransport $transport;
    private Mailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        // Mail is off in a test installation, which is right everywhere except
        // in the tests about mail.
        $this->originalConfig                 = app()->container()->get(Config::class);
        $settings                             = $this->originalConfig->all();
        $settings['nafinity']['mail_enabled'] = true;
        app()->container()->set(Config::class, new Config($settings));

        $this->notifications = app()->container()->make(NotificationService::class);
        $this->transport     = new DummyTransport();
        $this->mailer        = new Mailer($this->transport);

        // A member who wants mail, so a change in project A produces one.
        $this->actAs($this->member);
        app()->container()->make(PreferenceService::class)->save([
            'theme'         => 'system',
            'locale'        => 'de',
            'timezone'      => 'Europe/Berlin',
            'notify_in_app' => '1',
            'notify_mail'   => '1',
        ]);
        $this->actAs($this->alice);
    }

    protected function tearDown(): void
    {
        app()->container()->set(Config::class, $this->originalConfig);
        parent::tearDown();
    }

    public function testAChangeRecordsAMailDeliveryAlongsideIt(): void
    {
        $this->tickets->create($this->projectA, $this->ticketData());

        $this->assertGreaterThan(
            0,
            (int) $this->scalar('SELECT MAX(notification_id) FROM notification_deliveries'),
            'the change went through without recording the mail it owes',
        );
    }

    public function testATransportOutageIsRaisedAndLeftVisibleInTheLedger(): void
    {
        $notification = $this->pendingDelivery();

        $this->assertSame(
            'Simulated transport outage',
            $this->failOnce($notification),
            'the transport failure was swallowed',
        );

        $this->assertSame(
            'failed',
            $this->scalar(
                'SELECT state FROM notification_deliveries WHERE notification_id=?',
                [$notification],
            ),
            'the failure left no trace to retry from',
        );
    }

    public function testARetryAfterAFailureDeliversOnceAndCountsBothAttempts(): void
    {
        $notification = $this->pendingDelivery();
        // The first attempt is the one that failed; without it there would be
        // nothing to retry, and a second delivery of something already sent is
        // simply a no-op rather than an attempt.
        $this->failOnce($notification);

        $this->notifications->deliver($notification, $this->mailer);
        $this->notifications->deliver($notification, $this->mailer);

        $this->assertCount(1, $this->transport->getMessages(), 'the retry sent a second copy');
        $this->assertSame(
            2,
            (int) $this->scalar(
                'SELECT attempts FROM notification_deliveries WHERE notification_id=?',
                [$notification],
            ),
            'the second attempt was not counted',
        );
    }

    public function testSomeoneWhoLeftTheProjectIsNotMailedAfterAll(): void
    {
        $notification = $this->pendingDelivery();

        $this->projects->member($this->projectA, ['email' => 'member@example.test', 'role' => 'remove']);
        $this->notifications->deliver($notification, $this->mailer);

        $this->assertSame([], $this->transport->getMessages(), 'a revoked recipient was mailed');
        $this->assertSame(
            'skipped',
            $this->scalar(
                'SELECT state FROM notification_deliveries WHERE notification_id=?',
                [$notification],
            ),
            'the skip was not recorded',
        );
    }

    /**
     * One delivery attempt against a transport that is down, and the message it
     * failed with. The ledger is left marked failed, ready to be retried.
     */
    private function failOnce(int $notification): string
    {
        $broken = new class implements TransportInterface {
            public function sendMail(Mail $mail): bool
            {
                throw new RuntimeException('Simulated transport outage');
            }
        };

        try {
            $this->notifications->deliver($notification, new Mailer($broken));
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return '';
    }

    /** A change, and the mail it owes the member who asked for one. */
    private function pendingDelivery(): int
    {
        $this->tickets->create($this->projectA, $this->ticketData());

        return (int) $this->scalar('SELECT MAX(notification_id) FROM notification_deliveries');
    }
}
