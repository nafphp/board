<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use Nafinity\Support\UiContext;

/**
 * Supplies the template data of one UI contribution.
 *
 * Providers run only after the permission and mode filters have passed, and
 * they receive nothing but the authorized context.
 */
interface UiDataProviderInterface
{
    /**
     * @param UiContext $context The authorized rendering context
     *
     * @return array Template variables for the contribution
     */
    public function data(UiContext $context): array;
}
