<?php

declare(strict_types=1);

namespace Example\ExtensionB;

use Example\ExtensionA\ExtensionAProvider;
use Example\ExtensionB\Services\CountingTicketService;
use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Contracts\TicketServiceInterface;
use Nafinity\Definition\SettingDefinition;
use Nafinity\Definition\SettingSection;
use Nafinity\Definition\UiContribution;
use Nafinity\Definition\ViewOverride;
use Nafinity\ExtensionContext;
use Nafinity\Support\UiContext;

/**
 * What this example changes about what is already there.
 *
 * Every line below is an explicit replacement of something named. Nothing here
 * depends on load order beyond this provider's index, and nothing is replaced
 * by accident.
 */
final class ExtensionBProvider implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $this->decorate($context);
        $this->widgets($context);
        $this->settings($context);
        $this->views($context);
    }

    /**
     * Wrap the ticket service that is bound right now.
     *
     * The decorator is built from whatever the contract resolves to at this
     * moment, so it also wraps a replacement another extension installed — and
     * every consumer, including background jobs, goes through it.
     */
    private function decorate(ExtensionContext $context): void
    {
        $container = $context->container();
        $inner     = $container->get(TicketServiceInterface::class);

        $container->set(
            TicketServiceInterface::class,
            static fn() => new CountingTicketService($inner),
        );
    }

    private function widgets(ExtensionContext $context): void
    {
        // Same id, explicit replacement: extension A's widget is gone and this
        // one answers in its place.
        $context->ui()->add(
            new UiContribution(
                'example.reports.widget',
                'ticket.main.widgets',
                'example-b/ticket-widget',
                150,
                null,
                'read',
                [UiContext::MODE_DETAIL],
            ),
            true,
        );

        // The same index as the widget above: the tie is broken by id, so
        // `example.review.notes` sorts after `example.reports.widget`.
        $context->ui()->add(new UiContribution(
            'example.review.notes',
            'ticket.main.widgets',
            'example-b/notes-widget',
            150,
            null,
            'read',
            [UiContext::MODE_DETAIL],
        ));
    }

    private function settings(ExtensionContext $context): void
    {
        $context->settingSections()->add(new SettingSection(
            'example.review',
            'project',
            'Prüfung',
            null,
            null,
            860,
            ExtensionAProvider::PERMISSION,
            'fact_check',
        ));

        // Exactly one definition moves to another card; its scope, type,
        // default and rights stay what extension A declared.
        $context->settings()->add(
            new SettingDefinition(
                'example.reports.limit',
                'project',
                'example.review',
                'Zeilen im Bericht',
                'integer',
                25,
                100,
                ['min' => 5, 'max' => 200],
                null,
                ExtensionAProvider::PERMISSION,
            ),
            true,
        );
    }

    private function views(ExtensionContext $context): void
    {
        $context->views()->add(new ViewOverride('example-a/reports', 'example-b/reports'));
    }
}
