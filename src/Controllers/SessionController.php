<?php

declare(strict_types=1);

namespace Naf\Board\Controllers;

use Naf\Auth\Auth;
use Naf\Auth\Credentials\PasswordCredentials;
use Naf\Board\Contracts\AccountServiceInterface;
use Naf\Board\Contracts\BoardQueryInterface;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Services\RememberService;
use Naf\Board\Support\Input;
use Naf\RateLimit\PdoLimiter;
use Naf\Session\Core\Session;
use Psr\Http\Message\ResponseInterface;

use function Naf\Board\template;
use function Naf\config;
use function Naf\Form\csrf;
use function Naf\redirect;
use function Naf\request;
use function Naf\View\render;

/**
 * Signing in, signing out, and where a signed-in person lands.
 *
 * @internal
 */
final class SessionController
{
    public function __construct(
        private Auth $auth,
        private AccountServiceInterface $accounts,
        private PdoLimiter $limiter,
        private Session $session,
        private RememberService $remember,
        private BoardQueryInterface $query,
        private PageRendererInterface $pages,
    ) {
    }

    public function login(): ResponseInterface
    {
        if ($this->auth->check()) {
            return redirect('/', 303);
        }

        return render(template('login'), [
            'error'  => null,
            'email'  => '',
            'notice' => $this->session->getFlash('account.notice'),
        ])->withHeader('Cache-Control', 'no-store');
    }

    public function authenticate(): ResponseInterface
    {
        try {
            $body = Input::body();
            $data = Input::validate($body, [
                'email'    => 'required|string|email|max:190',
                'password' => 'required|string|max:1024',
            ]);
            $email        = strtolower(trim($data['email']));
            $peer         = request()->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
            $ipLimit      = $this->limiter->consume('login:ip:' . $peer, 100, 600);
            $accountLimit = $this->limiter->consume('login:account:' . $email, 10, 600);

            if (!$ipLimit['allowed'] || !$accountLimit['allowed']) {
                $retryAfter = max($ipLimit['retry_after'], $accountLimit['retry_after']);

                return render(template('login'), [
                    'error' => 'Zu viele Anmeldeversuche. Bitte versuche es später erneut.',
                    'email' => $data['email'],
                ])
                    ->withStatus(429)
                    ->withHeader('Retry-After', (string) $retryAfter);
            }

            $useLdap     = ($body['provider'] ?? 'users') === 'ldap' && config('ldap:enabled', false);
            $provider    = $useLdap ? 'ldap' : 'users';
            $credentials = new PasswordCredentials($email, $data['password']);

            if (!$this->accounts->authenticate($credentials, $provider)) {
                return render(template('login'), [
                    'error' => 'E-Mail oder Passwort stimmt nicht.',
                    'email' => $data['email'],
                ])->withStatus(401);
            }

            csrf()->generate();

            // Asked for, not assumed. A cookie that outlives the session is a
            // convenience somebody chooses on the device they are sitting at,
            // and not one an application decides on their behalf.
            if (($body['remember'] ?? '') !== '') {
                $this->remember->issue((int) $this->auth->id());
            }

            return redirect('/', 303);
        } catch (Failure $exception) {
            return render(template('login'), ['error' => $exception->getMessage(), 'email' => ''])
                ->withStatus($exception->status);
        }
    }

    public function logout(): ResponseInterface
    {
        // Before the session goes, because forgetting reads the cookie and needs
        // nothing else -- but after this the person is a guest, and a guest
        // signing out is not who this row belonged to.
        $this->remember->forget();
        $this->auth->logout();
        csrf()->generate();

        return redirect('/login', 303);
    }

    /** The first board somebody belongs to, or the list to start one from. */
    public function home(): ResponseInterface
    {
        if (!$this->auth->check()) {
            return redirect('/login', 303);
        }
        $projects = $this->query->projects();
        if ($projects) {
            return redirect('/projects/' . $projects[0]['id'], 303);
        }

        return $this->pages->render('projects', ['title' => 'Deine Projekte']);
    }
}
