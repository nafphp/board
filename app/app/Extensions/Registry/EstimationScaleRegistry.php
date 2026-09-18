<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\EstimationScale;

/**
 * The estimation scales a project can choose from.
 */
final class EstimationScaleRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(EstimationScale::class, 'Estimation scale');
    }

    public function get(string $id): ?EstimationScale
    {
        return parent::get($id);
    }
}
