<?php

declare(strict_types=1);

namespace Naf\Board\Commands;

use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\Rbac\Scope;
use PDO;

use function Naf\app;
use function Naf\Rbac\rbac;

/**
 * Make the first administrator, once.
 *
 * The door closes behind it: while nobody is an administrator there is no way
 * to become one through the application, so it has to be opened from the
 * machine the installation runs on. Once somebody holds the role, further
 * administrators are made by an administrator -- which is the point of having
 * the role at all, and leaves a trail that a command run on a server does not.
 *
 * @internal
 */
final class AdminCommand extends AbstractCommand
{
    public const string NAME = 'nafinity:admin';

    protected function configure(): void
    {
        $this
            ->setTitle('Appoint an administrator')
            ->setDescription('Grant the admin role to an existing account, while nobody holds it yet.')
            ->addArgument('email', true)
            ->addOption('force');
    }

    public function run(Input $input, Output $output): int
    {
        $rbac = rbac();
        $role = $rbac->roles->idOf('admin');

        if ($role === null) {
            $output->writeLine('No admin role yet. Run "naf rbac:sync" first.', 'error');

            return self::ERROR;
        }

        $forced  = $input->getOption('force') === true;
        $holders = $rbac->assignments->holdersOf('admin');

        if ($holders !== [] && !$forced) {
            $output->writeLine(
                sprintf(
                    'This installation already has %d administrator(s). Appoint further ones in '
                    . 'the settings, where it is recorded who did it -- or pass --force if none of '
                    . 'them can be reached any more.',
                    count($holders),
                ),
                'error',
            );

            return self::ERROR;
        }

        /*
         * Forcing it says out loud who is already there.
         *
         * The way in from the machine exists because the strict rule -- the last
         * administrator cannot be removed -- can freeze an installation whose
         * administrators are all gone. It opens nothing that was shut: whoever
         * can run this reads the database directly anyway. What it must not do
         * is happen quietly, so it names the existing administrators first.
         */
        if ($forced && $holders !== []) {
            $output->writeLine('Forced. This installation already has:', 'warning');
            foreach ($this->namesOf($holders) as $line) {
                $output->writeLine('  ' . $line);
            }
        }

        $email = trim((string) $input->getArgument('email'));
        $user  = app()->container()->get(PDO::class)->prepare('SELECT id, name FROM users WHERE email = ?');
        $user->execute([$email]);
        $found = $user->fetch(PDO::FETCH_ASSOC);

        if (!$found) {
            $output->writeLine(sprintf('No account with the address "%s".', $email), 'error');

            return self::ERROR;
        }

        // grantedBy stays empty: nobody granted this one, which is exactly what
        // distinguishes the first administrator from every later one.
        $rbac->assignments->assign((int) $found['id'], [$role], Scope::everywhere());
        $rbac->forget((int) $found['id']);

        $output->writeLine(
            sprintf('%s (%s) is now an administrator of this installation.', $found['name'], $email),
            'success',
        );

        return self::SUCCESS;
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    private function namesOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $marks     = implode(',', array_fill(0, count($ids), '?'));
        $statement = app()->container()->get(PDO::class)
            ->prepare("SELECT name, email FROM users WHERE id IN ($marks) ORDER BY name");
        $statement->execute($ids);

        return array_map(
            static fn(array $row) => sprintf('%s (%s)', $row['name'], $row['email']),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
