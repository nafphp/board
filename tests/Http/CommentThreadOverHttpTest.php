<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * Comments and replies over HTTP.
 *
 * A reply names the comment it answers, and that comment has to be on the same
 * ticket -- an id from elsewhere is refused rather than quietly re-parented.
 */
final class CommentThreadOverHttpTest extends AcceptanceTestCase
{
    private string $url;
    private string $other;
    private int $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->url   = $this->createTicket();
        $this->other = $this->createTicket(['title' => 'Another ticket']);

        $created = $this->post($this->alice, $this->url . '/comments', [
            'body' => 'Root for threaded HTTP reply',
        ]);
        $this->assertSame(200, $created['status'], 'the root comment was refused');
        $this->root = $this->commentCarrying('Root for threaded HTTP reply');
    }

    public function testAReplyIsShownUnderTheCommentItAnswers(): void
    {
        $reply = $this->post($this->alice, $this->url . '/comments', [
            'body'      => '@alice HTTP child',
            'parent_id' => $this->root,
        ]);

        $this->assertSame(200, $reply['status']);

        $page = $this->page($this->alice, $this->url);
        $this->assertStringContainsString('@alice HTTP child', $page);
        $this->assertStringContainsString('is-reply', $page, 'the reply was rendered as a root comment');
    }

    public function testAReplyCannotReachAcrossToAnotherTicket(): void
    {
        $refused = $this->post($this->alice, $this->other . '/comments', [
            'body'      => 'Foreign parent',
            'parent_id' => $this->root,
        ]);

        $this->assertSame(422, $refused['status']);
    }

    /** The id of the comment whose article carries this text. */
    private function commentCarrying(string $text): int
    {
        $page = $this->page($this->alice, $this->url);

        preg_match_all('#<article\b[^>]*id="comment-(\d+)".*?</article>#s', $page, $articles, PREG_SET_ORDER);
        foreach ($articles as $article) {
            if (str_contains($article[0], $text)) {
                return (int) $article[1];
            }
        }

        $this->fail('No comment on the page carries "' . $text . '".');
    }
}
