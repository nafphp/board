<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\BulkProperty;
use Naf\Board\ExtensionContext;
use Naf\Board\Services\UserRoleBulkProperty;

/** The explicitly opted-in mass edits supplied by the application. @internal */
final class CoreBulkProperties implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        foreach (['add_role' => 'Globale Rolle hinzufügen', 'remove_role' => 'Globale Rolle entfernen'] as $key => $label) {
            $context->bulkProperties()->add(new BulkProperty(
                $key,
                'users',
                $label,
                'select',
                UserRoleBulkProperty::class,
            ));
        }
    }
}
