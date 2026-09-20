<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Naf\Board\Domain\Failure;
use Throwable;

/**
 * A rejection is a result, not an accident.
 *
 * The board answers an unauthorised or malformed request by throwing a Failure
 * that carries the HTTP status it would be served with, so "this is refused, and
 * refused with 403 rather than 404" is one assertion. Getting the wrong status is
 * a real defect: 404 where 403 belongs hides a resource, 403 where 404 belongs
 * reveals that it exists.
 */
trait AssertsFailures
{
    /**
     * Assert that $call is refused with exactly $status.
     *
     * @param callable():mixed $call
     */
    protected function assertDenied(int $status, callable $call, string $message = ''): Failure
    {
        $context = $message === '' ? '' : ' (' . $message . ')';

        try {
            $call();
        } catch (Failure $failure) {
            $this->assertSame(
                $status,
                $failure->status,
                'Refused with the wrong status' . $context . ': ' . $failure->getMessage(),
            );

            return $failure;
        } catch (Throwable $other) {
            $this->fail(
                'Expected a Failure with status ' . $status . $context
                . ', got ' . $other::class . ': ' . $other->getMessage(),
            );
        }

        $this->fail('Expected a rejection with status ' . $status . $context . ', nothing was thrown.');
    }
}
