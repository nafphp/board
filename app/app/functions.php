<?php

declare(strict_types=1);

namespace Nafinity;

use App\Services\SlotRenderer;
use Nafinity\Contracts\PageRendererInterface;
use Nafinity\Support\PageSlotContext;
use Nafinity\Support\SlotContextInterface;
use Nafinity\Support\UiContext;

use function Naf\app;

/**
 * Nafinity's public entry points for extensions.
 *
 * This file only declares functions. It starts no application and touches no
 * database while it is included, so Composer can load it for every process.
 */
if (!function_exists('Nafinity\extensions')) {
    /**
     * The container-bound extension registry
     *
     * A Composer plugin bootstrap reaches this long before Nafinity registers
     * its own services: the helper binds a metadata-only registry on first use
     * and every later caller, including the application's own boot, gets that
     * same instance.
     */
    function extensions(): ExtensionRegistry
    {
        $container = app()->container();

        if (!$container->has(ExtensionRegistry::class)) {
            $container->set(ExtensionRegistry::class, new ExtensionRegistry());
        }

        return $container->get(ExtensionRegistry::class);
    }
}

if (!function_exists('Nafinity\template')) {
    /**
     * Resolve a logical view name through the registered view mappings
     *
     * Templates use this wherever they name another template, so a registered
     * override is honoured for full pages, partials and the layout alike.
     *
     * @param string $template Logical view name, in either spelling
     */
    function template(string $template): string
    {
        return extensions()->views()->resolve($template);
    }
}

if (!function_exists('Nafinity\partial')) {
    /**
     * Render a mapped partial through the application's page renderer
     *
     * @param string $template Logical view name
     * @param array  $data     Template variables
     */
    function partial(string $template, array $data = []): string
    {
        return app()->container()->get(PageRendererInterface::class)->fragment($template, $data);
    }
}

if (!function_exists('Nafinity\choice')) {
    /**
     * Render the reusable select
     *
     * The native control inside stays the form value and the fallback without
     * JavaScript; see docs/Extensibility.md for every argument.
     *
     * @param array $arguments At least name, label and options
     */
    function choice(array $arguments): string
    {
        return partial('components/choice', $arguments);
    }
}

if (!function_exists('Nafinity\field')) {
    /**
     * Render a labelled form field
     *
     * One entry point for the ordinary controls; `select` and `multiselect`
     * hand over to choice(). A package uses the same helper as the host.
     *
     * @param array $arguments At least label and name
     */
    function field(array $arguments): string
    {
        return partial('components/field', $arguments);
    }
}

if (!function_exists('Nafinity\slot')) {
    /**
     * Render everything contributed to a named slot
     *
     * The slot's own context says what this place offers a contribution; see
     * the SlotContextInterface implementations. A bare UiContext is accepted and
     * wrapped, for the slots that hand out nothing of their own.
     *
     * @param string                          $slot    Slot name
     * @param SlotContextInterface|UiContext  $context What this slot offers
     * @param array                           $extra   Data for the view's own templates
     */
    function slot(
        string $slot,
        SlotContextInterface|UiContext $context,
        array $extra = [],
    ): string {
        $slotContext = $context instanceof UiContext ? new PageSlotContext($context) : $context;

        return app()->container()->get(SlotRenderer::class)->render($slot, $slotContext, $extra);
    }
}

if (!function_exists('Nafinity\settings')) {
    /**
     * Declared settings for the signed-in person
     *
     * Other contexts are chosen explicitly: `settings()->forProject($id)`,
     * `->forProjectUser($id)` and `->forApplication()` each return their own
     * instance, so no URL parameter can quietly change which values are meant.
     */
    function settings(): Settings
    {
        return new Settings();
    }
}
