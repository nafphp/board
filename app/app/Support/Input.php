<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Failure;

use function Naf\request;
use function Naf\Form\validator;

final class Input
{
    public static function body(): array
    {
        $request = request();
        if (str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            try {
                $data = json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new Failure('Ungültiges JSON.', 400);
            }
            if (!is_array($data) || (array_is_list($data) && $data !== [])) {
                throw new Failure('Ein JSON-Objekt wird erwartet.', 400);
            }
            return $data;
        }
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }
    public static function validate(array $data, array $rules): array
    {
        $validation = validator()->validate($data, $rules);
        if (!$validation->isValid()) {
            throw new Failure('Bitte prüfe deine Eingaben.', 422, $validation->getErrorMessages());
        }
        return array_intersect_key($data, $rules);
    }
    public static function id(mixed $value, string $field = 'id'): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1) {
            throw new Failure('Ungültige ID: '.$field, 422, [$field => ['Eine positive ID wird erwartet.']]);
        }
        return (int)$value;
    }
    public static function ids(mixed $value, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value) || count($value) > 50) {
            throw new Failure('Ungültige Auswahl: '.$field);
        }
        return array_values(array_unique(array_map(static fn ($id) => self::id($id, $field), $value)));
    }
}
