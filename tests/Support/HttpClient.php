<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use CurlHandle;
use RuntimeException;

/**
 * A browser's worth of HTTP: cookies that persist, redirects that are not
 * followed, and the CSRF token read off the page it came on.
 *
 * Tests that go through this reach the application the way a person does --
 * through the front controller, the router, the session and the guards. A page
 * that only works when called in-process passes an in-process test and fails
 * here, which is the reason to have both.
 */
final class HttpClient
{
    private string $jar;
    private string $landing      = '';
    private string $loginHeaders = '';

    /**
     * @param string      $base       where the application answers
     * @param string      $name       a cookie jar of this client's own
     * @param string|null $authority  the CA to verify the certificate against,
     *                                because an installation serving HTTPS with
     *                                a certificate nobody checks proves nothing
     */
    public function __construct(
        private string $base,
        string $name = 'default',
        private ?string $authority = null,
    ) {
        $this->jar = sys_get_temp_dir() . '/nafinity-test-cookies-' . $name . '.txt';
        $this->forgetSession();
    }

    /**
     * @param array<string,mixed>|string|null $body form fields, a ready-made body
     *                                              (multipart, say), or null for a GET
     * @param list<string>                    $headers extra request headers
     *
     * @return array{status:int,body:string,headers:string}
     */
    public function request(
        string $path,
        array|string|null $body = null,
        array $headers = [],
        string $method = '',
    ): array {
        return self::answer($this->prepare($path, $body, $headers, $method));
    }

    /**
     * The same request, not sent yet.
     *
     * For the few checks that are about two requests arriving at once, which is
     * the only way to find out what happens when they do.
     *
     * @param array<string,mixed>|string|null $body
     * @param list<string>                    $headers
     * @return CurlHandle
     */
    public function prepare(
        string $path,
        array|string|null $body = null,
        array $headers = [],
        string $method = '',
    ) {
        $handle = curl_init($this->base . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            // Not followed on purpose: a redirect is an answer, and which one it
            // is carries the meaning -- 303 to the board is a successful login.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($this->authority !== null) {
            curl_setopt($handle, CURLOPT_CAINFO, $this->authority);
        }

        if ($body !== null) {
            $json = str_contains(strtolower(implode(' ', $headers)), 'application/json');
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, match (true) {
                is_string($body) => $body,
                $json            => json_encode($body),
                default          => http_build_query($body),
            });
        }
        if ($method !== '') {
            curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        }

        return $handle;
    }

    /**
     * Send several prepared requests together and answer them in the same order.
     *
     * @param  list<CurlHandle> $handles
     * @return list<array{status:int,body:string,headers:string}>
     */
    public static function together(array $handles): array
    {
        $multi = curl_multi_init();
        foreach ($handles as $handle) {
            curl_multi_add_handle($multi, $handle);
        }

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);

        $answers = [];
        foreach ($handles as $handle) {
            $answers[] = self::read($handle, (string) curl_multi_getcontent($handle));
            curl_multi_remove_handle($multi, $handle);
        }

        return $answers;
    }

    /** @param CurlHandle $handle */
    private static function answer($handle): array
    {
        $response = curl_exec($handle);
        if ($response === false) {
            throw new RuntimeException('Request failed: ' . curl_error($handle));
        }

        return self::read($handle, (string) $response);
    }

    /**
     * @param  CurlHandle $handle
     * @return array{status:int,body:string,headers:string}
     */
    private static function read($handle, string $response): array
    {
        // No curl_close(): a handle is an object since PHP 8.0 and is freed with
        // the variable. Calling it has done nothing since, and says so since 8.5.
        $size = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

        return [
            'status'  => curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'headers' => substr($response, 0, $size),
            'body'    => substr($response, $size),
        ];
    }

    /** @return array<string,mixed> */
    public function json(string $path, ?array $body = null, array $headers = []): array
    {
        $response = $this->request($path, $body, $headers);

        return json_decode($response['body'], true) ?? [];
    }

    /** The CSRF token a page carries, in a form or in its head. */
    public function token(string $page): string
    {
        foreach (['/name="_csrf" value="([^"]+)"/', '/name="csrf-token" content="([^"]+)"/'] as $pattern) {
            if (preg_match($pattern, $page, $found)) {
                return $found[1];
            }
        }

        throw new RuntimeException('No CSRF token on the page.');
    }

    /**
     * Sign in as somebody, from nothing: the session is dropped first, so one
     * test cannot inherit whoever the last one happened to be.
     *
     * @return string the token for the session that was just opened
     */
    public function login(string $email, string $password = 'Test-Password-2026!'): string
    {
        $this->forgetSession();
        $page  = $this->request('/login');
        $entry = $this->request('/login', [
            '_csrf'    => $this->token($page['body']),
            'email'    => $email,
            'password' => $password,
        ]);

        // 303 where the login form redirects, 200 where it answers the page it
        // landed on; both mean the session is open, and which one it is belongs
        // to the installation rather than to this client.
        if (!in_array($entry['status'], [200, 303], true)) {
            throw new RuntimeException('Login for ' . $email . ' answered ' . $entry['status']);
        }
        $this->landing      = $entry['body'];
        $this->loginHeaders = $entry['headers'];

        return $this->token($this->request('/preferences')['body']);
    }

    /** The page the last login answered with, for what it says about the account. */
    public function landing(): string
    {
        return $this->landing;
    }

    /** The headers that opened this session, for what they say about the cookie. */
    public function loginHeaders(): string
    {
        return $this->loginHeaders;
    }

    /** @return list<string> headers for a JSON request, with the token */
    public function jsonHeaders(string $token): array
    {
        return ['Accept: application/json', 'Content-Type: application/json', 'X-CSRF-Token: ' . $token];
    }

    public function forgetSession(): void
    {
        if (is_file($this->jar)) {
            unlink($this->jar);
        }
    }
}
