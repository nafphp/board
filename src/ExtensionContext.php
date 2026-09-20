<?php

declare(strict_types=1);

namespace Naf\Board;

use Naf\Board\Registry\ActivityTypeRegistry;
use Naf\Board\Registry\AiToolRegistry;
use Naf\Board\Registry\AssetPackageRegistry;
use Naf\Board\Registry\AssetRegistry;
use Naf\Board\Registry\BoardFilterRegistry;
use Naf\Board\Registry\EstimationScaleRegistry;
use Naf\Board\Registry\FieldTypeRegistry;
use Naf\Board\Registry\NavigationRegistry;
use Naf\Board\Registry\PermissionRegistry;
use Naf\Board\Registry\PriorityRegistry;
use Naf\Board\Registry\SettingRegistry;
use Naf\Board\Registry\SettingSectionRegistry;
use Naf\Board\Registry\TicketFieldRegistry;
use Naf\Board\Registry\UiRegistry;
use Naf\Board\Registry\ViewRegistry;
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

    public function priorities(): PriorityRegistry
    {
        return $this->extensions->priorities();
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
