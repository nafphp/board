<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\RememberToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two halves of a remember-me cookie.
 *
 * A cookie is something a stranger writes, and the first thing done with one is
 * a database lookup. So the shape is decided here, before anything else sees it:
 * what does not parse never reaches a query.
 */
final class RememberTokenTest extends TestCase
{
    public function testAnIssuedTokenSurvivesBeingWrittenDownAndReadBack(): void
    {
        $token = RememberToken::issue();
        $back  = RememberToken::parse($token->cookie());

        $this->assertNotNull($back);
        $this->assertSame($token->selector, $back->selector);
        $this->assertTrue($back->proves($token->hash()));
    }

    /** Two of them are two, which is the whole basis of the thing. */
    public function testTwoTokensShareNothing(): void
    {
        $one   = RememberToken::issue();
        $other = RememberToken::issue();

        $this->assertNotSame($one->selector, $other->selector);
        $this->assertNotSame($one->validator, $other->validator);
        $this->assertFalse($one->proves($other->hash()));
    }

    /** The validator is never what is kept, and the hash never gives it back. */
    public function testWhatIsStoredIsNotTheValidator(): void
    {
        $token = RememberToken::issue();

        $this->assertNotSame($token->validator, $token->hash());
        $this->assertSame(64, strlen($token->hash()));
        $this->assertStringNotContainsString($token->validator, $token->hash());
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return [
            'empty'               => [''],
            'no separator'        => [str_repeat('a', 96)],
            'two separators'      => ['aa.bb.cc'],
            'selector too short'  => [str_repeat('a', 30) . '.' . str_repeat('b', 64)],
            'validator too short' => [str_repeat('a', 32) . '.' . str_repeat('b', 60)],
            'not hexadecimal'     => [str_repeat('z', 32) . '.' . str_repeat('b', 64)],
            // The shapes somebody tries when they are not guessing a cookie.
            'a path'    => ['../../etc/passwd.' . str_repeat('b', 64)],
            'sql'       => ["' OR 1=1 --." . str_repeat('b', 64)],
            'null byte' => [str_repeat('a', 31) . "\0." . str_repeat('b', 64)],
        ];
    }

    #[DataProvider('malformed')]
    public function testNothingMisshapenBecomesAToken(string $cookie): void
    {
        $this->assertNull(RememberToken::parse($cookie), 'a malformed cookie was accepted');
    }
}
