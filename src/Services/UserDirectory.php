<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Rbac\Installation;
use Naf\Board\Rbac\Project;
use Naf\Rbac\Scope;
use PDO;

use function Naf\I18n\t;
use function Naf\Rbac\rbac;

/** Bounded, authorized account reads, without eagerly rendering editors. @internal */
final class UserDirectory
{
    public function __construct(private PDO $pdo, private AccessInterface $access)
    {
    }

    public function authorize(): int
    {
        $actor = $this->access->actor();
        foreach ([Installation::MANAGE_SETTINGS, Installation::VIEW_USERS] as $permission) {
            if (!rbac()->allows($actor, $permission)) {
                throw new Failure(t('Du hast für diese Aktion keine Berechtigung.'), 403);
            }
        }

        return $actor;
    }

    public function page(array $query, array $projects): array
    {
        $actor  = $this->authorize();
        $search = $query['users_q'] ?? '';
        $page   = $query['users_page'] ?? '1';
        if (!is_string($search) || mb_strlen($search) > 190
            || (!is_string($page) && !is_int($page))
            || filter_var($page, FILTER_VALIDATE_INT) === false || (int) $page < 1) {
            throw new Failure(t('Ungültige Suche oder Seite.'), 422);
        }
        $search     = trim($search);
        $where      = $search === '' ? '' : ' WHERE name LIKE ? ESCAPE \'!\' OR email LIKE ? ESCAPE \'!\'';
        $pattern    = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $parameters = $search === '' ? [] : [$pattern, $pattern];
        $count      = $this->pdo->prepare('SELECT COUNT(*) FROM users' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / 50));
        $page  = min((int) $page, $pages);
        $rows  = $this->pdo->prepare('SELECT id, name, email, active FROM users' . $where
            . ' ORDER BY name, id LIMIT 50 OFFSET ' . (($page - 1) * 50));
        $rows->execute($parameters);
        $labels = $this->scopeLabels($projects);
        $people = [];
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $people[] = [
                ...$row,
                'id'     => (int) $row['id'],
                'active' => (int) $row['active'] === 1,
                'roles'  => array_map(static fn(array $grant) => [
                    ...$grant,
                    'scopeLabel' => $labels[$grant['scope']] ?? t('Nicht verfügbar'),
                ], rbac()->assignments->grantsOf((int) $row['id'])),
            ];
        }

        return compact('people', 'total', 'pages', 'page', 'search', 'actor');
    }

    public function person(int $id): array
    {
        $this->authorize();
        $query = $this->pdo->prepare('SELECT id, name, email, active FROM users WHERE id=?');
        $query->execute([$id]);
        $person = $query->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            throw new Failure(t('Dieses Konto gibt es nicht.'), 404);
        }

        return $person;
    }

    private function scopeLabels(array $projects): array
    {
        $labels = ['' => t('Global'), (string) Scope::allOf(Project::SCOPE) => t('Alle Projekte')];
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
