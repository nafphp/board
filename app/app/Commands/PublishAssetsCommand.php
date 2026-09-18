<?php

declare(strict_types=1);

namespace App\Commands;

use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;

/** Copy every registered package's public files into app/public/plugins. */
final class PublishAssetsCommand extends AssetCommand
{
    public const string NAME = 'nafinity:assets:publish';

    protected function configure(): void
    {
        parent::configure();
        $this->setTitle('Publish plugin assets')->setDescription(
            'Copy registered package assets into the public directory; optional --package=vendor/name.',
        );
    }

    public function run(Input $input, Output $output): int
    {
        $result = $this->publisher()->publish($this->package($input));

        $this->report($output, [
            'Published' => $result['published'],
            'Unchanged' => $result['unchanged'],
            'Conflicts' => $result['conflicts'],
        ]);

        if ($result['conflicts'] !== []) {
            $output->writeLine(
                'Nothing was written: these files were not published by Nafinity.',
                'error',
            );

            return self::ERROR;
        }

        return self::SUCCESS;
    }
}
