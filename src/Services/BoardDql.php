<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Domain\Failure;
use Naf\Board\Support\BoardFilterContext;
use Naf\Board\Support\DqlParser;
use Naf\Board\Support\SqlCondition;

use function Naf\Board\extensions;
use function Naf\I18n\t;

/** Parse once on the server; dropdowns, saved links and live cards share this meaning. @internal */
final class BoardDql
{
    private const array ALIASES = ['ticket.status' => 'status', 'board.identifier' => 'board', 'assigned' => 'assignee', 'text' => 'q'];

    public static function parse(mixed $source): ?array
    {
        return (new DqlParser())->parse($source);
    }

    public static function condition(?array $node, BoardFilterContext $context): SqlCondition
    {
        if ($node === null) {
            return new SqlCondition('1=1');
        }
        if ($node['kind'] === 'NOT') {
            $condition = self::condition($node['child'], $context);

            return new SqlCondition('NOT (' . $condition->sql . ')', $condition->parameters);
        }
        if (in_array($node['kind'], ['AND', 'OR'], true)) {
            $left  = self::condition($node['left'], $context);
            $right = self::condition($node['right'], $context);

            return new SqlCondition('(' . $left->sql . ') ' . $node['kind'] . ' (' . $right->sql . ')', [...$left->parameters, ...$right->parameters]);
        }
        $field      = self::field($node['field']);
        $definition = extensions()->boardFilters()->get($field);
        if ($definition === null || $field === 'dql') {
            self::invalid($node, t('Unbekannte Eigenschaft: :field', ['field' => $node['field']]));
        }
        $negative   = in_array($node['operator'], ['!=', 'NOT IN'], true);
        $operator   = in_array($node['operator'], ['!=', 'IN', 'NOT IN'], true) ? '=' : $node['operator'];
        $conditions = [];
        $parameters = [];
        foreach ($node['values'] as $value) {
            try {
                if ($definition->dql !== null) {
                    $condition = ($definition->dql)($operator, $value, $context);
                } elseif ($operator === '=') {
                    $condition = $definition->condition($definition->normalize($value), $context);
                } else {
                    throw new Failure(t('Dieser Operator wird für :field nicht unterstützt.', ['field' => $field]));
                }
            } catch (Failure $failure) {
                self::invalid($node, $failure->getMessage());
            }
            $conditions[] = '(' . $condition->sql . ')';
            $parameters   = [...$parameters, ...$condition->parameters];
        }

        return new SqlCondition(($negative ? 'NOT ' : '') . '(' . implode(' OR ', $conditions) . ')', $parameters);
    }

    /** Archive visibility is only widened by an explicit reference to archived status. */
    public static function includesArchive(?array $node): bool
    {
        if ($node === null) {
            return false;
        }

        return match ($node['kind']) {
            'NOT'       => self::includesArchive($node['child']),
            'AND', 'OR' => self::includesArchive($node['left']) || self::includesArchive($node['right']),
            default     => self::field($node['field']) === 'status' && in_array('archived', $node['values'], true),
        };
    }

    public static function format(?array $node): string
    {
        if ($node === null) {
            return '';
        }

        return match ($node['kind']) {
            'AND', 'OR' => '(' . self::format($node['left']) . ' ' . $node['kind'] . ' ' . self::format($node['right']) . ')',
            'NOT'       => 'NOT (' . self::format($node['child']) . ')',
            default     => $node['field'] . ' ' . $node['operator'] . ' ' . (count($node['values']) > 1 || in_array($node['operator'], ['IN', 'NOT IN'], true)
                ? '(' . implode(', ', array_map(self::quote(...), $node['values'])) . ')' : self::quote($node['values'][0])),
        };
    }

    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /** Values that cannot be expressed as scalar DQL stay in the original filters envelope. */
    public static function advanced(array $filters): array
    {
        if (count($filters) === 1 && isset($filters['dql'])) {
            return ['expression' => $filters['dql'], 'retained' => []];
        }
        $parts    = [];
        $retained = [];
        foreach ($filters as $field => $value) {
            if ($field === 'dql') {
                if (trim($value) !== '') {
                    $parts[] = '(' . $value . ')';
                }
            } elseif (is_scalar($value) && self::portable($field, $value)) {
                $parts[] = ($field === 'q' ? 'text ~ ' : $field . ' = ') . self::quote(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            } else {
                $retained[$field] = $value;
            }
        }

        return ['expression' => implode(' AND ', $parts), 'retained' => $retained];
    }

    /** Pull only independent, representable conjuncts out of an expression. Never approximate OR/NOT. */
    public static function dropdown(?array $node, array $board): array
    {
        $filters  = [];
        $residual = [];
        foreach (self::conjuncts($node) as $part) {
            $field  = $part['kind'] === 'comparison' ? self::field($part['field']) : '';
            $value  = $part['values'][0] ?? null;
            $simple = $part['kind'] === 'comparison' && ($part['operator'] === '=' || ($field === 'q' && $part['operator'] === '~'));
            if ($simple && !array_key_exists($field, $filters)) {
                if (in_array($field, ['column', 'swimlane', 'label', 'assignee'], true)) {
                    $rows = $board[match ($field) {
                        'column' => 'columns', 'swimlane' => 'swimlanes', 'label' => 'labels', default => 'members',
                    }];
                    if ($field === 'assignee' && $value === 'me') {
                        $filters[$field] = 'me';
                        continue;
                    }
                    $matches = in_array($field, ['label', 'assignee'], true) && $value === 'none' ? [] : array_values(array_filter($rows, static fn(array $row) => ctype_digit($value) ? (int) $row['id'] === (int) $value : $row['name'] === $value || ($field === 'assignee' && $row['email'] === $value)));
                    $value   = count($matches) === 1 ? (string) $matches[0]['id'] : null;
                }
                $definition = extensions()->boardFilters()->get($field);
                $compatible = $definition?->dql === null || in_array($field, ['q', 'status', 'column', 'swimlane', 'label', 'assignee'], true);
                if ($value !== null && $compatible && ($definition?->view !== null || $field === 'q')) {
                    try {
                        $normalized = $value === 'archived' && $field === 'status' ? $value : $definition->normalize($value);
                        if ($normalized !== '' && $normalized !== null) {
                            $filters[$field] = $normalized;
                            continue;
                        }
                    } catch (Failure) {
                        // Keep an expression that a contributed control cannot represent.
                    }
                }
            }
            $residual[] = self::format($part);
        }

        return ['filters' => $filters, 'residual' => implode(' AND ', $residual)];
    }

    public static function retainedSummary(array $filters): string
    {
        $labels = array_map(static fn(string $id) => t(extensions()->boardFilters()->get($id)?->label ?? $id), array_keys($filters));

        return $labels ? t('Zusätzliche Filter bleiben aktiv: :filters', ['filters' => implode(', ', $labels)]) : '';
    }

    private static function conjuncts(?array $node): array
    {
        if ($node === null) {
            return [];
        }

        return $node['kind'] === 'AND' ? [...self::conjuncts($node['left']), ...self::conjuncts($node['right'])] : [$node];
    }

    private static function portable(string $field, mixed $value): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/D', $field) || isset(self::ALIASES[$field]) || (is_string($value) && preg_match('/[\x00-\x1f\x7f]/u', $value))) {
            return false;
        }
        if (in_array($field, ['q', 'status', 'column', 'swimlane', 'label', 'assignee'], true)) {
            return true;
        }
        $definition = extensions()->boardFilters()->get($field);
        if ($definition === null || $definition->dql !== null) {
            return false;
        }

        try {
            return $definition->normalize(is_bool($value) ? ($value ? '1' : '0') : (string) $value) === $value;
        } catch (Failure) {
            return false;
        }
    }

    private static function field(string $field): string
    {
        return self::ALIASES[$field] ?? $field;
    }

    private static function invalid(array $node, string $message): never
    {
        $message = t('DQL an Position :position: :message', ['position' => $node['position'], 'message' => $message]);
        throw new Failure($message, 422, ['dql' => [$message]]);
    }
}
