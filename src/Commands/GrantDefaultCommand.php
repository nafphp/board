<?php

declare(strict_types=1);

namespace Naf\Board\Commands;

use Naf\Board\Rbac\Grants;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use PDO;

use function Naf\app;
use function Naf\Rbac\rbac;

/**
 * Give every account that holds nothing what an ordinary account holds.
 *
 * The upgrade path for an installation that existed before roles did. Until
 * then every signed-in person could create boards because the user model said
 * so; now it is a grant, and the accounts from before have none -- so they
 * would quietly lose an ability nobody decided to take away.
 *
 * Idempotent, and it touches only accounts with no roles at all: somebody who
 * was deliberately given nothing stays that way.
 *
 * @internal
 */
final class GrantDefaultCommand extends AbstractCommand
{
    public const string NAME = 'nafinity:grant-default';

    protected function configure(): void
    {
        $this
            ->setTitle('Grant the ordinary role')
            ->setDescription('Give accounts that hold no role at all the one an ordinary account has.');
    }

    public function run(Input $input, Output $output): int
    {
        $rbac = rbac();

        if ($rbac->roles->idOf(Grants::DEFAULT_ROLE) === null) {
            $output->writeLine('No roles yet. Run "naf rbac:sync" first.', 'error');

            return self::ERROR;
        }

        $without = app()->container()->get(PDO::class)->query(
            'SELECT u.id, u.email FROM users u'
            . ' LEFT JOIN rbac_user_roles ur ON ur.user_id = u.id'
            . ' WHERE ur.user_id IS NULL ORDER BY u.id',
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($without as $account) {
            Grants::ensureDefault((int) $account['id']);
            $output->writeLine(sprintf('  %s', $account['email']));
        }

        $output->writeLine(
            $without === []
                ? 'Every account already holds something.'
                : sprintf('%d account(s) given the "%s" role.', count($without), Grants::DEFAULT_ROLE),
            'success',
        );

        return self::SUCCESS;
    }
}
