<?php

declare(strict_types=1);

namespace Nafinity\Support;

/**
 * A slot on a page that has nothing of its own to hand out.
 *
 * The sidebar, the top bar, the profile dialog and the notification and
 * activity pages are like this: a contribution there gets the authorized
 * context and, if it needs more, asks its own data provider.
 */
final readonly class PageSlotContext implements SlotContextInterface
{
    public function __construct(private UiContext $ui)
    {
    }

    public function ui(): UiContext
    {
        return $this->ui;
    }
}
