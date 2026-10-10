<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Export\ExportOptions;
use Naf\Board\Export\TimeExportOptions;
use Naf\Board\Rbac\Installation;
use Naf\Board\Services\ExportService;
use Naf\Board\Services\TimeExportService;
use Naf\Board\Support\Input;
use Psr\Http\Message\ResponseInterface;

use function Naf\I18n\t;
use function Naf\Rbac\rbac;
use function Naf\request;

/**
 * Boards as files, in whichever formats the exporter registry holds.
 *
 * The format comes out of the request and is looked up in the registry, so
 * nothing here knows about CSV or JSON and will keep knowing nothing about the
 * third one. All this does is turn a written stream into a download.
 *
 * @internal
 */
final class ExportController
{
    public function __construct(
        private ExportService $exports,
        private BoardQueryInterface $query,
        private AccessInterface $access,
        private TimeExportService $time,
    ) {
    }

    /** One board, everything on it. */
    public function board(string $project, string $format): ResponseInterface
    {
        return Respond::read(fn() => $this->file($this->exports->write(Input::id($project), $format)));
    }

    /** One board, narrowed to what the export card selected. */
    public function selection(string $project): ResponseInterface
    {
        return Respond::read(function () use ($project) {
            $input  = request()->getQueryParams();
            $format = Input::validate($input, ['format' => 'required|string|max:80'])['format'];

            return $this->file(
                $this->exports->writeSelected([Input::id($project)], $format, ExportOptions::fromInput($input)),
            );
        });
    }

    /** One board or all of them, for whoever administers the installation. */
    public function installation(): ResponseInterface
    {
        return Respond::read(function () {
            if (!rbac()->allows($this->access->actor(), Installation::MANAGE_SETTINGS)) {
                throw new Failure(t('Diese Seite ist Administratoren vorbehalten.'), 403);
            }
            $input     = request()->getQueryParams();
            $format    = Input::validate($input, ['format' => 'required|string|max:80'])['format'];
            $selection = $input['project'] ?? '';
            $projects  = $selection === 'all'
                ? array_map(intval(...), array_column($this->exports->availableProjects($this->query->projects()), 'id'))
                : [Input::id($selection, 'project')];

            return $this->file(
                $this->exports->writeSelected($projects, $format, ExportOptions::fromInput($input), true),
            );
        });
    }

    /** Personal exports are scoped to the signed-in account, never a request's user id. */
    public function personal(): ResponseInterface
    {
        return Respond::read(function () {
            $this->access->actor();
            $input  = request()->getQueryParams();
            $format = Input::validate($input, ['format' => 'required|string|max:80'])['format'];
            if (($input['source'] ?? 'time') !== 'time') {
                throw new Failure(t('Diese Exportdaten gibt es nicht.'), 404);
            }
            $selection = $input['project'] ?? 'all';
            $projects  = $selection === 'all'
                ? array_map(intval(...), array_column($this->time->availableProjects(), 'id'))
                : [Input::id($selection, 'project')];

            return $this->file($this->time->write($projects, $format, TimeExportOptions::fromInput($input)));
        });
    }

    /** @param array{stream: resource, mime: string, filename: string} $file */
    private function file(array $file): ResponseInterface
    {
        return Respond::download(
            $file['stream'],
            $file['mime'],
            'attachment; filename="' . $file['filename'] . '"',
        );
    }
}
