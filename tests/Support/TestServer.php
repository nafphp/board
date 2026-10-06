<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use RuntimeException;

/**
 * The application answering over HTTP, for the tests that go through the front
 * controller: PHP's built-in server in front of tests/Fixtures/public.
 *
 * Started once per process, on first use, and stopped when the process ends.
 * It inherits this process's environment, so it reaches the same test database.
 */
final class TestServer
{
    /** @var resource|null */
    private static $process = null;

    /** Where the server answers, starting it on first use. */
    public static function url(): string
    {
        $url = (string) getenv('APP_URL');

        if (self::$process === null) {
            self::start(parse_url($url, PHP_URL_HOST), (int) parse_url($url, PHP_URL_PORT));
        }

        return $url;
    }

    private static function start(string $host, int $port): void
    {
        $public = BASE_PATH . '/public';
        $log    = BASE_PATH . '/storage/logs/server.log';

        // Several workers, because a few tests are about two requests arriving
        // at once, and a single-threaded server would answer them one by one.
        $environment = [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '4'];

        self::$process = proc_open(
            [PHP_BINARY, '-S', $host . ':' . $port, '-t', $public, $public . '/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            $environment,
        );

        register_shutdown_function(static function (): void {
            if (!is_resource(self::$process)) {
                return;
            }
            // The workers first: they are the server's children, and stopping
            // only the parent would leave them answering on the port.
            exec('pkill -TERM -P ' . proc_get_status(self::$process)['pid']);
            proc_terminate(self::$process);
            proc_close(self::$process);
        });

        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $socket = @fsockopen($host, $port, $code, $message, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }

        throw new RuntimeException('The test server did not start on ' . $host . ':' . $port . '; see ' . $log . '.');
    }
}
