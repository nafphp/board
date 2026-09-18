<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * The existing personal preferences and per-project muting.
 */
interface PreferenceServiceInterface
{
    public function save(array $data): void;

    public function language(mixed $locale): void;

    public function mute(int $project, bool $muted): void;
}
