<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Domain\Change;
use Naf\Board\Domain\Failure;
use Naf\Board\Migrations\M202609210005NewTicketPlacement;
use Naf\Board\Rbac\Grants;
use Naf\Board\Tests\Support\AttachmentFixture;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\Websocket\Publisher;

/** Regression cases found through the real skeleton installation. */
final class ReviewRegressionTest extends BoardTestCase
{
    use AttachmentFixture;

    public function testAnAuthorizedAdministratorUploadSurvivesFinalization(): void
    {
        $this->setUpAttachments();
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        Grants::makeAdmin((int) $this->bob->getId());
        $this->actAs($this->bob);
        $this->assertTrue($this->access->project($this->projectA)->allows('upload'));

        $this->attachments->upload($this->projectA, $ticket, $this->upload('Administrator audit attachment.'));

        $this->assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM attachments WHERE project_id=? AND ticket_id=? AND state='ready'",
            [$this->projectA, $ticket],
        ), 'The authorized upload returned normally but its file and record disappeared.');
    }

    public function testChangingAnotherTicketDoesNotRejectAnUnchangedTicket(): void
    {
        $first        = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'First']));
        $second       = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Second']));
        $seenRevision = $this->revision();

        $this->tickets->update($this->projectA, $second, [
            'title' => 'Second updated', 'version' => 1,
        ] + $seenRevision);
        $this->assertSame(1, (int) $this->tickets->ticket($this->projectA, $first)['version']);

        $this->tickets->update($this->projectA, $first, [
            'title' => 'First updated', 'version' => 1,
        ] + $seenRevision);
        $this->assertSame('First updated', $this->tickets->ticket($this->projectA, $first)['title']);
    }

    public function testARolledBackChangeDoesNotPublishALiveUpdate(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $path   = sys_get_temp_dir() . '/nafinity-review-' . bin2hex(random_bytes(6)) . '.sock';
        $server = stream_socket_server('unix://' . $path, $code, $error);
        $this->assertIsResource($server);
        $container = \Naf\app()->container();
        $previous  = $container->get(Publisher::class);
        $container->set(Publisher::class, new Publisher($path));
        $active = true;
        \Naf\event()->listen(
            Change::class,
            static function (Change $change) use ($ticket, &$active): void {
                if ($active && $change->ticketId === $ticket && $change->type === 'ticket.updated') {
                    throw new Failure('Audit veto.', 422);
                }
            },
        );

        try {
            $before = (int) $this->scalar('SELECT COUNT(*) FROM naf_queue_jobs');
            $this->assertDenied(422, fn() => $this->tickets->update(
                $this->projectA,
                $ticket,
                ['title' => 'Must roll back', 'version' => 1] + $this->revision(),
            ));
            $this->assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM naf_queue_jobs'));
            $this->assertSame('Searchable sunflower', $this->tickets->ticket($this->projectA, $ticket)['title']);
            $client  = @stream_socket_accept($server, 0);
            $message = $client === false ? null : fgets($client);
            if (is_resource($client)) {
                fclose($client);
            }
            $this->assertNull($message, 'A real socket received an event for the rolled-back change: ' . $message);
        } finally {
            $active = false;
            $container->set(Publisher::class, $previous);
            fclose($server);
            unlink($path);
        }
    }

    public function testReadinessDetectsAPendingRecentMigration(): void
    {
        $statement = $this->pdo->prepare('DELETE FROM migrations WHERE name=?');
        $statement->execute([M202609210005NewTicketPlacement::class]);
        $this->assertSame(1, $statement->rowCount());
        $handler  = \Naf\route()->all()['health.ready']['action'];
        $response = $handler();
        $this->assertSame(503, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testHostRouteOverridesAnExistingNameAsDocumented(): void
    {
        $routes  = clone \Naf\route();
        $handler = static fn() => \Naf\json(['custom' => true]);
        $routes->add('GET', '/projects', $handler, 'projects');
        $this->assertSame($handler, $routes->find('/projects', 'GET')['action']);
        $this->assertSame('projects', $routes->find('/projects', 'GET')['name']);
    }

    public function testTheSameTicketStillRejectsAStaleVersion(): void
    {
        $ticket  = $this->tickets->create($this->projectA, $this->ticketData());
        $payload = ['version' => 1] + $this->revision();
        $this->tickets->update($this->projectA, $ticket, ['title' => 'Saved'] + $payload);
        $this->assertDenied(409, fn() => $this->tickets->update($this->projectA, $ticket, ['title' => 'Lost update'] + $payload));
        $this->assertSame('Saved', $this->tickets->ticket($this->projectA, $ticket)['title']);
    }

    public function testReadinessChecksTheConfiguredAttachmentDisk(): void
    {
        $this->setUpAttachments();
        $handler = \Naf\route()->all()['health.ready']['action'];
        $this->assertSame(200, $handler()->getStatusCode());
        rmdir($this->privateRoot . '/ready');
        file_put_contents($this->privateRoot . '/ready', 'blocked destination');

        try {
            $this->assertSame(503, $handler()->getStatusCode());
        } finally {
            unlink($this->privateRoot . '/ready');
        }
        $this->assertSame(200, $handler()->getStatusCode());
        $this->assertSame([], glob($this->privateRoot . '/staging/*'));
        $this->assertSame([], glob($this->privateRoot . '/ready/*'));
    }

    public function testTransferTargetsHonorAdministratorAccessWithoutMembership(): void
    {
        Grants::makeAdmin((int) $this->alice->getId());
        $this->assertContains($this->projectB, array_map(intval(...), array_column($this->query->transferTargets($this->projectA), 'id')));
    }

    public function testTransferTargetsDoNotOfferAFormerWriterAfterRbacRevocation(): void
    {
        $this->pdo->prepare('DELETE FROM rbac_user_roles WHERE user_id=?')->execute([$this->bob->getId()]);
        $this->actAs($this->bob);
        $this->assertSame([], $this->query->transferTargets($this->projectA));
    }

    public function testRoleAssignmentChecksUseConfiguredRbacGrants(): void
    {
        $id = \Naf\Rbac\rbac()->roles->idOf('member');
        $this->pdo->prepare("DELETE FROM rbac_role_permissions WHERE role_id=? AND permission='upload'")->execute([$id]);
        $this->assertNotContains('upload', $this->access->permissions($this->projectA, 'member'));
    }
}
