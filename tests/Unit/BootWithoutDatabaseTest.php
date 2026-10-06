<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Booting the board must not need a database.
 *
 * A production image publishes the assets while it is built, with no database
 * anywhere, and any command that does not touch data should run the same way.
 * Run in a process of its own, against a host name that cannot resolve.
 */
final class BootWithoutDatabaseTest extends TestCase
{
    public function testTheBoardBootsAndRegistersItsAssetsWithoutAConnection(): void
    {
        $root   = dirname(__DIR__, 2);
        $script = <<<'PHP'
        define('BASE_PATH', $argv[1] . '/tests/Fixtures');
        require $argv[1] . '/vendor/autoload.php';
        Naf\app();
        echo count(Naf\Board\extensions()->assetPackages()->all());
        PHP;

        $environment = [
            ...getenv(),
            'APP_ENV'     => 'prod',
            'DB_HOST'     => 'no-database.invalid',
            'DB_DATABASE' => 'unused',
        ];
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
        );
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), $output . $errors);
        $this->assertGreaterThan(0, (int) $output, 'the board registered no asset package');
    }
}
