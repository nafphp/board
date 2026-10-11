<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Domain\Failure;
use Naf\Board\Support\Themes;

use function Naf\I18n\t;

/** Theme edits are atomic installation configuration writes, with its existing revision. @internal */
final class ThemeSettings
{
    public function __construct(private ApplicationSettings $settings)
    {
    }

    public function authorize(): void
    {
        $this->settings->authorize();
    }

    public function draft(array $data): array
    {
        return Themes::normalize([
            'name'    => $data['name'] ?? '',
            'base'    => $data['base'] ?? 'classic',
            'enabled' => ($data['enabled'] ?? '') === '1',
            'common'  => $data['common'] ?? [],
            'light'   => $data['light'] ?? [],
            'dark'    => $data['dark'] ?? [],
            'css'     => $data['css'] ?? '',
        ]);
    }

    public function save(array $data): void
    {
        $this->authorize();
        $revision = $data['configurationRevision'] ?? null;
        if (!is_string($revision)) {
            throw new Failure(t('Bitte lade die Einstellungen neu.'), 409);
        }
        if (($data['action'] ?? '') === 'reset') {
            $this->settings->save([], [Themes::KEY], $revision);

            return;
        }
        $catalog = Themes::catalog();
        $id      = $data['id'] ?? '';
        if (!is_string($id)) {
            throw new Failure(t('Dieses Theme ist nicht verfügbar.'), 422);
        }
        if (($data['action'] ?? '') === 'default') {
            $catalog['default'] = $id;
        } elseif (($data['action'] ?? '') === 'delete') {
            if (!isset($catalog['items'][$id]) || $catalog['default'] === $id) {
                throw new Failure(t('Bitte wähle vor dem Löschen einen anderen Installationsstandard.'), 422);
            }
            unset($catalog['items'][$id]);
        } else {
            if ($id === '') {
                $id = 'theme-' . bin2hex(random_bytes(6));
            } elseif (!isset($catalog['items'][$id])) {
                throw new Failure(t('Dieses Theme ist nicht verfügbar.'), 422);
            }
            $catalog['items'][$id] = $this->draft($data);
        }
        $this->settings->save([Themes::KEY => $catalog], [], $revision);
    }
}
