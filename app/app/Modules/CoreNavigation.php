<?php

declare(strict_types=1);

namespace App\Modules;

use App\Modules\Providers\LanguagePickerProvider;
use App\Modules\Providers\ProfileTriggerProvider;
use App\Modules\Providers\TimerChipProvider;
use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Definition\NavigationItem;
use Nafinity\Definition\UiContribution;
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

        // The remaining shell actions are buttons and branching links rather than
        // plain routes, so they contribute their own small templates to the very
        // same slots. A plugin adds to these lists without touching the layout.
        $ui = $context->ui();

        $ui->add(new UiContribution(
            'core.sidebar.pin',
            'sidebar.footer',
            'shell/sidebar-pin',
            100,
            null,
            null,
            [UiContext::MODE_PAGE, UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
        ));
        $ui->add(new UiContribution(
            'core.sidebar.theme',
            'sidebar.footer',
            'shell/theme-toggle',
            200,
            null,
            null,
            [UiContext::MODE_PAGE, UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
        ));
        $ui->add(new UiContribution(
            'core.sidebar.settings',
            'sidebar.footer',
            'shell/settings-link',
            300,
            null,
            null,
            [UiContext::MODE_PAGE, UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
        ));
        $ui->add(new UiContribution(
            'core.sidebar.account',
            'sidebar.footer',
            'shell/profile-row',
            400,
            ProfileTriggerProvider::class,
            null,
            [UiContext::MODE_PAGE, UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
        ));

        $ui->add(new UiContribution(
            'core.topbar.timer',
            'topbar.actions',
            'shell/timer-chip',
            100,
            TimerChipProvider::class,
            null,
            [UiContext::MODE_PAGE, UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
        ));
        $ui->add(new UiContribution(
            'core.topbar.language',
            'topbar.actions',
            'shell/language-picker',
            200,
            LanguagePickerProvider::class,
            null,
            [UiContext::MODE_PAGE, UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
        ));
        $ui->add(new UiContribution(
            'core.topbar.account',
            'topbar.actions',
            'shell/profile-avatar',
            300,
            ProfileTriggerProvider::class,
            null,
            [UiContext::MODE_PAGE, UiContext::MODE_DETAIL, UiContext::MODE_CREATE],
        ));
    }
}
