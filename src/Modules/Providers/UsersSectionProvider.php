<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\InvitationServiceInterface;
use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Domain\Failure;
use Naf\Board\Services\BulkUpdateService;
use Naf\Board\Services\UserDirectory;
use Naf\Board\Support\UiContext;

use function Naf\I18n\t;

/** @internal */
final class UsersSectionProvider implements SettingSectionProviderInterface
{
    public function __construct(
        private InvitationServiceInterface $invitations,
        private UserDirectory $directory,
        private BulkUpdateService $bulk,
    ) {
    }

    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        try {
            $data = $this->directory->page($page['usersQuery'] ?? [], $page['projects'] ?? []);
        } catch (Failure $failure) {
            if ($failure->status !== 403) {
                throw $failure;
            }

            return ['visible' => false];
        }

        return [
            ...$data,
            'bulkProperties' => $this->bulk->properties('users'),
            'inviteTargets'  => $this->invitations->targets(),
            'description'    => t(':count Konten', ['count' => $data['total']]),
        ];
    }
}
