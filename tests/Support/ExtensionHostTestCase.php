<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Naf\Auth\Auth;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\TicketMetadataReaderInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Models\User;
use PDO;
use PHPUnit\Framework\TestCase;

use function Naf\app;

/**
 * A test inside the throwaway host that bin/check-extensions built.
 *
 * It works on the data an earlier phase left behind -- that is the point of the
 * phases: the host without the extensions is asked about rows the host with them
 * wrote. So there is no fixture here and nothing is rolled back; what these
 * tests read is whatever the installation before them stored.
 *
 * Services are taken through their interfaces rather than their classes, because
 * an extension is allowed to replace them and one of these hosts does.
 */
abstract class ExtensionHostTestCase extends TestCase
{
    protected PDO $pdo;
    protected Auth $auth;
    protected BoardQueryInterface $query;
    protected TicketServiceInterface $tickets;
    protected TicketMetadataReaderInterface $metadata;
    protected AccessInterface $access;

    protected int $project;
    protected int $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $container      = app()->container();
        $this->pdo      = $container->get(PDO::class);
        $this->auth     = $container->get(Auth::class);
        $this->query    = $container->get(BoardQueryInterface::class);
        $this->tickets  = $container->get(TicketServiceInterface::class);
        $this->metadata = $container->get(TicketMetadataReaderInterface::class);
        $this->access   = $container->get(AccessInterface::class);

        $row = $this->pdo
            ->query("SELECT * FROM users WHERE email='alice@example.test'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, 'the host has no seeded account to act as');
        $this->auth->setIdentity(new User($row));

        // A ticket that really carries a value of the extension that is gone, and
        // the project it belongs to. Taking the first of each separately would
        // pair a project with a ticket from another one as soon as the phase
        // before this created more than one board.
        $pair = $this->pdo->query(
            "SELECT project_id, ticket_id FROM ticket_metadata"
            . " WHERE meta_key='example.reviewed' ORDER BY ticket_id LIMIT 1",
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($pair, 'the phase before this left no ticket with a contributed value');

        $this->project = (int) $pair['project_id'];
        $this->ticket  = (int) $pair['ticket_id'];
    }

    protected function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }
}
