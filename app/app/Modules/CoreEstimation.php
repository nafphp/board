<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\EstimationScale;
use Naf\Board\Domain\Estimation;
use Naf\Board\ExtensionContext;

/**
 * The estimation scales Nafinity ships with.
 *
 * @internal
 */
final class CoreEstimation implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $scales = $context->estimationScales();
        $index  = 100;

        foreach (Estimation::SCALES as $id => $values) {
            $scales->add(new EstimationScale(
                $id,
                Estimation::LABELS[$id],
                Estimation::UNITS[$id] ?? '',
                $values,
                $index,
            ));
            $index += 100;
        }
    }
}
