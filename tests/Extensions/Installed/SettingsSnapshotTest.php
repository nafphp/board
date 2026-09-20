<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Tests\Support\ExtensionInstalledTestCase;
use Naf\Support\Collection;

use function Naf\Board\extensions;
use function Naf\Board\settings;

/**
 * The snapshot a view is handed is NAF's own collection, not a type of this
 * package's -- an extension writing a template already knows how to read one.
 *
 * It is a copy: writing to it changes what this render sees and nothing else.
 */
final class SettingsSnapshotTest extends ExtensionInstalledTestCase
{
    public function testTheSnapshotIsTheFrameworksOwnCollection(): void
    {
        $snapshot = settings()->collection();

        $this->assertInstanceOf(Collection::class, $snapshot);
        $this->assertSame(settings()->all(), $snapshot->all());
    }

    public function testWritingToTheSnapshotDoesNotStoreAnything(): void
    {
        $snapshot = settings()->collection();

        $snapshot->add('timezone', 'Antarctica/Troll');

        $this->assertNotSame('Antarctica/Troll', settings()->get('timezone'), 'the snapshot wrote through');
    }

    /** The collection reads absent as null; the settings API has its own presence check. */
    public function testTheCollectionsOwnDefaultStillWorks(): void
    {
        $this->assertSame('fallback', settings()->collection()->get('example.never', 'fallback'));
    }

    /**
     * The local assistant's settings stay in the browser: its address and model
     * are the person's, not the installation's, and a server that stored them
     * would be keeping something it never needs.
     */
    public function testNoLocalAiValueIsDeclaredAsAServerSetting(): void
    {
        foreach (array_keys(extensions()->settings()->forScope('user')) as $key) {
            $this->assertStringNotContainsString('ai.', $key, 'an AI value became a server setting');
            $this->assertStringNotContainsString('model', $key, 'an AI value became a server setting');
        }
    }

    public function testTheAiCardKeepsItsOwnForm(): void
    {
        $this->assertSame('settings/ai', extensions()->settingSections()->get('ai')?->template);
    }
}
