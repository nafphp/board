<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Contracts\InvitationServiceInterface;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Modules\Providers\UsersSectionProvider;
use Naf\Board\Rbac\Grants;
use Naf\Board\Rbac\Project;
use Naf\Board\Support\UiContext;
use Naf\Board\Tests\Support\BoardTestCase;
use Naf\Rbac\Scope;

use function Naf\I18n\t;
use function Naf\Rbac\rbac;

final class UsersSectionProviderTest extends BoardTestCase
{
    public function testSummariesFollowBoardRenamesAndKeepArchivedBoardNames(): void
    {
        Grants::makeAdmin((int) $this->alice->getId());
        $this->pdo->prepare('UPDATE projects SET name=?,archived_at=? WHERE id=?')
            ->execute(['Archived <Research> & Design', gmdate('Y-m-d H:i:s'), $this->projectB]);

        $roles = $this->rolesOf((int) $this->bob->getId());

        $this->assertSame('Archived <Research> & Design', $roles['project:' . $this->projectB]['scopeLabel']);
        $this->assertSame('Global', $roles['']['scopeLabel']);
        $this->assertSame('owner', $roles['project:' . $this->projectB]['role']);
    }

    public function testSummariesDoNotExposeNamesOfBoardsTheViewerCannotReach(): void
    {
        $roles = $this->rolesOf((int) $this->bob->getId());

        $this->assertSame(t('Nicht verfügbar'), $roles['project:' . $this->projectB]['scopeLabel']);
        $this->assertSame('A', $this->rolesOf((int) $this->alice->getId())['project:' . $this->projectA]['scopeLabel']);
    }

    public function testWildcardAndMissingPlacesHaveReadableLabels(): void
    {
        $rbac = rbac();
        $rbac->assignments->assign(
            (int) $this->bob->getId(),
            [$rbac->roles->idOf('viewer')],
            Scope::allOf(Project::SCOPE),
        );
        $rbac->assignments->assign(
            (int) $this->bob->getId(),
            [$rbac->roles->idOf('viewer')],
            Scope::of(Project::SCOPE, 9999999),
        );

        $roles = $this->rolesOf((int) $this->bob->getId());

        $this->assertSame(t('Alle Projekte'), $roles['project:*']['scopeLabel']);
        $this->assertSame(t('Nicht verfügbar'), $roles['project:9999999']['scopeLabel']);
    }

    private function rolesOf(int $user): array
    {
        $provider = new UsersSectionProvider($this->createStub(InvitationServiceInterface::class));
        $data     = $provider->data(
            new SettingSection('installation_users', 'application', 'Nutzer'),
            new UiContext((int) $this->alice->getId()),
            ['projects' => $this->query->projects()],
        );
        $person = array_column($data['people'], null, 'id')[$user];

        return array_column($person['roles'], null, 'scope');
    }
}
