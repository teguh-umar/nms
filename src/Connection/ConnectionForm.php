<?php

namespace GlpiPlugin\Nms\Connection;

use GlpiPlugin\Nms\Config;
use GlpiPlugin\Nms\Driver\Capabilities;
use GlpiPlugin\Nms\Driver\DriverFactory;
use GlpiPlugin\Nms\RightDefinition;

/**
 * Connection list and edit form (front/connection.php, front/connection.form.php).
 * Every per-connection setting of Settings::DEFAULTS is edited here, with the 1.3.2 field
 * rendering and validation. Secrets are never echoed: blank = keep.
 */
final class ConnectionForm
{
    public const FORM_PATH = '/plugins/nms/front/connection.form.php';
    public const LIST_PATH = '/plugins/nms/front/connection.php';

    /** Header the NMS sends the token in (X-OPM-Token is still accepted, front/webhook.php). */
    public const WEBHOOK_HEADER = Config::WEBHOOK_HEADER;

    /** Hidden field: the POST carries the whole settings form (absent = settings untouched). */
    public const SETTINGS_MARKER = '_nms_settings';

    /** Session key: [connections_id => token] shown once after "Generate new token". */
    public const NEW_TOKEN_KEY = 'plugin_nms_new_webhook_token';

    public const TABS = ['connection', 'matching', 'tickets', 'filter', 'webhook', 'outbound', 'hygiene'];

    /**
     * Test seam for the lookups that read GLPI tables.
     *
     * @var null|callable(int): array{ranges: array<int, string>, event_types: list<string>, outbox: array{pending: int, failed: int, last_error: string}, hygiene: array<string, int>}
     */
    public static $lookups = null;

    public function __construct(private ConnectionStore $store)
    {
    }

    /** @return string HTML */
    public function renderList(): string
    {
        $out  = '<table class="table table-striped">';
        $out .= '<thead><tr>'
            . '<th>' . __s('Name', 'nms') . '</th>'
            . '<th>' . __s('Brand', 'nms') . '</th>'
            . '<th>' . __s('Entity', 'nms') . '</th>'
            . '<th>' . __s('Active', 'nms') . '</th>'
            . '<th>' . __s('Tickets', 'nms') . '</th>'
            . '<th>' . __s('Write-back', 'nms') . '</th>'
            . '<th>' . __s('Last device sync', 'nms') . '</th>'
            . '<th>' . __s('Last alarm sync', 'nms') . '</th>'
            . '<th>' . __s('Status', 'nms') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody>';
        $yes = static fn (int $v): string => $v ? __s('Yes') : __s('No');
        foreach ($this->store->all() as $conn) {
            $status = $conn->last_status === '' ? __('never synced', 'nms') : $conn->last_status;
            if ($conn->last_error !== '') {
                $status .= ' - ' . $conn->last_error;
            }
            $out .= '<tr>'
                . '<td>' . htmlescape($conn->name) . '</td>'
                . '<td>' . htmlescape($conn->brand) . '</td>'
                . '<td>' . (int) $conn->entities_id . '</td>'
                . '<td>' . $yes($conn->is_active) . '</td>'
                . '<td>' . $yes($conn->tickets_enabled) . '</td>'
                . '<td>' . $yes($conn->writeback_enabled) . '</td>'
                . '<td>' . htmlescape($conn->last_device_sync !== '' ? $conn->last_device_sync : __('never', 'nms')) . '</td>'
                . '<td>' . htmlescape($conn->last_alarm_sync !== '' ? $conn->last_alarm_sync : __('never', 'nms')) . '</td>'
                . '<td>' . htmlescape($status) . '</td>'
                . '<td><a href="' . htmlescape(\Html::getPrefixedUrl(self::FORM_PATH . '?id=' . (int) $conn->id)) . '">' . __s('Edit') . '</a></td>'
                . '</tr>';
        }
        $out .= '</tbody></table>';
        return $out;
    }

    /** Edit form; $id 0 = a new connection (OpManager, inactive, tickets and write-back off). @return string HTML */
    public function renderForm(int $id): string
    {
        $conn = $id > 0 ? $this->store->get($id) : self::blank();
        if ($conn === null) {
            return htmlescape(__('Connection not found', 'nms'));
        }
        $canedit  = \Session::haveRight(RightDefinition::CONFIG, UPDATE);
        $disabled = $canedit ? '' : ' disabled';
        $s        = Settings::fromJson($conn->settings);
        $caps     = self::capabilitiesOf($conn);
        $data     = self::lookups((int) $conn->id);

        ob_start();
        echo '<form method="post" action="' . htmlescape(\Html::getPrefixedUrl(self::FORM_PATH . '?id=' . (int) $conn->id)) . '">';
        echo \Html::hidden('id', ['value' => (int) $conn->id]);
        echo \Html::hidden(self::SETTINGS_MARKER, ['value' => 1]);

        $labels = [
            'connection' => __('Connection', 'nms'),
            'matching'   => __('Matching', 'nms'),
            'tickets'    => __('Tickets', 'nms'),
            'filter'     => __('Alarm filter', 'nms'),
            'webhook'    => __('Webhook', 'nms'),
            'outbound'   => __('Write-back', 'nms'),
            'hygiene'    => __('NMS hygiene', 'nms'),
        ];
        echo '<ul class="nav nav-tabs" role="tablist">';
        foreach (self::TABS as $i => $tab) {
            echo '<li class="nav-item" role="presentation"><a class="nav-link' . ($i === 0 ? ' active' : '') . '" data-bs-toggle="tab" role="tab" href="#nms-tab-' . $tab . '">'
                . htmlescape($labels[$tab]) . '</a></li>';
        }
        echo '</ul><div class="tab-content border border-top-0 rounded-bottom p-3 mb-3">';
        foreach (self::TABS as $i => $tab) {
            echo '<div class="tab-pane' . ($i === 0 ? ' active show' : '') . '" role="tabpanel" id="nms-tab-' . $tab . '">';
            match ($tab) {
                'connection' => self::tabConnection($conn, $caps, $disabled, $canedit),
                'matching'   => self::tabMatching($s, $canedit),
                'tickets'    => self::tabTickets($conn, $s, $data['ranges'], $disabled, $canedit),
                'filter'     => self::tabFilter($s, $data['event_types'], $disabled),
                'webhook'    => self::tabWebhook($conn, $caps, $canedit),
                'outbound'   => self::tabOutbound($s, $data['outbox'], $disabled),
                'hygiene'    => self::tabHygiene($s, $data['hygiene'], $disabled, $canedit),
            };
            echo '</div>';
        }
        echo '</div>';

        if ($canedit) {
            echo '<div class="d-flex gap-2">';
            echo \Html::submit(_sx('button', 'Save'), ['name' => 'update_connection', 'class' => 'btn btn-primary']);
            if ($conn->id > 0) {
                echo \Html::submit(__('Test connection', 'nms'), ['name' => 'test_connection', 'class' => 'btn btn-outline-secondary']);
            }
            echo '</div>';
        }
        \Html::closeForm();
        return (string) ob_get_clean();
    }

    /**
     * Save a POST. $id 0 creates a connection.
     *
     * @return array{ok: bool, id: int, message: string, errors: list<string>}
     */
    public function handleSave(int $id, array $post): array
    {
        $fail = static fn (string $m): array => ['ok' => false, 'id' => $id, 'message' => $m, 'errors' => []];
        if (!\Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
            return $fail(__('Missing update right', 'nms'));
        }
        $conn = $id > 0 ? $this->store->get($id) : self::blank();
        if ($conn === null) {
            return $fail(__('Connection not found', 'nms'));
        }
        $brand = (string) ($post['brand'] ?? $conn->brand);
        if (!in_array($brand, Connection::BRANDS, true)) {
            return $fail(__('Unknown NMS brand', 'nms'));
        }
        $name = trim((string) ($post['name'] ?? $conn->name));
        if ($name === '') {
            return $fail(__('A connection needs a name', 'nms'));
        }
        $url = rtrim(trim((string) ($post['url'] ?? $conn->url)), '/');
        if ($url !== '' && preg_match('#^https?://[^\s/]+(/[^\s]*)?$#i', $url) !== 1) {
            return $fail(__('The URL must start with http:// or https://', 'nms'));
        }

        $enc    = new \GLPIKey();
        $secret = static function (string $field, string $stored) use ($post, $enc): string {
            if (!empty($post[$field . '_clear'])) {
                return '';
            }
            $v = trim((string) ($post[$field] ?? ''));
            return $v !== '' ? (string) $enc->encrypt($v) : $stored;
        };

        $fields = [
            'id'                => $id,
            'name'              => $name,
            'brand'             => $brand,
            'entities_id'       => max(0, (int) ($post['entities_id'] ?? $conn->entities_id)),
            'is_recursive'      => !empty($post['is_recursive']) ? 1 : 0,
            'url'               => $url,
            'username'          => trim((string) ($post['username'] ?? $conn->username)),
            'api_key'           => $secret('api_key', $conn->api_key),
            'password'          => $secret('password', $conn->password),
            // The webhook token is generated (generateToken), never typed in.
            'webhook_token'     => $conn->webhook_token,
            'verify_tls'        => !empty($post['verify_tls']) ? 1 : 0,
            'is_active'         => !empty($post['is_active']) ? 1 : 0,
            'writeback_enabled' => !empty($post['writeback_enabled']) ? 1 : 0,
            'tickets_enabled'   => !empty($post['tickets_enabled']) ? 1 : 0,
            'date_mod'          => date('Y-m-d H:i:s'),
        ];

        $errors = [];
        if ($id === 0) {
            // A new connection starts from its brand's defaults (Settings::forNewConnection).
            $settings = Settings::forNewConnection($brand, (int) $fields['entities_id']);
            $fields['settings'] = $settings->toJson();
        } else {
            $settings = Settings::fromJson($conn->settings);
        }
        if (!empty($post[self::SETTINGS_MARKER])) {
            $checked = Config::sanitizeConnectionSettings($post);
            $errors  = $checked['errors'];
            $values  = $checked['values'];
            if ($id === 0) {
                // The new form shows OpManager defaults: a value left at them yields to the brand default.
                foreach ($values as $k => $v) {
                    if ($v === Settings::DEFAULTS[$k] && $settings->get($k) !== $v) {
                        unset($values[$k]);
                    }
                }
            }
            // The Zabbix block is posted only for a Zabbix connection.
            if ($brand === 'zabbix' && isset($post['zabbix_min_severity'])) {
                $values += Config::sanitizeZabbix($post);
            }
            // The PRTG block is posted only for a PRTG connection.
            if ($brand === 'prtg' && isset($post['prtg_api'])) {
                $values += Config::sanitizePrtg($post);
                // API v2 is read-only: a saved write-back switch cannot stay on.
                if ($values['prtg_api'] === 'v2') {
                    $fields['writeback_enabled'] = 0;
                }
            }
            $fields['settings'] = $settings->with($values)->toJson();
        }

        $newId = $this->store->save($fields);
        return [
            'ok'      => true,
            'id'      => $newId,
            'message' => $id > 0 ? __('Connection saved', 'nms') : __('Connection created', 'nms'),
            'errors'  => $errors,
        ];
    }

    /** New webhook token for a connection; returned once for display, stored encrypted. */
    public function generateToken(int $id): ?string
    {
        if (!\Session::haveRight(RightDefinition::CONFIG, UPDATE)) {
            return null;
        }
        $conn = $this->store->get($id);
        if ($conn === null) {
            return null;
        }
        $token = Config::newWebhookToken();
        $this->store->save(['id' => $id, 'brand' => $conn->brand, 'webhook_token' => (string) (new \GLPIKey())->encrypt($token), 'date_mod' => date('Y-m-d H:i:s')]);
        return $token;
    }

    /** @return list<string> brands that DriverFactory supports. */
    public static function supportedBrands(): array
    {
        return DriverFactory::supportedBrands();
    }

    private static function blank(): Connection
    {
        return new Connection([
            'id' => 0, 'name' => '', 'brand' => 'opmanager', 'entities_id' => 0, 'is_recursive' => 1,
            'verify_tls' => 1, 'is_active' => 0, 'writeback_enabled' => 0, 'tickets_enabled' => 0, 'settings' => '{}',
        ]);
    }

    private static function capabilitiesOf(Connection $conn): Capabilities
    {
        try {
            return DriverFactory::for($conn)->capabilities();
        } catch (\Throwable) {
            // A brand without a driver yet (Plan 3): nothing can be switched on.
            return new Capabilities(false, false, false, false, false, false, false, false);
        }
    }

    /** @return array{ranges: array<int, string>, event_types: list<string>, outbox: array{pending: int, failed: int, last_error: string}, hygiene: array<string, int>} */
    private static function lookups(int $cid): array
    {
        if (self::$lookups !== null) {
            return (self::$lookups)($cid);
        }
        global $DB;
        return [
            'ranges'      => \GlpiPlugin\Nms\Ticketing\AddressingRanges::options(),
            'event_types' => \GlpiPlugin\Nms\Ticketing\AlarmFilter::knownEventTypes(),
            'outbox'      => (new \GlpiPlugin\Nms\Outbound\DbOutboxStore($cid))->summary(),
            'hygiene'     => $DB->tableExists('glpi_plugin_nms_hygienetickets') ? (new \GlpiPlugin\Nms\Hygiene\DbHygieneStore($cid))->counts() : [],
        ];
    }

    private static function tabConnection(Connection $conn, Capabilities $caps, string $disabled, bool $canedit): void
    {
        echo self::textRow('nms-name', 'name', __('Name', 'nms'), $conn->name, 'text', $disabled);

        echo '<div class="mb-2"><label class="form-label" for="nms-brand">' . __s('Brand', 'nms') . '</label>'
            . '<select class="form-select" id="nms-brand" name="brand"' . $disabled . '>';
        $brands = array_values(array_unique(array_merge(self::supportedBrands(), [$conn->brand])));
        foreach ($brands as $brand) {
            echo '<option value="' . htmlescape($brand) . '"' . ($conn->brand === $brand ? ' selected' : '') . '>' . htmlescape($brand) . '</option>';
        }
        echo '</select></div>';

        echo self::textRow('nms-url', 'url', __('URL', 'nms'), $conn->url, 'url', $disabled);
        self::secretRow('api_key', __('API key', 'nms'), $conn->api_key !== '', $disabled);
        echo self::textRow('nms-username', 'username', __('Username', 'nms'), $conn->username, 'text', $disabled);
        self::secretRow('password', __('Password', 'nms'), $conn->password !== '', $disabled);
        self::checkRow('verify_tls', __('Verify the TLS certificate', 'nms'), $conn->verify_tls === 1, $disabled);

        if ($conn->brand === 'zabbix') {
            self::zabbixBlock(Settings::fromJson($conn->settings), $disabled);
        }

        if ($conn->brand === 'prtg') {
            self::prtgBlock(Settings::fromJson($conn->settings), $disabled);
        }

        echo '<div class="mb-2"><label class="form-label">' . __s('Entity that owns this connection', 'nms') . '</label>';
        \Entity::dropdown(['name' => 'entities_id', 'value' => $conn->entities_id, 'readonly' => !$canedit]);
        echo '</div>';
        self::checkRow('is_recursive', __('Child entities included', 'nms'), $conn->is_recursive === 1, $disabled);
        self::checkRow('is_active', __('Active (synced by the cron)', 'nms'), $conn->is_active === 1, $disabled);

        $tickets_off = $caps->alarms ? '' : ' disabled';
        self::checkRow('tickets_enabled', __('Create GLPI tickets from this NMS\'s alarms', 'nms'), $conn->tickets_enabled === 1, $disabled . $tickets_off, $caps->alarms ? '' : __('Not supported by this NMS', 'nms'));
        $wb = $caps->note || $caps->ack || $caps->clear;
        self::checkRow('writeback_enabled', __('Send notes, acknowledges and clears back to this NMS', 'nms'), $conn->writeback_enabled === 1, $disabled . ($wb ? '' : ' disabled'), $wb ? '' : __('Not supported by this NMS', 'nms'));

        if ($conn->id > 0) {
            echo '<div class="mt-3">' . __s('Last device sync', 'nms') . ': ' . htmlescape($conn->last_device_sync ?: __('never', 'nms'))
                . ' · ' . __s('last alarm sync', 'nms') . ': ' . htmlescape($conn->last_alarm_sync ?: __('never', 'nms')) . '</div>';
            if ($conn->last_error !== '') {
                echo '<div class="alert alert-warning mt-1">' . __s('Last error', 'nms') . ': ' . htmlescape($conn->last_error) . '</div>';
            }
        }
    }

    /** Zabbix filters (brand zabbix only). The API token goes in the API key field above. */
    private static function zabbixBlock(Settings $s, string $disabled): void
    {
        echo '<fieldset class="border rounded p-2 mb-2"><legend class="fs-6">' . __s('Zabbix filters', 'nms') . '</legend>';
        echo '<div class="mb-2"><label class="form-label" for="nms-zabbix-host-groups">' . __s('Host groups (one per line)', 'nms') . '</label>'
            . '<textarea class="form-control" rows="4" id="nms-zabbix-host-groups" name="zabbix_host_groups"' . $disabled . '>'
            . htmlescape($s->get('zabbix_host_groups')) . '</textarea>'
            . '<div class="form-hint">' . __s('Names are matched exactly: nested subgroups are not included, list them too. Empty = every host the API token can read. Names that match no group give an empty sync.', 'nms') . '</div></div>';
        echo '<div class="mb-2"><label class="form-label" for="nms-zabbix-min-severity">' . __s('Minimum problem severity', 'nms') . '</label>'
            . '<select class="form-select" id="nms-zabbix-min-severity" name="zabbix_min_severity"' . $disabled . '>';
        $labels = [0 => 'Not classified', 1 => 'Information', 2 => 'Warning', 3 => 'Average', 4 => 'High', 5 => 'Disaster'];
        foreach ($labels as $n => $label) {
            echo '<option value="' . $n . '"' . ($s->get('zabbix_min_severity') === (string) $n ? ' selected' : '') . '>' . $n . ' - ' . htmlescape($label) . '</option>';
        }
        echo '</select></div>';
        self::checkRow('zabbix_include_suppressed', __('Include suppressed problems (maintenance)', 'nms'), $s->get('zabbix_include_suppressed') === '1', $disabled);
        echo '</fieldset>';
    }

    /** PRTG settings (brand prtg only). Credentials go in the API key / username / password fields above. */
    private static function prtgBlock(Settings $s, string $disabled): void
    {
        echo '<fieldset class="border rounded p-2 mb-2"><legend class="fs-6">' . __s('PRTG', 'nms') . '</legend>';
        echo '<div class="mb-2"><label class="form-label" for="nms-prtg-api">' . __s('PRTG API', 'nms') . '</label>'
            . '<select class="form-select" id="nms-prtg-api" name="prtg_api"' . $disabled . '>';
        foreach (['classic' => 'Classic API', 'v2' => 'API v2 (experimental, read-only)'] as $value => $label) {
            echo '<option value="' . $value . '"' . ($s->get('prtg_api') === $value ? ' selected' : '') . '>' . htmlescape($label) . '</option>';
        }
        echo '</select><div class="form-hint">' . __s('Credentials: the API key is preferred and is sent as a header; otherwise fill in the username and password.', 'nms') . '</div></div>';
        echo '<div class="mb-2"><label class="form-label" for="nms-prtg-alarm-states">' . __s('Alarm states', 'nms') . '</label>'
            . '<select class="form-select" id="nms-prtg-alarm-states" name="prtg_alarm_states"' . $disabled . '>';
        foreach (['down' => 'Down', 'down+warning' => 'Down and warning', 'down+warning+unusual' => 'Down, warning and unusual'] as $value => $label) {
            echo '<option value="' . $value . '"' . ($s->get('prtg_alarm_states') === $value ? ' selected' : '') . '>' . htmlescape($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="mb-2"><label class="form-label" for="nms-prtg-groups">' . __s('Groups (one per line)', 'nms') . '</label>'
            . '<textarea class="form-control" rows="4" id="nms-prtg-groups" name="prtg_groups"' . $disabled . '>'
            . htmlescape($s->get('prtg_groups')) . '</textarea>'
            . '<div class="form-hint">' . __s('Names are matched exactly against the immediate group of each device, and are case-sensitive: parent and child groups are not included, list them too. Empty = every group.', 'nms') . '</div></div>';
        echo '</fieldset>';
    }

    private static function tabMatching(Settings $s, bool $canedit): void
    {
        echo '<label class="form-label">' . __s('Match mode', 'nms') . '</label>';
        \Dropdown::showFromArray('match_mode', Config::matchModeLabels(), [
            'value'    => $s->get('match_mode'),
            'width'    => '100%',
            'readonly' => !$canedit,
        ]);
        echo '<div class="form-hint">' . __s('A manual pin always wins. A name or IP owned by more than one GLPI asset is never used: the device goes to "Needs review".', 'nms') . '</div>';

        echo '<div class="mt-2"><label class="form-label">' . __s('Coverage page: GLPI statuses counted as in use (empty = all)', 'nms') . '</label>';
        // 'value', not 'values': for multiple, Dropdown::show() copies value into values (11.0.5).
        \State::dropdown(['name' => 'coverage_states[]', 'multiple' => true, 'value' => Config::coverageStatesFromSettings($s), 'width' => '100%', 'readonly' => !$canedit]);
        echo '</div>';
    }

    /** @param array<int, string> $ranges */
    private static function tabTickets(Connection $conn, Settings $s, array $ranges, string $disabled, bool $canedit): void
    {
        if ($conn->tickets_enabled !== 1) {
            echo '<div class="alert alert-info">' . __s('Tickets are off for this connection: its alarms are only shown on the asset tab.', 'nms') . '</div>';
        }
        echo '<div class="mb-3"><label class="form-label" for="nms-source">' . __s('Ticket source', 'nms') . '</label>';
        echo '<select class="form-select" id="nms-source" name="ticket_source"' . $disabled . '>';
        $labels = [
            'webhook' => __('NMS webhook criteria: only alarms sent by the webhook profile get a ticket', 'nms'),
            'rules'   => __('Plugin rules: severities and alarm filter below, from every poll', 'nms'),
        ];
        foreach (Config::TICKET_SOURCES as $source) {
            echo '<option value="' . htmlescape($source) . '"' . ($s->get('ticket_source') === $source ? ' selected' : '') . '>' . htmlescape($labels[$source]) . '</option>';
        }
        echo '</select></div>';

        echo '<div>' . __s('Create a ticket for these severities', 'nms') . '</div>';
        $sev_selected = array_map('intval', (array) (json_decode($s->get('ticket_severities'), true) ?: []));
        foreach (Config::SEVERITIES as $num => $label) {
            self::inlineCheck('ticket_severities[]', (string) $num, 'nms-sev-' . $num, $label, in_array($num, $sev_selected, true), $disabled);
        }

        echo '<div class="mt-2">' . __s('Production IP ranges (Tools > IP Addressing): tickets are created only for alarms on these', 'nms') . '</div>';
        $prod_selected = array_map('intval', (array) (json_decode($s->get('production_addressings'), true) ?: []));
        foreach ($ranges as $rid => $rlabel) {
            echo '<div class="form-check">';
            self::inlineCheck('production_addressings[]', (string) $rid, 'nms-prod-' . $rid, $rlabel, in_array((int) $rid, $prod_selected, true), $disabled, false);
            echo '</div>';
        }
        if ($ranges === []) {
            echo '<div class="alert alert-warning">' . __s('No IP Addressing range found (is the IP Addressing plugin active?). No ticket will be created.', 'nms') . '</div>';
        } elseif (array_intersect($prod_selected, array_map('intval', array_keys($ranges))) === []) {
            echo '<div class="alert alert-warning">' . __s('No production range selected: alarms are stored but no ticket is created.', 'nms') . '</div>';
        }

        echo '<div class="mt-2"><label class="form-label">' . __s('Ticket template', 'nms') . '</label>';
        \TicketTemplate::dropdown([
            'name'                => 'ticket_template',
            'value'               => (int) $s->get('ticket_template'),
            'display_emptychoice' => true,
            'emptylabel'          => __('Default GLPI template', 'nms'),
            'readonly'            => !$canedit,
        ]);
        echo '</div>';
        echo '<div class="mt-2"><label class="form-label">' . __s('GLPI user that creates the tickets (service account)', 'nms') . '</label>';
        echo \User::dropdown(['name' => 'service_user', 'value' => (int) $s->get('service_user'), 'right' => 'all', 'readonly' => !$canedit, 'display' => false]);
        echo '</div>';
        echo '<div class="mt-2"><label class="form-label">' . __s('Default entity (no asset and no matching IP network)', 'nms') . '</label>';
        \Entity::dropdown(['name' => 'ticket_entity_default', 'value' => (int) $s->get('ticket_entity_default'), 'readonly' => !$canedit]);
        echo '</div>';
        self::checkRow('ticket_use_ipnetwork_entity', __('Use the entity of the GLPI IP network containing the device IP', 'nms'), $s->get('ticket_use_ipnetwork_entity') === '1', $disabled);
        echo self::textRow('nms-title', 'ticket_title_pattern', __('Ticket title pattern', 'nms'), $s->get('ticket_title_pattern'), 'text', $disabled);
        echo '<div class="mb-2"><label class="form-label" for="nms-content">' . __s('Ticket description pattern', 'nms') . '</label>'
            . '<textarea class="form-control" rows="6" id="nms-content" name="ticket_content_pattern"' . $disabled . '>' . htmlescape($s->get('ticket_content_pattern')) . '</textarea>'
            . '<div class="form-hint">' . __s('Variables: {alarm_key} {severity} {message} {device} {ip} {alarm_type} {time} {category}', 'nms') . '</div></div>';
        echo self::textRow('nms-relink', 'relink_window_hours', __('Link a repeat of the same alarm type on the same device within (hours)', 'nms'), $s->get('relink_window_hours'), 'number', $disabled);
        echo self::textRow('nms-storm', 'storm_threshold', __('Alarm storm: new tickets allowed per window before grouping (0 = off)', 'nms'), $s->get('storm_threshold'), 'number', $disabled);
        echo self::textRow('nms-storm-win', 'storm_window_minutes', __('Alarm storm window (minutes)', 'nms'), $s->get('storm_window_minutes'), 'number', $disabled);
    }

    /** @param list<string> $known */
    private static function tabFilter(Settings $s, array $known, string $disabled): void
    {
        if ($s->get('ticket_source') === 'webhook') {
            echo '<div class="form-hint">' . __s('Used only when the ticket source is "Plugin rules". Kept when saved.', 'nms') . '</div>';
        }
        $filter = Config::filterSettings($s->get('ticket_filter_mode'), $s->get('ticket_filter_event_types'), $s->get('ticket_filter_keywords'));
        echo '<div><label class="form-label" for="nms-filter-mode">' . __s('Alarm filter (new tickets only)', 'nms') . '</label>';
        echo '<select class="form-select" id="nms-filter-mode" name="ticket_filter_mode"' . $disabled . '>';
        $labels = [
            'exclude' => __('Exclude: matching alarms get no ticket', 'nms'),
            'include' => __('Only: only matching alarms get a ticket', 'nms'),
        ];
        foreach (Config::FILTER_MODES as $mode) {
            echo '<option value="' . htmlescape($mode) . '"' . ($filter['filter_mode'] === $mode ? ' selected' : '') . '>' . htmlescape($labels[$mode]) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="mt-2">' . __s('Event types', 'nms') . '</div>';
        $types = array_values(array_unique(array_merge($known, $filter['filter_event_types'])));
        sort($types);
        foreach ($types as $i => $type) {
            self::inlineCheck('ticket_filter_event_types[]', $type, 'nms-evt-' . $i, $type, in_array($type, $filter['filter_event_types'], true), $disabled);
        }
        if ($types === []) {
            echo '<div class="form-hint">' . __s('No alarm received yet; event types appear here after the first alarm sync.', 'nms') . '</div>';
        }
        echo '<div class="mt-2"><label class="form-label" for="nms-filter-kw">' . __s('Message keywords (one per line, not case sensitive)', 'nms') . '</label>'
            . '<textarea class="form-control" rows="3" id="nms-filter-kw" name="ticket_filter_keywords"' . $disabled . '>' . htmlescape(implode("\n", $filter['filter_keywords'])) . '</textarea>'
            . '<div class="form-hint">' . __s('An alarm matches when its event type is ticked or its message contains a keyword. Alarms that already have a ticket are not affected.', 'nms') . '</div></div>';
        if ($filter['filter_event_types'] === [] && $filter['filter_keywords'] === []) {
            echo '<div class="alert alert-info mt-1">' . __s('Alarm filter is off: every alarm that passes the severity and production rules gets a ticket.', 'nms') . '</div>';
        }
    }

    private static function tabWebhook(Connection $conn, Capabilities $caps, bool $canedit): void
    {
        if (!$caps->webhook) {
            echo '<div class="alert alert-info">' . __s('This NMS has no webhook: alarms are polled only.', 'nms') . '</div>';
            return;
        }
        global $CFG_GLPI;
        $url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . Config::WEBHOOK_PATH;
        echo '<div>' . __s('OpManager > Notification Profile > Invoke a Webhook: POST, raw JSON', 'nms') . '</div>';
        echo '<div class="mb-1">URL: <code>' . htmlescape($url) . '</code></div>';
        echo '<div class="mb-1">' . __s('Header', 'nms') . ': <code>' . htmlescape(self::WEBHOOK_HEADER) . '</code> = '
            . ($conn->webhook_token !== '' ? __s('stored token (shown once when generated)', 'nms') : '<strong>' . __s('not set - webhook disabled', 'nms') . '</strong>') . '</div>';
        echo '<div class="mb-1">' . __s('Body', 'nms') . ': <code>{"alarm_id":"$alarmid","entity":"$entity"}</code></div>';
        echo '<div class="form-hint">' . __s('The token tells nms which connection sent the alarm: every connection has its own.', 'nms') . '</div>';

        $tokens = (array) ($_SESSION[self::NEW_TOKEN_KEY] ?? []);
        $new    = (string) ($tokens[$conn->id] ?? '');
        unset($_SESSION[self::NEW_TOKEN_KEY][$conn->id]);
        if ($new !== '') {
            echo '<div class="alert alert-warning mt-2">' . __s('New webhook token - copy it into the NMS now, it is not shown again:', 'nms')
                . '<input type="text" class="form-control font-monospace mt-1" readonly onclick="this.select()" value="' . htmlescape($new) . '"></div>';
        }
        if ($canedit && $conn->id > 0) {
            echo '<div class="mt-1">' . \Html::submit(__('Generate new token', 'nms'), ['name' => 'generate_webhook_token', 'class' => 'btn btn-outline-secondary']) . '</div>';
        }
    }

    /** @param array{pending: int, failed: int, last_error: string} $box */
    private static function tabOutbound(Settings $s, array $box, string $disabled): void
    {
        echo '<div class="mb-2"><strong>' . __s('Sent to the NMS (notes, acknowledges and clears)', 'nms') . '</strong></div>';
        echo self::textRow('nms-outbound', 'outbound_max_attempts', __('Retries before a note/clear is given up (1-50)', 'nms'), $s->get('outbound_max_attempts'), 'number', $disabled);
        echo '<div>' . sprintf(__s('Waiting: %d, given up: %d', 'nms'), (int) $box['pending'], (int) $box['failed']) . '</div>';
        if ((string) $box['last_error'] !== '') {
            echo '<div class="alert alert-warning">' . __s('Last write error', 'nms') . ': ' . htmlescape((string) $box['last_error']) . '</div>';
        }
    }

    /** D34: NMS hygiene settings (spec §6, §8.4). @param array<string, int> $counts */
    private static function tabHygiene(Settings $s, array $counts, string $disabled, bool $canedit): void
    {
        $list  = static fn (string $key): array => array_map('intval', (array) (json_decode($s->get($key), true) ?: []));
        $lines = static fn (string $key): string => implode("\n", array_map('strval', (array) (json_decode($s->get($key), true) ?: [])));
        echo '<p class="text-muted">' . __s('GLPI is the asset source of truth. Request tickets: devices monitored in the NMS but not in GLPI go to the GLPI and asset teams (NMS team as observer); unmanaged devices that are dead or of unknown liveness go to the NMS team. Nothing is ever changed in the NMS.', 'nms') . '</p>';
        self::checkRow('hygiene_enabled', __('Create hygiene tickets (the classification runs daily either way)', 'nms'), $s->get('hygiene_enabled') === '1', $disabled);
        echo self::textRow('nms-hy-freq', 'hygiene_frequency_days', __('Days between hygiene ticket runs', 'nms'), $s->get('hygiene_frequency_days'), 'number', $disabled);
        echo '<div class="mb-2"><label class="form-label">' . __s('GLPI team and asset team groups (observers on the "not in GLPI" ticket)', 'nms') . '</label>';
        \Group::dropdown(['name' => 'hygiene_glpi_groups[]', 'multiple' => true, 'value' => $list('hygiene_glpi_groups'), 'readonly' => !$canedit]);
        echo '</div><div class="mb-2"><label class="form-label">' . __s('Asset technician(s) responsible for asset input and checks (assigned to the "not in GLPI" ticket; required to turn tickets on)', 'nms') . '</label>';
        echo \User::dropdown(['name' => 'hygiene_glpi_users[]', 'multiple' => true, 'value' => $list('hygiene_glpi_users'), 'right' => 'own_ticket', 'readonly' => !$canedit, 'display' => false]);
        echo '</div><div class="mb-2"><label class="form-label">' . __s('ITIL category of the hygiene tickets (they skip the ticket business rules; empty = none)', 'nms') . '</label>';
        \ITILCategory::dropdown(['name' => 'hygiene_itil_category', 'value' => (int) $s->get('hygiene_itil_category'), 'readonly' => !$canedit]);
        echo '</div><div class="mb-2"><label class="form-label">' . __s('NMS team group (assigned to the "unmanaged" ticket, observer on the other)', 'nms') . '</label>';
        \Group::dropdown(['name' => 'hygiene_nms_group', 'value' => (int) $s->get('hygiene_nms_group'), 'readonly' => !$canedit]);
        echo '</div><div class="mb-2"><label class="form-label">' . __s('Hygiene ticket template', 'nms') . '</label>';
        \TicketTemplate::dropdown([
            'name'                => 'hygiene_ticket_template',
            'value'               => (int) $s->get('hygiene_ticket_template'),
            'display_emptychoice' => true,
            'emptylabel'          => __('Default GLPI Request template', 'nms'),
            'readonly'            => !$canedit,
        ]);
        echo '</div>';
        echo '<div class="mb-2"><label class="form-label" for="nms-hy-unm">' . __s('NMS availabilities that mean "not monitored" (one per line)', 'nms') . '</label>'
            . '<textarea class="form-control" rows="2" id="nms-hy-unm" name="hygiene_unmanaged_availabilities"' . $disabled . '>' . htmlescape($lines('hygiene_unmanaged_availabilities')) . '</textarea></div>';
        echo self::textRow('nms-hy-agent', 'hygiene_agent_stale_days', __('GLPI agent silent for more than (days) = dead', 'nms'), $s->get('hygiene_agent_stale_days'), 'number', $disabled);
        echo self::textRow('nms-hy-mist', 'hygiene_mist_stale_days', __('Mist device not seen for more than (days) = dead', 'nms'), $s->get('hygiene_mist_stale_days'), 'number', $disabled);
        echo '<div class="mb-2"><label class="form-label" for="nms-hy-cidr">' . __s('Segments the GLPI server can reach, for the TCP probe (IPv4 CIDR, one per line; empty = no probe)', 'nms') . '</label>'
            . '<textarea class="form-control" rows="3" id="nms-hy-cidr" name="hygiene_probe_cidrs"' . $disabled . '>' . htmlescape($lines('hygiene_probe_cidrs')) . '</textarea></div>';
        echo self::textRow('nms-hy-ports', 'hygiene_probe_tcp_ports', __('Probe TCP ports (comma separated, up to 10)', 'nms'), implode(',', $list('hygiene_probe_tcp_ports')), 'text', $disabled);
        echo self::textRow('nms-hy-timeout', 'hygiene_probe_timeout_ms', __('Probe timeout per port (ms)', 'nms'), $s->get('hygiene_probe_timeout_ms'), 'number', $disabled);
        echo '<div class="form-hint mb-2">' . __s('Other plugins can add liveness sources (e.g. a FortiCloud plugin) through the nms_liveness hook.', 'nms') . '</div>';

        foreach ($counts as $state => $n) {
            echo '<span class="badge bg-secondary-lt me-1">' . htmlescape((\GlpiPlugin\Nms\Hygiene\Classifier::labels()[$state] ?? $state) . ': ' . $n) . '</span>';
        }
        echo '<div class="mt-2">' . __s('Last hygiene run', 'nms') . ': ' . htmlescape($s->get('last_hygiene_run') ?: __('never', 'nms'))
            . ' · ' . __s('last ticket run', 'nms') . ': ' . htmlescape($s->get('last_hygiene_ticket_run') ?: __('never', 'nms')) . '</div>';
        if ($s->get('last_hygiene_error') !== '') {
            echo '<div class="alert alert-warning">' . __s('Last hygiene error', 'nms') . ': ' . htmlescape($s->get('last_hygiene_error')) . '</div>';
        }
    }

    private static function secretRow(string $name, string $label, bool $stored, string $disabled): void
    {
        $placeholder = $stored ? __('Stored - leave blank to keep', 'nms') : __('Not set', 'nms');
        echo '<div class="mb-2"><label class="form-label" for="nms-' . $name . '">' . htmlescape($label) . '</label>'
            . '<input type="password" autocomplete="new-password" class="form-control" id="nms-' . $name . '" name="' . $name . '" value="" placeholder="' . htmlescape($placeholder) . '"' . $disabled . '>';
        if ($stored) {
            echo '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="' . $name . '_clear" value="1" id="nms-' . $name . '-clear"' . $disabled . '>'
                . '<label class="form-check-label" for="nms-' . $name . '-clear">' . __s('Remove the stored value', 'nms') . '</label></div>';
        }
        echo '</div>';
    }

    private static function checkRow(string $name, string $label, bool $checked, string $disabled, string $note = ''): void
    {
        echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="' . $name . '" value="1" id="nms-' . $name . '"'
            . ($checked ? ' checked' : '') . $disabled . '>'
            . '<label class="form-check-label" for="nms-' . $name . '">' . htmlescape($label) . '</label>'
            . ($note !== '' ? ' <span class="text-muted">(' . htmlescape($note) . ')</span>' : '') . '</div>';
    }

    private static function inlineCheck(string $name, string $value, string $id, string $label, bool $checked, string $disabled, bool $inline = true): void
    {
        echo ($inline ? '<div class="form-check form-check-inline">' : '')
            . '<input class="form-check-input" type="checkbox" name="' . htmlescape($name) . '" value="' . htmlescape($value) . '" id="' . htmlescape($id) . '"'
            . ($checked ? ' checked' : '') . $disabled . '>'
            . '<label class="form-check-label" for="' . htmlescape($id) . '">' . htmlescape($label) . '</label>'
            . ($inline ? '</div>' : '');
    }

    private static function textRow(string $id, string $name, string $label, string $value, string $type, string $disabled): string
    {
        return '<div class="mb-2"><label class="form-label" for="' . htmlescape($id) . '">' . htmlescape($label) . '</label>'
            . '<input type="' . $type . '" class="form-control" id="' . htmlescape($id) . '" name="' . htmlescape($name) . '" value="' . htmlescape($value) . '"' . $disabled . '></div>';
    }
}
