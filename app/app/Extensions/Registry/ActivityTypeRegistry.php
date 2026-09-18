<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use Nafinity\Definition\ActivityType;

/**
 * Readable presentations for recorded change types.
 */
final class ActivityTypeRegistry extends DefinitionRegistry
{
    public function __construct()
    {
        parent::__construct(ActivityType::class, 'Activity type');
    }

    public function get(string $id): ?ActivityType
    {
        return parent::get($id);
    }
}
