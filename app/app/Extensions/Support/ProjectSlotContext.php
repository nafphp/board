<?php

declare(strict_types=1);

namespace Nafinity\Support;

/**
 * One project, on the project overview.
 */
final readonly class ProjectSlotContext implements SlotContextInterface
{
    /**
     * @param UiContext $ui      The authorized rendering context
     * @param array     $project The project row being listed
     */
    public function __construct(private UiContext $ui, public array $project)
    {
    }

    public function ui(): UiContext
    {
        return $this->ui;
    }

    public function projectId(): int
    {
        return (int) $this->project['id'];
    }
}
