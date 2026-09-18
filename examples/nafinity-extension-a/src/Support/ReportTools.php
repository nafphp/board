<?php

declare(strict_types=1);

namespace Example\ExtensionA\Support;

use App\Ai\ProjectTool;
use Example\ExtensionA\ExtensionAProvider;
use Example\ExtensionA\Services\ReportService;
use Nafinity\Contracts\AiToolProviderInterface;
use Nafinity\Support\AiToolContext;

use function Nafinity\settings;

/**
 * This extension's tool for the local chat.
 *
 * It asks for its own permission, so it only appears for someone who has been
 * granted it, and Nafinity checks that again before running it.
 */
final class ReportTools implements AiToolProviderInterface
{
    public function __construct(private ReportService $reports)
    {
    }

    public function tools(AiToolContext $context): iterable
    {
        $project = $context->projectId();

        if ($project === null) {
            return;
        }

        yield new ProjectTool(
            'example_reports',
            'Read this project\'s review report: how many tickets are marked reviewed.',
            ['properties' => []],
            fn() => $this->reports->summary(
                $project,
                (int) settings()->forProject($project)->get('example.reports.limit', 25),
            ),
            'Bericht lesen',
            ExtensionAProvider::PERMISSION,
            requires: ['nafinity_board'],
            keywords: ['Bericht', 'Auswertung', 'geprüft'],
        );
    }
}
