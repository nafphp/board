<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Services\NotificationService;
use Naf\Board\Services\PreferenceService;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\app;

/**
 * A notification is not a message that was delivered once and is then owned by
 * its recipient: whether it may be shown is decided when it is read. Someone
 * who has left a project must not keep seeing what happens in it, and a muted
 * project must go quiet immediately rather than from the next event on.
 */
final class NotificationVisibilityTest extends BoardTestCase
{
    private NotificationService $notifications;
    private PreferenceService $preferences;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = app()->container()->make(NotificationService::class);
        $this->preferences   = app()->container()->make(PreferenceService::class);
        $this->tickets->create($this->projectA, $this->ticketData());
    }

    public function testAMemberSeesWhatHappensInTheirProject(): void
    {
        $this->actAs($this->viewer);

        $this->assertNotSame([], $this->notifications->list());
    }

    public function testMutingAProjectHidesItsNotificationsAtOnce(): void
    {
        $this->actAs($this->viewer);
        $this->assertNotSame([], $this->notifications->list(), 'nothing to mute');

        $this->preferences->mute($this->projectA, true);

        $this->assertSame([], $this->notifications->list());
    }

    public function testLeavingAProjectHidesWhatWasAlreadyAddressedToYou(): void
    {
        $this->actAs($this->viewer);
        $this->assertNotSame([], $this->notifications->list(), 'nothing to revoke');

        $this->actAs($this->alice);
        $this->projects->member($this->projectA, ['email' => 'viewer@example.test', 'role' => 'remove']);

        $this->actAs($this->viewer);
        $this->assertSame([], $this->notifications->list());
    }
}
