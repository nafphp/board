<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Example\ExtensionA\ExtensionAProvider;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;
use PDO;

/**
 * A right an extension declares behaves like any other right.
 *
 * It goes into a custom role, is handed out through the member administration
 * rather than by hand, and grants exactly itself -- holding it must not imply
 * anything else. A stranger is told the project does not exist.
 *
 * And a grant whose extension is currently missing is left alone when a role is
 * saved: the extension may be back tomorrow, and rewriting the role would have
 * quietly revoked something nobody chose to revoke.
 */
final class PermissionGrantTest extends ExtensionInstalledTestCase
{
    private int $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->role = $this->reviewerRole();
    }

    public function testTheGrantIsStoredAndLoadedBack(): void
    {
        $granted = $this->access->permissions($this->project, 'viewer', $this->role);

        $this->assertContains(ExtensionAProvider::PERMISSION, $granted);
    }

    public function testHoldingTheRoleGrantsThatRightAndNothingElse(): void
    {
        $this->actAs('reviewer');

        $scope = $this->access->project($this->project, ExtensionAProvider::PERMISSION);

        $this->assertTrue($scope->allows(ExtensionAProvider::PERMISSION));
        $this->assertFalse($scope->allows('write'), 'an unrelated right came with it');
    }

    public function testAStrangerIsNotToldTheProjectExists(): void
    {
        $this->actAs('stranger');

        $this->assertDenied(404, fn() => $this->access->project($this->project, ExtensionAProvider::PERMISSION));
    }

    public function testAGrantOfAMissingExtensionSurvivesARoleSave(): void
    {
        $this->pdo
            ->prepare('INSERT INTO project_role_permissions(project_id,role_id,permission) VALUES(?,?,?)')
            ->execute([$this->project, $this->role, 'example.gone.right']);

        $this->roles->save($this->project, [
            'id'          => $this->role,
            'version'     => (int) $this->scalar('SELECT version FROM project_roles WHERE id=?', [$this->role]),
            'name'        => 'Prüfer',
            'permissions' => [ExtensionAProvider::PERMISSION],
        ]);

        $stored = $this->pdo->prepare(
            'SELECT permission FROM project_role_permissions WHERE project_id=? AND role_id=?',
        );
        $stored->execute([$this->project, $this->role]);

        $this->assertContains(
            'example.gone.right',
            $stored->fetchAll(PDO::FETCH_COLUMN),
            'a grant whose extension is absent was quietly revoked',
        );
    }
}
