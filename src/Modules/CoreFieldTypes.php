<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\ExtensionContext;
use Naf\Board\Support\Fields\BooleanType;
use Naf\Board\Support\Fields\DateType;
use Naf\Board\Support\Fields\IntegerType;
use Naf\Board\Support\Fields\MultiselectType;
use Naf\Board\Support\Fields\SelectType;
use Naf\Board\Support\Fields\TextareaType;
use Naf\Board\Support\Fields\TextType;

/**
 * The value types settings and ticket metadata share.
 *
 * @internal
 */
final class CoreFieldTypes implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $types = $context->fieldTypes();

        $types->add(new TextType());
        $types->add(new TextareaType());
        $types->add(new BooleanType());
        $types->add(new IntegerType());
        $types->add(new DateType());
        $types->add(new SelectType());
        $types->add(new MultiselectType());
    }
}
