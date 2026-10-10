<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

use JsonException;
use Naf\Board\Domain\Failure;
use Naf\Client\Core\Client;
use Nyholm\Psr7\Request;
use SensitiveParameter;
use Throwable;

use function Naf\I18n\t;

/** Native NAF transport, with replay and credential redirects disabled. */
final class Api
{
    public function __construct(private Client $client)
    {
    }

    public function request(string $url, string $method, #[SensitiveParameter] string $authorization, ?array $payload = null): array
    {
        // The native stream fallback does not yet honor max_redirects. Fail closed
        // rather than let it forward an API credential to a redirected host.
        if (!function_exists('curl_init')) {
            throw new Failure(t('Die direkte Rechnungsübergabe benötigt die PHP-Erweiterung cURL.'), 503);
        }

        try {
            $response = $this->client->withOptions([
                'retries' => 0, 'max_redirects' => 0, 'ssl_verify' => true,
                'timeout' => 20, 'connect_timeout' => 8,
            ])->sendRequest(new Request($method, $url, [
                'Authorization' => $authorization, 'Accept' => 'application/json', 'Content-Type' => 'application/json',
            ], $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR)));
        } catch (Throwable) {
            // Do not retain an exception containing a request, credentials or customer data.
            throw new Failure(t('Das Rechnungstool konnte nicht sicher antworten. Bitte prüfe den Vorgang dort, bevor du erneut exportierst.'), 502);
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new Failure(t('Das Rechnungstool hat die Anfrage abgelehnt (HTTP :status). Prüfe Zugang und Rechnungsdaten.', ['status' => (string) $status]), 502);
        }

        try {
            $body = $response->getBody()->read(1048577);
            $data = strlen($body) > 1048576 ? null : json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = null;
        }
        if (!is_array($data) || isset($data['ERRORS']) || isset($data['RESPONSE']['ERRORS'])) {
            throw new Failure(t('Das Rechnungstool hat keine gültige Bestätigung geliefert.'), 502);
        }

        return $data;
    }

    public static function identifier(mixed $id, bool $uuid = false): string
    {
        if ((!is_int($id) && !is_string($id)) || !preg_match($uuid ? '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD' : '/^[1-9][0-9]{0,17}$/D', (string) $id)) {
            throw new Failure(t('Das Rechnungstool hat keine gültige Bestätigung geliefert.'), 502);
        }

        return (string) $id;
    }

    public static function customerId(string $id, bool $uuid = false): string
    {
        try {
            return self::identifier($id, $uuid);
        } catch (Failure) {
            throw new Failure(t('Bitte gib eine gültige Kunden-ID aus dem gewählten Rechnungstool an.'), 422);
        }
    }

    public static function customerName(mixed $name): string
    {
        if (!is_string($name) || trim($name) === '') {
            throw new Failure(t('Dieser Rechnungskunde wurde im gewählten Zugang nicht gefunden.'), 422);
        }

        return mb_substr(trim($name), 0, 240);
    }
}
