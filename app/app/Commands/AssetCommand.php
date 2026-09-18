<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Assets\AssetPublisher;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;

use function Naf\app;

/**
 * Publishing, checking and removing a package's public files.
 *
 * Three commands share this base because they differ only in what they do with
 * the same plan; each names itself and reports exactly which files it touched.
 */
abstract class AssetCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->addOption('package', null, true);
    }

    protected function publisher(): AssetPublisher
    {
        return new AssetPublisher(
            BASE_PATH . '/public',
            BASE_PATH . '/storage/plugin-assets',
        );
    }

    protected function package(Input $input): ?string
    {
        $package = $input->getOption('package');

        return is_string($package) && $package !== '' ? $package : null;
    }

    /**
     * @param Output               $output Console output
     * @param array<string, array> $groups Heading to the files it lists
     */
    protected function report(Output $output, array $groups): void
    {
        foreach ($groups as $heading => $files) {
            $output->writeLine($heading . ': ' . count($files));

            foreach ($files as $file) {
                $output->writeLine('  ' . str_replace(BASE_PATH . '/', '', $file));
            }
        }
    }

    protected function container(): mixed
    {
        return app()->container();
    }
}
