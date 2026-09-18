<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Failure;
use App\Domain\ProjectScope;
use App\Support\Input;
use Naf\MCP\Support\ToolRegistry;
use Naf\RateLimit\PdoLimiter;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\AiServiceInterface;
use Nafinity\Contracts\AiToolProviderInterface;
use Nafinity\Contracts\ProjectToolInterface;
use Nafinity\Support\AiToolContext;
use Nafinity\Support\Resolver;

use function Naf\app;
use function Nafinity\extensions;

final class AiService implements AiServiceInterface
{
    public function __construct(
        private AccessInterface $access,
        private PdoLimiter $limiter,
    ) {
    }

    public function definitions(?int $project): array
    {
        $registry    = $this->registry($project);
        $definitions = $registry->definitions();
        foreach ($definitions as &$definition) {
            $tool               = $registry->getTool($definition['name']);
            $definition['meta'] = [
                'title'    => $tool->title(),
                'risk'     => $tool->permission() === 'read' ? 'read' : 'write',
                'autoRun'  => $tool->permission() === 'read',
                'requires' => $tool->requires(),
                'keywords' => $tool->keywords(),
            ];
        }

        return $definitions;
    }

    public function call(?int $project, array $data): mixed
    {
        $actor = $this->access->actor();
        if (!$this->limiter->consume('ai:tools:' . $actor, 60, 60)['allowed']) {
            throw new Failure('Zu viele AI-Aktionen. Bitte warte kurz.', 429);
        }
        $name      = Input::validate($data, ['name' => 'required|string|max:80'])['name'];
        $arguments = $data['arguments'] ?? [];
        if (!is_array($arguments)) {
            throw new Failure('Ungültige Werkzeugargumente.');
        }
        $registry = $this->registry($project);
        if (!in_array($name, array_column($registry->definitions(), 'name'), true)) {
            throw new Failure('Dieses Werkzeug ist für dich hier nicht verfügbar.', 403);
        }
        $tool = $registry->getTool($name);
        if ($tool->permission() !== 'read' && ($data['confirmed'] ?? false) !== true) {
            throw new Failure('Bitte bestätige die Änderung zuerst im Chat.', 422);
        }

        // Checked again right before running: the catalogue may be a moment old,
        // and a right can be taken away between asking and doing.
        if (!$this->permitted($tool, $project === null ? null : $this->access->project($project))) {
            throw new Failure('Dieses Werkzeug ist für dich hier nicht verfügbar.', 403);
        }

        return $registry->call($name, $arguments);
    }

    /**
     * Build this request's tool registry from every registered provider
     *
     * Rights are filtered here, before the catalogue is handed out, and again
     * before a tool runs. Two providers cannot claim the same tool name by
     * accident: replacing one takes saying so in the provider's definition.
     *
     * @param int|null $project The project the chat is about, when there is one
     */
    private function registry(?int $project): ToolRegistry
    {
        $actor    = $this->access->actor();
        $scope    = $project === null ? null : $this->access->project($project);
        $context  = new AiToolContext($actor, $scope);
        $registry = new ToolRegistry();
        $owners   = [];

        foreach (extensions()->aiTools()->all() as $definition) {
            $provider = Resolver::service(app()->container(), $definition->provider);

            if (!$provider instanceof AiToolProviderInterface) {
                throw new Failure(
                    'Der AI-Werkzeuganbieter "' . $definition->id . '" ist ungültig.',
                    500,
                );
            }

            foreach ($provider->tools($context) as $tool) {
                $name = $tool->name();

                if (isset($owners[$name]) && !in_array($name, $definition->replaceNames, true)) {
                    throw new Failure(
                        'Das Werkzeug "' . $name . '" ist bereits von "' . $owners[$name]
                        . '" belegt. Nenne es in replaceNames, um es zu ersetzen.',
                        500,
                    );
                }

                $owners[$name] = $definition->id;

                if ($this->permitted($tool, $scope)) {
                    $registry->register($tool);
                }
            }
        }

        return $registry;
    }

    /**
     * Whether the actor may see and run a tool right now
     *
     * @param ProjectToolInterface $tool  The tool being offered
     * @param ProjectScope|null    $scope The authorized project, when there is one
     */
    private function permitted(ProjectToolInterface $tool, ?ProjectScope $scope): bool
    {
        return $tool->permission() === 'read' || (bool) $scope?->allows($tool->permission());
    }
}
