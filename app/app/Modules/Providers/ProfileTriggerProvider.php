<?php

declare(strict_types=1);

namespace App\Modules\Providers;

use Naf\Auth\Auth;
use Nafinity\Contracts\UiDataProviderInterface;
use Nafinity\Support\UiContext;

use function Naf\I18n\t;

/** The account row and avatar that open the profile dialog. */
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
