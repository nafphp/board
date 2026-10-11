<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Rbac\Scope;
use PDO;

use function Naf\app;
use function Naf\Rbac\rbac;

final class UserRolesOverHttpTest extends AcceptanceTestCase
{
    public function testClosedMultiselectRowsRoundTripMultipleRolesAndPreserveArchivedGrants(): void
    {
        $pdo    = app()->container()->get(PDO::class);
        $target = (int) $pdo->query("SELECT id FROM users WHERE email='viewer@example.test'")->fetchColumn();
        $before = rbac()->assignments->grantsOf($target);
        $member = rbac()->roles->idOf('member');
        $viewer = rbac()->roles->idOf('viewer');
        rbac()->assignments->assign($target, [$member, $viewer], Scope::of('project', self::PROJECT));
        rbac()->assignments->assign($target, [$viewer], Scope::allOf('project'));
        rbac()->assignments->assign($target, [$member], Scope::of('project', self::OTHER_PROJECT));
        $pdo->exec('UPDATE projects SET archived_at=CURRENT_TIMESTAMP WHERE id=' . self::OTHER_PROJECT);

        try {
            $expected = rbac()->assignments->grantsOf($target);
            $page     = $this->page($this->alice, '/settings/users/' . $target);
            $xpath    = $this->xpath($page);
            $form     = $xpath->query('//form[@class="rbac-grants"]')->item(0);
            $this->assertNotNull($form);
            $this->assertCount(0, $xpath->query('.//details[@open]', $form));
            $this->assertCount(2, $xpath->query('.//input[@type="checkbox" and @checked and contains(@value,"|project:1")]', $form));
            $this->assertCount(1, $xpath->query('.//input[@type="hidden" and @name="grants[]" and @value="' . $member . '|project:2"]', $form));
            $this->assertCount(1, $xpath->query('.//input[@name="grants"]', $form));
            $this->assertGreaterThan(1, $xpath->query('.//*[@role="listbox" and @aria-multiselectable="true"]', $form)->length);
            $fields = [];
            foreach ($xpath->query('.//input[@name and (@type="hidden" or @checked)]', $form) as $input) {
                $fields[] = rawurlencode($input->getAttribute('name')) . '=' . rawurlencode($input->getAttribute('value'));
            }
            $answer = $this->alice->request($form->getAttribute('action'), implode('&', $fields), [
                'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json',
            ]);
            $this->assertSame(200, $answer['status'], $answer['body']);
            $this->assertSame($expected, rbac()->assignments->grantsOf($target));

            // Removing every direct role from one board must retain global/wildcard/archived grants.
            parse_str(implode('&', $fields), $body);
            $body['grants'] = array_values(array_filter($body['grants'], static fn(string $grant) => !str_ends_with($grant, '|project:1')));
            $answer         = $this->alice->request($form->getAttribute('action'), http_build_query($body), [
                'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json',
            ]);
            $this->assertSame(200, $answer['status'], $answer['body']);
            $this->assertSame(array_values(array_filter($expected, static fn(array $grant) => $grant['scope'] !== 'project:1')), rbac()->assignments->grantsOf($target));
        } finally {
            $pdo->exec('UPDATE projects SET archived_at=NULL WHERE id=' . self::OTHER_PROJECT);
            $pdo->prepare('DELETE FROM rbac_user_roles WHERE user_id=?')->execute([$target]);
            $byScope = [];
            foreach ($before as $grant) {
                $byScope[$grant['scope']][] = $grant['role_id'];
            }
            foreach ($byScope as $scope => $ids) {
                rbac()->assignments->assign($target, $ids, Scope::parse($scope));
            }
        }
    }

    public function testOwnRolesRemainReadOnly(): void
    {
        $id    = (int) app()->container()->get(PDO::class)->query("SELECT id FROM users WHERE email='alice@example.test'")->fetchColumn();
        $xpath = $this->xpath($this->page($this->alice, '/settings/users/' . $id));
        $form  = $xpath->query('//form[@class="rbac-grants"]')->item(0);
        $this->assertCount(0, $xpath->query('.//details | .//input[@type="checkbox"] | .//button', $form));
        $this->assertGreaterThan(0, $xpath->query('.//*[@data-person-role-summary]', $form)->length);
    }

    public function testUserViewPermissionDoesNotExposeRoleEditingOrAllowNativeRoleWrites(): void
    {
        $pdo    = app()->container()->get(PDO::class);
        $actor  = (int) $pdo->query("SELECT id FROM users WHERE email='viewer@example.test'")->fetchColumn();
        $target = (int) $pdo->query("SELECT id FROM users WHERE email='bob@example.test'")->fetchColumn();
        $before = rbac()->assignments->grantsOf($actor);
        $role   = rbac()->roles->create('user-role-view-test', 'View users', '', ['settings.manage', 'users.view']);
        rbac()->assignments->assign($actor, [$role]);

        try {
            $page  = $this->page($this->viewer, '/settings/users/' . $target);
            $xpath = $this->xpath($page);
            $form  = $xpath->query('//form[@class="rbac-grants"]')->item(0);
            $this->assertCount(0, $xpath->query('.//details | .//input[@type="checkbox"] | .//button', $form));
            $grants = rbac()->assignments->grantsOf($target);
            $answer = $this->viewer->request($form->getAttribute('action'), http_build_query([
                '_csrf' => $this->viewer->token($page), 'user' => $target, 'grants' => '',
            ]), ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
            $this->assertSame(403, $answer['status']);
            $this->assertSame($grants, rbac()->assignments->grantsOf($target));
        } finally {
            rbac()->assignments->assign($actor, array_column(array_filter($before, static fn(array $grant) => $grant['scope'] === ''), 'role_id'));
            rbac()->roles->delete($role);
        }
    }

    private function xpath(string $markup): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $markup);

        return new DOMXPath($document);
    }
}
