<?php

declare(strict_types=1);

namespace Example\ExtensionA\Support;

use Nafinity\Contracts\UiDataProviderInterface;
use Nafinity\Support\UiContext;
use PDO;

/**
 * What the ticket widget cannot read from the slot itself.
 *
 * The ticket's own values — its metadata, its fields, its rights — are already
 * in the slot context, so nothing fetches those again. This provider exists for
 * the one thing that is not there: when this extension last counted the open
 * reviews of the project, which lives in its own table.
 */
final class ReviewWidgetProvider implements UiDataProviderInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function data(UiContext $context): array
    {
        if ($context->projectId() === null) {
            return ['lastRun' => null];
        }

        $statement = $this->pdo->prepare('SELECT ran_at FROM example_report_runs WHERE project_id=?');
        $statement->execute([$context->projectId()]);
        $when = $statement->fetchColumn();

        return ['lastRun' => $when === false ? null : (string) $when];
    }
}
