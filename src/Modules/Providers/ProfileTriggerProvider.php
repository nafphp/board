<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Auth\Auth;
use Naf\Board\Contracts\UiDataProviderInterface;
use Naf\Board\Support\UiContext;

use function Naf\I18n\t;

/** The account row and avatar that open the profile dialog. *
 * @internal
 */
final class ProfileTriggerProvider implements UiDataProviderInterface
{
    public function __construct(private Auth $auth)
    {
    }

    public function data(UiContext $context): array
    {
        $scope    = $context->scope;
        $roleName = $scope === null
            ? t('Persönliches Konto')
            : ($scope->roleName ?? ucfirst($scope->role));

        return [
            'profile'     => $this->auth->user()->getProfile(),
            'roleName'    => $roleName,
            'roleContext' => $scope === null
                ? $roleName
                : $roleName . ' · ' . $scope->project['name'],
        ];
    }
}
