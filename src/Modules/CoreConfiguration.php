<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\SettingDefinition;
use Naf\Board\Definition\SettingSection;
use Naf\Board\ExtensionContext;
use Naf\Board\Support\Fields\ConfigurationJsonType;
use Naf\Board\Support\Fields\SecretType;
use Naf\Board\Support\Settings\ConfigurationLabels;
use Naf\Core\Config;
use Naf\Mail\Core\Transport\TransportConfiguration;

use function Naf\config;

/** Adds the remaining installation configuration to the existing settings renderer. */
final class CoreConfiguration implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        $configuration = $context->container()->get(Config::class);
        // Older message consumers use the Nafinity keys. Keep the native mail
        // configuration authoritative during that transition, including UI overrides.
        // Remove this bridge once all Board 0.1.6 message consumers use native mail keys.
        $legacy = [];
        foreach (['mail:notifications_enabled' => 'nafinity:mail_enabled', 'mail:from' => 'nafinity:mail_from'] as $path => $alias) {
            $value = $configuration->get($path);
            if ($value !== null) {
                $legacy[$alias] = $path === 'mail:notifications_enabled'
                    ? filter_var($value, FILTER_VALIDATE_BOOL) : $value;
            }
        }
        $configuration->overlay('mail_compatibility', $legacy);
        $context->fieldTypes()->add(new ConfigurationJsonType());
        $context->fieldTypes()->add(new SecretType());
        $known = [];
        foreach ($context->settings()->forScope('application') as $definition) {
            if ($definition->configKey !== null) {
                $known[$definition->configKey] = true;
            }
        }
        $schema = [
            'app:name'                    => ['label' => 'Name der Anwendung'],
            'app:url'                     => ['label' => 'Adresse der Anwendung'],
            'public_url'                  => ['label' => 'Öffentliche Adresse'],
            'csrf_validation'             => ['label' => 'CSRF-Prüfung aktiviert', 'type' => 'boolean', 'default' => true],
            'csrf_exempt_routes'          => ['label' => 'Routen ohne CSRF-Prüfung', 'type' => 'configuration_json', 'default' => []],
            'database:port'               => ['type' => 'integer', 'default' => 3306, 'options' => ['min' => 1, 'max' => 65535]],
            'mail:notifications_enabled'  => ['label' => 'Mailbenachrichtigungen aktiviert', 'type' => 'boolean', 'default' => false],
            'mail:from'                   => ['label' => 'Absenderadresse', 'type' => 'text', 'default' => ''],
            'mail:reply_to'               => ['label' => 'Antwortadresse', 'type' => 'text', 'default' => ''],
            'mail:username'               => ['label' => 'Mail-Benutzername', 'type' => 'text', 'default' => ''],
            'mail:password'               => ['label' => 'Mail-Passwort', 'sensitive' => true, 'default' => ''],
            'mail:imap:enabled'           => ['label' => 'Mail-to-Ticket aktiviert', 'type' => 'boolean', 'default' => false],
            'mail:imap:port'              => ['label' => 'IMAP-Port', 'type' => 'integer', 'default' => null, 'options' => ['min' => 1, 'max' => 65535]],
            'mail:imap:security'          => ['label' => 'IMAP-Verschlüsselung', 'type' => 'select', 'default' => 'ssl', 'options' => ['choices' => ['ssl' => 'TLS', 'starttls' => 'STARTTLS']]],
            'mail:imap:authentication'    => ['label' => 'IMAP-Anmeldung', 'type' => 'select', 'default' => 'password', 'options' => ['choices' => ['password' => 'Passwort', 'oauth' => 'OAuth']]],
            'websocket:enabled'           => ['label' => 'Live-Aktualisierung aktiviert', 'type' => 'boolean', 'default' => false],
            'session:trust_proxy_headers' => ['type' => 'boolean', 'default' => false],
        ];
        if (class_exists(TransportConfiguration::class)) {
            $schema = array_replace($schema, TransportConfiguration::fields((string) config('mail:transport', '')));
        }
        $fields = self::flatten($configuration->base());
        foreach ($schema as $path => $field) {
            $fields[$path] ??= $field['default'] ?? null;
        }
        $groups = ['app' => true];
        foreach ($fields as $path => $value) {
            if (in_array($path, ['nafinity:mail_enabled', 'nafinity:mail_from'], true)) {
                continue;
            }
            if (isset($known[$path])) {
                continue;
            }
            $group   = str_contains($path, ':') ? explode(':', $path)[0] : 'app';
            $section = $group === 'app' ? 'application' : 'configuration_' . $group;
            if (!isset($groups[$group])) {
                $labels = ['mail' => 'E-Mail', 'database' => 'Datenbank', 'session' => 'Sitzungen', 'app' => 'Anwendung', 'auth' => 'Authentifizierung', 'websocket' => 'Live-Aktualisierung', 'storage' => 'Dateispeicher', 'oauth' => 'OAuth', 'view' => 'Ansichten', 'queue' => 'Warteschlange', 'schedule' => 'Zeitplanung', 'nafinity' => 'Nafinity'];
                $context->settingSections()->add(new SettingSection(
                    $section,
                    'application',
                    $labels[$group] ?? ConfigurationLabels::label($group),
                    index: 300,
                    icon: 'tune',
                ));
                $groups[$group] = true;
            }
            $field     = $schema[$path] ?? [];
            $sensitive = $field['sensitive'] ?? self::sensitive($path, $value);
            $type      = $sensitive ? (is_array($value) ? 'configuration_json' : 'secret') : ($field['type'] ?? match (true) {
                is_bool($value)                    => 'boolean',
                is_int($value)                     => 'integer',
                is_array($value), is_float($value) => 'configuration_json',
                default                            => 'text',
            });
            $options = ['nullable' => true];
            if ($type === 'text') {
                $options['max'] = 16384;
            }
            if ($type === 'configuration_json') {
                $options['shape'] = get_debug_type($value);
            }
            $context->settings()->add(new SettingDefinition(
                key: 'config.' . $path,
                scope: 'application',
                section: $section,
                label: $field['label'] ?? ConfigurationLabels::label($path),
                type: $type,
                default: array_key_exists('default', $field) ? $field['default'] : $value,
                index: match (true) {
                    isset($field['index'])               => $field['index'],
                    $path === 'mail:reply_to'            => 30,
                    $path === 'mail:username'            => 40,
                    $path === 'mail:password'            => 50,
                    $path === 'mail:transport'           => 60,
                    str_starts_with($path, 'mail:smtp:') => 100,
                    str_starts_with($path, 'mail:imap:') => 300,
                    default                              => 100,
                },
                options: array_replace($options, $field['options'] ?? []),
                sensitive: $sensitive,
                configKey: $path,
                advanced: true,
            ));
        }
    }

    public static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . ':' . $key;
            if (is_array($value) && $value !== [] && !array_is_list($value) && $path !== 'csrf_exempt_routes') {
                $flat += self::flatten($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }

    public static function sensitive(string $path, mixed $value): bool
    {
        if ($path === 'auth:users:password_field') {
            return false;
        }
        if (preg_match('/(?:password|passphrase|secret|token|privatekey|apikey|encryptionkey|signingkey)$/i', $path)) {
            return true;
        }
        if (preg_match('/(?:^|[:_.-])(?:password|passphrase|secret|token|key|credential|credentials|bindPassword|privateKey|apiKey|authorization|dsn)(?:$|[:_.-])/i', $path)) {
            return true;
        }
        if (is_string($value) && preg_match('~://[^/]*:[^/]*@~', $value)) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (self::sensitive((string) $key, $child)) {
                    return true;
                }
            }
        }

        return false;
    }
}
