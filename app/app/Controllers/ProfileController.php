<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Failure;
use App\Support\Input;
use Naf\Auth\Auth;
use Naf\Auth\Exceptions\UnauthenticatedException;
use Naf\Session\Core\Session;
use Nafinity\Contracts\AccountServiceInterface;
use Psr\Http\Message\ResponseInterface;

use function Naf\Form\csrf;
use function Naf\json;

final class ProfileController
{
    public function __construct(private AccountServiceInterface $accounts, private Auth $auth, private Session $session)
    {
    }

    public function show(): ResponseInterface
    {
        return $this->respond(fn() => ['profile' => $this->accounts->profile()]);
    }

    public function password(): ResponseInterface
    {
        return $this->respond(function (): array {
            $this->accounts->changePassword(Input::body());

            return $this->signedOut('Dein Passwort wurde geändert. Bitte melde dich mit dem neuen Passwort an.');
        });
    }

    public function requestEmail(): ResponseInterface
    {
        return $this->respond(fn() => ['pending' => $this->accounts->requestEmail(Input::body())]);
    }

    public function confirmEmail(): ResponseInterface
    {
        return $this->respond(function (): array {
            $this->accounts->confirmEmail(Input::body());

            return $this->signedOut('Deine neue E-Mail-Adresse wurde bestätigt. Bitte melde dich damit erneut an.');
        });
    }

    public function cancelEmail(): ResponseInterface
    {
        return $this->respond(function (): array {
            $this->accounts->cancelEmail();

            return ['pending' => null];
        });
    }

    private function signedOut(string $message): array
    {
        $this->auth->logout();
        csrf()->generate();
        $this->session->flash('account.notice', $message);

        return ['url' => '/login'];
    }

    private function respond(callable $operation): ResponseInterface
    {
        try {
            $this->auth->requireLogin();
            $response = json($operation());
        } catch (UnauthenticatedException) {
            $response = json(['message' => 'Bitte melde dich erneut an.', 'url' => '/login'], 401);
        } catch (Failure $exception) {
            $response = json(['message' => $exception->getMessage(), 'errors' => $exception->errors], $exception->status);
        }

        return $response->withHeader('Cache-Control', 'no-store');
    }
}
