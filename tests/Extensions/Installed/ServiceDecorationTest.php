<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Example\ExtensionB\Services\CountingTicketService;
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
use Naf\Board\Contracts\TicketServiceInterface;
use Naf\Board\Contracts\TimerServiceInterface;
use Naf\Board\Controllers\AiController;
use Naf\Board\Controllers\AppController;
use Naf\Board\Events\ActivityListener;
use Naf\Board\Jobs\FinalizeAttachmentJob;
use Naf\Board\Jobs\MaintenanceJob;
use Naf\Board\Services\BoardQuery;
use Naf\Board\Services\CommentService;
use Naf\Board\Services\PageRenderer;
use Naf\Board\Services\RoleService;
use Naf\Board\Support\Resolver;
use Naf\Board\Tests\Support\ContractDecorator;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;
use Naf\CLI\Core\Output;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Throwable;

use function Naf\app;

/**
 * Replacing a service the application depends on.
 *
 * An extension binds its own implementation of a contract, and everything that
 * uses that contract has to receive it -- not only whoever asks the container
 * directly. A replacement that satisfies container->get() and nothing else would
 * look right and change nothing.
 *
 * So each contract is wrapped and the consumer that really uses it in production
 * is built the way the application builds it, then asked whether it is holding
 * the replacement.
 */
final class ServiceDecorationTest extends ExtensionInstalledTestCase
{
    public function testTheBoundTicketServiceIsTheOneExtensionBProvides(): void
    {
        $this->assertInstanceOf(CountingTicketService::class, $this->tickets);
        $this->assertSame(
            $this->tickets,
            app()->container()->get(TicketServiceInterface::class),
            'consumers get a different instance than the container hands out',
        );
    }

    /**
     * @return array<string,array{class-string,class-string}>
     */
    public static function contractsAndTheirConsumers(): array
    {
        $consumers = [
            AccessInterface::class              => BoardQuery::class,
            AccountServiceInterface::class      => MaintenanceJob::class,
            AiServiceInterface::class           => AiController::class,
            AttachmentServiceInterface::class   => MaintenanceJob::class,
            BoardQueryInterface::class          => PageRenderer::class,
            CommentServiceInterface::class      => AppController::class,
            NotificationServiceInterface::class => ActivityListener::class,
            PageRendererInterface::class        => AppController::class,
            PreferenceServiceInterface::class   => AppController::class,
            ProjectServiceInterface::class      => RoleService::class,
            RoleServiceInterface::class         => AppController::class,
            TicketServiceInterface::class       => CommentService::class,
            TimerServiceInterface::class        => PageRenderer::class,
        ];
        $cases = [];
        foreach ($consumers as $contract => $consumer) {
            $cases[substr((string) strrchr($contract, '\\'), 1)] = [$contract, $consumer];
        }

        return $cases;
    }

    #[DataProvider('contractsAndTheirConsumers')]
    public function testTheConsumerReceivesTheReplacement(string $contract, string $consumer): void
    {
        $container = app()->container();
        $original  = $container->get($contract);
        $className = ContractDecorator::for($contract);
        $standIn   = new $className($original);

        $container->set($contract, static fn() => $standIn);

        try {
            $built = Resolver::build($container, $consumer);
            $found = false;
            foreach ((new ReflectionClass($built))->getProperties() as $property) {
                if ($property->getValue($built) === $standIn) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue($found, $consumer . ' did not receive the replacement of ' . $contract);
        } finally {
            $container->set($contract, static fn() => $original);
        }
    }

    /** Background work is built the same way, and must go through it too. */
    public function testTheBackgroundJobsRunThroughTheReplacement(): void
    {
        $container = app()->container();
        $contract  = AttachmentServiceInterface::class;
        $original  = $container->get($contract);
        $className = ContractDecorator::for($contract);
        $standIn   = new $className($original);

        $container->set($contract, static fn() => $standIn);

        try {
            $output = new Output();
            ob_start();

            try {
                $container->make(FinalizeAttachmentJob::class, ['attachmentId' => 1])->execute($output);
            } catch (Throwable) {
                // There is no attachment 1; which object was asked is the point.
            }
            $container->make(MaintenanceJob::class)->execute($output);
            ob_end_clean();
        } finally {
            $container->set($contract, static fn() => $original);
        }

        $this->assertContains('finalize', $standIn->calls, 'the finalize job bypassed the replacement');
        $this->assertContains('cleanup', $standIn->calls, 'the maintenance job bypassed the replacement');
    }
}
