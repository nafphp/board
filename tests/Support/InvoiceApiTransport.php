<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Closure;
use Naf\Client\Transports\CurlTransport;
use Naf\Client\Transports\TransportInterface;
use RuntimeException;
use Throwable;

/** Synthetic provider accounts only; no invoice request ever leaves the test process. */
final class InvoiceApiTransport implements TransportInterface
{
    public array $calls       = [];
    public array $responses   = [];
    public bool $failCreate   = false;
    public ?Closure $onCreate = null;

    public function isAvailable(): bool
    {
        return true;
    }

    public function send(string $url, string $method, array $headerLines, string $body, array $config): array
    {
        if (!in_array(parse_url($url, PHP_URL_HOST), ['api.lexware.io', 'api.easybill.de', 'my.sevdesk.de', 'my.fastbill.com'], true)) {
            return (new CurlTransport())->send($url, $method, $headerLines, $body, $config);
        }
        $data          = $body === '' ? [] : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $this->calls[] = compact('url', 'method', 'headerLines', 'data', 'config');
        if ($this->responses !== []) {
            $response = array_shift($this->responses);
            if ($response instanceof Throwable) {
                throw $response;
            }

            return $response;
        }
        $create = $method === 'POST' && ($data['SERVICE'] ?? '') !== 'customer.get';
        if ($create) {
            if ($this->onCreate !== null) {
                ($this->onCreate)();
            }
            if (getenv('NAFINITY_TEST_INVOICE_API') === '1') {
                file_put_contents(BASE_PATH . '/storage/invoice-api-calls.jsonl', json_encode(['url' => $url, 'data' => $data], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
                usleep(200000);
            }
            if ($this->failCreate) {
                throw new RuntimeException('Connection timed out with a secret that must never reach the response');
            }
        }
        $answer = match (parse_url($url, PHP_URL_HOST)) {
            'api.lexware.io'  => $create ? ['id' => '12345678-1234-1234-1234-123456789012'] : ['company' => ['name' => 'Example Customer GmbH']],
            'api.easybill.de' => $create ? ['id' => 1234, 'is_draft' => true] : ['display_name' => 'Example Customer GmbH'],
            'my.sevdesk.de'   => $create ? ['objects' => ['invoice' => ['id' => '1234', 'status' => '100']]] : ['objects' => [['name' => 'Example Customer GmbH']]],
            'my.fastbill.com' => $create ? ['RESPONSE' => ['STATUS' => 'success', 'INVOICE_ID' => '1234']] : ['RESPONSE' => ['CUSTOMERS' => [['ORGANIZATION' => 'Example Customer GmbH']]]],
        };

        return [json_encode($answer, JSON_THROW_ON_ERROR), ['HTTP/1.1 ' . ($create ? '201 Created' : '200 OK'), 'Content-Type: application/json']];
    }
}
