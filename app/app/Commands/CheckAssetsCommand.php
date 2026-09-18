<?php

declare(strict_types=1);

namespace App\Commands;

use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;

/** Report what publishing would change, without touching anything. */
final class CheckAssetsCommand extends AssetCommand
{
    public const string NAME = 'nafinity:assets:check';

    protected function configure(): void
    {
        parent::configure();
        $this->setTitle('Check plugin assets')->setDescription(
            'Compare published package assets with their sources; optional --package=vendor/name.',
        );
    }

    public function run(Input $input, Output $output): int
    {
        $result = $this->publisher()->check($this->package($input));

        $this->report($output, [
            'Current'   => $result['current'],
            'Missing'   => $result['missing'],
            'Stale'     => $result['stale'],
            'Conflicts' => $result['conflicts'],
        ]);

        $pending = $result['missing'] !== [] || $result['stale'] !== [] || $result['conflicts'] !== [];

        return $pending ? self::ERROR : self::SUCCESS;
    }
}
