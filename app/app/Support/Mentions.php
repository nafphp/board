<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use function Naf\View\s;

final class Mentions
{
    /** Handles are derived from project member names; duplicate names receive an ID suffix. */
    public static function members(array $members): array
    {
        $handles = array_map(static fn(array $member) => self::handle($member['name']), $members);
        $counts  = array_count_values($handles);
        foreach ($members as $index => &$member) {
            $base             = $handles[$index];
            $member['handle'] = $base . ($counts[$base] > 1 ? '.' . $member['id'] : '');
        }

        return $members;
    }

    public static function handle(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '.', $name), '.') ?: 'user';
    }

    public static function render(string $body, array $members): string
    {
        $handles = array_column($members, 'name', 'handle');
        $parts   = preg_split('/(?<![\p{L}\p{N}_@])(@[\p{L}\p{N}]+(?:[._-][\p{L}\p{N}]+)*)/u', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        $html    = '';
        foreach ($parts as $part) {
            $name = $handles[mb_substr($part, 1)] ?? null;
            $html .= str_starts_with($part, '@') && $name !== null
                ? '<span class="mention" title="' . s($name) . '">' . s($part) . '</span>'
                : s($part);
        }

        return nl2br($html);
    }
}
