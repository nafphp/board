<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the web server must never hand out.
 *
 * The document root holds the entry point and the published assets. Everything
 * else -- the environment file, the dependency manifest, the autoloader, the
 * stored files -- is on the same disk and must have no address at all. This is
 * checked from outside because it is nginx's answer that matters, not PHP's.
 */
final class WebrootTest extends AcceptanceTestCase
{
    /**
     * @return array<string,array{string}>
     */
    public static function privatePaths(): array
    {
        return [
            'stored attachments' => ['/storage/attachments/test'],
            'the environment'    => ['/.env'],
            'the manifest'       => ['/composer.json'],
            'the autoloader'     => ['/vendor/autoload.php'],
        ];
    }

    #[DataProvider('privatePaths')]
    public function testAPrivatePathHasNoAddress(string $path): void
    {
        $this->assertContains(
            $this->alice->request($path)['status'],
            [403, 404],
            $path . ' is served to anyone who asks',
        );
    }
}
