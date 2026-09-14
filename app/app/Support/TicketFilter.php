<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Failure;

final readonly class TicketFilter
{
    private function __construct(public array $values)
    {
    }
    public static function from(array $query): self
    {
        $values = [];
        foreach (['column','swimlane','assignee','label'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') {
                $values[$key] = Input::id($query[$key], $key);
            }
        }
        foreach (['status' => ['open','closed','archived'],'priority' => ['low','normal','high','urgent']] as $key => $allowed) {
            if (!isset($query[$key]) || $query[$key] === '') {
                continue;
            }
            if (!is_string($query[$key]) || !in_array($query[$key], $allowed, true)) {
                throw new Failure('Ungültiger Filter: '.$key);
            }
            $values[$key] = $query[$key];
        }
        if (isset($query['q']) && $query['q'] !== '') {
            $values['q'] = trim(Input::validate($query, ['q' => 'string|max:200'])['q']);
        }
        return new self($values);
    }
}
