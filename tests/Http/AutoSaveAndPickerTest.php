<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * How the ticket page saves, and how it lets people choose.
 *
 * Every inline editor saves as it is changed and carries the version it was
 * rendered with, so two people editing different fields do not overwrite each
 * other -- and none of them has a save button, because there is nothing to
 * press. Publishing a comment is the exception: that is a decision, not an edit.
 *
 * Every select is the same picker, in the detail view and in the draft, and it
 * keeps the native checkboxes underneath so a form without JavaScript still
 * submits what was ticked -- including nothing, which has to be sendable too.
 */
final class AutoSaveAndPickerTest extends AcceptanceTestCase
{
    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->url = $this->createTicket();
    }

    /**
     * The count is not asserted: a field added tomorrow would fail a number
     * without anything being wrong. What every one of them must carry is.
     */
    public function testEveryInlineEditorSavesUnderTheOptimisticLockAndHasNoButton(): void
    {
        $forms = $this->autoSaveForms($this->page($this->alice, $this->url . '?fragment=1'));

        $this->assertGreaterThanOrEqual(13, count($forms), 'inline editors have gone missing');
        foreach ($forms as $form) {
            $this->assertStringContainsString('name="version"', $form, 'an editor saves without a version');
            $this->assertStringContainsString('name="board_revision"', $form);
            $this->assertStringNotContainsString('type="submit"', $form, 'an editor grew a save button');
        }
    }

    public function testTheTitleEditorCanWrap(): void
    {
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*name="title"[^>]*maxlength="200"/',
            $this->page($this->alice, $this->url . '?fragment=1'),
        );
    }

    public function testPublishingACommentIsNeverAutomatic(): void
    {
        $markup = $this->page($this->alice, $this->url . '?fragment=1');

        preg_match_all('#<form[^>]*data-comment-composer[^>]*>.*?</form>#s', $markup, $composers);
        $this->assertNotEmpty($composers[0], 'there is no comment composer to check');
        foreach ($composers[0] as $composer) {
            $this->assertStringNotContainsString('data-auto-save', $composer);
        }
    }

    public function testTheSamePickerIsUsedInTheDetailViewAndTheDraft(): void
    {
        $detail = $this->page($this->alice, $this->url . '?fragment=1');
        $draft  = $this->page($this->alice, '/projects/1/tickets/new?fragment=1');

        foreach ([$detail, $draft] as $markup) {
            $this->assertStringContainsString('data-choice="column_id"', $markup);
            $this->assertStringContainsString('data-choice="assignee_ids"', $markup);
        }
        $this->assertStringContainsString('aria-multiselectable="true"', $detail, 'people cannot be multi-selected');
    }

    public function testEveryPickerBringsItsOwnMenu(): void
    {
        $detail = $this->page($this->alice, $this->url . '?fragment=1');

        $pickers = substr_count($detail, 'data-choice=');
        $this->assertGreaterThanOrEqual(2, $pickers);
        $this->assertSame(
            $pickers,
            substr_count($detail, 'popover="manual"'),
            'a picker was rendered without the menu it opens',
        );
    }

    /**
     * The person filter names you, and names you first.
     *
     * "Was liegt bei mir?" is what this filter is asked most often, and finding
     * your own name in an alphabetical list of colleagues is a poor way to answer
     * it. Only the label and the order change: the option's value stays the plain
     * user id, so a shared link still means the same thing to whoever opens it.
     */
    public function testThePersonFilterPutsYouFirstAndSaysSo(): void
    {
        $board = $this->page($this->alice, '/projects/' . self::PROJECT);

        preg_match('/<select[^>]*name="assignee"[^>]*>(.*?)<\/select>/s', $board, $select);
        $this->assertNotEmpty($select, 'the person filter was not rendered');

        preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>([^<]*)</', $select[1], $options, PREG_SET_ORDER);
        $labels = array_map(static fn(array $o) => trim($o[2]), $options);

        $this->assertSame('Alle Verantwortlichen', $labels[0]);
        $this->assertStringStartsWith('Ich (', $labels[1], 'your own entry is not the first person offered');
        $this->assertSame('1', $options[1][1], 'the option carries something other than the plain user id');
        $this->assertSame(
            1,
            count(array_filter($labels, static fn(string $l) => str_starts_with($l, 'Ich ('))),
            'the current user appears twice: once named and once as themselves',
        );
    }

    /** However short the list, these two stay searchable. */
    public function testColumnAndPeopleAreAlwaysSearchable(): void
    {
        $this->assertSame(
            2,
            substr_count($this->page($this->alice, $this->url . '?fragment=1'), 'data-choice-search="0"'),
        );
    }

    public function testThePickerSubmitsSeveralPeopleAndCanClearThemAgain(): void
    {
        $form   = $this->assigneeForm($this->page($this->alice, $this->url . '?fragment=1'));
        $action = $this->firstMatch('/action="([^"]+)"/', $form, 'the assignee form action');

        preg_match_all('/name="assignee_ids\[\]" value="([^"]+)"/', $form, $people);
        $chosen = array_slice($people[1], 0, 2);
        $this->assertCount(2, $chosen, 'the project needs two active members for this');

        $assigned = $this->submitAssignees($action, $form, $chosen);
        $this->assertSame(200, $assigned['status']);

        $updated = $this->page($this->alice, $this->url . '?fragment=1');
        foreach ($chosen as $person) {
            $this->assertMatchesRegularExpression(
                '/name="assignee_ids\[\]" value="' . preg_quote($person, '/') . '" checked/',
                $updated,
                'an assignment was not kept',
            );
        }
        $this->assertStringContainsString('class="choice-count"', $updated, 'the compact count is missing');

        // And nothing: the empty clear field alone, which is all a form with
        // nothing ticked sends.
        $cleared = $this->submitAssignees($action, $this->assigneeForm($updated), []);
        $this->assertSame(200, $cleared['status']);
        $this->assertDoesNotMatchRegularExpression(
            '/name="assignee_ids\[\]"[^>]* checked/',
            $this->page($this->alice, $this->url . '?fragment=1'),
            'the assignments survived being cleared',
        );
    }

    /** @param list<string> $people */
    private function submitAssignees(string $action, string $form, array $people): array
    {
        $fields = [];
        foreach (['_csrf', 'version', 'board_revision'] as $name) {
            $fields[] = [$name, $this->firstMatch('/name="' . $name . '" value="([^"]+)"/', $form, $name)];
        }
        $fields[] = ['assignee_ids', ''];
        foreach ($people as $person) {
            $fields[] = ['assignee_ids[]', $person];
        }
        $body = implode('&', array_map(
            static fn(array $pair) => urlencode($pair[0]) . '=' . urlencode($pair[1]),
            $fields,
        ));

        return $this->alice->request($action, $body, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
    }

    /** @return list<string> */
    private function autoSaveForms(string $markup): array
    {
        preg_match_all('#<form[^>]*data-auto-save[^>]*>.*?</form>#s', $markup, $found);

        return $found[0];
    }

    private function assigneeForm(string $markup): string
    {
        foreach ($this->autoSaveForms($markup) as $form) {
            if (str_contains($form, 'data-choice="assignee_ids"')) {
                return $form;
            }
        }

        $this->fail('The page has no assignee picker.');
    }
}
