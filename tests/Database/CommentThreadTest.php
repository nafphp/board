<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\TicketDetailTestCase;

/**
 * Replies, and what happens to them when the comment they answer is deleted.
 *
 * A thread is not a list: every reply names exactly what it answers, and that
 * ancestry has to survive the parent being deleted -- otherwise deleting one
 * comment silently rewrites the conversation around it. So a deleted comment is
 * redacted in place, kept as the anchor its replies point at, and closed to new
 * ones.
 */
final class CommentThreadTest extends TicketDetailTestCase
{
    private int $parent;
    private int $reply;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comments->save($this->project, $this->ticket, ['body' => 'Parent']);
        $this->parent = (int) $this->scalar('SELECT MAX(id) FROM comments');
        $this->comments->save($this->project, $this->ticket, [
            'body'      => '@alice Reply',
            'parent_id' => $this->parent,
        ]);
        $this->reply = (int) $this->scalar('SELECT MAX(id) FROM comments');
    }

    public function testAReplyCanItselfBeRepliedTo(): void
    {
        $this->comments->save($this->project, $this->ticket, [
            'body'      => 'Nested',
            'parent_id' => $this->reply,
        ]);

        $rows = $this->query->detail($this->project, $this->ticket)['comments'];
        $this->assertCount(3, $rows);
        $this->assertSame($this->reply, (int) $rows[2]['parent_id']);
    }

    public function testAReplyCannotReachAcrossToAnotherTicket(): void
    {
        $this->assertDenied(422, fn() => $this->comments->save($this->project, $this->other, [
            'body'      => 'Cross ticket',
            'parent_id' => $this->parent,
        ]));
    }

    public function testAReplyToSomethingThatDoesNotExistIsRefused(): void
    {
        $this->assertDenied(422, fn() => $this->comments->save($this->project, $this->ticket, [
            'body'      => 'Forged',
            'parent_id' => 999999,
        ]));
    }

    public function testADeletedCommentIsRedactedAndKeepsItsRepliesInPlace(): void
    {
        $this->comments->save($this->project, $this->ticket, [
            'body'      => 'Nested',
            'parent_id' => $this->reply,
        ]);

        $this->deleteParent();

        $rows = $this->query->detail($this->project, $this->ticket)['comments'];
        $this->assertCount(3, $rows, 'the replies went with the comment they answered');
        $this->assertSame('', $rows[0]['body'], 'the deleted comment still shows what it said');
        $this->assertNotNull($rows[0]['deleted_at']);
        $this->assertSame($this->parent, (int) $rows[1]['parent_id'], 'the reply lost what it answered');
        $this->assertSame($this->reply, (int) $rows[2]['parent_id'], 'the nested reply lost its ancestry');
    }

    public function testNobodyCanReplyToADeletedComment(): void
    {
        $this->deleteParent();

        $this->assertDenied(422, fn() => $this->comments->save($this->project, $this->ticket, [
            'body'      => 'Deleted target',
            'parent_id' => $this->parent,
        ]));
    }

    private function deleteParent(): void
    {
        $this->comments->save($this->project, $this->ticket, [
            'id'      => $this->parent,
            'version' => 1,
            'action'  => 'delete',
        ]);
    }
}
