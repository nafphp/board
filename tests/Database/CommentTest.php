<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\BoardTestCase;

/**
 * A comment belongs to whoever wrote it, carries a version so two editors cannot
 * overwrite each other silently, and is never really deleted -- it is marked.
 */
final class CommentTest extends BoardTestCase
{
    private int $ticket;
    private int $comment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ticket = $this->tickets->create($this->projectA, $this->ticketData());
        $this->comments->save($this->projectA, $this->ticket, ['body' => 'Alice note']);
        $this->comment = (int) $this->scalar('SELECT MAX(id) FROM comments');
    }

    public function testSomeoneElseCannotEditTheComment(): void
    {
        $this->actAs($this->member);

        $this->assertDenied(403, fn() => $this->comments->save($this->projectA, $this->ticket, [
            'id'      => $this->comment,
            'version' => 1,
            'body'    => 'Hijack',
        ]));
    }

    public function testEditingTwiceFromTheSameVersionConflicts(): void
    {
        $this->comments->save($this->projectA, $this->ticket, [
            'id'      => $this->comment,
            'version' => 1,
            'body'    => 'Revised',
        ]);

        $this->assertDenied(409, fn() => $this->comments->save($this->projectA, $this->ticket, [
            'id'      => $this->comment,
            'version' => 1,
            'body'    => 'Lost update',
        ]));
    }

    public function testDeletingMarksTheCommentRatherThanRemovingIt(): void
    {
        $this->comments->save($this->projectA, $this->ticket, [
            'id'      => $this->comment,
            'version' => 1,
            'action'  => 'delete',
        ]);

        $this->assertNotNull(
            $this->scalar('SELECT deleted_at FROM comments WHERE id=?', [$this->comment]),
            'the comment was not soft deleted',
        );
    }
}
