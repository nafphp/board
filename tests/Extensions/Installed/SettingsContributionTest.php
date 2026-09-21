<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\Board\extensions;
use function Naf\Board\settings;

/**
 * Settings an extension declares.
 *
 * They behave like the application's own: a declared default, a value that can
 * be written, and a scope that decides who may do what. Reading a project value
 * needs membership; writing one needs the right the extension itself declared --
 * being the owner of the project is not the same as being entitled to the thing
 * an extension put there.
 *
 * The three scopes stay apart. A project value must not appear among a person's
 * own, or the same key would mean two things depending on where it was read.
 */
final class SettingsContributionTest extends ExtensionInstalledTestCase
{
    public function testAPersonalSettingHasItsDeclaredDefaultAndCanBeWritten(): void
    {
        // The host is shared across this suite and nothing here is rolled back,
        // so a test about the default starts by making sure there is none stored.
        settings()->save([], ['example.reports.compact']);

        $this->assertTrue(settings()->has('example.reports.compact'), 'the setting was not declared');
        $this->assertFalse(settings()->get('example.reports.compact'), 'the declared default was lost');

        settings()->save(['example.reports.compact' => true]);

        $this->assertTrue(settings()->get('example.reports.compact'));
    }

    public function testAProjectValueIsReadableByAMemberAndWritableOnlyWithTheRight(): void
    {
        $this->assertSame(25, settings()->forProject($this->project)->get('example.reports.limit'));

        // Alice owns the project and was never given the extension's own right.
        $this->assertDenied(
            403,
            fn() => settings()->forProject($this->project)->save(['example.reports.limit' => 50]),
        );
    }

    public function testAMultiselectDeclaresAListAsItsDefault(): void
    {
        $definition = extensions()->settings()->find('project', 'example.reports.columns');

        $this->assertNotNull($definition);
        $this->assertSame(['title', 'assignee'], $definition->default);
        $this->assertSame(
            ['title', 'assignee'],
            settings()->forProject($this->project)->get('example.reports.columns'),
            'the default was not read back',
        );
    }

    public function testWritingAMultiselectNeedsTheSameRightAsAnyOtherProjectValue(): void
    {
        $this->assertDenied(
            403,
            fn() => settings()->forProject($this->project)->save(['example.reports.columns' => ['due']]),
        );
    }

    /** B moved A's field onto its own card, without taking the field away. */
    public function testExtensionBMovedOneFieldToItsOwnCard(): void
    {
        $definition = extensions()->settings()->find('project', 'example.reports.limit');

        $this->assertNotNull($definition, 'the definition was lost in the move');
        $this->assertSame('example.review', $definition->section);
    }

    public function testValuesPresenceAndTheSnapshotAllAgree(): void
    {
        settings()->save(['example.reports.compact' => true]);

        $all = settings()->all();

        $this->assertArrayHasKey('theme', $all, 'a core value is missing from all()');
        $this->assertTrue($all['example.reports.compact'], 'the contributed value is missing from all()');
        $this->assertSame($all, settings()->collection()->all(), 'the snapshot differs from the values');
    }

    /** A snapshot is a copy to read, not a second way to write. */
    public function testWritingToTheSnapshotChangesNothing(): void
    {
        settings()->save(['example.reports.compact' => true]);

        settings()->collection()->add('example.reports.compact', false);

        $this->assertTrue(settings()->get('example.reports.compact'), 'the snapshot wrote through');
    }

    public function testAProjectValueDoesNotAppearAmongAPersonsOwn(): void
    {
        $this->assertArrayNotHasKey('example.reports.limit', settings()->all());
    }

    public function testTheApplicationScopeIsItsOwn(): void
    {
        $this->assertFalse(settings()->forApplication()->get('mail_enabled'));
    }

    public function testAStrangerReadsNothingOfAProject(): void
    {
        $this->actAs('stranger');

        $this->assertDenied(404, fn() => settings()->forProject($this->project)->all());
    }
}
