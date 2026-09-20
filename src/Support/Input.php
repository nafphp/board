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
     * What a broken rule is called here.
     *
     * naf/form ships English defaults, and they cannot be translated where they
     * are written: the package is a dependency, not a working copy, so anything
     * edited there is gone at the next install. It takes a message per field and
     * rule, though, so this application says it in its own words and the package
     * keeps its defaults for whoever does not.
     *
     * `%d` rather than a named placeholder, because the validator fills these in
     * with sprintf and the number is its to insert.
     *
     * A rule not named here keeps the English the package gave it. That is not
     * pretty, but a message in the wrong language is still an answer, where a
     * blank one is not.
     */
    private const array SAID = [
        'required' => 'Dieses Feld wird benötigt.',
        'string'   => 'Bitte gib einen Text ein.',
        'array'    => 'Bitte triff eine Auswahl.',
        'integer'  => 'Bitte gib eine ganze Zahl ein.',
        'email'    => 'Bitte gib eine gültige E-Mail-Adresse an.',
        'min'      => 'Mindestens %d Zeichen.',
        'max'      => 'Höchstens %d Zeichen.',
        'boolean'  => 'Bitte wähle Ja oder Nein.',
        'date'     => 'Bitte gib ein Datum im Format JJJJ-MM-TT an.',
    ];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     *
     * @return array<string, mixed>
     */
    public static function validate(array $data, array $rules): array
    {
        $validation = validator()->validate($data, $rules, self::wording($rules));
        if (!$validation->isValid()) {
            throw new Failure(t('Bitte prüfe deine Eingaben.'), 422, $validation->getErrorMessages());
        }

        return array_intersect_key($data, $rules);
    }

    /**
     * The message for every rule these fields are checked against.
     *
     * @param array<string, string> $rules
     *
     * @return array<string, array<string, string>>
     */
    private static function wording(array $rules): array
    {
        $wording = [];

        foreach ($rules as $field => $spec) {
            foreach (explode('|', (string) $spec) as $rule) {
                $name = explode(':', $rule, 2)[0];
                if (isset(self::SAID[$name])) {
                    $wording[$field][$name] = t(self::SAID[$name]);
                }
            }
        }

        return $wording;
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
