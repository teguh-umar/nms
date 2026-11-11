<?php

namespace GlpiPlugin\Nms\Connection;

/**
 * One NMS connection. Read-only data object: secrets are decrypted only in secret().
 * Connection rows live in glpi_plugin_nms_connections and are keyed by connections_id everywhere else.
 */
final class Connection
{
    public const BRANDS = ['opmanager', 'prtg', 'zabbix', 'librenms', 'solarwinds'];

    /** @var 'api_key'|'password'|'webhook_token' */
    private const SECURED_FIELDS = ['api_key', 'password', 'webhook_token'];

    public readonly int $id;
    public readonly string $name;
    public readonly string $brand;
    public readonly int $entities_id;
    public readonly int $is_recursive;
    public readonly string $url;
    public readonly string $api_key;      // encrypted at rest
    public readonly string $username;
    public readonly string $password;     // encrypted at rest
    public readonly int $verify_tls;
    public readonly int $is_active;
    public readonly int $writeback_enabled;
    public readonly int $tickets_enabled;
    public readonly string $webhook_token; // encrypted at rest
    public readonly string $settings;     // JSON
    public readonly string $last_device_sync;
    public readonly string $last_alarm_sync;
    public readonly string $last_status;
    public readonly string $last_error;
    public readonly string $date_creation;
    public readonly string $date_mod;

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields)
    {
        $this->id             = (int) ($fields['id'] ?? 0);
        $this->name           = (string) ($fields['name'] ?? '');
        $this->brand          = (string) ($fields['brand'] ?? '');
        $this->entities_id    = (int) ($fields['entities_id'] ?? 0);
        $this->is_recursive   = (int) ($fields['is_recursive'] ?? 1);
        $this->url            = (string) ($fields['url'] ?? '');
        $this->api_key        = (string) ($fields['api_key'] ?? '');
        $this->username       = (string) ($fields['username'] ?? '');
        $this->password       = (string) ($fields['password'] ?? '');
        $this->verify_tls     = (int) ($fields['verify_tls'] ?? 1);
        $this->is_active      = (int) ($fields['is_active'] ?? 0);
        $this->writeback_enabled = (int) ($fields['writeback_enabled'] ?? 0);
        $this->tickets_enabled = (int) ($fields['tickets_enabled'] ?? 0);
        $this->webhook_token  = (string) ($fields['webhook_token'] ?? '');
        $this->settings       = (string) ($fields['settings'] ?? '');
        $this->last_device_sync = (string) ($fields['last_device_sync'] ?? '');
        $this->last_alarm_sync  = (string) ($fields['last_alarm_sync'] ?? '');
        $this->last_status    = (string) ($fields['last_status'] ?? '');
        $this->last_error     = (string) ($fields['last_error'] ?? '');
        $this->date_creation  = (string) ($fields['date_creation'] ?? '');
        $this->date_mod       = (string) ($fields['date_mod'] ?? '');
    }

    /**
     * Decrypt a secured field. Returns '' when the field is not secured or empty.
     * The decrypted value is never cached and never echoed.
     */
    public function secret(string $field): string
    {
        if (!in_array($field, self::SECURED_FIELDS, true)) {
            return '';
        }
        $raw = match ($field) {
            'api_key'      => $this->api_key,
            'password'     => $this->password,
            'webhook_token'=> $this->webhook_token,
            default        => '',
        };
        if ($raw === '') {
            return '';
        }
        $decrypted = (new \GLPIKey())->decrypt($raw);
        return $decrypted === false || $decrypted === null ? '' : (string) $decrypted;
    }

    public function __toString(): string
    {
        return sprintf(
            'Connection(id=%d, name=%s, brand=%s, entity=%d, active=%d, url=%s)',
            $this->id,
            $this->name,
            $this->brand,
            $this->entities_id,
            $this->is_active,
            $this->url,
        );
    }

    /**
     * Export without secured fields, so var_export() never leaks a secret.
     */
    public static function __set_state(array $properties): self
    {
        foreach (self::SECURED_FIELDS as $secured) {
            unset($properties[$secured]);
        }
        return new self($properties);
    }
}
