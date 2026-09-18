<?php

declare(strict_types=1);

namespace App\Commands;

use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;

/**
 * Remove published files again, from what was recorded when they were written.
 *
 * It works without the package being installed, which is the point: after an
 * uninstall the record is all that is left. A file somebody changed is kept and
 * reported rather than deleted.
 */
final class RemoveAssetsCommand extends AssetCommand
{
    public const string NAME = 'nafinity:assets:remove';

    protected function configure(): void
    {
        parent::configure();
        $this->setTitle('Remove plugin assets')->setDescription(
            'Delete previously published package assets; optional --package=vendor/name.',
        );
    }

    public function run(Input $input, Output $output): int
    {
        $result = $this->publisher()->remove($this->package($input));

        $this->report($output, [
            'Removed' => $result['removed'],
            'Kept'    => $result['kept'],
        ]);

        if ($result['kept'] !== []) {
            $output->writeLine('Kept files differ from what was published; remove them by hand.');
        }

        return self::SUCCESS;
    }
}
