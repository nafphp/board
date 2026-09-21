<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\Mentions;
use PHPUnit\Framework\TestCase;

/**
 * Mentioning someone by name, where names are not unique.
 *
 * Two people called Anna Schmid need handles that tell them apart, or a mention
 * reaches the wrong one. And a comment is text someone else wrote, so rendering
 * it must not let that text become markup.
 */
final class MentionsTest extends TestCase
{
    /** @return list<array{id:int,name:string,handle:string}> */
    private function members(): array
    {
        return Mentions::members([
            ['id' => 1, 'name' => 'Anna Schmid'],
            ['id' => 2, 'name' => 'Anna Schmid'],
            ['id' => 3, 'name' => 'Florian Knapp'],
        ]);
    }

    public function testASharedNameIsDisambiguatedByTheAccount(): void
    {
        $members = $this->members();

        $this->assertSame('anna.schmid.1', $members[0]['handle']);
        $this->assertSame('anna.schmid.2', $members[1]['handle']);
    }

    public function testAUniqueNameKeepsThePlainHandle(): void
    {
        $this->assertSame('florian.knapp', $this->members()[2]['handle']);
    }

    public function testAMentionIsMarkedUpAndTheRestOfTheTextIsNot(): void
    {
        $html = Mentions::render(
            '@florian.knapp <img src=x onerror=alert(1)> person@florian.knapp',
            $this->members(),
        );

        $this->assertSame(1, substr_count($html, 'class="mention"'), 'the wrong number of mentions was marked');
        $this->assertStringNotContainsString('<img', $html, 'text in a comment became markup');
    }

    /**
     * An address is not a mention. The handle appears in "person@florian.knapp"
     * too, and marking that up would turn every email address into a ping.
     */
    public function testAHandleInTheMiddleOfAWordIsNotAMention(): void
    {
        $html = Mentions::render('person@florian.knapp', $this->members());

        $this->assertStringNotContainsString('class="mention"', $html);
    }
}
