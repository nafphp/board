<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

final class LanguageSurfaceTest extends AcceptanceTestCase
{
    public function testEnglishAppliesToFragmentsValidationToolsAndBrowserMessages(): void
    {
        $page  = $this->page($this->alice, '/projects/' . self::PROJECT);
        $token = $this->alice->token($page);
        $this->assertSame(200, $this->post($this->alice, '/api/settings/user', [
            'values' => ['locale' => 'en'],
        ], $token)['status']);

        try {
            $page = $this->page($this->alice, '/projects/' . self::PROJECT);
            $this->assertStringContainsString('lang="en"', $page);
            $this->assertStringContainsString('aria-label="Open menu"', $page);
            $this->assertSame(1, preg_match('/id="nafinity-browser-words">(.*?)<\/script>/s', $page, $matches));
            $words = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('Ticket details', $words['Ticketdetails']);
            $this->assertSame('Saving changes …', $words['Änderungen werden gespeichert …']);

            $fragment = $this->page($this->alice, '/projects/' . self::PROJECT . '/tickets/new?fragment=1');
            $this->assertStringContainsString('New ticket', $fragment);
            $this->assertStringNotContainsString('Neues Ticket', $fragment);

            $refused = $this->post($this->alice, '/projects/' . self::PROJECT . '/tickets', ['title' => ''], $token);
            $this->assertSame(422, $refused['status']);
            $failure = json_decode($refused['body'], true);
            $this->assertSame('Please check your input.', $failure['message']);
            $this->assertSame('This field is required.', $failure['errors']['title'][0]);

            $catalog = json_decode($this->page($this->alice, '/ai/tools?project=' . self::PROJECT), true)['tools'];
            $titles  = array_column(array_column($catalog, 'meta'), 'title');
            $this->assertContains('View board', $titles);
            $this->assertContains('Read ticket', $titles);
        } finally {
            $this->post($this->alice, '/api/settings/user', ['values' => ['locale' => 'de']], $token);
        }
    }
}
