<?php

namespace GlpiPlugin\Nms\Connection;

use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\RightDefinition;

/**
 * Connection form: renders the connections list and the edit form,
 * and processes POST saves. Secret fields are never echoed.
 */
final class ConnectionForm
{
    public function __construct(private ConnectionStore $store)
    {
    }

    /** Render the connections list table. @return string HTML */
    public function renderList(): string
    {
        $out  = '<table class="table table-striped">';
        $out .= '<thead><tr>'
            . '<th>' . __('Name', 'nms') . '</th>'
            . '<th>' . __('Brand', 'nms') . '</th>'
            . '<th>' . __('Entity', 'nms') . '</th>'
            . '<th>' . __('Active', 'nms') . '</th>'
            . '<th>' . __('Tickets', 'nms') . '</th>'
            . '<th>' . __('Write-back', 'nms') . '</th>'
            . '<th>' . __('Last sync', 'nms') . '</th>'
            . '<th>' . __('Last status', 'nms') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody>';
        foreach ($this->store->active() as $conn) {
            $out .= '<tr>'
                . '<td>' . htmlescape($conn->name) . '</td>'
                . '<td>' . htmlescape($conn->brand) . '</td>'
                . '<td>' . (int) $conn->entities_id . '</td>'
                . '<td>' . ($conn->is_active ? __('yes', 'nms') : __('no', 'nms')) . '</td>'
                . '<td>' . ($conn->tickets_enabled ? __('yes', 'nms') : __('no', 'nms')) . '</td>'
                . '<td>' . ($conn->writeback_enabled ? __('yes', 'nms') : __('no', 'nms')) . '</td>'
                . '<td>' . htmlescape($conn->last_device_sync !== '' ? $conn->last_device_sync : __('never', 'nms')) . '</td>'
                . '<td>' . htmlescape($conn->last_status !== '' ? $conn->last_status : __('—', 'nms')) . '</td>'
                . '<td><a href="' . htmlescape(\Html::getPrefixedUrl('/plugins/opmanager/front/connection.form.php?id=' . $conn->id)) . '">' . __('Edit', 'nms') . '</a></td>'
                . '</tr>';
        }
        $out .= '</tbody></table>';
        return $out;
    }

    /** Render the edit form for one connection. @return string HTML */
    public function renderForm(int $id): string
    {
        $conn = $this->store->get($id);
        if ($conn === null) {
            return __('Connection not found', 'nms');
        }
        $canedit = \Session::haveRight(RightDefinition::CONFIG, UPDATE);
        $disabled = $canedit ? '' : ' disabled';

        $driver = DriverFactory::for($conn);
        $caps   = $driver->capabilities();

        $hasApiKey     = $conn->secret('api_key') !== '';
        $hasWebhookTok = $conn->secret('webhook_token') !== '';

        $out  = '<form method="post" action="' . htmlescape(\Html::getPrefixedUrl('/plugins/opmanager/front/connection.php')) . '">';
        $out .= \Html::hidden('id', ['value' => $conn->id]);
        $out .= '<input type="hidden" name="_csrf_token" value="' . htmlescape($_SESSION['glpi_csrf_token'] ?? '') . '">';

        $out .= '<div class="mb-2"><label class="form-label">' . __('Name', 'nms') . '</label>'
            . '<input type="text" class="form-control" name="name" value="' . htmlescape($conn->name) . '"' . $disabled . '></div>';

        $out .= '<div class="mb-2"><label class="form-label">' . __('Brand', 'nms') . '</label>'
            . '<select class="form-select" name="brand"' . $disabled . '>';
        foreach (self::supportedBrands() as $brand) {
            $out .= '<option value="' . htmlescape($brand) . '"'
                . ($conn->brand === $brand ? ' selected' : '') . '>'
                . htmlescape($brand) . '</option>';
        }
        $out .= '</select></div>';

        $out .= '<div class="mb-2"><label class="form-label">' . __('URL', 'nms') . '</label>'
            . '<input type="url" class="form-control" name="url" value="' . htmlescape($conn->url) . '"' . $disabled . '></div>';

        // Secrets: never echoed, blank = keep.
        $out .= '<div class="mb-2"><label class="form-label">' . __('API key', 'nms') . '</label>'
            . '<input type="password" autocomplete="new-password" class="form-control" name="api_key" value=""'
            . ' placeholder="' . ($hasApiKey ? __('Stored - leave blank to keep', 'nms') : __('Not set', 'nms')) . '"' . $disabled . '></div>';

        $out .= '<div class="mb-2"><label class="form-label">' . __('Username', 'nms') . '</label>'
            . '<input type="text" class="form-control" name="username" value="' . htmlescape($conn->username) . '"' . $disabled . '></div>';

        $out .= '<div class="mb-2"><label class="form-label">' . __('Password', 'nms') . '</label>'
            . '<input type="password" autocomplete="new-password" class="form-control" name="password" value=""'
            . ' placeholder="' . ($conn->password !== '' ? __('Stored - leave blank to keep', 'nms') : __('Not set', 'nms')) . '"' . $disabled . '></div>';

        $out .= '<div class="mb-2"><label class="form-label">' . __('Webhook token', 'nms') . '</label>'
            . '<input type="password" autocomplete="new-password" class="form-control" name="webhook_token" value=""'
            . ' placeholder="' . ($hasWebhookTok ? __('Stored - leave blank to keep', 'nms') : __('Not set', 'nms')) . '"' . $disabled . '></div>';

        $out .= '<div class="mb-2"><label class="form-label">' . __('Entity', 'nms') . '</label>'
            . '<input type="number" class="form-control" name="entities_id" value="' . (int) $conn->entities_id . '"' . $disabled . '></div>';

        $out .= '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_recursive" value="1" id="is_recursive"'
            . ($conn->is_recursive ? ' checked' : '') . $disabled . '>'
            . '<label class="form-check-label" for="is_recursive">' . __('Recursive entity scope', 'nms') . '</label></div>';

        $out .= '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"'
            . ($conn->is_active ? ' checked' : '') . $disabled . '>'
            . '<label class="form-check-label" for="is_active">' . __('Active', 'nms') . '</label></div>';

        $writebackDisabled = $disabled . ($caps->writeback ? '' : ' disabled');
        $out .= '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="writeback_enabled" value="1" id="writeback_enabled"'
            . ($conn->writeback_enabled ? ' checked' : '') . $writebackDisabled . '>'
            . '<label class="form-check-label" for="writeback_enabled">' . __('Write-back', 'nms') . '</label>'
            . ($caps->writeback ? '' : ' <span class="text-muted">(' . __('Not supported by this NMS', 'nms') . ')</span>') . '</div>';

        $ticketsDisabled = $disabled . ($caps->webhook ? '' : ' disabled');
        $out .= '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="tickets_enabled" value="1" id="tickets_enabled"'
            . ($conn->tickets_enabled ? ' checked' : '') . $ticketsDisabled . '>'
            . '<label class="form-check-label" for="tickets_enabled">' . __('Tickets', 'nms') . '</label>'
            . ($caps->webhook ? '' : ' <span class="text-muted">(' . __('Not supported by this NMS', 'nms') . ')</span>') . '</div>';

        $out .= '<div class="mb-2"><label class="form-label">' . __('Verify TLS', 'nms') . '</label>'
            . '<input type="checkbox" name="verify_tls" value="1" id="verify_tls"'
            . ($conn->verify_tls ? ' checked' : '') . $disabled . '></div>';

        // Decode settings once.
        $settingsData = json_decode((string) $conn->settings, true);
        if (!is_array($settingsData)) {
            $settingsData = [];
        }
        $getSetting = static fn (string $key, $default) => $settingsData[$key] ?? $default;

        // --- Match mode ---
        $out .= '<div class="mb-2"><label class="form-label" for="match_mode">' . __s('Match mode', 'nms') . '</label>';
        $out .= '<select class="form-select" id="match_mode" name="match_mode"' . $disabled . '>';
        foreach (['ip_only', 'name_only', 'hybrid', 'hybrid_name', 'manual'] as $mode) {
            $out .= '<option value="' . htmlescape($mode) . '"' . ($getSetting('match_mode', Settings::DEFAULTS['match_mode']) === $mode ? ' selected' : '') . '>'
                . __s($mode === 'manual' ? 'Manual only' : ($mode === 'hybrid_name' ? 'Hybrid - name first' : ($mode === 'hybrid' ? 'Hybrid - IP first' : ($mode === 'ip_only' ? 'IP only' : 'Name only'))), 'nms') . '</option>';
        }
        $out .= '</select></div>';

        // --- Ticket source ---
        $out .= '<div class="mb-2"><label class="form-label" for="ticket_source">' . __s('Ticket source', 'nms') . '</label>';
        $out .= '<select class="form-select" id="ticket_source" name="ticket_source"' . $disabled . '>';
        foreach (['webhook', 'rules'] as $source) {
            $out .= '<option value="' . htmlescape($source) . '"' . ($getSetting('ticket_source', Settings::DEFAULTS['ticket_source']) === $source ? ' selected' : '') . '>'
                . __s($source === 'webhook' ? 'OpManager webhook' : 'Plugin rules', 'nms') . '</option>';
        }
        $out .= '</select></div>';

        // --- Ticket severities ---
        $out .= '<div class="mt-2"><label class="form-label">' . __s('Ticket severities', 'nms') . '</label>';
        $sevValues = json_decode($getSetting('ticket_severities', Settings::DEFAULTS['ticket_severities']), true) ?? [1];
        foreach ([1, 2, 3, 4] as $sev) {
            $sevLabel = ['1' => 'Critical', '2' => 'Trouble', '3' => 'Attention', '4' => 'Service Down'][$sev] ?? (string) $sev;
            $isChecked = in_array($sev, $sevValues) ? ' checked' : '';
            $out .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="ticket_severities[]" value="' . $sev . '" id="sev-' . $sev . '"' . $isChecked . $disabled . '>'
                . '<label class="form-check-label" for="sev-' . $sev . '">' . __s($sevLabel, 'nms') . '</label></div>';
        }
        $out .= '</div>';

        // --- Service user ---
        $out .= '<div class="mb-2"><label class="form-label">' . __('Service user', 'nms') . '</label>'
            . '<input type="number" class="form-control" name="service_user" value="' . (int) $getSetting('service_user', Settings::DEFAULTS['service_user']) . '"' . $disabled . '></div>';

        // --- Ticket entity default ---
        $out .= '<div class="mb-2"><label class="form-label">' . __('Default entity', 'nms') . '</label>'
            . '<input type="number" class="form-control" name="ticket_entity_default" value="' . (int) $getSetting('ticket_entity_default', Settings::DEFAULTS['ticket_entity_default']) . '"' . $disabled . '></div>';

        // --- Use IP network entity ---
        $out .= '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="ticket_use_ipnetwork_entity" value="1" id="ticket_use_ipnetwork_entity"'
            . ((int) $getSetting('ticket_use_ipnetwork_entity', Settings::DEFAULTS['ticket_use_ipnetwork_entity']) ? ' checked' : '') . $disabled . '>'
            . '<label class="form-check-label" for="ticket_use_ipnetwork_entity">' . __('Use IP network entity', 'nms') . '</label></div>';

        // --- Ticket title pattern ---
        $out .= self::textRow('ticket_title_pattern', 'ticket_title_pattern', __('Ticket title pattern', 'nms'), $getSetting('ticket_title_pattern', Settings::DEFAULTS['ticket_title_pattern']), 'text', $disabled);

        // --- Ticket content pattern ---
        $out .= '<div class="mb-2"><label class="form-label" for="ticket_content_pattern">' . __s('Ticket content pattern', 'nms') . '</label>'
            . '<textarea class="form-control" rows="6" id="ticket_content_pattern" name="ticket_content_pattern"' . $disabled . '>'
            . htmlescape($getSetting('ticket_content_pattern', Settings::DEFAULTS['ticket_content_pattern'])) . '</textarea></div>';

        // --- Relink window ---
        $out .= self::textRow('relink_window_hours', 'relink_window_hours', __('Relink window (hours)', 'nms'), $getSetting('relink_window_hours', Settings::DEFAULTS['relink_window_hours']), 'number', $disabled);

        // --- Storm threshold ---
        $out .= self::textRow('storm_threshold', 'storm_threshold', __('Storm threshold', 'nms'), (int) $getSetting('storm_threshold', Settings::DEFAULTS['storm_threshold']), 'number', $disabled);

        // --- Storm window ---
        $out .= self::textRow('storm_window_minutes', 'storm_window_minutes', __('Storm window (minutes)', 'nms'), (int) $getSetting('storm_window_minutes', Settings::DEFAULTS['storm_window_minutes']), 'number', $disabled);

        // --- Outbound max attempts ---
        $out .= self::textRow('outbound_max_attempts', 'outbound_max_attempts', __('Outbound max attempts', 'nms'), (int) $getSetting('outbound_max_attempts', Settings::DEFAULTS['outbound_max_attempts']), 'number', $disabled);

        // --- Test button: calls Driver::testConnection().
        if ($canedit) {
            $out .= '<div class="mt-2">';
            $out .= \Html::submit(__('Test connection', 'nms'), [
                'name' => 'test_connection',
                'class' => 'btn btn-outline-secondary',
            ]);
            $out .= '</div>';
        }

        if ($canedit) {
            $out .= \Html::submit(__('Save', 'nms'), ['name' => 'update_connection', 'class' => 'btn btn-primary']);
        }
        \Html::closeForm();
        return $out;
    }

    /** Process a save POST. @return array{ok: bool, message: string} */
    public function handleSave(int $id, array $post): array
    {
        if (!\Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
            return ['ok' => false, 'message' => __('Missing update right', 'nms')];
        }
        $conn = $this->store->get($id);
        if ($conn === null) {
            return ['ok' => false, 'message' => __('Connection not found', 'nms')];
        }

        $brand = (string) ($post['brand'] ?? $conn->brand);
        if (!in_array($brand, Connection::BRANDS, true)) {
            return ['ok' => false, 'message' => __('Unknown NMS brand', 'nms')];
        }

        $fields = [
            'id'             => $id,
            'name'           => (string) ($post['name'] ?? $conn->name),
            'brand'          => $brand,
            'entities_id'    => (int) ($post['entities_id'] ?? $conn->entities_id),
            'is_recursive'   => !empty($post['is_recursive']) ? 1 : 0,
            'url'            => rtrim((string) ($post['url'] ?? $conn->url), '/'),
            'username'       => (string) ($post['username'] ?? $conn->username),
            'verify_tls'     => !empty($post['verify_tls']) ? 1 : 0,
            'is_active'      => !empty($post['is_active']) ? 1 : 0,
            'writeback_enabled'=> !empty($post['writeback_enabled']) ? 1 : 0,
            'tickets_enabled' => !empty($post['tickets_enabled']) ? 1 : 0,
            'date_creation'  => $conn->date_creation,
            'date_mod'       => date('Y-m-d H:i:s'),
        ];

        // Secrets: empty = keep stored.
        $enc = new \GLPIKey();
        $fields['api_key']      = (($post['api_key'] ?? '') !== '')      ? $enc->encrypt((string) ($post['api_key']     ?? '')) : $conn->api_key;
        $fields['password']     = (($post['password'] ?? '') !== '')     ? $enc->encrypt((string) ($post['password']    ?? '')) : $conn->password;
        $fields['webhook_token']= (($post['webhook_token'] ?? '') !== '') ? $enc->encrypt((string) ($post['webhook_token'] ?? '')) : $conn->webhook_token;

        // Settings: save all per-connection settings.
        $settingsData = json_decode((string) $conn->settings, true);
        if (!is_array($settingsData)) {
            $settingsData = [];
        }
        foreach (Settings::DEFAULTS as $key => $default) {
            $postKey = $key;
            if (array_key_exists($postKey, $post)) {
                $val = $post[$postKey];
                if ($val === '' || $val === null) {
                    $settingsData[$key] = $default;
                } elseif (is_bool($default)) {
                    $settingsData[$key] = (int) $val;
                } elseif (is_int($default)) {
                    $settingsData[$key] = (int) $val;
                } else {
                    $settingsData[$key] = (string) $val;
                }
            }
        }
        $fields['settings'] = json_encode($settingsData);

        $this->store->save($fields);
        return ['ok' => true, 'message' => __('Connection saved', 'nms')];
    }

    /** @return list<string> brands that DriverFactory supports. */
    public static function supportedBrands(): array
    {
        return DriverFactory::supportedBrands();
    }

    private static function textRow(string $id, string $name, string $label, string $value, string $type, string $disabled): string
    {
        return '<div class="mb-2"><label class="form-label" for="' . htmlescape($id) . '">' . htmlescape($label) . '</label>'
            . '<input type="' . $type . '" class="form-control" id="' . htmlescape($id) . '" name="' . htmlescape($name) . '" value="' . htmlescape($value) . '"' . $disabled . '></div>';
    }
}
