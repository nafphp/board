<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Contracts\ProjectServiceInterface;
use Naf\Board\Contracts\RoleServiceInterface;
use Naf\Board\Support\Input;
use Psr\Http\Message\ResponseInterface;

/**
 * A project's own settings: what it is called, who is in it, which roles and
 * which columns, lanes and labels it has.
 *
 * @internal
 */
final class ProjectController
{
    public function __construct(
        private ProjectServiceInterface $projects,
        private RoleServiceInterface $roles,
        private BoardQueryInterface $query,
        private AccessInterface $access,
        private PageRendererInterface $pages,
    ) {
    }

    public function settings(string $project): ResponseInterface
    {
        return Respond::read(function () use ($project) {
            $id    = Input::id($project);
            $scope = $this->access->project($id);

            return $this->pages->render('settings', [
                'title'       => 'Einstellungen',
                'customRoles' => $this->roles->list($id),
                ...$this->query->board($id),
                'offScale' => $this->projects->offScaleEstimates(
                    $id,
                    $scope->project['estimation_scale'] ?? null,
                ),
            ]);
        });
    }

    public function create(): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) {
            $id = $this->projects->create($data);

            return ['url' => '/projects/' . $id];
        });
    }

    public function update(string $project): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project) {
            $this->projects->update(Input::id($project), $data);

            return ['url' => '/projects/' . $project . '/settings'];
        });
    }

    public function member(string $project): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project) {
            $this->projects->member(Input::id($project), $data);

            return ['url' => '/projects/' . $project . '/settings'];
        });
    }

    public function saveRole(string $project): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project) {
            $this->roles->save(Input::id($project), $data);

            return ['url' => '/projects/' . $project . '/settings#roles'];
        });
    }

    public function structure(string $project): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project) {
            $this->projects->structure(Input::id($project), $data);

            return ['url' => '/projects/' . $project . '/settings'];
        });
    }

    public function delete(string $project): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project) {
            $this->projects->delete(Input::id($project), $data);

            return ['url' => '/projects'];
        });
    }

    public function archive(string $project): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project) {
            $this->projects->archive(
                Input::id($project),
                ($data['action'] ?? 'archive') !== 'restore',
            );

            return ['url' => '/projects/' . $project];
        });
    }
}
