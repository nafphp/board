<?php

declare(strict_types=1);

namespace Nafinity\Support;

/**
 * What a slot hands its contributions.
 *
 * Every slot declares its own context, so a contributed template can be written
 * against something that says what it contains instead of against whatever the
 * surrounding view happened to have in scope. The authorized rendering context
 * is always reachable, whatever else a particular slot carries.
 */
interface SlotContextInterface
{
    public function ui(): UiContext;
}
