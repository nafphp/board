<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Assets;

use Naf\Board\Domain\Failure;
use Naf\Board\Support\Assets\AssetPublisher;
use PHPUnit\Framework\TestCase;

use function Naf\Board\extensions;

/**
 * Getting a package's stylesheets and scripts into the document root.
 *
 * They cannot be served from vendor/, so they are copied -- and a copy is a
 * second place the same file exists, which is the whole difficulty. Publishing
 * therefore keeps a record of what it wrote and what it wrote there, so it can
 * tell its own copy from one somebody has since edited by hand. A file the host
 * changed is never overwritten and never deleted.
 */
final class AssetPublishingTest extends TestCase
{
    private string $publicRoot;
    private string $manifestRoot;
    private AssetPublisher $publisher;
    private string $fileA;
    private string $fileB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publicRoot   = BASE_PATH . '/public';
        $this->manifestRoot = BASE_PATH . '/storage/plugin-assets';
        $this->publisher    = new AssetPublisher($this->publicRoot, $this->manifestRoot);
        $this->fileA        = $this->publicRoot . '/plugins/example/nafinity-extension-a/review.js';
        $this->fileB        = $this->publicRoot . '/plugins/example/nafinity-extension-b/review.css';

        // A clean start, so each test describes itself rather than its predecessor.
        $this->publisher->remove();
    }

    /**
     * naf/board is first because its index is 0: the application's own
     * stylesheets are in place before a package adds to them -- and unlike a
     * plugin's, they go to the document root rather than under plugins/.
     *
     * The order is the claim, not the membership: anything an installation has
     * brings its own files along, and naming them all here would make this test
     * fail every time somebody installs something.
     */
    public function testEveryPackageRegistersItsPublicDirectoryAfterTheBoardItself(): void
    {
        $registered = array_keys(extensions()->assetPackages()->all());

        $this->assertSame('naf/board', $registered[0] ?? null, 'the application is not first');
        $this->assertLessThan(
            array_search('example/nafinity-extension-b', $registered, true),
            array_search('example/nafinity-extension-a', $registered, true),
            'the extensions lost their order among themselves',
        );
    }

    public function testCheckReportsWhatIsMissingBeforeAnythingIsPublished(): void
    {
        $result = $this->publisher->check();

        $this->assertContains($this->fileA, $result['missing']);
        $this->assertSame([], $result['current'], 'something was reported as already in place');
    }

    public function testPublishingWritesBothPackages(): void
    {
        $result = $this->publisher->publish();

        $this->assertContains($this->fileA, $result['published'], 'extension A was not published');
        $this->assertContains($this->fileB, $result['published'], 'extension B was not published');
        $this->assertSame([], $result['conflicts']);
        $this->assertFileExists($this->fileA);
        $this->assertFileExists($this->fileB);
    }

    /**
     * Running it twice must be the same as running it once -- a deploy step that
     * rewrites every file each time changes their timestamps for nothing, and
     * would hide a conflict behind a fresh copy.
     */
    public function testPublishingTwiceWritesNothingTheSecondTime(): void
    {
        $this->publisher->publish();

        $second = $this->publisher->publish();

        $this->assertSame([], $second['published'], 'the second run wrote again');
        // Both files by name rather than a count: naf/board publishes through
        // the same registry, and so may anything else installed here.
        $this->assertContains($this->fileA, $second['unchanged']);
        $this->assertContains($this->fileB, $second['unchanged']);

        $status = $this->publisher->check();
        $this->assertSame([], $status['missing'], 'check disagrees with publish');
        $this->assertSame([], $status['stale']);
    }

    /** A package holds more than assets, and only the assets belong in public. */
    public function testOnlyDeclaredPublicFileTypesArePublished(): void
    {
        $this->publisher->publish();

        foreach (glob($this->publicRoot . '/plugins/example/*/*') ?: [] as $file) {
            $this->assertContains(
                strtolower(pathinfo($file, PATHINFO_EXTENSION)),
                AssetPublisher::EXTENSIONS,
                'published ' . $file,
            );
        }
        $this->assertFileDoesNotExist(
            $this->publicRoot . '/plugins/example/nafinity-extension-a/composer.json',
            'a manifest leaked into the document root',
        );
    }

    public function testAFileTheHostChangedIsNeitherOverwrittenNorRemoved(): void
    {
        $this->publisher->publish();
        file_put_contents($this->fileA, "// changed by hand\n");

        $conflicted = $this->publisher->publish('example/nafinity-extension-a');

        $this->assertContains($this->fileA, $conflicted['conflicts'], 'the conflict went unreported');
        $this->assertSame([], $conflicted['published'], 'it wrote over the change anyway');
        $this->assertStringContainsString('changed by hand', (string) file_get_contents($this->fileA));

        $removed = $this->publisher->remove('example/nafinity-extension-a');

        $this->assertContains($this->fileA, $removed['kept'], 'the changed file was not kept');
        $this->assertFileExists($this->fileA, 'the changed file was deleted');

        unlink($this->fileA);
    }

    /**
     * Removing has to work from the record alone, because by the time anyone
     * removes a package's files the package itself is usually already gone --
     * Composer deleted it, and only what was written down is left to go on.
     */
    public function testRemovingWorksFromTheRecordWithoutThePackage(): void
    {
        $this->publisher->publish();
        $this->assertFileExists($this->fileB, 'there is nothing to remove');

        $registry = extensions()->assetPackages();
        $package  = $registry->get('example/nafinity-extension-b');
        $registry->remove('example/nafinity-extension-b');

        try {
            $this->assertNull($registry->get('example/nafinity-extension-b'));

            $result = $this->publisher->remove('example/nafinity-extension-b');

            $this->assertContains($this->fileB, $result['removed']);
            $this->assertFileDoesNotExist($this->fileB);
            $this->assertFileDoesNotExist(
                $this->manifestRoot . '/example.nafinity-extension-b.json',
                'the record was left behind',
            );
        } finally {
            // Put it back: the registry lives in the process, and the next test
            // would otherwise inherit an installation missing a package.
            $registry->add($package);
        }
    }

    public function testAnUnknownPackageIsNamedRatherThanGuessedAt(): void
    {
        $failure = null;

        try {
            $this->publisher->publish('example/never-installed');
        } catch (Failure $caught) {
            $failure = $caught;
        }

        $this->assertNotNull($failure, 'an unknown package was accepted');
        $this->assertSame(404, $failure->status);
    }
}
