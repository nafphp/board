<?php

declare(strict_types=1);

namespace Naf\Board\Support\Settings;

/** Display names only: literal configuration paths are never rewritten. */
final class ConfigurationLabels
{
    private const array WORDS = [
        'api'    => 'API', 'ca' => 'CA', 'cli' => 'CLI', 'csrf' => 'CSRF', 'csv' => 'CSV',
        'db'     => 'DB', 'dsn' => 'DSN', 'html' => 'HTML', 'http' => 'HTTP',
        'https'  => 'HTTPS', 'id' => 'ID', 'imap' => 'IMAP', 'ip' => 'IP',
        'json'   => 'JSON', 'jwt' => 'JWT', 'ldap' => 'LDAP', 'mcp' => 'MCP',
        'mfa'    => 'MFA', 'naf' => 'NAF', 'oauth' => 'OAuth', 'oauth2' => 'OAuth2',
        'openid' => 'OpenID', 'pdf' => 'PDF', 'pdo' => 'PDO', 'php' => 'PHP',
        'rbac'   => 'RBAC', 'smtp' => 'SMTP', 'sql' => 'SQL', 'ssl' => 'SSL',
        'sso'    => 'SSO', 'tls' => 'TLS', 'ui' => 'UI', 'uri' => 'URI',
        'url'    => 'URL', 'websocket' => 'WebSocket', 'xml' => 'XML',
    ];

    public static function label(string $path): string
    {
        return implode(' · ', array_map(self::segment(...), explode(':', $path)));
    }

    private static function segment(string $segment): string
    {
        $words = [];
        foreach (preg_split('/[_.\\-\\s]+/u', $segment, flags: PREG_SPLIT_NO_EMPTY) as $token) {
            if (isset(self::WORDS[strtolower($token)])) {
                $words[] = self::WORDS[strtolower($token)];
                continue;
            }
            $token = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $token);
            $token = preg_replace('/([A-Z])([A-Z][a-z])/', '$1 $2', $token);
            foreach (explode(' ', $token) as $word) {
                $words[] = self::WORDS[strtolower($word)] ?? ($words === [] ? ucfirst(strtolower($word)) : strtolower($word));
            }
        }

        return implode(' ', $words);
    }
}
