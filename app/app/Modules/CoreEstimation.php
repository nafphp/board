<?php

declare(strict_types=1);

namespace App\Modules;

use App\Domain\Estimation;
use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Definition\EstimationScale;
use Nafinity\ExtensionContext;

/**
 * The estimation scales Nafinity ships with.
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
