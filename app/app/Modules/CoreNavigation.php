<?php

declare(strict_types=1);

namespace App\Modules;

use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Definition\NavigationItem;
use Nafinity\ExtensionContext;
use Nafinity\Support\UiContext;

/**
 * The menu entries Nafinity has always had, as ordinary definitions.
 *
 * The list of projects a person may actually open stays a BoardQuery result;
 * a registry of every project would be a different, wrong thing.
 */
final class CoreNavigation implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $navigation = $context->navigation();

        $navigation->add(new NavigationItem(
            'core.projects',
            'sidebar.workspace',
            'Alle Projekte',
            'projects',
            [],
            'grid_view',
            ['projects', 'home'],
            100,
        ));
        $navigation->add(new NavigationItem(
            'core.notifications',
            'sidebar.workspace',
            'Benachrichtigungen',
            'notifications',
            [],
            'notifications',
            ['notifications'],
            200,
        ));
        $navigation->add(new NavigationItem(
            'core.activity',
            'sidebar.project',
            'Verlauf',
            'project.activity',
            static fn(UiContext $uiContext) => ['project' => $uiContext->projectId()],
            'history',
            ['project.activity'],
            100,
            'read',
        ));
        $navigation->add(new NavigationItem(
            'core.settings',
            'sidebar.footer',
            'Einstellungen',
            'preferences',
            [],
            'settings',
            ['preferences', 'project.settings'],
            300,
        ));
    }
}
