<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\ProjectScope;
use Naf\Auth\Auth;
use Nafinity\Contracts\BoardQueryInterface;
use Nafinity\Contracts\PageRendererInterface;
use Nafinity\Contracts\TimerServiceInterface;
use Nafinity\Support\UiContext;
use Psr\Http\Message\ResponseInterface;

use function Naf\redirect;
use function Naf\route;
use function Naf\View\asset;
use function Naf\View\render;
use function Naf\View\view;
use function Nafinity\extensions;

/**
 * The application shell every normal page is rendered in.
 *
 * Language, own projects, the running timer, the signed-in user and the
 * personal preferences are the renderer's own answer. Page data is spread
 * first, so a template cannot hand in a different `user` and be believed.
 */
final class PageRenderer implements PageRendererInterface
{
    public function __construct(
        private Auth $auth,
        private BoardQueryInterface $query,
        private TimerServiceInterface $timers,
    ) {
    }

    public function render(string $template, array $data = []): ResponseInterface
    {
        if (!$this->auth->check()) {
            return redirect('/login', 303);
        }

        $preferences = $this->query->preferences();
        \Naf\I18n\translator()->setLanguage($preferences['locale']);
        $this->assets();

        return render($this->template($template), [
            ...$data,
            'projects'     => $this->query->projects(),
            'runningTimer' => $this->timers->running(),
            'user'         => $this->auth->user(),
            'preferences'  => $preferences,
            'uiContext'    => $this->context($data),
        ]);
    }

    public function fragment(string $template, array $data = []): string
    {
        return view($this->template($template), $data);
    }

    /**
     * Hand every registered asset to NAF's asset service, in registry order
     *
     * The service decides css from js by the file's extension, which is why a
     * registered path carries no query string.
     */
    private function assets(): void
    {
        $service = asset();

        foreach (extensions()->assets()->all() as $definition) {
            $service->add($definition->publicPath, $definition->module ? 'module' : 'classic');
        }
    }

    /**
     * Resolve a logical view name through the registered mappings
     *
     * @param string $template Logical view name
     */
    public function template(string $template): string
    {
        return extensions()->views()->resolve($template);
    }

    /**
     * Build the rendering context contributions are filtered against
     *
     * @param array $data Page data; only its already authorized scope is used
     */
    private function context(array $data): UiContext
    {
        $scope = $data['scope'] ?? null;
        $mode  = $data['mode'] ?? UiContext::MODE_PAGE;

        return new UiContext(
            (int) $this->auth->id(),
            $scope instanceof ProjectScope ? $scope : null,
            is_string($mode) ? $mode : UiContext::MODE_PAGE,
            route()->current(),
            isset($data['ticket']['id']) ? (int) $data['ticket']['id'] : null,
            is_array($data['ticket'] ?? null) ? $data['ticket'] : [],
        );
    }
}
