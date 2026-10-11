<?php

declare(strict_types=1);

namespace Naf\Board\Mail;

use Naf\Board\Contracts\FieldTypeInterface;

use function Naf\I18n\t;

final class EmailType implements FieldTypeInterface
{
    public function id(): string
    {
        return 'board_mail.email';
    }

    public static function address(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Keep the local part exact; providers may treat it as case sensitive.
        [$local, $domain] = explode('@', $value, 2);

        return $local . '@' . strtolower($domain);
    }

    public function normalize(mixed $value, array $options): mixed
    {
        return self::address($value);
    }

    public function validate(mixed $value, array $options): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return [];
        }

        return self::address($value) === null ? [t('Bitte gib eine gültige E-Mail-Adresse an.')] : [];
    }

    public function view(): string
    {
        return 'fields/text';
    }

    public function module(): ?string
    {
        return null;
    }
}
