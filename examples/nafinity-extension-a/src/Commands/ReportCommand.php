<?php

declare(strict_types=1);

namespace Example\ExtensionA\Commands;

use Example\ExtensionA\Services\ReportService;
use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;

use function Naf\app;

/** Print how many open tickets of a project are still unreviewed. */
final class ReportCommand extends AbstractCommand
{
    public const string NAME = 'example:reports';

    protected function configure(): void
    {
        $this->setTitle('Example extension report')
            ->setDescription('Count unreviewed open tickets of one project.')
            ->addArgument('project');
    }

    public function run(Input $input, Output $output): int
    {
        $project = (int) $input->getArgument('project');

        if ($project < 1) {
            $output->writeLine('Pass a project id.', 'error');

            return self::ERROR;
        }

        $reports = app()->container()->get(ReportService::class);
        $open    = $reports->unreviewed($project);

        $output->writeLine('Unreviewed open tickets: ' . count($open));

        return self::SUCCESS;
    }
}
