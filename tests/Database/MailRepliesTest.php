<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Auth\Auth;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\CommentServiceInterface;
use Naf\Board\Contracts\ProjectServiceInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Mail\Importer;
use Naf\Board\Mail\Inbox;
use Naf\Board\Mail\IncomingMail;
use Naf\Board\Mail\Jobs\ConfirmationJob;
use Naf\Board\Mail\Poller;
use Naf\Board\Mail\Settings;
use Naf\Board\Models\User;
use Naf\Board\Rbac\Grants;
use Naf\Board\Support\Resolver;
use Naf\Board\Tests\Support\TestDatabase;
use Naf\CLI\Core\Output;
use Naf\Mail\Core\Mailer;
use Naf\Mail\Core\TransportInterface;
use Naf\Mail\Models\Mail;
use Naf\ORM\Core\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Naf\app;
use function Naf\Rbac\rbac;

final class MailRepliesTest extends TestCase
{
    private PDO $pdo;
    private Auth $auth;
    private TicketServiceInterface $tickets;
    private ProjectServiceInterface $projects;
    private Importer $importer;
    private Settings $settings;
    private User $owner;
    private int $project;
    private int $ticket;
    private int $bot;
    private string $reference;

    public static function setUpBeforeClass(): void
    {
        TestDatabase::migrate();
    }

    protected function setUp(): void
    {
        $container = app()->container();
        $this->pdo = $container->get(PDO::class);
        $this->pdo->beginTransaction();
        $this->auth     = $container->get(Auth::class);
        $this->tickets  = $container->get(TicketServiceInterface::class);
        $this->projects = $container->get(ProjectServiceInterface::class);
        $this->settings = new Settings([
            'enabled' => true, 'host' => 'imap.example.test', 'username' => 'support', 'password' => 'test-only',
            'actor'   => 'bot@example.test', 'from' => 'bot@example.test', 'reply_to' => 'support@example.test',
        ]);
        $container->set(Settings::class, $this->settings);
        $this->importer = Resolver::service($container, Importer::class);
        $this->owner    = $this->user('owner');
        $this->bot      = (int) $this->user('bot')->getId();
        $this->auth->setIdentity($this->owner);
        $this->project = $this->projects->create(['name' => 'Mail project', 'description' => 'Mail test project']);
        $this->projects->member($this->project, ['email' => 'bot@example.test', 'role' => 'member']);
        $this->ticket = $this->tickets->create($this->project, [
            'title'          => 'Private internal title',
            'description'    => 'Private description',
            'priority'       => 'normal',
            'column_id'      => $this->scalar('SELECT id FROM board_columns WHERE project_id=? ORDER BY position LIMIT 1', [$this->project]),
            'swimlane_id'    => $this->scalar('SELECT id FROM swimlanes WHERE project_id=? ORDER BY is_default DESC LIMIT 1', [$this->project]),
            'board_revision' => $this->tickets->board($this->project)['revision'],
            'metadata'       => ['board_mail.requester' => 'requester@example.test'],
        ]);
        $this->reference = $this->scalar('SELECT reference FROM board_mail_cases WHERE ticket_id=?', [$this->ticket]);
        $this->auth->reset();
    }

    protected function tearDown(): void
    {
        $this->auth->reset();
        app()->container()->set(Settings::class, new Settings());
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        rbac()->forget();
    }

    public function testFollowUpMailAddsACommentExactlyOnce(): void
    {
        self::assertMatchesRegularExpression('/^V-[A-F0-9]{24}$/', $this->reference);
        self::assertSame('imported', $this->importer->import($this->mail()));
        self::assertSame('duplicate', $this->importer->import($this->mail()));
        self::assertSame('duplicate', $this->importer->import($this->mail(uid: 'copied-uid')));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM comments WHERE ticket_id=?', [$this->ticket]));
        self::assertSame($this->bot, (int) $this->scalar('SELECT author_id FROM comments WHERE ticket_id=?', [$this->ticket]));
        self::assertSame("E-Mail von requester@example.test\n\nWeitere Angaben zum Anliegen.", $this->scalar('SELECT body FROM comments WHERE ticket_id=?', [$this->ticket]));
        self::assertFalse($this->auth->check());
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tickets'));
    }

    public function testUnknownWrongSenderAutomaticAndAmbiguousMailsStayUnprocessed(): void
    {
        foreach ([
            $this->mail(sender: 'stranger@example.test'),
            $this->mail(reference: 'V-AAAAAAAAAAAAAAAAAAAAAAAA'),
            $this->mail(reference: 'ULX-15'),
            $this->mail(automatic: true),
            $this->mail(body: ''),
            $this->mail(body: str_repeat('x', 20001)),
            $this->mail(body: 'Vorgangsnummer: V-AAAAAAAAAAAAAAAAAAAAAAAA'),
        ] as $mail) {
            self::assertSame('ignored', $this->importer->import($mail));
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM comments'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM board_mail_receipts'));
    }

    public function testInactiveProjectMembershipCannotImport(): void
    {
        $this->pdo->prepare('UPDATE project_members SET active=0 WHERE project_id=? AND user_id=?')->execute([$this->project, $this->bot]);

        try {
            $this->importer->import($this->mail());
            self::fail('Expected project authorization failure.');
        } catch (Failure $failure) {
            self::assertSame(404, $failure->getCode());
        }
        self::assertFalse($this->auth->check());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM comments'));
    }

    public function testChangingTheRequesterRevokesTheOldReference(): void
    {
        $this->auth->setIdentity($this->owner);
        $row = $this->tickets->ticket($this->project, $this->ticket);
        $this->tickets->update($this->project, $this->ticket, [
            'version'  => $row['version'],
            'metadata' => ['board_mail.requester' => 'replacement@example.test'],
        ]);
        $newReference = $this->scalar('SELECT reference FROM board_mail_cases WHERE ticket_id=?', [$this->ticket]);
        $this->auth->reset();
        self::assertNotSame($this->reference, $newReference);
        self::assertSame('ignored', $this->importer->import($this->mail()));
        self::assertSame('imported', $this->importer->import($this->mail(sender: 'replacement@example.test', reference: $newReference)));
    }

    public function testChangingRequesterWhileDisabledRevokesTheOldReference(): void
    {
        app()->container()->set(Settings::class, new Settings());
        $this->auth->setIdentity($this->owner);
        $this->tickets->update($this->project, $this->ticket, [
            'version'  => $this->tickets->ticket($this->project, $this->ticket)['version'],
            'metadata' => ['board_mail.requester' => 'replacement@example.test'],
        ]);
        $this->auth->reset();
        self::assertSame('ignored', $this->importer->import($this->mail()));
        self::assertNull($this->scalar('SELECT recipient FROM board_mail_cases WHERE ticket_id=?', [$this->ticket]));
    }

    public function testConfirmationUsesOnlyThePublicReference(): void
    {
        $transport = new class implements TransportInterface {
            public array $sent = [];

            public function sendMail(Mail $mail): bool
            {
                $this->sent[] = $mail;

                return true;
            }
        };
        $job = new ConfirmationJob($this->ticket, $this->reference, $this->pdo, $this->settings, new Mailer($transport));
        $job->execute(new Output());
        $job->execute(new Output());
        self::assertCount(1, $transport->sent);
        $mail = $transport->sent[0];
        self::assertSame('Vorgangsnummer: ' . $this->reference, $mail->getSubject());
        self::assertSame('support@example.test', $mail->getReplyTo());
        self::assertStringNotContainsString('Private', $mail->getContent());
        self::assertSame(['requester@example.test'], $mail->getRecipients());
    }

    public function testCommentAndReceiptRollbackTogether(): void
    {
        $comments = app()->container()->get(CommentServiceInterface::class);
        $broken   = new class ($comments) implements CommentServiceInterface {
            public function __construct(private CommentServiceInterface $inner)
            {
            }

            public function save(int $project, int $ticket, array $data): void
            {
                $this->inner->save($project, $ticket, $data);
                throw new RuntimeException('Simulated failure before receipt');
            }
        };
        $importer = new Importer($this->pdo, $this->auth, app()->container()->get(AccessInterface::class), $this->tickets, $broken, $this->settings);

        try {
            $importer->import($this->mail());
            self::fail('Expected simulated failure.');
        } catch (RuntimeException $failure) {
            self::assertSame('Simulated failure before receipt', $failure->getMessage());
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM comments'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM board_mail_receipts'));
        self::assertSame('imported', $this->importer->import($this->mail()));
    }

    public function testTransferredCaseUsesTheCurrentProjectAndDeletionCleansUp(): void
    {
        $this->auth->setIdentity($this->owner);
        $destination = $this->projects->create(['name' => 'Other project', 'description' => 'Other tenant']);
        $this->projects->member($destination, ['email' => 'bot@example.test', 'role' => 'member']);
        $this->tickets->transfer($this->project, $this->ticket, [
            'project_id' => $destination,
            'version'    => $this->tickets->ticket($this->project, $this->ticket)['version'],
        ]);
        $this->auth->reset();
        self::assertSame('imported', $this->importer->import($this->mail()));
        self::assertSame($destination, (int) $this->scalar('SELECT project_id FROM comments WHERE ticket_id=?', [$this->ticket]));
        self::assertSame($this->reference, $this->scalar('SELECT reference FROM board_mail_cases WHERE ticket_id=?', [$this->ticket]));

        $this->auth->setIdentity($this->owner);
        $this->tickets->delete($destination, $this->ticket, [
            'version' => $this->tickets->ticket($destination, $this->ticket)['version'],
        ]);
        $this->auth->reset();
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM board_mail_cases'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM board_mail_receipts'));
        self::assertSame('ignored', $this->importer->import($this->mail()));
    }

    public function testPollingPassesJunkAndRecoversAfterFlagFailureAndUidValidityChange(): void
    {
        $inbox = new class ($this->mail()) implements Inbox {
            public int $validity  = 1;
            public bool $failSeen = true;
            public array $seen    = [];

            public function __construct(private IncomingMail $valid)
            {
            }

            public function open(): void
            {
            }

            public function uidValidity(): int
            {
                return $this->validity;
            }

            public function uidsAfter(int $uid): array
            {
                return array_slice(array_values(array_filter(range(1, 51), static fn(int $id) => $id > $uid)), 0, 50);
            }

            public function read(int $uid): ?IncomingMail
            {
                return $uid === 51 ? $this->valid : null;
            }

            public function markSeen(int $uid): void
            {
                if ($this->failSeen) {
                    $this->failSeen = false;
                    throw new RuntimeException('IMAP flag failed');
                }
                $this->seen[] = $uid;
            }

            public function close(): void
            {
            }
        };
        $poller = new Poller($this->pdo, $inbox, $this->importer, $this->settings);
        self::assertSame(50, $poller->poll()['ignored']);

        try {
            $poller->poll();
            self::fail('Expected flag failure.');
        } catch (RuntimeException $failure) {
            self::assertSame('IMAP flag failed', $failure->getMessage());
        }
        self::assertSame(1, $poller->poll()['duplicate']);
        self::assertSame([51], $inbox->seen);
        $inbox->validity = 2;
        self::assertSame(50, $poller->poll()['ignored']);
        self::assertSame(1, $poller->poll()['duplicate']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM comments'));
    }

    private function user(string $name): User
    {
        $user = new User(['name' => $name, 'email' => $name . '@example.test', 'created_at' => gmdate('Y-m-d H:i:s')]);
        app()->container()->get(EntityManager::class)->save($user);
        Grants::ensureDefault((int) $user->getId());

        return $user;
    }

    private function scalar(string $sql, array $parameters = []): mixed
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($parameters);

        return $query->fetchColumn();
    }

    private function mail(
        string $sender = 'requester@example.test',
        ?string $reference = null,
        string $uid = 'uid:51',
        string $body = 'Weitere Angaben zum Anliegen.',
        bool $automatic = false,
    ): IncomingMail {
        return new IncomingMail(hash('sha256', $uid), [$sender], 'Vorgangsnummer: ' . ($reference ?? $this->reference), $body, 'reply@example.test', $automatic);
    }
}
