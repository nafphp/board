<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\BoardFilterDefinition;
use Naf\Board\Domain\Failure;
use Naf\Board\Domain\Status;
use Naf\Board\ExtensionContext;
use Naf\Board\Support\BoardFilterContext;
use Naf\Board\Support\Input;
use Naf\Board\Support\SqlCondition;
use PDO;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\I18n\t;

/**
 * The board filters Nafinity already had, as definitions.
 *
 * One normalized value feeds the chips, the count and the card query alike, so
 * a filter cannot mean one thing in the list and another in the summary. The
 * project predicate and the archive rule stay outside every fragment.
 *
 * @internal
 */
final class CoreBoard implements ExtensionProviderInterface
{
    /** Choice filters whose accepted values are fixed. */
    private const array CHOICES = ['status' => Status::class];

    /** Relation filters and the pivot they look in. */
    private const array RELATIONS = [
        'assignee' => ['ticket_assignees', 'user_id'],
        'label'    => ['ticket_labels', 'label_id'],
    ];

    public function register(ExtensionContext $context): void
    {
        $filters = $context->boardFilters();
        $index   = 100;

        foreach (['column' => 'column_id', 'swimlane' => 'swimlane_id'] as $id => $column) {
            $filters->add(new BoardFilterDefinition(
                $id,
                ucfirst($id),
                static fn(mixed $value) => Input::id($value, $id),
                static fn(mixed $value, BoardFilterContext $filterContext) => new SqlCondition(
                    $filterContext->alias . '.' . $column . '=?',
                    [$value],
                ),
                $index,
            ));
            $index += 100;
        }

        foreach (self::RELATIONS as $id => [$table, $column]) {
            $filters->add(new BoardFilterDefinition(
                $id,
                ucfirst($id),
                static fn(mixed $value) => Input::id($value, $id),
                static fn(mixed $value, BoardFilterContext $filterContext) => new SqlCondition(
                    "EXISTS(SELECT 1 FROM $table f WHERE f.project_id={$filterContext->alias}"
                    . ".project_id AND f.ticket_id={$filterContext->alias}.id AND f.$column=?)",
                    [$value],
                ),
                $index,
            ));
            $index += 100;
        }

        /*
         * Priorities are asked for when the filter runs, not when it is
         * registered. Whoever brings one registers it during boot, and the
         * order that happens in is not this provider's to know -- capturing the
         * list here would accept exactly those declared before this line ran.
         */
        $filters->add(new BoardFilterDefinition(
            'priority',
            'Priorität',
            static function (mixed $value) {
                if (!is_string($value) || !in_array($value, extensions()->priorities()->keys(), true)) {
                    throw new Failure(t('Ungültiger Filter: priority'));
                }

                return $value;
            },
            static fn(mixed $value, BoardFilterContext $filterContext) => new SqlCondition(
                $filterContext->alias . '.priority=?',
                [$value],
            ),
            $index,
        ));
        $index += 100;

        foreach (self::CHOICES as $id => $enum) {
            $filters->add(new BoardFilterDefinition(
                $id,
                ucfirst($id),
                static function (mixed $value) use ($id, $enum) {
                    if (!is_string($value) || !in_array($value, $enum::keys(), true)) {
                        throw new Failure(t('Ungültiger Filter: :filter', ['filter' => $id]));
                    }

                    return $value;
                },
                static fn(mixed $value, BoardFilterContext $filterContext) => new SqlCondition(
                    $filterContext->alias . '.' . $id . '=?',
                    [$value],
                ),
                $index,
            ));
            $index += 100;
        }

        $filters->add(new BoardFilterDefinition(
            'q',
            'Suche',
            static fn(mixed $value) => trim(Input::validate(['q' => $value], ['q' => 'string|max:200'])['q']),
            static function (mixed $value, BoardFilterContext $filterContext): SqlCondition {
                $alias   = $filterContext->alias;
                $isMysql = app()->container()->get(PDO::class)
                    ->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

                return new SqlCondition(
                    $isMysql
                        ? "MATCH($alias.title,$alias.description) AGAINST(? IN NATURAL LANGUAGE MODE)"
                        : "to_tsvector('simple',$alias.title || ' ' || $alias.description)"
                            . " @@ plainto_tsquery('simple',?)",
                    [$value],
                );
            },
            $index,
        ));
    }
}
