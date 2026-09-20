<?php

declare(strict_types=1);

namespace Naf\Board\Commands;

use Naf\Auth\Support\PasswordHasher;
use Naf\Board\Models\User;
use Naf\Board\Rbac\Grants;
use Naf\Board\Support\PasswordRule;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\ORM\Core\EntityManager;
use PDO;

use function Naf\app;

/**
 * Create an account from the machine the installation runs on.
 *
 * Needed because there is no other way in yet: a fresh installation has no
 * accounts, so there is nobody to sign in as and nobody to appoint. Once
 * invitations exist this stays for the first one and for recovery.
 *
 * The password is read from standard input rather than taken as an argument.
 * An argument ends up in the shell history and, for as long as the process
 * runs, in the process list where every other user of the machine can read it:
 *
 *     printf '%s' 'a long enough password' | naf nafinity:user a@b.test 'A B'
 *
 * @internal
 */
final class CreateUserCommand extends AbstractCommand
{
    public const string NAME = 'nafinity:user';

    protected function configure(): void
    {
        $this
            ->setTitle('Create an account')
            ->setDescription('Create an account, reading its password from standard input.')
            ->addArgument('email', true)
            ->addArgument('name', true)
            ->addOption('admin');
    }

    public function run(Input $input, Output $output): int
    {
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $name  = trim((string) $input->getArgument('name'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
            $output->writeLine('An address and a name are required.', 'error');

            return self::ERROR;
        }

        $container = app()->container();
        $pdo       = $container->get(PDO::class);

        $existing = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $existing->execute([$email]);
        if ($existing->fetchColumn() !== false) {
            $output->writeLine(sprintf('An account with "%s" already exists.', $email), 'error');

            return self::ERROR;
        }

        $password = $this->readPassword();
        if ($password === null) {
            $output->writeLine(
                'No password on standard input. Pipe one in: printf \'%s\' \'…\' | naf ' . self::NAME . ' …',
                'error',
            );

            return self::ERROR;
        }

        if (null !== $complaint = PasswordRule::complaint($password)) {
            $output->writeLine($complaint, 'error');

            return self::ERROR;
        }

        $user = new User([
            'name'          => $name,
            'email'         => $email,
            'password_hash' => $container->get(PasswordHasher::class)->hash($password),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        $container->get(EntityManager::class)->save($user);

        $id = (int) $user->getId();
        Grants::ensureDefault($id);

        if ($input->getOption('admin') === true) {
            Grants::makeAdmin($id);
        }

        $output->writeLine(
            sprintf(
                '%s (%s) created%s.',
                $name,
                $email,
                $input->getOption('admin') === true ? ' as an administrator' : '',
            ),
            'success',
        );

        return self::SUCCESS;
    }

    /** Everything on standard input, minus one trailing newline a shell adds. */
    private function readPassword(): ?string
    {
        if (stream_isatty(STDIN)) {
            return null;
        }

        $piped = stream_get_contents(STDIN);
        if ($piped === false || $piped === '') {
            return null;
        }

        return preg_replace('/\r?\n$/', '', $piped);
    }
}
