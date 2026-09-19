<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\TimerServiceInterface;
use Naf\Board\Contracts\UiDataProviderInterface;
use Naf\Board\Support\UiContext;

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
