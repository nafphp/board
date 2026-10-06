<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\NotificationServiceInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Contracts\PreferenceServiceInterface;
use Naf\Board\Support\Input;
use Psr\Http\Message\ResponseInterface;

use function Naf\route;

/**
 * What a person decides for themselves: preferences, language, and which
 * notifications reach them.
 *
 * @internal
 */
final class PreferenceController
{
    public function __construct(
        private PreferenceServiceInterface $preferences,
        private NotificationServiceInterface $notifications,
        private AccessInterface $access,
        private PageRendererInterface $pages,
    ) {
    }

    public function show(): ResponseInterface
    {
        return $this->pages->render('settings', ['title' => 'Einstellungen']);
    }

    public function save(): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) {
            $this->preferences->save($data);

            return ['url' => route('preferences')];
        });
    }

    public function language(): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) {
            $locale = $data['locale'] ?? null;
            $this->preferences->language($locale);

            // No address is returned on purpose: the page the picker sits on reloads itself,
            // and a destination taken from a request header would be an open redirect.
            return ['locale' => $locale];
        });
    }

    public function notifications(): ResponseInterface
    {
        return Respond::read(
            fn() => $this->pages->render('notifications', [
                'title'         => 'Benachrichtigungen',
                'notifications' => $this->notifications->list(),
            ]),
        );
    }

    public function markRead(): ResponseInterface
    {
        return Respond::mutation($this->access, function () {
            $this->notifications->markRead();

            return ['url' => route('notifications')];
        });
    }

    public function mute(string $project): ResponseInterface
    {
        return Respond::mutation($this->access, function ($data) use ($project) {
            $this->preferences->mute(Input::id($project), ($data['muted'] ?? '') === '1');

            return ['url' => route('notifications')];
        });
    }
}
