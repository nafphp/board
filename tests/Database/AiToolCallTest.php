<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\AiProjectTestCase;

/**
 * Calling a tool is checked again on the way in.
 *
 * The catalog was built at the start of a conversation and rights can be
 * withdrawn during one, so the answer to "may this run" is given now rather than
 * remembered. Writes need confirmation and carry the same board revision as any
 * other write: the assistant gets no shortcut past the lock everyone else obeys.
 *
 * Not transactional: a call passes the rate limiter, which refuses to count
 * inside a transaction -- an attempt has to survive a rollback to be a limit.
 */
final class AiToolCallTest extends AiProjectTestCase
{
    protected bool $transactional = false;

    public function testCallingAToolTheRoleDoesNotAllowIsRefused(): void
    {
        $this->actAs($this->member);

        $this->assertDenied(403, fn() => $this->ai->call($this->project, [
            'name'      => 'nafinity_ticket_create',
            'arguments' => [],
            'confirmed' => true,
        ]));
    }

    public function testRightsWithdrawnDuringAConversationTakeEffectOnTheNextCall(): void
    {
        $this->projects->member($this->project, ['email' => 'member@example.test', 'role' => 'remove']);

        $this->actAs($this->member);

        $this->assertDenied(404, fn() => $this->ai->call($this->project, [
            'name'      => 'nafinity_board',
            'arguments' => [],
        ]));
    }

    public function testAWriteIsRefusedUntilItIsConfirmed(): void
    {
        $this->assertDenied(422, fn() => $this->ai->call($this->project, $this->createCall()));
    }

    public function testAConfirmedWriteGoesThroughAndObeysTheBoardRevision(): void
    {
        $call = $this->createCall();

        $result  = $this->ai->call($this->project, [...$call, 'confirmed' => true]);
        $created = $this->tickets->resolve($this->project, (string) $result['id']);

        $this->assertSame('AI test', $this->tickets->ticket($this->project, $created)['title']);

        // The same call again carries a revision the board has moved past.
        $this->assertDenied(409, fn() => $this->ai->call($this->project, [...$call, 'confirmed' => true]));
    }

    public function testArgumentsThatNameAnotherProjectAreRefused(): void
    {
        $this->assertDenied(422, fn() => $this->ai->call($this->project, [
            'name'      => 'nafinity_board',
            'arguments' => ['project_id' => 999],
        ]));
    }
}
