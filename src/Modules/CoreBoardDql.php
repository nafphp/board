<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Definition\BoardFilterDefinition;
use Naf\Board\Domain\Failure;
use Naf\Board\Registry\BoardFilterRegistry;
use Naf\Board\Support\BoardFilterContext;
use Naf\Board\Support\Input;
use Naf\Board\Support\SqlCondition;

use function Naf\I18n\t;

/** DQL-specific operators and named values on the existing filter definitions. @internal */
final class CoreBoardDql
{
    public static function register(BoardFilterRegistry $filters): void
    {
        foreach (['board' => 'Board', 'title' => 'Titel', 'number' => 'Ticketnummer', 'due' => 'Fällig am', 'created' => 'Erstellt am', 'updated' => 'Geändert am'] as $id => $label) {
            $filters->add(new BoardFilterDefinition(
                $id,
                $label,
                static function (mixed $value) use ($id): string {
                    if (!is_string($value) || mb_strlen($value) > 200) {
                        throw new Failure(t('Ungültiger Filter: :filter', ['filter' => $id]));
                    }

                    return $value;
                },
                static fn(mixed $value, BoardFilterContext $context) => self::condition($id, '=', $value, $context),
                dql: static fn(string $operator, string $value, BoardFilterContext $context) => self::condition($id, $operator, $value, $context),
            ));
        }
    }

    public static function condition(string $field, string $operator, string $value, BoardFilterContext $context): SqlCondition
    {
        $alias = $context->alias;
        if (in_array($field, ['number', 'due', 'created', 'updated'], true)) {
            if (!in_array($operator, ['=', '>', '>=', '<', '<='], true)) {
                self::unsupported($field);
            }
            if ($field === 'number') {
                return new SqlCondition("$alias.number $operator ?", [Input::id($value, $field)]);
            }
            $column = match ($field) {
                'due' => 'due_date', 'created' => 'created_at', default => 'updated_at',
            };
            if ($value === 'none' && $field === 'due' && $operator === '=') {
                return new SqlCondition("$alias.$column IS NULL");
            }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $date) || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
                throw new Failure(t('Bitte gib ein Datum im Format JJJJ-MM-TT an.'));
            }

            return new SqlCondition("DATE($alias.$column) $operator ?", [$value]);
        }
        if ($field === 'title' && $operator === '~') {
            return new SqlCondition("$alias.title LIKE ? ESCAPE '!'", ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%']);
        }
        if ($field === 'q' && in_array($operator, ['=', '~'], true)) {
            $value = trim(Input::validate(['q' => $value], ['q' => 'string|max:200'])['q']);

            return new SqlCondition("MATCH($alias.title,$alias.description) AGAINST(? IN NATURAL LANGUAGE MODE)", [$value]);
        }
        if ($operator !== '=') {
            self::unsupported($field);
        }
        if ($field === 'title') {
            return new SqlCondition("$alias.title=?", [$value]);
        }
        if ($field === 'board') {
            return ctype_digit($value)
                ? new SqlCondition("$alias.project_id=?", [Input::id($value, $field)])
                : new SqlCondition("EXISTS(SELECT 1 FROM projects f WHERE f.id=$alias.project_id AND f.ticket_key=?)", [$value]);
        }
        if ($field === 'status') {
            if (!in_array($value, ['open', 'closed', 'archived'], true)) {
                throw new Failure(t('Status erwartet open, closed oder archived.'));
            }

            return $value === 'archived'
                ? new SqlCondition("$alias.archived_at IS NOT NULL")
                : new SqlCondition("$alias.status=? AND $alias.archived_at IS NULL", [$value]);
        }
        if (in_array($field, ['column', 'swimlane'], true)) {
            $table = $field === 'column' ? 'board_columns' : 'swimlanes';

            return ctype_digit($value)
                ? new SqlCondition("$alias.{$field}_id=?", [Input::id($value, $field)])
                : new SqlCondition("EXISTS(SELECT 1 FROM $table f WHERE f.project_id=$alias.project_id AND f.id=$alias.{$field}_id AND CAST(f.name AS BINARY)=CAST(? AS BINARY))", [$value]);
        }
        $table  = $field === 'assignee' ? 'ticket_assignees' : 'ticket_labels';
        $column = $field === 'assignee' ? 'user_id' : 'label_id';
        $base   = "f.project_id=$alias.project_id AND f.ticket_id=$alias.id";
        if ($value === 'none') {
            return new SqlCondition("NOT EXISTS(SELECT 1 FROM $table f WHERE $base)");
        }
        if ($field === 'assignee' && $value === 'me') {
            $value = $context->scope->userId;
        }
        if (ctype_digit($value)) {
            return new SqlCondition("EXISTS(SELECT 1 FROM $table f WHERE $base AND f.$column=?)", [Input::id($value, $field)]);
        }
        $join      = $field === 'assignee' ? 'users' : 'labels';
        $predicate = $field === 'assignee'
            ? '(CAST(v.name AS BINARY)=CAST(? AS BINARY) OR CAST(v.email AS BINARY)=CAST(? AS BINARY)) AND v.active=1 AND EXISTS(SELECT 1 FROM project_members m WHERE m.project_id=f.project_id AND m.user_id=v.id AND m.active=1)'
            : 'CAST(v.name AS BINARY)=CAST(? AS BINARY) AND v.project_id=f.project_id';

        return new SqlCondition("EXISTS(SELECT 1 FROM $table f JOIN $join v ON v.id=f.$column WHERE $base AND $predicate)", $field === 'assignee' ? [$value, $value] : [$value]);
    }

    private static function unsupported(string $field): never
    {
        throw new Failure(t('Dieser Operator wird für :field nicht unterstützt.', ['field' => $field]));
    }
}
