<?php

declare(strict_types=1);

namespace Example\ExtensionA;

use Example\ExtensionA\Commands\ReportCommand;
use Example\ExtensionA\Controllers\ReportController;
use Example\ExtensionA\Jobs\ReviewReminderJob;
use Example\ExtensionA\Support\ReportTools;
use Example\ExtensionA\Support\ReviewBadgeProvider;
use Example\ExtensionA\Support\ReviewWidgetProvider;
use Naf\CLI\Support\CommandRegistry;
use Naf\Schedule\Core\JobRepository;
use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Definition\AiToolProviderDefinition;
use Nafinity\Definition\AssetPackage;
use Nafinity\Definition\BoardFilterDefinition;
use Nafinity\Definition\NavigationItem;
use Nafinity\Definition\PermissionDefinition;
use Nafinity\Definition\SettingDefinition;
use Nafinity\Definition\SettingSection;
use Nafinity\Definition\TicketFieldDefinition;
use Nafinity\Definition\UiContribution;
use Nafinity\ExtensionContext;
use Nafinity\Support\BoardFilterContext;
use Nafinity\Support\SqlCondition;
use Nafinity\Support\UiContext;

use function Naf\I18n\translation_paths;
use function Naf\route;

/**
 * Everything this example contributes, in one place.
 *
 * It runs after Nafinity's own defaults, so its route replaces nothing by
 * accident and its definitions sit beside the built-in ones rather than
 * fighting them.
 */
final class ExtensionAProvider implements ExtensionProviderInterface
{
    /** The permission this extension brings with it. */
    public const string PERMISSION = 'example.reports.view';

    public function register(ExtensionContext $context): void
    {
        $this->routes();
        $this->rights($context);
        $this->menu($context);
        $this->settings($context);
        $this->ticket($context);
        $this->board($context);
        $this->ai($context);
        $this->resources($context);
    }

    private function routes(): void
    {
        route()->add(
            'GET',
            '/projects/{project}/reports',
            [ReportController::class, 'show'],
            'example.reports',
        );
    }

    private function rights(ExtensionContext $context): void
    {
        $context->permissions()->add(new PermissionDefinition(
            self::PERMISSION,
            'Berichte dieses Projekts ansehen',
            'Zeigt die Auswertung der Erweiterung im linken Menü und als eigene Seite.',
            800,
        ));
    }

    private function menu(ExtensionContext $context): void
    {
        $context->navigation()->add(new NavigationItem(
            'example.reports.link',
            'sidebar.project',
            'Berichte',
            'example.reports',
            static fn(UiContext $uiContext) => ['project' => $uiContext->projectId()],
            'insights',
            ['example.reports'],
            150,
            self::PERMISSION,
        ));
    }

    private function settings(ExtensionContext $context): void
    {
        $context->settingSections()->add(new SettingSection(
            'example.reports',
            'project',
            'Berichte',
            null,
            null,
            850,
            self::PERMISSION,
            'insights',
        ));

        $context->settings()->add(new SettingDefinition(
            'example.reports.limit',
            'project',
            'example.reports',
            'Zeilen im Bericht',
            'integer',
            25,
            100,
            ['min' => 5, 'max' => 200],
            // Reading needs nothing beyond membership; writing needs this
            // extension's own right, which is granted to nobody by default.
            null,
            self::PERMISSION,
        ));
        $context->settings()->add(new SettingDefinition(
            'example.reports.compact',
            'user',
            'personal',
            'Berichte kompakt anzeigen',
            'boolean',
            false,
            900,
        ));
    }

    private function ticket(ExtensionContext $context): void
    {
        $context->ticketFields()->add(new TicketFieldDefinition(
            key: 'example.external_id',
            label: 'Externe Nummer',
            type: 'text',
            group: 'details',
            index: 500,
            default: null,
            options: ['max' => 40],
        ));
        $context->ticketFields()->add(new TicketFieldDefinition(
            key: 'example.reviewed',
            label: 'Geprüft',
            type: 'boolean',
            group: 'details',
            index: 600,
            default: false,
            nullable: false,
        ));

        // Index 150 puts this between the links widget (100) and attachments (200).
        $context->ui()->add(new UiContribution(
            'example.reports.widget',
            'ticket.main.widgets',
            'example-a/ticket-widget',
            150,
            ReviewWidgetProvider::class,
            'read',
            [UiContext::MODE_DETAIL],
            '/plugins/example/nafinity-extension-a/review.js',
        ));
    }

    private function board(ExtensionContext $context): void
    {
        $context->ui()->add(new UiContribution(
            'example.reports.badge',
            'board.card.badges',
            'example-a/board-badge',
            150,
            ReviewBadgeProvider::class,
            'read',
            [UiContext::MODE_PAGE],
        ));

        $context->boardFilters()->add(new BoardFilterDefinition(
            'example.reviewed',
            'Geprüft',
            static fn(mixed $value) => in_array($value, ['1', 1, true, 'true'], true),
            static fn(mixed $value, BoardFilterContext $filter) => new SqlCondition(
                'EXISTS(SELECT 1 FROM ticket_metadata m WHERE m.project_id=' . $filter->alias
                . '.project_id AND m.ticket_id=' . $filter->alias
                . '.id AND m.meta_key=? AND m.value_json=?)',
                ['example.reviewed', $value ? 'true' : 'false'],
            ),
            150,
        ));
    }

    private function ai(ExtensionContext $context): void
    {
        $context->aiTools()->add(new AiToolProviderDefinition(
            'example.reports.tools',
            ReportTools::class,
            200,
        ));
    }

    private function resources(ExtensionContext $context): void
    {
        translation_paths()->add('example.reports', __DIR__ . '/lang', 100);

        $context->assetPackages()->add(new AssetPackage(
            'example/nafinity-extension-a',
            __DIR__ . '/public',
        ));

        // Services are only asked for here, after every plugin has booted, so this
        // never depends on which package Composer happened to load first.
        $context->container()->get(JobRepository::class)->add(ReviewReminderJob::class, []);
        $context->container()->get(CommandRegistry::class)->add(ReportCommand::class);
    }
}
