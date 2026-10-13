<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Board\Domain\Failure;

use function Naf\I18n\t;

/** A bounded expression grammar, never SQL. @internal */
final class DqlParser
{
    private array $tokens  = [];
    private int $cursor    = 0;
    private string $source = '';

    public function parse(mixed $source): ?array
    {
        if (!is_string($source) || !mb_check_encoding($source, 'UTF-8') || mb_strlen($source) > 2000) {
            throw new Failure(t('Die DQL-Abfrage muss ein Text mit höchstens 2000 Zeichen sein.'), 422, ['dql' => [t('Bitte kürze die Abfrage.')]]);
        }
        $this->source = $source;
        $this->tokens = [];
        $this->cursor = 0;
        $offset       = 0;
        if (preg_match('/[\x00-\x1f\x7f]/u', $source, $control, PREG_OFFSET_CAPTURE)) {
            $this->fail(t('Unerwartetes Zeichen.'), $control[0][1]);
        }
        while ($offset < strlen($source)) {
            if (preg_match('/\G\s+/u', $source, $match, 0, $offset)) {
                $offset += strlen($match[0]);
                continue;
            }
            if (!preg_match('/\G(?:"(?:[^"\\\\]|\\\\["\\\\])*"|\x27(?:[^\x27\\\\]|\\\\[\x27\\\\])*\x27|!=|>=|<=|[=~><(),]|[\p{L}\p{N}_@.\-]+)/u', $source, $match, 0, $offset)) {
                $this->fail(t('Unerwartetes Zeichen.'), $offset);
            }
            $raw            = $match[0];
            $quoted         = $raw[0] === '"' || $raw[0] === "'";
            $value          = $quoted ? preg_replace('/\\\\(["\x27\\\\])/', '$1', substr($raw, 1, -1)) : $raw;
            $this->tokens[] = ['value' => $value, 'quoted' => $quoted, 'offset' => $offset];
            $offset += strlen($raw);
            if (count($this->tokens) > 256) {
                $this->fail(t('Die Abfrage enthält zu viele Bedingungen.'), $offset);
            }
        }
        if ($this->tokens === []) {
            return null;
        }
        $expression = $this->expression(0);
        if (isset($this->tokens[$this->cursor])) {
            $this->fail(t('Hier wird AND oder OR erwartet.'));
        }

        return $expression;
    }

    private function expression(int $depth): array
    {
        $left = $this->conjunction($depth);
        while ($this->take('OR')) {
            $left = ['kind' => 'OR', 'left' => $left, 'right' => $this->conjunction($depth)];
        }

        return $left;
    }

    private function conjunction(int $depth): array
    {
        $left = $this->term($depth);
        while ($this->take('AND')) {
            $left = ['kind' => 'AND', 'left' => $left, 'right' => $this->term($depth)];
        }

        return $left;
    }

    private function term(int $depth): array
    {
        if ($depth > 12) {
            $this->fail(t('Die Abfrage ist zu tief verschachtelt.'));
        }
        if ($this->take('NOT')) {
            return ['kind' => 'NOT', 'child' => $this->term($depth + 1)];
        }
        if ($this->take('(')) {
            $node = $this->expression($depth + 1);
            $this->expect(')');

            return $node;
        }
        $position = $this->tokens[$this->cursor]['offset'] ?? strlen($this->source);
        $field    = $this->value();
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D', $field)) {
            $this->fail(t('Eine Eigenschaft wird erwartet.'), $position);
        }
        $negate = $this->take('NOT');
        if ($this->take('IN')) {
            $operator = $negate ? 'NOT IN' : 'IN';
            $this->expect('(');
            $values = [$this->value()];
            while ($this->take(',')) {
                $values[] = $this->value();
                if (count($values) > 20) {
                    $this->fail(t('Eine Liste darf höchstens 20 Werte enthalten.'));
                }
            }
            $this->expect(')');
        } else {
            if ($negate) {
                $this->fail(t('Nach NOT wird IN erwartet.'));
            }
            $operator = $this->tokens[$this->cursor]['value'] ?? '';
            if (!in_array($operator, ['=', '!=', '~', '>', '>=', '<', '<='], true) || $this->tokens[$this->cursor]['quoted']) {
                $this->fail(t('Ein Vergleich wie =, != oder IN wird erwartet.'));
            }
            ++$this->cursor;
            $values = [$this->value()];
        }

        return ['kind' => 'comparison', 'field' => strtolower($field), 'operator' => $operator, 'values' => $values, 'position' => mb_strlen(substr($this->source, 0, $position)) + 1];
    }

    private function value(): string
    {
        $token = $this->tokens[$this->cursor] ?? null;
        if ($token === null || (!$token['quoted'] && (in_array(strtoupper($token['value']), ['AND', 'OR', 'NOT', 'IN'], true) || in_array($token['value'], ['(', ')', ',', '=', '!=', '~', '>', '>=', '<', '<='], true)))) {
            $this->fail(t('Ein Wert wird erwartet. Namen mit Leerzeichen brauchen Anführungszeichen.'));
        }
        ++$this->cursor;

        return $token['value'];
    }

    private function take(string $value): bool
    {
        $token = $this->tokens[$this->cursor] ?? null;
        if ($token === null || $token['quoted'] || strtoupper($token['value']) !== $value) {
            return false;
        }
        ++$this->cursor;

        return true;
    }

    private function expect(string $value): void
    {
        if (!$this->take($value)) {
            $this->fail(t('Erwartet: :token', ['token' => $value]));
        }
    }

    private function fail(string $message, ?int $offset = null): never
    {
        $position = mb_strlen(substr($this->source, 0, $offset ?? ($this->tokens[$this->cursor]['offset'] ?? strlen($this->source)))) + 1;
        $message  = t('DQL an Position :position: :message', ['position' => $position, 'message' => $message]);
        throw new Failure($message, 422, ['dql' => [$message]]);
    }
}
