<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use LogicException;
use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\BulkPropertyHandlerInterface;
use Naf\Board\Definition\BulkProperty;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\Input;
use Naf\Board\Support\Resolver;
use Naf\ORM\Core\EntityManager;
use Throwable;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\I18n\t;

/** A bounded, explicit patch, committed as one unit across all targets/properties. */
final class BulkUpdateService
{
    public function __construct(private AccessInterface $access, private EntityManager $transactions)
    {
    }

    /** Authorized properties and server-resolved options for a mass-edit form. */
    public function properties(string $resource): array
    {
        $actor      = $this->access->actor();
        $properties = [];
        foreach (extensions()->bulkProperties()->forResource($resource) as $key => $property) {
            try {
                $options = $this->handler($property)->options($property, $actor);
            } catch (Failure $failure) {
                if ($failure->status !== 403) {
                    throw $failure;
                }
                continue;
            }
            $properties[$key] = ['definition' => $property, 'options' => $options];
        }

        return $properties;
    }

    public function apply(string $resource, mixed $targets, mixed $values): int
    {
        $actor = $this->access->actor();
        if (!is_array($targets) || !array_is_list($targets) || $targets === [] || count($targets) > 50) {
            throw new Failure(t('Bitte wähle zwischen 1 und 50 Zeilen aus.'), 422);
        }
        $ids = Input::ids($targets, 'targets');
        sort($ids, SORT_NUMERIC);
        if (!is_array($values) || $values === [] || array_is_list($values) || count($values) > 20) {
            throw new Failure(t('Bitte wähle mindestens eine Eigenschaft aus.'), 422);
        }

        // Resolve the entire allowlist first; an unknown key never becomes a column.
        $properties = extensions()->bulkProperties()->forResource($resource);
        if (array_diff_key($values, $properties) !== []) {
            throw new Failure(t('Diese Eigenschaft unterstützt kein Mass-Update.'), 422);
        }
        ksort($values, SORT_STRING);
        $this->transactions->begin();

        try {
            $writes = [];
            foreach ($values as $key => $raw) {
                $property = $properties[$key];
                $handler  = $this->handler($property);
                $options  = $handler->options($property, $actor);
                $type     = extensions()->fieldTypes()->get($property->type)
                    ?? throw new LogicException('Unknown bulk property field type: ' . $property->type);
                $errors = $type->validate($raw, $options);
                if ($errors !== []) {
                    throw new Failure(t('Bitte prüfe deine Eingaben.'), 422, ['values.' . $key => $errors]);
                }
                $writes[] = $handler->prepare($property, $actor, $ids, $type->normalize($raw, $options));
            }
            foreach ($writes as $write) {
                $write();
            }
            $this->transactions->commit();
        } catch (Throwable $failure) {
            $this->transactions->rollback();
            throw $failure;
        }

        return count($ids);
    }

    private function handler(BulkProperty $property): BulkPropertyHandlerInterface
    {
        $handler = Resolver::service(app()->container(), $property->handler);
        if (!$handler instanceof BulkPropertyHandlerInterface) {
            throw new LogicException('Bulk property handler must implement BulkPropertyHandlerInterface.');
        }

        return $handler;
    }
}
