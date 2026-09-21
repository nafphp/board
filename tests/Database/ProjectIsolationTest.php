<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Rbac\Grants;
use Naf\Board\Tests\Support\BoardTestCase;

/**
 * A project is private to its members, and says so the same way whether the
 * caller is a stranger or a neighbour: 404, never 403. A 403 would confirm that
 * the project exists, which is the one thing a non-member must not learn.
 */
final class ProjectIsolationTest extends BoardTestCase
{
    public function testProjectListShowsOnlyProjectsTheCallerBelongsTo(): void
    {
        $listed = array_map('intval', array_column($this->query->projects(), 'id'));

        $this->assertSame([$this->projectA], $listed);
    }

    public function testReadingAForeignProjectIsNotFound(): void
    {
        $this->assertDenied(404, fn() => $this->query->board($this->projectB));
        $this->assertDenied(404, fn() => $this->query->activity($this->projectB));
    }

    public function testNonMemberCanNeitherReadNorMoveATicket(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());

        $this->actAs($this->bob);

        $this->assertDenied(404, fn() => $this->query->detail($this->projectA, $ticket));
        $this->assertDenied(404, fn() => $this->tickets->move($this->projectA, $ticket, []));
    }

    /**
     * The one documented exception, and only the documented one.
     *
     * `projects.administer` says "jedes Board, auch ohne Mitgliedschaft" and now
     * means it. Everyone else keeps the 404 -- including somebody who holds an
     * ordinary installation-wide role, because this is the permission that opens
     * a foreign board and no other one does it by accident.
     */
    public function testAnAdministratorOfEveryProjectReachesAForeignBoard(): void
    {
        Grants::makeAdmin((int) $this->bob->getId());
        $this->actAs($this->bob);

        $board = $this->query->board($this->projectA);

        $this->assertSame($this->projectA, (int) $board['project']['id']);
    }

    public function testLookingAtABoardDoesNotJoinIt(): void
    {
        Grants::makeAdmin((int) $this->bob->getId());
        $this->actAs($this->bob);
        $this->query->board($this->projectA);

        $this->assertSame(
            0,
            (int) $this->scalar(
                'SELECT COUNT(*) FROM project_members WHERE project_id=? AND user_id=?',
                [$this->projectA, (int) $this->bob->getId()],
            ),
            'looking at a board made somebody a member of it',
        );
    }

    public function testAnOrdinaryInstallationRoleDoesNotOpenIt(): void
    {
        Grants::ensureDefault((int) $this->bob->getId());
        $this->actAs($this->bob);

        $this->assertDenied(404, fn() => $this->query->board($this->projectA));
    }

    /**
     * Reaching a board and seeing it listed are the same right.
     *
     * A list that hides what the next click opens is not a smaller permission,
     * only a worse way to use it -- the administrator would have to know the
     * address of a board to administer it.
     */
    public function testAnAdministratorSeesEveryProjectInTheList(): void
    {
        Grants::makeAdmin((int) $this->bob->getId());
        $this->actAs($this->bob);

        $listed = array_map('intval', array_column($this->query->projects(), 'id'));

        $this->assertContains($this->projectA, $listed);
        $this->assertContains($this->projectB, $listed);
    }

    public function testTheirOwnRoleStillShowsOnTheBoardsTheyAreIn(): void
    {
        Grants::makeAdmin((int) $this->bob->getId());
        $this->actAs($this->bob);

        $listed = array_column($this->query->projects(), 'role', 'id');

        $this->assertSame('owner', $listed[$this->projectB], 'the membership role was overwritten');
        $this->assertSame('Administrator', $listed[$this->projectA]);
    }
}
