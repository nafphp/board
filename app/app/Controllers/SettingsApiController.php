<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Failure;
use App\Support\Input;
use Naf\Auth\Exceptions\UnauthenticatedException;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\SettingsServiceInterface;
use Nafinity\Definition\SettingDefinition;
use Nafinity\Support\SettingsContext;
use Psr\Http\Message\ResponseInterface;

use function Naf\json;
use function Naf\redirect;
use function Naf\request;
use function Naf\route;
use function Nafinity\extensions;

/**
 * The protected JSON access to declared settings.
 *
 * These routes answer with values, never with definitions, configuration trees
 * or anything marked sensitive. They are separate from the existing HTML forms
 * and share their authorization with them through the settings service.
 */
final class SettingsApiController
{
    public function __construct(
        private SettingsServiceInterface $settings,
        private AccessInterface $access,
    ) {
    }

    public function readUser(): ResponseInterface
    {
        return $this->respond(fn() => $this->values(SettingsContext::user($this->access->actor())));
    }

    public function writeUser(): ResponseInterface
    {
        return $this->write(SettingsContext::user($this->access->actor()), route('preferences'));
    }

    public function readProject(string $project): ResponseInterface
    {
        return $this->respond(function () use ($project) {
            $id = Input::id($project);
            $this->access->project($id);

            return $this->values(SettingsContext::project($id));
        });
    }

    public function writeProject(string $project): ResponseInterface
    {
        $id = Input::id($project);
        $this->access->project($id);

        return $this->write(
            SettingsContext::project($id),
            route('project.settings', ['project' => $id]),
        );
    }

    public function readProjectUser(string $project): ResponseInterface
    {
        return $this->respond(function () use ($project) {
            $id = Input::id($project);
            $this->access->project($id);

            return $this->values(SettingsContext::projectUser($id, $this->access->actor()));
        });
    }

    public function writeProjectUser(string $project): ResponseInterface
    {
        $id = Input::id($project);
        $this->access->project($id);

        return $this->write(
            SettingsContext::projectUser($id, $this->access->actor()),
            route('project.settings', ['project' => $id]),
        );
    }

    /**
     * The readable, non-sensitive values, optionally narrowed to one key
     *
     * @param SettingsContext $context Scope and owner
     */
    private function values(SettingsContext $context): array
    {
        $values = $this->settings->all($context);
        $key    = request()->getQueryParams()['key'] ?? null;

        if ($key === null) {
            return ['values' => $values];
        }

        if (!is_string($key) || !array_key_exists($key, $values)) {
            throw new Failure('Diese Einstellung gibt es hier nicht.', 404);
        }

        return ['values' => [$key => $values[$key]]];
    }

    /**
     * @param SettingsContext $context  Scope and owner
     * @param string          $fallback Where a native form post returns to
     */
    private function write(SettingsContext $context, string $fallback): ResponseInterface
    {
        return $this->respond(function () use ($context, $fallback) {
            $body      = Input::body();
            $values    = $body['values'] ?? [];
            $resetKeys = $body['resetKeys'] ?? [];

            if (!is_array($values) || !is_array($resetKeys)) {
                throw new Failure('Erwartet wird {"values": {...}, "resetKeys": [...]}.', 422);
            }

            $this->settings->save($context, $this->coerce($context, $values), array_values($resetKeys));

            if (!$this->wantsJson()) {
                return ['redirect' => $fallback];
            }

            return $this->values($context);
        });
    }

    /**
     * Turn a native form's strings into what the declared types expect
     *
     * A form sends everything as text, so a boolean arrives as `0` or `1` and a
     * multiselect as a list. Anything unknown is passed through untouched and
     * rejected by the type, never silently corrected.
     *
     * @param SettingsContext $context Scope and owner
     * @param array           $values  The submitted values
     */
    private function coerce(SettingsContext $context, array $values): array
    {
        if ($this->wantsJson()) {
            return $values;
        }

        $registry = extensions()->settings();
        $coerced  = [];

        foreach ($values as $key => $value) {
            $definition = is_string($key) ? $registry->find($context->scope, $key) : null;

            $coerced[$key] = $definition instanceof SettingDefinition
                && $definition->type === 'multiselect'
                && !is_array($value)
                    ? [$value]
                    : $value;
        }

        return $coerced;
    }

    private function wantsJson(): bool
    {
        return str_contains(request()->getHeaderLine('Accept'), 'application/json');
    }

    private function respond(callable $operation): ResponseInterface
    {
        try {
            $result = $operation();

            if (isset($result['redirect'])) {
                return redirect($result['redirect'], 303);
            }

            return json($result)->withHeader('Cache-Control', 'private, no-store');
        } catch (UnauthenticatedException) {
            return json(['message' => 'Bitte melde dich an.'], 401)
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (Failure $exception) {
            return json(
                ['message' => $exception->getMessage(), 'errors' => $exception->errors],
                $exception->status,
            )->withHeader('Cache-Control', 'private, no-store');
        }
    }
}
