<?php

declare(strict_types=1);

namespace Example\ExtensionA\Controllers;

use App\Domain\Failure;
use App\Support\Input;
use Example\ExtensionA\ExtensionAProvider;
use Example\ExtensionA\Services\ReportService;
use Naf\Auth\Exceptions\UnauthenticatedException;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\PageRendererInterface;
use Psr\Http\Message\ResponseInterface;

use function Naf\redirect;
use function Naf\View\render;
use function Nafinity\settings;
use function Nafinity\template;

/**
 * The reports page.
 *
 * An ordinary class with constructor injection and named route parameters. It
 * renders through Nafinity's page renderer, so it gets the same shell, the same
 * language and the same menu as every built-in page without inheriting anything.
 */
final class ReportController
{
    public function __construct(
        private ReportService $reports,
        private AccessInterface $access,
        private PageRendererInterface $pages,
    ) {
    }

    /**
     * @param string $project The project id from the route
     */
    public function show(string $project): ResponseInterface
    {
        try {
            $projectId = Input::id($project);
            // 404 for a project that is not this person's, 403 without the right.
            $scope = $this->access->project($projectId, ExtensionAProvider::PERMISSION);
            $limit = (int) settings()->forProject($projectId)->get('example.reports.limit', 25);

            return $this->pages->render('example-a/reports', [
                'title' => 'Berichte',
                'scope' => $scope,
                ...$this->reports->summary($projectId, $limit),
                'limit'   => $limit,
                'compact' => (bool) settings()->get('example.reports.compact', false),
            ]);
        } catch (UnauthenticatedException) {
            return redirect('/login', 303);
        } catch (Failure $exception) {
            return render(template('error'), [
                'message' => $exception->getMessage(),
                'status'  => $exception->status,
            ])->withStatus($exception->status);
        }
    }
}
