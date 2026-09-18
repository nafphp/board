<?php

declare(strict_types=1);

namespace App\Modules;

use App\Support\Fields\BooleanType;
use App\Support\Fields\DateType;
use App\Support\Fields\IntegerType;
use App\Support\Fields\MultiselectType;
use App\Support\Fields\SelectType;
use App\Support\Fields\TextareaType;
use App\Support\Fields\TextType;
use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\ExtensionContext;

/**
 * The value types settings and ticket metadata share.
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
