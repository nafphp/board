<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\NotificationService;
use Naf\Board\Services\TicketSubscriptions;
use Naf\Board\Support\Settings\PreferenceStore;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\app;

final class NotificationRelevanceTest extends BoardTestCase
{
    private NotificationService $notifications;
    private TicketSubscriptions $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = app()->container()->make(NotificationService::class);
        $this->subscriptions = app()->container()->make(TicketSubscriptions::class);
        foreach ([$this->viewer, $this->member] as $user) {
            app()->container()->make(PreferenceStore::class)->writeUser((int) $user->getId(), ['notify_scope' => 'relevant']);
        }
        $this->pdo->exec('DELETE FROM notifications');
    }

    public function testUnrelatedProjectChangesStayQuietAndAssignedTicketsAreDelivered(): void
    {
        $this->tickets->create($this->projectA, $this->ticketData(['assignee_ids' => [$this->member->getId()]]));
        $this->actAs($this->viewer);
        self::assertSame([], $this->notifications->list());
        $this->actAs($this->member);
        self::assertCount(1, $this->notifications->list());
    }

    public function testFollowingIsPersonalIdempotentAndNeverBypassesProjectAccessOrMuting(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->actAs($this->viewer);
        $this->subscriptions->save($this->projectA, $ticket, true);
        $this->subscriptions->save($this->projectA, $ticket, true);
        self::assertTrue($this->subscriptions->state($this->projectA, $ticket)['subscribed']);
        $this->actAs($this->alice);
        $this->comments->save($this->projectA, $ticket, ['body' => 'A change for subscribers']);
        $this->actAs($this->viewer);
        self::assertCount(1, $this->notifications->list());
        $this->subscriptions->save($this->projectA, $ticket, false);
        $this->actAs($this->alice);
        $this->comments->save($this->projectA, $ticket, ['body' => 'No longer subscribed']);
        $this->actAs($this->viewer);
        self::assertCount(1, $this->notifications->list());
        $this->actAs($this->bob);
        $this->assertDenied(404, fn() => $this->subscriptions->save($this->projectA, $ticket, true));
    }

    public function testNewCommentMentionsReachUnassignedMembersButEmailAddressesAndRepeatedMentionsDoNot(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->comments->save($this->projectA, $ticket, ['body' => '@viewer please review. person@member is an email.']);
        $comment = (int) $this->scalar('SELECT MAX(id) FROM comments WHERE ticket_id=?', [$ticket]);
        $this->comments->save($this->projectA, $ticket, ['id' => $comment, 'version' => 1, 'body' => '@viewer please review again. @member now too.']);
        $this->actAs($this->viewer);
        self::assertCount(1, $this->notifications->list());
        $this->actAs($this->member);
        self::assertCount(1, $this->notifications->list());
    }

    public function testNewAssignmentsAreNotLostWhenAutosaveFoldsThePreviousEdit(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->tickets->update($this->projectA, $ticket, ['version' => 1, 'title' => 'First autosave']);
        $this->tickets->update($this->projectA, $ticket, ['version' => 2, 'assignee_ids' => [$this->viewer->getId()]]);
        $this->actAs($this->viewer);
        self::assertCount(1, $this->notifications->list());
    }

    public function testCreatorRemainsInvolvedAndMutedMentionsStaySilent(): void
    {
        $ticket = $this->tickets->create($this->projectA, $this->ticketData());
        app()->container()->make(PreferenceStore::class)->writeUser((int) $this->alice->getId(), ['notify_scope' => 'relevant']);
        app()->container()->make(PreferenceStore::class)->writeProjectUser($this->projectA, (int) $this->viewer->getId(), ['muted' => true]);
        $this->actAs($this->member);
        $this->comments->save($this->projectA, $ticket, ['body' => '@viewer this project is muted']);
        $this->actAs($this->alice);
        self::assertCount(1, $this->notifications->list());
        $this->actAs($this->viewer);
        self::assertSame([], $this->notifications->list());
    }
}
