<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\FormMarkup;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Creating a ticket is the same workspace as reading one, in draft.
 *
 * One form, not nested, posting to the ordinary endpoint -- so it works without
 * JavaScript and means the same thing with it. A draft is explicitly submitted:
 * the inline editors elsewhere save as you type, and something that does not
 * exist yet must not.
 */
final class TicketCreationOverHttpTest extends AcceptanceTestCase
{
    private const NEW = '/projects/1/tickets/new';

    private FormMarkup $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->form = new FormMarkup($this->page($this->alice, self::NEW . '?fragment=1'));
    }

    public function testTheBoardOffersTheCreationFragmentAsTheSharedWorkspace(): void
    {
        $fragment = $this->page($this->alice, self::NEW . '?fragment=1');

        $this->assertStringContainsString('data-ticket-create-link', $this->page($this->alice, '/projects/1'));
        $this->assertStringContainsString('ticket-workspace', $fragment);
        $this->assertStringNotContainsString('<html', $fragment, 'the fragment carried a whole page');
        $this->assertStringNotContainsString(
            'data-comment-composer',
            $fragment,
            'a ticket that does not exist yet was offered a comment box',
        );
    }

    public function testThereIsExactlyOneFormAndItIsNotNested(): void
    {
        $forms = $this->form->forms();

        $this->assertCount(1, $forms, 'creation renders more than one form');
        $this->assertFalse($this->form->hasNestedForm(), 'a form sits inside another and would never submit');
        $this->assertTrue($forms[0]->hasAttribute('data-ticket-create'));
        $this->assertSame('/projects/1/tickets', $forms[0]->getAttribute('action'));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function draftFields(): array
    {
        $names = [
            '_csrf', 'board_revision', 'title', 'description', 'column_id', 'swimlane_id',
            'assignee_ids[]', 'label_ids[]', 'priority', 'color',
            'start_date', 'due_date', 'estimate_minutes', 'spent_minutes',
        ];

        return array_combine($names, array_map(static fn(string $name) => [$name], $names));
    }

    #[DataProvider('draftFields')]
    public function testEveryDraftFieldIsOnTheForm(string $name): void
    {
        $this->assertNotNull($this->form->control($name), $name . ' is missing from the draft');
    }

    /** Without JavaScript the description is still typed as plain text. */
    public function testTheDescriptionHasAPlainTextFallback(): void
    {
        $this->assertFalse(
            $this->form->control('description')?->hasAttribute('hidden'),
            'the plain-text description is hidden, so there is no fallback',
        );
    }

    public function testNothingInTheDraftSavesOnItsOwn(): void
    {
        $this->assertFalse(
            $this->form->control('column_id')?->hasAttribute('data-save-on-change'),
            'a draft field saved before the ticket existed',
        );
        $this->assertStringNotContainsString(
            'data-auto-save',
            $this->page($this->alice, self::NEW . '?fragment=1'),
        );
    }

    public function testTheDirectAddressStillAnswersWithAWholePage(): void
    {
        $this->assertStringContainsString('<html', $this->page($this->alice, self::NEW));
    }

    public function testAViewerCannotOpenItAndAStrangerIsNotToldItExists(): void
    {
        $this->assertSame(403, $this->viewer->request(self::NEW . '?fragment=1')['status']);
        $this->assertSame(404, $this->bob->request(self::NEW . '?fragment=1')['status']);
    }

    public function testATitleIsRequiredAndATokenToo(): void
    {
        $draft = $this->draft();

        $this->assertSame(
            422,
            $this->post($this->alice, '/projects/1/tickets', ['title' => ''] + $draft)['status'],
        );
        $this->assertSame(
            400,
            $this->alice->request(
                '/projects/1/tickets',
                $draft,
                ['Content-Type: application/json', 'Accept: application/json'],
            )['status'],
        );
    }

    /**
     * Posted as the form posts it -- url-encoded, with the token the page carried
     * -- and the answer is the editable detail of what was just created.
     */
    public function testTheFormSubmissionCreatesTheTicketWithEverythingOnIt(): void
    {
        $draft = $this->draft();
        $body  = http_build_query([
            ...array_filter($draft, static fn(mixed $value) => !is_array($value)),
            'assignee_ids[]' => 1,
            'label_ids[]'    => 1,
            '_csrf'          => $this->form->valueOf('_csrf'),
        ]);

        $created = $this->alice->request('/projects/1/tickets', $body, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);

        $this->assertSame(200, $created['status']);

        $fragment = $this->page($this->alice, json_decode($created['body'], true)['url'] . '?fragment=1');
        $this->assertStringContainsString('Created from unified modal', $fragment);
        $this->assertStringContainsString('<h2>Modal plan</h2>', $fragment, 'the rich description was lost');
        $this->assertStringContainsString('<strong>Formatted</strong>', $fragment);
        $this->assertStringContainsString('value="2026-10-01"', $fragment, 'the start date was lost');
        $this->assertStringContainsString('value="1h 30m"', $fragment, 'the estimate was not written back readably');
        $this->assertStringContainsString('value="15m"', $fragment);
        $this->assertStringNotContainsString('data-ticket-create', $fragment, 'it is still in draft');
        $this->assertStringContainsString('data-comment-composer', $fragment, 'it cannot be commented on');
    }

    public function testRepeatingTheSubmissionCannotCreateADuplicate(): void
    {
        $draft = $this->draft();
        $this->post($this->alice, '/projects/1/tickets', $draft);

        $again = $this->post($this->alice, '/projects/1/tickets', $draft);

        $this->assertSame(409, $again['status'], 'the board revision did not stop a second creation');
    }

    /** @return array<string,mixed> */
    private function draft(): array
    {
        $state = $this->boardState($this->alice);

        return [
            'title' => 'Created from unified modal',
            // Both, which is more than any one browser sends: the editor renames
            // the field to description_html, and without it the plain one goes
            // instead. `testTheEditorsSubmissionNeedsNoPlainDescription` covers
            // what the first of those actually posts.
            'description'      => 'HTTP roundtrip sunflower',
            'description_html' => '<h2>Modal plan</h2><p><strong>Formatted</strong> description</p>',
            'priority'         => 'normal',
            'column_id'        => $state['column'],
            'swimlane_id'      => $state['lane'],
            'start_date'       => '2026-10-01',
            'due_date'         => '2026-10-03',
            'estimate_minutes' => 90,
            'spent_minutes'    => 15,
            'board_revision'   => (int) $this->form->valueOf('board_revision'),
        ];
    }

    /**
     * What the rich-text editor actually posts.
     *
     * It renames the textarea to `description_html`, so a browser running it
     * sends no plain `description` at all. The validator hands a missing field to
     * every rule as null, and null is not text -- so a rule for it that was meant
     * to be optional refused every ticket the editor tried to create, with "Must
     * be text" against a field the person never saw.
     */
    public function testTheEditorsSubmissionNeedsNoPlainDescription(): void
    {
        $draft = $this->draft();
        unset($draft['description']);

        $created = $this->post($this->alice, '/projects/1/tickets', $draft);

        $this->assertSame(200, $created['status'], $created['body']);

        $fragment = $this->page($this->alice, json_decode($created['body'], true)['url'] . '?fragment=1');
        $this->assertStringContainsString('<h2>Modal plan</h2>', $fragment, 'the rich description was lost');
    }

    /** A form without JavaScript keeps its plain field, and that has to go through too. */
    public function testAPlainDescriptionWithoutTheEditorStillCreates(): void
    {
        $draft = $this->draft();
        unset($draft['description_html']);

        $created = $this->post($this->alice, '/projects/1/tickets', $draft);

        $this->assertSame(200, $created['status'], $created['body']);
    }
}
