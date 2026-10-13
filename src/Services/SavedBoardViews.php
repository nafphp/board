<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\SettingsStoreInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\Input;
use Naf\Board\Support\SettingsContext;

use function Naf\I18n\t;
use function Naf\route;

/** Personal bookmarks of normalized board filters. @internal */
final class SavedBoardViews
{
    private const string KEY = 'board.saved_views';

    public function __construct(
        private AccessInterface $access,
        private BoardQueryInterface $boards,
        private SettingsStoreInterface $store,
    ) {
    }

    public function read(int $project): array
    {
        $this->access->project($project);
        $key     = self::KEY . '.' . $project;
        $context = SettingsContext::user($this->access->actor());
        $views   = $this->store->read($context, [$key])[$key] ?? [];

        return ['views' => $views, 'revision' => $this->revision($views)];
    }

    public function save(int $project, array $data): string
    {
        return $this->access->write($project, 'read', function () use ($project, $data): string {
            $current = $this->read($project);
            if (($data['revision'] ?? null) !== $current['revision']) {
                throw new Failure(t('Deine gespeicherten Ansichten wurden inzwischen geändert. Bitte lade die Seite neu.'), 409);
            }
            $views = $current['views'];
            if (isset($data['remove'])) {
                if (!is_string($data['remove']) || !isset($views[$data['remove']])) {
                    throw new Failure(t('Diese gespeicherte Ansicht gibt es nicht.'), 404);
                }
                unset($views[$data['remove']]);
                $url = route('board', ['project' => $project]);
            } else {
                if (count($views) >= 20) {
                    throw new Failure(t('Du kannst bis zu 20 Ansichten pro Projekt speichern.'));
                }
                $name = trim(Input::validate($data, ['name' => 'required|string|max:80'])['name']);
                if ($name === '') {
                    throw new Failure(t('Bitte gib der Ansicht einen Namen.'));
                }
                $query = $data['query'] ?? [];
                if (!is_array($query)) {
                    throw new Failure(t('Ungültige Filter.'));
                }
                // The same query, normalization and authorization as opening this board.
                $filters                          = $this->boards->board($project, $query)['filters'];
                $views[bin2hex(random_bytes(12))] = ['name' => $name, 'filters' => $filters];
                $url                              = self::url($project, $filters);
            }
            $this->store->write(
                SettingsContext::user($this->access->actor()),
                [self::KEY . '.' . $project => $views],
            );

            return $url;
        });
    }

    /** Portable links contain filters, never a private bookmark id or an access token. */
    public static function url(int $project, array $filters): string
    {
        $query = self::parameters($filters);

        return route('board', ['project' => $project]) . ($query ? '?' . http_build_query($query) : '');
    }

    public static function parameters(array $filters): array
    {
        $query = [];
        foreach ($filters as $id => $value) {
            if (in_array($id, ['q', 'dql', 'status', 'column', 'swimlane', 'assignee', 'label', 'priority'], true)) {
                $query[$id] = $value;
            } else {
                $query['filters'][$id] = $value;
            }
        }

        return $query;
    }

    private function revision(array $views): string
    {
        return hash('sha256', json_encode($views, JSON_THROW_ON_ERROR));
    }
}
