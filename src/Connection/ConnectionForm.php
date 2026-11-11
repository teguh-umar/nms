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

        // Test button: calls Driver::testConnection().
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

        // Settings: keep current, allow future per-connection edits here.
        $fields['settings'] = $conn->settings;

        $this->store->save($fields);
        return ['ok' => true, 'message' => __('Connection saved', 'nms')];
    }

    /** @return list<string> brands that DriverFactory supports. */
    public static function supportedBrands(): array
    {
        return DriverFactory::supportedBrands();
    }
}
