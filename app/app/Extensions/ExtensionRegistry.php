<?php

declare(strict_types=1);

namespace Nafinity;

use InvalidArgumentException;
use LogicException;
use Nafinity\Contracts\ExtensionProviderInterface;
use Nafinity\Contracts\TicketMetadataReaderInterface;
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
use Nafinity\Support\Resolver;
use Psr\Container\ContainerInterface;
use Throwable;

use function Naf\app;

/**
 * The single place extensions describe what they contribute.
 *
 * The registry is reachable from a Composer plugin's bootstrap, long before the
 * application has registered its own defaults. Providers noted there are only
 * remembered; they run in one explicit pass after the defaults exist, so an
 * override is never overwritten by the very thing it replaces.
 */
final class ExtensionRegistry
{
    /** @var array<string, array{id: string, class: class-string, index: int}> */
    private array $providers = [];

    /** @var list<string> */
    private array $executed = [];

    private bool $initialized  = false;
    private bool $initializing = false;

    private ?PermissionRegistry $permissions         = null;
    private ?UiRegistry $ui                          = null;
    private ?NavigationRegistry $navigation          = null;
    private ?ViewRegistry $views                     = null;
    private ?SettingRegistry $settings               = null;
    private ?SettingSectionRegistry $settingSections = null;
    private ?FieldTypeRegistry $fieldTypes           = null;
    private ?TicketFieldRegistry $ticketFields       = null;
    private ?AssetRegistry $assets                   = null;
    private ?AssetPackageRegistry $assetPackages     = null;
    private ?BoardFilterRegistry $boardFilters       = null;
    private ?EstimationScaleRegistry $estimation     = null;
    private ?ActivityTypeRegistry $activityTypes     = null;
    private ?AiToolRegistry $aiTools                 = null;

    /**
     * Remember a provider; it runs after Nafinity's own defaults
     *
     * @param string       $id            Stable extension id, namespaced like example.reports
     * @param class-string $providerClass Provider resolved from the container later
     * @param int          $index         Run order, ascending; ties run by id
     * @param bool         $replace       Whether an already noted id may be replaced
     */
    public function register(
        string $id,
        string $providerClass,
        int $index = 100,
        bool $replace = false,
    ): void {
        if (trim($id) === '') {
            throw new InvalidArgumentException('An extension needs a non-empty id.');
        }

        if ($this->initialized) {
            throw new LogicException(sprintf(
                'Extension "%s" was registered after the provider pass had finished. Providers '
                . 'must be noted during Composer plugin boot; definitions may still be added to '
                . 'the existing registries before the request is handled.',
                $id,
            ));
        }

        if (isset($this->providers[$id]) && !$replace) {
            throw new LogicException(sprintf(
                'Extension "%s" is already registered. Pass replace: true to replace it.',
                $id,
            ));
        }

        if (!is_a($providerClass, ExtensionProviderInterface::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Provider "%s" of extension "%s" must implement %s.',
                $providerClass,
                $id,
                ExtensionProviderInterface::class,
            ));
        }

        $this->providers[$id] = ['id' => $id, 'class' => $providerClass, 'index' => $index];
    }

    /**
     * Run every noted provider once, ascending by index and id
     *
     * @param ContainerInterface $container The booted application container
     */
    public function initialize(ContainerInterface $container): void
    {
        if ($this->initialized) {
            return;
        }

        if ($this->initializing) {
            throw new LogicException(
                'Extension initialization is already running. A provider must not boot the '
                . 'extension pass again.',
            );
        }

        $this->initializing = true;

        try {
            $context = new ExtensionContext($container, $this);

            foreach ($this->ordered() as $provider) {
                $this->runProvider($provider, $container, $context);
            }
        } finally {
            $this->initializing = false;
            $this->initialized  = true;
        }
    }

    public function initialized(): bool
    {
        return $this->initialized;
    }

    /**
     * The noted providers in the order they run
     *
     * @return list<array{id: string, class: class-string, index: int}>
     */
    public function ordered(): array
    {
        $providers = array_values($this->providers);

        usort(
            $providers,
            fn(array $left, array $right) => $left['index'] <=> $right['index']
                ?: strcmp($left['id'], $right['id']),
        );

        return $providers;
    }

    /**
     * Extension ids that actually ran, in execution order
     *
     * @return list<string>
     */
    public function executed(): array
    {
        return $this->executed;
    }

    public function permissions(): PermissionRegistry
    {
        return $this->permissions ??= new PermissionRegistry();
    }

    public function ui(): UiRegistry
    {
        return $this->ui ??= new UiRegistry();
    }

    public function navigation(): NavigationRegistry
    {
        return $this->navigation ??= new NavigationRegistry();
    }

    public function views(): ViewRegistry
    {
        return $this->views ??= new ViewRegistry();
    }

    public function settings(): SettingRegistry
    {
        return $this->settings ??= new SettingRegistry();
    }

    public function settingSections(): SettingSectionRegistry
    {
        return $this->settingSections ??= new SettingSectionRegistry();
    }

    public function fieldTypes(): FieldTypeRegistry
    {
        return $this->fieldTypes ??= new FieldTypeRegistry();
    }

    public function ticketFields(): TicketFieldRegistry
    {
        return $this->ticketFields ??= new TicketFieldRegistry();
    }

    public function assets(): AssetRegistry
    {
        return $this->assets ??= new AssetRegistry();
    }

    public function assetPackages(): AssetPackageRegistry
    {
        return $this->assetPackages ??= new AssetPackageRegistry();
    }

    public function boardFilters(): BoardFilterRegistry
    {
        return $this->boardFilters ??= new BoardFilterRegistry();
    }

    public function estimationScales(): EstimationScaleRegistry
    {
        return $this->estimation ??= new EstimationScaleRegistry();
    }

    public function activityTypes(): ActivityTypeRegistry
    {
        return $this->activityTypes ??= new ActivityTypeRegistry();
    }

    public function aiTools(): AiToolRegistry
    {
        return $this->aiTools ??= new AiToolRegistry();
    }

    /**
     * The authorized reader for ticket metadata.
     *
     * The facade only forwards; it keeps no evaluated request data of its own.
     */
    public function ticketMetadata(): TicketMetadataReaderInterface
    {
        return app()->container()->get(TicketMetadataReaderInterface::class);
    }

    /**
     * @param array{id: string, class: class-string, index: int} $provider
     */
    private function runProvider(
        array $provider,
        ContainerInterface $container,
        ExtensionContext $context,
    ): void {
        try {
            $instance = Resolver::service($container, $provider['class']);

            if (!$instance instanceof ExtensionProviderInterface) {
                throw new LogicException(sprintf(
                    'Provider "%s" is not a %s.',
                    $provider['class'],
                    ExtensionProviderInterface::class,
                ));
            }

            $instance->register($context);
        } catch (Throwable $exception) {
            throw new LogicException(
                sprintf(
                    'Extension "%s" (%s) failed to register: %s',
                    $provider['id'],
                    $provider['class'],
                    $exception->getMessage(),
                ),
                0,
                $exception,
            );
        }

        $this->executed[] = $provider['id'];
    }
}
