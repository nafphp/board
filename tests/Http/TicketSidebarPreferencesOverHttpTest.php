<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;

use function Naf\Board\extensions;

final class TicketSidebarPreferencesOverHttpTest extends AcceptanceTestCase
{
    private const string ENDPOINT = '/api/projects/1/settings/user';
    private const string TICKET   = '/projects/1/tickets/NAF-2';

    protected function tearDown(): void
    {
        foreach ([$this->alice, $this->viewer] as $client) {
            $this->post($client, self::ENDPOINT, ['resetKeys' => ['ticket_fields']]);
        }
        parent::tearDown();
    }

    public function testTheExistingProjectSettingsOfferPersonalSwitchesToViewers(): void
    {
        $page = $this->page($this->viewer, '/projects/1/settings');
        $this->assertStringContainsString('data-settings-open="ticket_sidebar"', $page);
        $xpath = $this->xpath($page);
        $this->assertSame(1, $xpath->query('//form[@data-settings-form="ticket_sidebar"]//input[@name="values[ticket_fields][priority]" and @type="hidden" and @value="0"]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-settings-form="ticket_sidebar"]//input[@name="values[ticket_fields][priority]" and @role="switch" and @checked]')->length);
        $this->assertStringContainsString('/projects/1/settings#ticket_sidebar', $this->page($this->viewer, self::TICKET));
    }

    public function testChoicesArePersonalPerBoardAndApplyToStandaloneAndDrawerViews(): void
    {
        $before = $this->ticketState(self::TICKET);
        $this->save($this->alice, ['priority' => false, 'spent_minutes' => false, 'creator' => false]);
        foreach (['', '?fragment=1'] as $suffix) {
            $page = $this->page($this->alice, self::TICKET . $suffix);
            $this->assertStringNotContainsString('data-inline-field="priority"', $page);
            $this->assertStringNotContainsString('data-ticket-timer', $page);
            $this->assertSame(0, $this->xpath($page)->query('//aside//dt[text()="Ersteller"]')->length);
            $this->assertStringContainsString('data-inline-field="due_date"', $page);
        }
        $this->assertStringContainsString('data-inline-field="priority"', $this->page($this->viewer, self::TICKET));
        $this->assertStringContainsString('data-inline-field="priority"', $this->page($this->alice, '/projects/3/tickets/ARC-1'));
        $this->assertSame($before, $this->ticketState(self::TICKET), 'A display preference must not write ticket values or versions.');
    }

    public function testHidingAllAttributesRemovesEmptyGroupsButRetainsActionsAndCreationInputs(): void
    {
        $page       = $this->page($this->alice, '/projects/1/settings');
        $switches   = $this->xpath($page)->query('//form[@data-settings-form="ticket_sidebar"]//input[@role="switch"]');
        $visibility = [];
        foreach ($switches as $switch) {
            preg_match('/^values\[ticket_fields\]\[(.+)\]$/', $switch->getAttribute('name'), $match);
            $visibility[$match[1]] = false;
        }
        $this->save($this->alice, $visibility);
        $page  = $this->page($this->alice, self::TICKET);
        $xpath = $this->xpath($page);
        $this->assertSame(0, $xpath->query('//aside//dl[@class="ticket-meta"]')->length);
        $this->assertStringContainsString('class="ticket-menu"', $page);
        $this->assertStringContainsString('/projects/1/settings#ticket_sidebar', $page);
        $this->assertStringContainsString('data-description-display', $page);
        $this->assertStringContainsString('data-comment-composer', $page);
        $create = $this->page($this->alice, '/projects/1/tickets/new?fragment=1');
        $this->assertStringContainsString('data-inline-field="column_id"', $create);
        $this->assertStringContainsString('data-inline-field="priority"', $create);
    }

    public function testAnEmptyInformationGroupRetainsItsLiveRefreshBoundary(): void
    {
        $visibility              = array_fill_keys(array_keys(extensions()->ticketFields()->forGroup('information')), false);
        $visibility['closed_at'] = true;
        $this->save($this->alice, $visibility);
        $url   = $this->createTicket(['title' => 'Conditional information']);
        $xpath = $this->xpath($this->page($this->alice, $url));
        $this->assertSame(1, $xpath->query('//details[@data-ticket-readonly="audit" and @hidden]')->length);
        $this->assertSame(0, $xpath->query('//details[@data-ticket-readonly="audit"]//dl')->length);

        $closed = $this->post($this->alice, $url . '/state', ['action' => 'close'] + $this->ticketState($url));
        $this->assertSame(200, $closed['status']);
        $xpath = $this->xpath($this->page($this->alice, $url . '?fragment=1'));
        $this->assertSame(1, $xpath->query('//details[@data-ticket-readonly="audit" and not(@hidden)]//dt[text()="Geschlossen"]')->length);
    }

    public function testTheNativeFormCanSaveFalseAndResetRestoresDefaults(): void
    {
        $token  = $this->alice->token($this->page($this->alice, '/projects/1/settings'));
        $native = $this->alice->request(self::ENDPOINT, http_build_query([
            '_csrf' => $token, 'values' => ['ticket_fields' => ['priority' => '0', 'due_date' => '1']],
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertSame(303, $native['status']);
        $this->assertStringNotContainsString('data-inline-field="priority"', $this->page($this->alice, self::TICKET));
        $reset = $this->post($this->alice, self::ENDPOINT, ['resetKeys' => ['ticket_fields']]);
        $this->assertSame(200, $reset['status']);
        $this->assertSame([], json_decode($reset['body'], true)['values']['ticket_fields']);
        $this->assertStringContainsString('data-inline-field="priority"', $this->page($this->alice, self::TICKET));
    }

    public function testMalformedChoicesAreRefusedAtomicallyAndForeignBoardsStayProtected(): void
    {
        $this->save($this->viewer, ['priority' => false]);
        foreach ([['not-installed' => false], ['priority' => 'yes'], ['priority' => []], false, ['title' => false]] as $invalid) {
            $answer = $this->post($this->viewer, self::ENDPOINT, ['values' => ['ticket_fields' => $invalid]]);
            $this->assertSame(422, $answer['status']);
            $this->assertStringNotContainsString('data-inline-field="priority"', $this->page($this->viewer, self::TICKET));
        }
        $foreign = $this->post($this->viewer, '/api/projects/2/settings/user', ['values' => ['ticket_fields' => ['priority' => false]]]);
        $this->assertSame(404, $foreign['status']);
    }

    private function save(HttpClient $client, array $visibility): void
    {
        $answer = $this->post($client, self::ENDPOINT, ['values' => ['ticket_fields' => $visibility]]);
        $this->assertSame(200, $answer['status'], $answer['body']);
    }

    private function xpath(string $markup): DOMXPath
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $markup);

        return new DOMXPath($dom);
    }
}
