<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Auth\Exceptions\UnauthenticatedException;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\Input;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

use function Naf\Board\template;
use function Naf\json;
use function Naf\redirect;
use function Naf\request;
use function Naf\response;
use function Naf\View\render;

/**
 * How the page controllers answer: a page, a write, or a download.
 *
 * One place for the three shapes, so a refusal looks the same whichever area
 * of the board it came from.
 *
 * @internal
 */
final class Respond
{
    /**
     * Something to look at. A guest is sent to sign in; a refusal becomes the
     * error page with its own status.
     *
     * @param callable(): ResponseInterface $read
     */
    public static function read(callable $read): ResponseInterface
    {
        try {
            return $read();
        } catch (UnauthenticatedException) {
            return redirect('/login', 303);
        } catch (Failure $exception) {
            return self::error($exception);
        }
    }

    /**
     * A write, answered the way it was asked: JSON for the page's own script,
     * a redirect to the returned `url` for a plain form.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $operation receives the request body
     */
    public static function mutation(AccessInterface $access, callable $operation): ResponseInterface
    {
        $acceptsJson = str_contains(request()->getHeaderLine('Accept'), 'application/json');

        try {
            $access->actor();
            $result = $operation(Input::body());

            return $acceptsJson
                ? json($result)
                : redirect($result['url'], 303);
        } catch (Failure $exception) {
            if ($acceptsJson) {
                return json([
                    'message' => $exception->getMessage(),
                    'errors'  => $exception->errors,
                ], $exception->status);
            }

            return self::error($exception);
        }
    }

    /**
     * A file that is never shown inline and never cached.
     *
     * @param resource $stream
     */
    public static function download(mixed $stream, string $type, string $disposition): ResponseInterface
    {
        return response()
            ->withBody(Stream::create($stream))
            ->withHeader('Content-Type', $type)
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private static function error(Failure $exception): ResponseInterface
    {
        return render(template('error'), [
            'message' => $exception->getMessage(),
            'status'  => $exception->status,
        ])->withStatus($exception->status);
    }
}
