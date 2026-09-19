<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\AccountServiceInterface;
use Naf\Board\Contracts\AiServiceInterface;
use Naf\Board\Contracts\AttachmentServiceInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\CommentServiceInterface;
use Naf\Board\Contracts\NotificationServiceInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Contracts\PreferenceServiceInterface;
use Naf\Board\Contracts\ProjectServiceInterface;
use Naf\Board\Contracts\RoleServiceInterface;
use Naf\Board\Contracts\SettingsServiceInterface;
use Naf\Board\Contracts\SettingsStoreInterface;
use Naf\Board\Contracts\TicketMetadataReaderInterface;
use Naf\Board\Contracts\TicketMetadataStoreInterface;
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Contracts\TimerServiceInterface;
use Naf\Board\Services\Access;
use Naf\Board\Services\AccountService;
use Naf\Board\Services\AiService;
use Naf\Board\Services\AttachmentService;
use Naf\Board\Services\BoardQuery;
use Naf\Board\Services\CommentService;
use Naf\Board\Services\NotificationService;
use Naf\Board\Services\PageRenderer;
use Naf\Board\Services\PreferenceService;
use Naf\Board\Services\ProjectService;
use Naf\Board\Services\RoleService;
use Naf\Board\Services\SettingsService;
use Naf\Board\Services\SlotRenderer;
use Naf\Board\Services\TicketMetadataReader;
use Naf\Board\Services\TicketMetadataWriter;
use Naf\Board\Services\TicketService;
use Naf\Board\Services\TimerService;
use Naf\Board\Support\Settings\DatabaseSettingsStore;
use Naf\Board\Support\Settings\PreferenceStore;
use Naf\Board\Support\Ticket\DatabaseTicketMetadataStore;
use Psr\Container\ContainerInterface;

/**
 * Nafinity's own service defaults, bound lazily.
 *
 * Every replaceable service is bound twice: once under its concrete class, so
 * existing constructor calls keep working, and once under its contract, which
 * is what consumers ask for. An extension rebinds the contract and reaches
 * every consumer, including background jobs.
 *
 * Nothing is resolved here. The closures run when a request, a command or a job
 * first needs the service.
 */
final class ServiceDefaults
{
    /** Contract to default implementation. */
    private const array SERVICES = [
        AccessInterface::class               => Access::class,
        AccountServiceInterface::class       => AccountService::class,
        AiServiceInterface::class            => AiService::class,
        AttachmentServiceInterface::class    => AttachmentService::class,
        BoardQueryInterface::class           => BoardQuery::class,
        CommentServiceInterface::class       => CommentService::class,
        NotificationServiceInterface::class  => NotificationService::class,
        PageRendererInterface::class         => PageRenderer::class,
        PreferenceServiceInterface::class    => PreferenceService::class,
        ProjectServiceInterface::class       => ProjectService::class,
        RoleServiceInterface::class          => RoleService::class,
        SettingsServiceInterface::class      => SettingsService::class,
        SettingsStoreInterface::class        => DatabaseSettingsStore::class,
        TicketMetadataReaderInterface::class => TicketMetadataReader::class,
        TicketMetadataStoreInterface::class  => DatabaseTicketMetadataStore::class,
        TicketServiceInterface::class        => TicketService::class,
        TimerServiceInterface::class         => TimerService::class,
    ];

    /** Services that have no contract of their own but are still shared. */
    private const array SHARED = [
        PreferenceStore::class,
        SlotRenderer::class,
        TicketMetadataWriter::class,
    ];

    /**
     * Bind every default; callers get one shared instance per class
     *
     * @param ContainerInterface $container The application container
     */
    public static function register(ContainerInterface $container): void
    {
        foreach (self::SHARED as $shared) {
            $container->set($shared, static fn() => Resolver::build($container, $shared));
        }

        // The base container hands a closure the inner container, not this
        // decorator, so the one that can build services is captured here.
        foreach (self::SERVICES as $contract => $default) {
            $container->set(
                $default,
                static fn() => Resolver::build($container, $default),
            );
            $container->set(
                $contract,
                static fn() => $container->get($default),
            );
        }
    }
}
