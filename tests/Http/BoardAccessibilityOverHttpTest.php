<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use PDO;

use function Naf\app;

final class BoardAccessibilityOverHttpTest extends AcceptanceTestCase
{
    public function testBoardAndLiveCardsExposePriorityAssigneesAndDatesWithoutReadingDecorativeInitials(): void
    {
        $url       = $this->createTicket(['priority' => 'urgent', 'assignee_ids' => [1], 'due_date' => '2030-12-31']);
        $id        = $this->ticketId($url);
        $fragments = json_decode($this->alice->request('/projects/' . self::PROJECT . '/cards')['body'], true);

        foreach ([$this->page($this->alice, '/projects/' . self::PROJECT), $fragments['cards'][$id]] as $markup) {
            $xpath = $this->xpath($markup);
            $card  = $xpath->query('//*[@data-ticket="' . $id . '"]')->item(0);
            self::assertNotNull($card);
            self::assertStringContainsString('Priorität: Dringend', $card->textContent);
            self::assertStringContainsString('Zuständig: Alice Winter', $card->textContent);
            self::assertStringContainsString('Fällig am:', $card->textContent);
            self::assertSame(1, $xpath->query('.//time[@datetime="2030-12-31"]', $card)->length);
            self::assertSame(1, $xpath->query('.//*[contains(@class,"card-assignees")]//span[@aria-hidden="true" and text()="A"]', $card)->length);
            self::assertSame(1, $xpath->query('.//a[@data-ticket-link and @href]', $card)->length);
        }
    }

    public function testCardAndProjectMemberNamesAreTranslatedAndReadOnlyCardsKeepTheirInformation(): void
    {
        $url   = $this->createTicket(['priority' => 'urgent', 'assignee_ids' => [1]]);
        $id    = $this->ticketId($url);
        $token = $this->viewer->token($this->page($this->viewer, '/projects/' . self::PROJECT));
        self::assertSame(200, $this->post($this->viewer, '/api/settings/user', ['values' => ['locale' => 'en']], $token)['status']);

        try {
            $xpath = $this->xpath($this->page($this->viewer, '/projects/' . self::PROJECT));
            $card  = $xpath->query('//*[@data-ticket="' . $id . '"]')->item(0);
            self::assertStringContainsString('Priority: Urgent', $card->textContent);
            self::assertStringContainsString('Assigned to: Alice Winter', $card->textContent);
            self::assertSame(0, $xpath->query('.//button[@data-move-card]', $card)->length);
            self::assertSame(1, $xpath->query('//*[contains(@class,"avatar-stack")]//*[contains(@class,"sr-only") and text()="Alice Winter"]')->length);
        } finally {
            $this->post($this->viewer, '/api/settings/user', ['values' => ['locale' => 'de']], $token);
        }
    }

    public function testAPriorityWhosePluginIsMissingKeepsAnEscapedReadableValue(): void
    {
        $id = $this->ticketId($this->createTicket());
        app()->container()->get(PDO::class)->prepare('UPDATE tickets SET priority=? WHERE id=?')->execute(['old <priority> & value', $id]);
        $fragments = json_decode($this->alice->request('/projects/' . self::PROJECT . '/cards')['body'], true);
        $markup    = $fragments['cards'][$id];
        self::assertStringContainsString('Priorität: old &lt;priority&gt; &amp; value', $markup);
        self::assertStringNotContainsString('<priority>', $markup);
        self::assertStringContainsString('Priorität: old <priority> & value', $this->xpath($markup)->query('//*[@data-ticket]')->item(0)->textContent);
    }

    private function ticketId(string $url): int
    {
        return (int) $this->firstMatch('/data-ticket="(\d+)"/', $this->page($this->alice, $url), 'ticket id');
    }

    private function xpath(string $markup): DOMXPath
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $markup);

        return new DOMXPath($dom);
    }
}
