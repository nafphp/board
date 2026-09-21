<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Domain\Change;
use Naf\Board\ExtensionContext;
use Naf\Rbac\Events\GrantsChanged;
use Naf\Rbac\Scope;
use PDO;
use Throwable;

use function Naf\app;
use function Naf\event;

/**
 * Put a change of somebody's roles into the history.
 *
 * naf/rbac keeps no history of its own -- a history is a product decision and
 * access control is not -- so it says what happened and this decides that it is
 * worth recording. Which it is: in a ticket system, who was given which rights
 * where is the entry people come looking for long after everything else has
 * been forgotten.
 *
 * The event arrives after the grant is committed, so this opens a transaction of
 * its own. The recorder insists on one because an activity must not outlive a
 * change that was rolled back; here the change is already a fact, and the entry
 * is a second write about it.
 *
 * @internal
 */
final class CoreGrantAudit implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        if (!class_exists(GrantsChanged::class)) {
            return;
        }

        event()->listen('rbac.granted', static function (GrantsChanged $grant): void {
            $pdo   = app()->container()->get(PDO::class);
            $scope = Scope::parse($grant->scope);
            // A grant on one board can be joined to it; one held on every board
            // of a kind, or installation-wide, belongs to no single project.
            $project = $scope->type === 'project' && !$scope->isAllOfAKind()
                ? (int) $scope->id
                : null;

            $owned = !$pdo->inTransaction();
            if ($owned) {
                $pdo->beginTransaction();
            }

            try {
                event()->dispatch('nafinity.changed', Change::at(
                    $grant->scope,
                    $project,
                    $grant->actorId,
                    'rbac.granted',
                    [
                        // Resolved now, for the same reason the roles travel by
                        // label: an id names nothing once the row behind it is
                        // gone, and this entry has to be readable then.
                        'person' => self::nameOf($pdo, $grant->targetId),
                        'before' => $grant->before,
                        'after'  => $grant->after,
                    ],
                ));

                if ($owned) {
                    $pdo->commit();
                }
            } catch (Throwable $failure) {
                if ($owned) {
                    $pdo->rollBack();
                }

                throw $failure;
            }
        });
    }

    private static function nameOf(PDO $pdo, int $user): string
    {
        $statement = $pdo->prepare('SELECT name FROM users WHERE id=?');
        $statement->execute([$user]);

        return (string) $statement->fetchColumn();
    }
}
