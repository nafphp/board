<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;

/**
 * What each role may do, and the two rules that keep a project reachable:
 * its last owner cannot be demoted, and nobody hands out a rank above their own.
 */
final class PermissionTest extends BoardTestCase
{
    public function testAViewerMayNotWriteAnything(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());

        $this->actAs($this->viewer);

        $this->assertDenied(403, fn() => $this->tickets->create($this->projectA, $this->ticketData()));
        $this->assertDenied(403, fn() => $this->tickets->move($this->projectA, $ticket, []));
        $this->assertDenied(403, fn() => $this->projects->archive($this->projectA, true));
        $this->assertDenied(403, fn() => $this->comments->save($this->projectA, $ticket, ['body' => 'No']));
    }

    public function testTheLastOwnerCannotRemoveThemselves(): void
    {
        $this->assertDenied(422, fn() => $this->projects->member($this->projectA, [
            'email' => 'alice@example.test',
            'role'  => 'remove',
        ]));
    }

    public function testAManagerCannotAppointAnOwnerNorDemoteOne(): void
    {
        $this->projects->member($this->projectA, ['email' => 'member@example.test', 'role' => 'manager']);

        $this->actAs($this->member);

        $this->assertDenied(403, fn() => $this->projects->member($this->projectA, [
            'email' => 'viewer@example.test',
            'role'  => 'owner',
        ]), 'a manager appointed an owner');
        $this->assertDenied(403, fn() => $this->projects->member($this->projectA, [
            'email' => 'alice@example.test',
            'role'  => 'viewer',
        ]), 'a manager demoted the owner');
    }

    public function testRemovingAMemberTakesEffectOnTheirNextRequest(): void
    {
        $this->projects->member($this->projectA, ['email' => 'viewer@example.test', 'role' => 'remove']);

        $this->actAs($this->viewer);

        $this->assertDenied(404, fn() => $this->query->board($this->projectA));
        $this->assertSame([], $this->query->projects(), 'the removed project is still listed');
    }
}
