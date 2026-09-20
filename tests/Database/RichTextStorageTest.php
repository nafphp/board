<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\TicketDetailTestCase;

/**
 * A rich description is stored twice: sanitized HTML for reading, plain text for
 * searching. What the sanitizer keeps is covered without a database in the unit
 * test of the same name; what matters here is that the ticket really carries
 * both, and that they stay in step with each other.
 */
final class RichTextStorageTest extends TicketDetailTestCase
{
    private const RICH = '<h2>Goal</h2><p><strong>Bold</strong></p><script>alert(1)</script>';

    public function testBothRenderingsAreStoredAndTheUnsafePartIsGone(): void
    {
        $this->edit(['description_html' => self::RICH]);

        $row = $this->row();
        $this->assertStringContainsString('<strong>Bold</strong>', $row['description_html']);
        $this->assertStringNotContainsString('<script', $row['description_html'], 'the text was stored unsanitized');
        $this->assertStringContainsString('Bold', $row['description'], 'there is nothing for search to find');
        $this->assertStringNotContainsString('<strong>', $row['description']);
    }

    public function testAnEditToAnotherFieldLeavesTheFormattingAlone(): void
    {
        $this->edit(['description_html' => self::RICH]);
        $html = $this->row()['description_html'];

        $this->edit(['priority' => 'high']);

        $this->assertSame($html, $this->row()['description_html']);
    }

    /**
     * Writing through the plain field is a deliberate choice of plain text, so
     * the formatting goes rather than lingering invisibly and reappearing the
     * next time the editor opens.
     */
    public function testWritingPlainTextClearsTheFormatting(): void
    {
        $this->edit(['description_html' => self::RICH]);

        $this->edit(['description' => 'Updated through plain API']);

        $this->assertNull($this->row()['description_html']);
    }

    public function testSomethingThatIsNotTextIsRefused(): void
    {
        $this->assertDenied(422, fn() => $this->edit(['description_html' => ['bad']]));
    }

    public function testALongUnicodeDescriptionSurvivesTheColumn(): void
    {
        $this->edit(['description_html' => '<p>' . str_repeat('界', 40000) . '</p>']);

        $this->assertSame(40000, mb_strlen($this->row()['description']), 'the storage cut the text');
    }
}
