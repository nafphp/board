<?php

declare(strict_types=1);

namespace Naf\Board\Modules\Providers;

use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\UiDataProviderInterface;
use Naf\Board\Support\UiContext;

use function Naf\Form\csrf;

/** The language picker of the top bar. */
final class LanguagePickerProvider implements UiDataProviderInterface
{
    public function __construct(private BoardQueryInterface $query)
    {
    }

    public function data(UiContext $context): array
    {
        return [
            'preferences' => $this->query->preferences(),
            'token'       => csrf()->token(),
        ];
    }
}
