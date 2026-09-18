<?php

declare(strict_types=1);

namespace App\Modules\Providers;

use Nafinity\Contracts\TimerServiceInterface;
use Nafinity\Contracts\UiDataProviderInterface;
use Nafinity\Support\UiContext;

/** The running timer shown in the top bar. */
final class TimerChipProvider implements UiDataProviderInterface
{
    public function __construct(private TimerServiceInterface $timers)
    {
    }

    public function data(UiContext $context): array
    {
        return ['runningTimer' => $this->timers->running()];
    }
}
