<?php

declare(strict_types=1);

namespace Nafinity;

use Nafinity\Registry\ActivityTypeRegistry;
use Nafinity\Registry\AiToolRegistry;
use Nafinity\Registry\AssetPackageRegistry;
use Nafinity\Registry\AssetRegistry;
use Nafinity\Registry\BoardFilterRegistry;
use Nafinity\Registry\EstimationScaleRegistry;
use Nafinity\Registry\FieldTypeRegistry;
use Nafinity\Registry\NavigationRegistry;
use Nafinity\Registry\PermissionRegistry;
use Nafinity\Registry\SettingRegistry;
use Nafinity\Registry\SettingSectionRegistry;
use Nafinity\Registry\TicketFieldRegistry;
use Nafinity\Registry\UiRegistry;
use Nafinity\Registry\ViewRegistry;
use Psr\Container\ContainerInterface;

/**
 * What a provider is handed while it registers.
 *
 * The context carries the container and the registries, never a current user:
 * definitions are code, and user data is read in the request that needs it.
 */
final readonly class ExtensionContext
{
    public function __construct(
        private ContainerInterface $container,
        private ExtensionRegistry $extensions,
    ) {
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function extensions(): ExtensionRegistry
    {
        return $this->extensions;
    }

    public function permissions(): PermissionRegistry
    {
        return $this->extensions->permissions();
    }

    public function ui(): UiRegistry
    {
        return $this->extensions->ui();
    }

    public function navigation(): NavigationRegistry
    {
        return $this->extensions->navigation();
    }

    public function views(): ViewRegistry
    {
        return $this->extensions->views();
    }

    public function settings(): SettingRegistry
    {
        return $this->extensions->settings();
    }

    public function settingSections(): SettingSectionRegistry
    {
        return $this->extensions->settingSections();
    }

    public function fieldTypes(): FieldTypeRegistry
    {
        return $this->extensions->fieldTypes();
    }

    public function ticketFields(): TicketFieldRegistry
    {
        return $this->extensions->ticketFields();
    }

    public function assets(): AssetRegistry
    {
        return $this->extensions->assets();
    }

    public function assetPackages(): AssetPackageRegistry
    {
        return $this->extensions->assetPackages();
    }

    public function boardFilters(): BoardFilterRegistry
    {
        return $this->extensions->boardFilters();
    }

    public function estimationScales(): EstimationScaleRegistry
    {
        return $this->extensions->estimationScales();
    }

    public function activityTypes(): ActivityTypeRegistry
    {
        return $this->extensions->activityTypes();
    }

    public function aiTools(): AiToolRegistry
    {
        return $this->extensions->aiTools();
    }
}
