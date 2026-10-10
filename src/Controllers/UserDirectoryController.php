<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Services\BulkUpdateService;
use Naf\Board\Services\UserDirectory;
use Naf\Board\Support\Input;
use Psr\Http\Message\ResponseInterface;

use function Naf\request;
use function Naf\response;
use function Naf\route;

/** @internal */
final class UserDirectoryController
{
    public function __construct(
        private UserDirectory $directory,
        private BulkUpdateService $bulk,
        private AccessInterface $access,
        private BoardQueryInterface $boards,
        private PageRendererInterface $pages,
    ) {
    }

    public function index(): ResponseInterface
    {
        return Respond::read(function () {
            $this->directory->authorize();
            $data = $this->directory->page(request()->getQueryParams(), $this->boards->projects());

            return response($this->pages->fragment('settings/users-table', [
                ...$data,
                'bulkable' => $this->bulk->properties('users') !== [],
            ]))->withHeader('Cache-Control', 'private, no-store');
        });
    }

    public function show(string $user): ResponseInterface
    {
        return Respond::read(fn() => response($this->pages->fragment('settings/user-detail', [
            'person' => $this->directory->person(Input::id($user)),
        ]))->withHeader('Cache-Control', 'private, no-store'));
    }

    public function update(): ResponseInterface
    {
        return Respond::mutation($this->access, function (array $data) {
            $this->directory->authorize();
            // Opposing operations on the same role are ambiguous, even if the
            // individual properties are valid. The user chooses one intention.
            if (isset($data['values']['add_role'], $data['values']['remove_role'])
                && $data['values']['add_role'] === $data['values']['remove_role']) {
                throw new Failure(\Naf\I18n\t('Eine Rolle kann nicht gleichzeitig hinzugefügt und entfernt werden.'), 422);
            }
            $count = $this->bulk->apply('users', $data['targets'] ?? null, $data['values'] ?? null);

            return ['count' => $count, 'url' => route('installation.settings') . '#installation_users'];
        });
    }
}
