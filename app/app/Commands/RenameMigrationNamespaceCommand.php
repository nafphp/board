<?php

declare(strict_types=1);

namespace Naf\Board\Commands;

use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use PDO;
use RuntimeException;
use Throwable;

use function Naf\app;

/**
 * Rewrites the namespace recorded for applied migrations.
 *
 * MigrationRunner identifies a migration by "namespace + file name" and stores
 * that string in the migrations table. Renaming the application namespace
 * orphans every applied record, and the runner refuses to go either up or down
 * while a recorded migration has no source -- so this cannot be delivered as a
 * migration itself. It is the step that has to run before the first migrate
 * after such a rename.
 *
 * Each row is matched exactly, never by pattern: LIKE gives a backslash its own
 * meaning, which is precisely the character a namespace is made of. Package
 * migrations keep their own namespaces and are left alone, and a second run
 * finds nothing to do.
 *
 * @internal
 */
final class RenameMigrationNamespaceCommand extends AbstractCommand
{
    public const string NAME = 'nafinity:migrations:rename';

    protected function configure(): void
    {
        $this->setTitle('Rewrite the namespace of applied migrations')->setDescription(
            'Repoints recorded migrations after a namespace rename; shows the plan unless --apply is given.',
        );

        $this->addOption('from', null, true);
        $this->addOption('to', null, true);
        $this->addOption('apply', null, false);
    }

    public function run(Input $input, Output $output): int
    {
        $from = $input->getOption('from');
        $to   = $input->getOption('to');

        if (!is_string($from) || $from === '' || !is_string($to) || $to === '') {
            throw new RuntimeException('Both --from and --to are required, for example --from="App\\Migrations\\".');
        }

        $pdo     = app()->container()->get(PDO::class);
        $records = $pdo->query('SELECT name FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $hits    = array_values(array_filter($records, static fn(string $r): bool => str_starts_with($r, $from)));

        if ($hits === []) {
            $output->writeLine(sprintf('Nothing to rewrite: no record starts with %s.', $from));

            return self::SUCCESS;
        }

        $output->writeLine(sprintf('%d of %d records start with %s.', count($hits), count($records), $from));

        $rename = static fn(string $r): string => $to . substr($r, strlen($from));

        if ($input->getOption('apply') === null) {
            foreach ($hits as $hit) {
                $output->writeLine(sprintf('  %s -> %s', $hit, $rename($hit)));
            }
            $output->writeLine('Nothing written. Pass --apply to carry this out.');

            return self::SUCCESS;
        }

        $statement = $pdo->prepare('UPDATE migrations SET name = ? WHERE name = ?');
        $written   = 0;

        $pdo->beginTransaction();

        try {
            foreach ($hits as $hit) {
                $statement->execute([$rename($hit), $hit]);
                $written += $statement->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();

            throw new RuntimeException('Rewrite failed, nothing changed: ' . $error->getMessage(), 0, $error);
        }

        $output->writeLine(sprintf('Rewrote %d records.', $written));

        return self::SUCCESS;
    }
}
