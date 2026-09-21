<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Without;

use Naf\Board\Tests\Support\ExtensionHostTestCase;
use PDO;

use function Naf\Board\extensions;
use function Naf\Board\settings;

/**
 * The same installation, booted without the two example extensions.
 *
 * Uninstalling a package takes its contributions away. It must not take away
 * what people stored while it was there: the values, the grants and the history
 * stay, and come back when the package does. Anything else would make removing
 * an extension for an afternoon a way to lose a year of work.
 *
 * Meanwhile nothing of what is left may act: a permission nobody defines any
 * more must not authorize, and a value nobody can interpret must not be shown.
 */
final class UninstalledExtensionTest extends ExtensionHostTestCase
{
    public function testNothingOfTheExtensionsIsRegisteredAnyMore(): void
    {
        $this->assertSame([], extensions()->executed(), 'a provider still ran');
        $this->assertFalse(extensions()->permissions()->has('example.reports.view'));
        $this->assertNull(extensions()->ui()->get('example.reports.widget'));
        $this->assertNull(extensions()->boardFilters()->get('example.reviewed'));
        $this->assertNull(extensions()->settings()->find('project', 'example.reports.limit'));
    }

    public function testWhatWasStoredIsStillStored(): void
    {
        $keys = $this->pdo->prepare(
            'SELECT meta_key FROM ticket_metadata WHERE project_id=? AND ticket_id=?',
        );
        $keys->execute([$this->project, $this->ticket]);

        $this->assertContains('example.reviewed', $keys->fetchAll(PDO::FETCH_COLUMN), 'the metadata was deleted');
        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM project_role_permissions WHERE permission='example.reports.view'"),
            'the grant was deleted',
        );
        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM user_settings WHERE setting_key='example.reports.compact'"),
            'the personal value was deleted',
        );
    }

    /**
     * Its table stands too. A package's migrations are not rolled back when it
     * is removed, because that would delete the rows the package put there --
     * and Composer removing a directory is not a decision to discard data.
     */
    public function testTheExtensionsOwnTableIsStillThere(): void
    {
        $this->assertNotFalse(
            $this->pdo->query('SELECT COUNT(*) FROM example_report_runs')->fetchColumn(),
        );
    }

    public function testAPermissionNobodyDefinesDoesNotAuthorize(): void
    {
        $scope = $this->access->project($this->project);

        $this->assertFalse($scope->allows('example.reports.view'));
    }

    public function testAValueNobodyCanInterpretIsNotHandedOut(): void
    {
        $this->assertNull($this->metadata->get($this->project, $this->ticket, 'example.reviewed'));
        $this->assertSame([], $this->metadata->all($this->project, $this->ticket));
    }

    /**
     * The ticket says something is missing without saying what it was. The
     * distinction matters: "this ticket has values from an extension you do not
     * have" is useful, showing the raw values is not -- nobody left can read
     * them, and they may be meaningless without the code that wrote them.
     */
    public function testTheTicketReportsWhatIsMissingWithoutShowingIt(): void
    {
        $detail = $this->query->detail($this->project, $this->ticket);

        $this->assertSame([], $detail['metadata'], 'raw values were handed out');
        $this->assertSame([], $detail['metaDefinitions']);
        $this->assertContains('example.reviewed', $detail['metaUnknown'], 'the missing key was not reported');
    }

    public function testAnOrdinaryTicketEditLeavesTheUnknownValuesAlone(): void
    {
        $before = (int) $this->scalar('SELECT COUNT(*) FROM ticket_metadata');
        $row    = $this->tickets->ticket($this->project, $this->ticket);

        $this->tickets->update($this->project, $this->ticket, [
            'title'          => 'Renamed without the extension',
            'version'        => $row['version'],
            'board_revision' => $this->tickets->board($this->project)['revision'],
        ]);

        $this->assertSame($before, (int) $this->scalar('SELECT COUNT(*) FROM ticket_metadata'));
    }

    public function testAPersonalValueOfAMissingExtensionIsNeitherReadNorLost(): void
    {
        $this->assertFalse(settings()->has('example.reports.compact'), 'a missing key is still readable');
        $this->assertArrayNotHasKey('example.reports.compact', settings()->all());

        settings()->save(['theme' => 'dark']);

        $this->assertSame(
            1,
            (int) $this->scalar("SELECT COUNT(*) FROM user_settings WHERE setting_key='example.reports.compact'"),
            'an unrelated save deleted it',
        );
    }
}
