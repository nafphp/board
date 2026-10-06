<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    /** @return array<string,array{string,string}> */
    public static function versions(): array
    {
        return [
            'a tag from Packagist'      => ['v0.1.3', 'v0.1.3'],
            'a path repository alias'   => ['0.1.3-dev', 'v0.1.3-dev'],
            'a plain release number'    => ['1.2.0', 'v1.2.0'],
            'a branch stays as it is'   => ['dev-main', 'dev-main'],
            'nothing known shows empty' => ['', ''],
        ];
    }

    #[DataProvider('versions')]
    public function testTheLabelCarriesExactlyOneV(string $installed, string $shown): void
    {
        $this->assertSame($shown, Version::label($installed));
    }
}
