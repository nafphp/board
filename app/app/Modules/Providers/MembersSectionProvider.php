<?php

declare(strict_types=1);

namespace App\Modules\Providers;

use Nafinity\Contracts\SettingSectionProviderInterface;
use Nafinity\Definition\SettingSection;
use Nafinity\Support\UiContext;

/** The first few member names, as the card has always shown them. */
final class MembersSectionProvider implements SettingSectionProviderInterface
{
    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        return ['description' => Names::of($page['members'] ?? [])];
    }
}
