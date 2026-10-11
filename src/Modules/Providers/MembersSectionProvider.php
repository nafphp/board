<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\InvitationServiceInterface;
use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Support\UiContext;

/** The first few member names, as the card has always shown them. *
 * @internal
 */
final class MembersSectionProvider implements SettingSectionProviderInterface
{
    public function __construct(private InvitationServiceInterface $invitations)
    {
    }

    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        return ['description' => Names::of($page['members'] ?? []), 'invitations' => $this->invitations->pending((int) $page['projectId'])];
    }
}
