<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\InvitationServiceInterface;
use Naf\Board\Contracts\SettingSectionProviderInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Rbac\Project;
use Naf\Board\Support\UiContext;
use Naf\Rbac\Scope;
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
    public function __construct(private InvitationServiceInterface $invitations)
    {
    }

    public function data(SettingSection $section, UiContext $context, array $page): array
    {
        $rows = app()->container()->get(PDO::class)
            ->query('SELECT id, name, email, active FROM users ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC);

        $rbac        = rbac();
        $scopeLabels = $this->scopeLabels($page['projects'] ?? []);
        $people      = array_map(
            static fn(array $row) => [
                'id'     => (int) $row['id'],
                'name'   => (string) $row['name'],
                'email'  => (string) $row['email'],
                'active' => (int) $row['active'] === 1,
                'roles'  => array_map(
                    static fn(array $grant) => [
                        ...$grant,
                        'scopeLabel' => $scopeLabels[$grant['scope']] ?? t('Nicht verfügbar'),
                    ],
                    $rbac->assignments->grantsOf((int) $row['id']),
                ),
            ],
            $rows,
        );

        return [
            'people'        => $people,
            'inviteTargets' => $this->invitations->targets(),
            'description'   => t(':count Konten', ['count' => count($people)]),
        ];
    }

    /** @return array<string, string> */
    private function scopeLabels(array $projects): array
    {
        $labels = [
            ''                                    => t('Installation'),
            (string) Scope::allOf(Project::SCOPE) => t('Alle Projekte'),
        ];

        // The authorized list includes archived boards, which can still hold grants.
        foreach ($projects as $project) {
            $labels[(string) Scope::of(Project::SCOPE, $project['id'])] = $project['name'];
        }

        foreach (rbac()->declared->scopes() as $type => $source) {
            if ($type === Project::SCOPE) {
                continue;
            }
            $labels[(string) Scope::allOf($type)] = t('Auf allen') . ' · ' . t($source->label());
            foreach ($source->instances() as $id => $label) {
                $labels[(string) Scope::of($type, $id)] = $label;
            }
        }

        return $labels;
    }
}
