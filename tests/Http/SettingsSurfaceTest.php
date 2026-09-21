<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The settings page shows what the person may actually do.
 *
 * Hiding a card is not the protection -- the endpoints refuse regardless -- but
 * offering one that will be refused is a promise the application then breaks.
 */
final class SettingsSurfaceTest extends AcceptanceTestCase
{
    /** What a project is: everything that belongs to it, and nothing else. */
    private const array PROJECT_CARDS = [
        'general',
        'roles',
        'users',
        'column',
        'swimlane',
        'label',
        'project_personal',
    ];

    /** What an account is: what holds for one person, in every project. */
    private const array ACCOUNT_CARDS = ['personal', 'ai'];

    /**
     * @return array<string,array{string}>
     */
    public static function ownerCards(): array
    {
        return array_combine(
            self::PROJECT_CARDS,
            array_map(static fn(string $card) => [$card], self::PROJECT_CARDS),
        );
    }

    #[DataProvider('ownerCards')]
    public function testTheOwnerIsOfferedEveryCard(string $card): void
    {
        $this->assertStringContainsString(
            'data-settings-open="' . $card . '"',
            $this->page($this->alice, '/projects/' . self::PROJECT . '/settings'),
        );
    }

    /**
     * The two are separate places, and each shows only its own.
     *
     * They used to be one list: every project repeated the account cards, so
     * there was no single place the account lived and the sidebar's one gear
     * meant whichever of the two you happened to be nearest.
     */
    public function testTheAccountCardsAreNotRepeatedInsideAProject(): void
    {
        $project = $this->page($this->alice, '/projects/' . self::PROJECT . '/settings');
        $account = $this->page($this->alice, '/preferences');

        foreach (self::ACCOUNT_CARDS as $card) {
            $this->assertStringNotContainsString(
                'data-settings-open="' . $card . '"',
                $project,
                sprintf('the account card "%s" is offered inside a project', $card),
            );
            $this->assertStringContainsString(
                'data-settings-open="' . $card . '"',
                $account,
                sprintf('the account card "%s" is nowhere', $card),
            );
        }

        foreach (self::PROJECT_CARDS as $card) {
            $this->assertStringNotContainsString(
                'data-settings-open="' . $card . '"',
                $account,
                sprintf('the project card "%s" is offered outside a project', $card),
            );
        }
    }

    /** The settings are a view of the project, reached and left through its tabs. */
    public function testTheProjectSettingsKeepTheProjectAroundThem(): void
    {
        $settings = $this->page($this->alice, '/projects/' . self::PROJECT . '/settings');

        $this->assertStringContainsString('<nav class="tabs"', $settings, 'the project views are gone');
        $this->assertMatchesRegularExpression(
            '#<a\s[^>]*class="active"[^>]*href="[^"]*/projects/' . self::PROJECT . '/settings"#',
            $settings,
            'the settings tab does not show as the one being viewed',
        );
    }

    public function testAViewerSeesTheRolesWithoutTheMemberAdministration(): void
    {
        $settings = $this->page($this->viewer, '/projects/' . self::PROJECT . '/settings');

        $this->assertStringContainsString(
            'data-settings-open="roles"',
            $settings,
            'a viewer cannot see what their role means',
        );
        $this->assertStringNotContainsString(
            'data-settings-open="users"',
            $settings,
            'a viewer was offered the member administration',
        );
    }

    /**
     * A card that is offered and shows nothing is the failure this covers.
     *
     * The settings page hands every card a fixed set of keys, and the three
     * structure cards read theirs out of it. When a key is not in that set the
     * card still renders, still opens, and simply says nothing is there -- which
     * is indistinguishable from a project that really has no columns.
     */
    public function testTheStructureCardsListWhatTheProjectHas(): void
    {
        $settings = $this->page($this->alice, '/projects/' . self::PROJECT . '/settings');

        foreach (['column', 'swimlane', 'label'] as $kind) {
            $this->assertMatchesRegularExpression(
                '/id="structure-' . $kind . '-\d+"/',
                $settings,
                sprintf('the %s card offered no entry to edit', $kind),
            );
        }

        foreach (['Offen', 'In Arbeit', 'Review', 'Erledigt'] as $column) {
            $this->assertStringContainsString(
                '<span class="settings-row-title">' . $column . '</span>',
                $settings,
                sprintf('the column "%s" is missing from the card that edits columns', $column),
            );
        }
    }

    /**
     * A person's own settings live behind their avatar, on every page.
     *
     * They used to be a page of their own, reached from a sidebar gear that
     * meant the project's settings while you stood in a project and the
     * account's while you did not -- one word for two places. The word now
     * means the installation, and the account went where the account already
     * was.
     */
    public function testTheOwnSettingsAreInTheAccountModalOnAnyPage(): void
    {
        $anywhere = $this->page($this->alice, '/projects');

        $this->assertStringContainsString('name="locale"', $anywhere, 'the language picker is gone');
        $this->assertStringContainsString('data-ai-settings', $anywhere, 'the assistant is gone');
        $this->assertStringContainsString(
            'data-profile-form="password"',
            $anywhere,
            'the account sections they sit among are gone',
        );
    }

    /** One gear, one meaning: what holds for everyone, for whoever may change it. */
    public function testTheSidebarOffersTheInstallationOnlyToWhoMayChangeIt(): void
    {
        $this->assertStringContainsString(
            'href="/settings"',
            $this->page($this->alice, '/projects'),
            'an administrator is not offered the installation settings',
        );
        $this->assertStringNotContainsString(
            'href="/settings"',
            $this->page($this->viewer, '/projects'),
            'somebody who may not change them is offered them anyway',
        );
    }

    public function testThePersonalSettingsIncludeTheLocalAssistant(): void
    {
        $this->assertStringContainsString('Lokale AI', $this->page($this->alice, '/preferences'));
    }

    /**
     * The personal panel lists roles, not reach.
     *
     * Alice administers every board, so the sidebar offers her Bob's project
     * too. "Deine Projektrollen" answers a different question -- what she is on
     * a board -- and on one she was never added to the answer is nothing. Both
     * in one column made an installation-wide role read as a project one.
     */
    public function testThePersonalPanelListsOnlyBoardsWithARoleInThem(): void
    {
        $page = $this->page($this->alice, '/projects/' . self::PROJECT);

        preg_match('/<section class="profile-memberships".*?<\/section>/s', $page, $panel);
        $this->assertNotEmpty($panel, 'the roles panel was not rendered');

        $this->assertStringContainsString('/projects/' . self::PROJECT, $panel[0]);
        $this->assertStringNotContainsString(
            '/projects/' . self::OTHER_PROJECT,
            $panel[0],
            'a board she only administers was listed as a role she holds',
        );
        $this->assertStringNotContainsString(
            'Administrator',
            $panel[0],
            'an installation-wide role was listed among the project ones',
        );
    }

    /**
     * How you want the interface to look has nothing to do with whether anybody
     * has put you on a board, so those two sections do not hang off the list of
     * projects the way they used to.
     */
    public function testTheOwnSettingsAreThereWhateverTheProjectsSay(): void
    {
        $page     = $this->page($this->alice, '/projects/' . self::PROJECT);
        $body     = substr($page, (int) strpos($page, 'class="profile-body"'));
        $panelEnd = (int) strpos($body, '</section>');

        foreach (['Darstellung &amp; Sprache', 'Lokale AI'] as $section) {
            $at = strpos($body, $section);
            $this->assertNotFalse($at, "the $section section is missing");
            $this->assertGreaterThan(
                $panelEnd,
                $at,
                "the $section section sits inside the project list and disappears with it",
            );
        }
    }

    /**
     * The two numbers on the sidebar card come from one list.
     *
     * The held count used to be every permission the person has anywhere. Once
     * administrators started carrying `rbac.all` that included the
     * installation's own, and against a total of only the board's it read
     * "20 von 11" -- a fraction larger than one, which is the tell that the
     * numerator and the denominator were counting different things.
     */
    public function testTheRightsCardCountsBothNumbersFromTheSameList(): void
    {
        $page = $this->page($this->alice, '/projects/' . self::PROJECT);

        $this->assertSame(
            1,
            preg_match('/(\d+) von (\d+) Rechten/u', $page, $found),
            'the rights card was not rendered',
        );

        [, $held, $total] = $found;
        $this->assertGreaterThan(0, (int) $total);
        $this->assertLessThanOrEqual(
            (int) $total,
            (int) $held,
            'somebody holds more board rights than the board has',
        );
        $this->assertSame($total, $held, 'an owner who administers everything holds all of them');
    }
}
