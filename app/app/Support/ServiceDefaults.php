<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Access;
use App\Services\AccountService;
use App\Services\AiService;
use App\Services\AttachmentService;
use App\Services\BoardQuery;
use App\Services\CommentService;
use App\Services\NotificationService;
use App\Services\PageRenderer;
use App\Services\PreferenceService;
use App\Services\ProjectService;
use App\Services\RoleService;
use App\Services\SettingsService;
use App\Services\SlotRenderer;
use App\Services\TicketMetadataReader;
use App\Services\TicketMetadataWriter;
use App\Services\TicketService;
use App\Services\TimerService;
use App\Support\Settings\DatabaseSettingsStore;
use App\Support\Settings\PreferenceStore;
use App\Support\Ticket\DatabaseTicketMetadataStore;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\AccountServiceInterface;
use Nafinity\Contracts\AiServiceInterface;
use Nafinity\Contracts\AttachmentServiceInterface;
use Nafinity\Contracts\BoardQueryInterface;
use Nafinity\Contracts\CommentServiceInterface;
use Nafinity\Contracts\NotificationServiceInterface;
use Nafinity\Contracts\PageRendererInterface;
use Nafinity\Contracts\PreferenceServiceInterface;
use Nafinity\Contracts\ProjectServiceInterface;
use Nafinity\Contracts\RoleServiceInterface;
use Nafinity\Contracts\SettingsServiceInterface;
use Nafinity\Contracts\SettingsStoreInterface;
use Nafinity\Contracts\TicketMetadataReaderInterface;
use Nafinity\Contracts\TicketMetadataStoreInterface;
use Nafinity\Contracts\TicketServiceInterface;
use Nafinity\Contracts\TimerServiceInterface;
use Nafinity\Support\Resolver;
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
