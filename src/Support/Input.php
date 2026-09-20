<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use JsonException;
use Naf\Board\Domain\Failure;

use function Naf\Form\validator;
use function Naf\I18n\t;
use function Naf\param;
use function Naf\request;

/**
 * What is left once the framework does the reading.
 *
 * Reading a request is `param()`: it already merges the query string, the parsed
 * body and a JSON body, so nothing here does that any more. What stays is the
 * part the framework has no opinion about -- turning a bad value into a refusal
 * with a status and a field name, which is this application's business and not
 * the framework's.
 */
final class Input
{
    /**
     * The request, as the framework reads it, with one refusal added.
     *
     * `param()` shrugs at a body that announces itself as JSON and is not one:
     * it finds nothing and hands back an empty array, and the request then fails
     * later as though somebody had left the form blank. A client that lies about
     * its content type has made a mistake worth being told about plainly.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        $request = request();

        if (str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            try {
                $data = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new Failure(t('Ungültiges JSON.'), 400);
            }

            if (!is_array($data) || (array_is_list($data) && $data !== [])) {
                throw new Failure(t('Ein JSON-Objekt wird erwartet.'), 400);
            }
        }

        return param()->all();
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     *
     * @return array<string, mixed>
     */
    public static function validate(array $data, array $rules): array
    {
        $validation = validator()->validate($data, $rules);
        if (!$validation->isValid()) {
            throw new Failure(t('Bitte prüfe deine Eingaben.'), 422, $validation->getErrorMessages());
        }

        return array_intersect_key($data, $rules);
    }

    public static function id(mixed $value, string $field = 'id'): int
    {
        if (
            (!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1
        ) {
            throw new Failure(t('Ungültige ID: :field', ['field' => $field]), 422, [
                $field => [t('Eine positive ID wird erwartet.')],
            ]);
        }

        return (int) $value;
    }

    /** @return list<int> */
    public static function ids(mixed $value, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value) || count($value) > 50) {
            throw new Failure(t('Ungültige Auswahl: :field', ['field' => $field]));
        }

        return array_values(
            array_unique(array_map(static fn($id) => self::id($id, $field), $value)),
        );
    }
}
