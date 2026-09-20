<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Support\UiContext;
use PDO;

use function Naf\app;
use function Naf\I18n\t;
use function Naf\Rbac\rbac;

/**
 * Who has an account here, and what each of them holds.
 *
 * The list is the host's: naf/rbac never learns who exists. What it does answer
 * is the second column, so the card shows the two together and the editor for
 * one person opens where they stand.
 *
 * @internal
 */
final class UsersSectionProvider implements SettingSectionProviderInterface
{
    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        $rows = app()->container()->get(PDO::class)
            ->query('SELECT id, name, email, active FROM users ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC);

        $rbac   = rbac();
        $people = array_map(
            static fn(array $row) => [
                'id'     => (int) $row['id'],
                'name'   => (string) $row['name'],
                'email'  => (string) $row['email'],
                'active' => (int) $row['active'] === 1,
                'roles'  => $rbac->assignments->grantsOf((int) $row['id']),
            ],
            $rows,
        );

        return [
            'people'      => $people,
            'description' => t(':count Konten', ['count' => count($people)]),
        ];
    }
}
