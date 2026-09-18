<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Failure;
use App\Support\Input;
use Naf\Auth\Exceptions\UnauthenticatedException;
use Nafinity\Contracts\AiServiceInterface;
use Psr\Http\Message\ResponseInterface;

use function Naf\json;
use function Naf\request;

final class AiController
{
    public function __construct(private AiServiceInterface $ai)
    {
    }

    public function tools(): ResponseInterface
    {
        return $this->respond(function () {
            $project = request()->getQueryParams()['project'] ?? null;

            return ['tools' => $this->ai->definitions($project === null ? null : Input::id($project))];
        });
    }

    public function call(): ResponseInterface
    {
        return $this->respond(function () {
            $data    = Input::body();
            $project = $data['project'] ?? null;

            return ['result' => $this->ai->call($project === null ? null : Input::id($project), $data)];
        });
    }

    private function respond(callable $operation): ResponseInterface
    {
        try {
            return json($operation())->withHeader('Cache-Control', 'private, no-store');
        } catch (UnauthenticatedException) {
            return json(['message' => 'Bitte melde dich erneut an.'], 401);
        } catch (Failure $exception) {
            return json(['message' => $exception->getMessage()], $exception->status);
        }
    }
}
