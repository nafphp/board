<?php

declare(strict_types=1);

namespace Example\ExtensionA\Support;

use Example\ExtensionA\Services\ReportService;
use Nafinity\Contracts\UiDataProviderInterface;
use Nafinity\Support\UiContext;

/** What the ticket widget shows about this ticket's review. */
final class ReviewWidgetProvider implements UiDataProviderInterface
{
    public function __construct(private ReportService $reports)
    {
    }

    public function data(UiContext $context): array
    {
        if ($context->recordId === null || $context->projectId() === null) {
            return ['review' => ['reviewed' => false, 'external_id' => null]];
        }

        return [
            'review' => $this->reports->reviewState($context->projectId(), $context->recordId),
        ];
    }
}
