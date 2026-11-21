<?php

/**
 * PGN Suite — shared settings page, one entry in the Setup sidebar (revision r5).
 * Its title is the customisable suite label (default "ICT Tools"), the same one the
 * Tools ICT menu uses, edited in this page's own "General" section.
 *
 * r5 (2026-10-27, user decision): the settings moved OUT of Setup > General (they
 * were a tab there up to r4) into their own Setup entry. The section hook below is
 * unchanged, so a plugin's own settings code needs no change; it ships this file and
 * front/pgnsuite.settings.php (byte-identical, see shared/pgnsuite/front/).
 *
 * CANONICAL COPY: …/Documents/PGN GLPI Suite/shared/pgnsuite/ConfigTab.php
 * Every PGN plugin ships this file byte-identical at lib/pgnsuite/ConfigTab.php.
 * Never edit a plugin's copy on its own — change the canonical copy, bump
 * CONTRACT_VERSION if the API changes, and redistribute to every plugin (hub §5).
 */

namespace GlpiPlugin\Pgnsuite;

use CommonGLPI;
use Config;
use Html;
use Plugin;
use Session;

final class ConfigTab extends CommonGLPI
{
    public const CONTRACT_VERSION = 1;

    public const HOOK = 'pgnsuite_config_section';

    /** Same context, key and default as Menu and AssetTab (each reads it itself). */
    public const CONFIG_CONTEXT = 'pgnsuite';
    public const DEFAULT_LABEL  = 'ICT Tools';
    public const LABEL_MAX      = 40;

    /** Every plugin ships this controller; the menu points at the first one found. */
    public const PAGE_FILE = 'front/pgnsuite.settings.php';

    /** Core's Setup sector key. */
    private const SECTOR = 'config';

    private const SESSION_KEY = 'pgnsuite_active_section';

    /** Not a valid plugin key (those match /^[a-z0-9]+$/), so it never collides. */
    private const GENERAL = '_general';

    /**
     * Call from plugin_init_<plugin>(), as before (no argument: the r4 call sites stay).
     * Adds this class to the Setup sector for the calling plugin, read from the calling
     * setup.php's path because every plugin calls this before it sets its section hook,
     * and for every plugin that has registered a section so far. Repeated calls are
     * harmless: core keys the entry by class name, so the sidebar shows one entry.
     */
    public static function register(): void
    {
        global $PLUGIN_HOOKS;

        $plugins = array_keys($PLUGIN_HOOKS[self::HOOK] ?? []);
        $caller  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file'] ?? '';
        if (preg_match('#[\\\\/](?:plugins|marketplace)[\\\\/]([a-z0-9]+)[\\\\/]setup\.php$#', (string) $caller, $m)) {
            $plugins[] = $m[1];
        }

        foreach (array_unique($plugins) as $plugin) {
            if (!is_string($plugin) || !preg_match('/^[a-z0-9]+$/', $plugin)) {
                continue;
            }
            $current = $PLUGIN_HOOKS['menu_toadd'][$plugin][self::SECTOR] ?? [];
            $current = is_array($current) ? $current : [$current];
            if (!in_array(self::class, $current, true)) {
                $current[] = self::class;
            }
            $PLUGIN_HOOKS['menu_toadd'][$plugin][self::SECTOR] = $current;
        }
    }

    public static function label(): string
    {
        static $label = null;
        if ($label === null) {
            $value = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['label'])['label'] ?? '';
            $label = is_string($value) && trim($value) !== '' ? trim($value) : self::DEFAULT_LABEL;
        }
        return $label;
    }

    public static function getTypeName($nb = 0)
    {
        return self::label();
    }

    public static function getIcon()
    {
        return 'ti ti-adjustments-alt';
    }

    /**
     * One entry under Setup. Hidden for a user who may see no section, and while no
     * active plugin ships the page controller (e.g. mid-rollout).
     *
     * @return array|false
     */
    public static function getMenuContent()
    {
        $page = self::pagePath();
        if ($page === null || self::allSections() === []) {
            return false;
        }
        return [
            'title' => self::label(),
            'page'  => $page,
            'icon'  => self::getIcon(),
        ];
    }

    /**
     * The settings page itself. Called by every plugin's front/pgnsuite.settings.php.
     */
    public static function displayPage(): void
    {
        $sections = self::allSections();
        if ($sections === []) {
            Html::displayRightError();
            return;
        }
        Html::header(self::label(), '', self::SECTOR, strtolower(self::class));
        self::renderSections($sections);
        Html::footer();
    }

    /**
     * URL a plugin's config controller redirects to after saving, landing back
     * on this page with the plugin's own section selected.
     */
    public static function getReturnUrl(string $section): string
    {
        $_SESSION[self::SESSION_KEY] = $section;
        $page = self::pagePath();
        return $page !== null ? Html::getPrefixedUrl($page) : Config::getFormURL();
    }

    /**
     * The suite's own "General" section: the label form. Posts to core
     * config.form.php with config_context, which saves through configUpdate() below.
     */
    public static function renderGeneral(): void
    {
        $stored = Config::getConfigurationValues(self::CONFIG_CONTEXT, ['label'])['label'] ?? '';

        echo '<form method="post" action="' . htmlescape(Config::getFormURL()) . '">';
        echo '<div class="card"><div class="card-body">';
        echo '<div class="mb-3" style="max-width:32rem">';
        echo '<label class="form-label" for="pgnsuite-label">' . htmlescape(__('Name')) . '</label>';
        echo sprintf(
            '<input type="text" class="form-control" id="pgnsuite-label" name="label" value="%s" maxlength="%d" placeholder="%s">',
            htmlescape(is_string($stored) ? $stored : ''),
            self::LABEL_MAX,
            htmlescape(self::DEFAULT_LABEL)
        );
        echo '<div class="form-text">'
            . htmlescape(sprintf(
                'Shown as the sidebar menu, this settings page under Setup and the asset tab. '
                . 'Leave empty for "%s". Other users see a new name after they log in again.',
                self::DEFAULT_LABEL
            ))
            . '</div>';
        echo '</div>';
        echo Html::hidden('config_context', ['value' => self::CONFIG_CONTEXT]);
        echo Html::hidden('config_class', ['value' => self::class]);
        echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        echo '</div></div>';
        Html::closeForm();
    }

    /**
     * Called by core Config::prepareInputForUpdate() (config_class). Its return value
     * is exactly what gets stored, so only "label" can be written through this form.
     * core config.form.php checks only config READ, so UPDATE is enforced here.
     * Core redirects back to the referer, which is this settings page.
     */
    public static function configUpdate(array $input): array
    {
        if (!Session::haveRight('config', UPDATE)) {
            return [];
        }
        $label = preg_replace('/[\p{C}]+/u', '', (string) ($input['label'] ?? ''));
        $label = trim(preg_replace('/\s+/u', ' ', (string) $label) ?? '');
        $label = mb_substr($label, 0, self::LABEL_MAX);

        // The sidebar is cached per session: rebuild it for this admin now.
        unset($_SESSION['glpimenu']);
        $_SESSION[self::SESSION_KEY] = self::GENERAL;

        return ['label' => $label];
    }

    /**
     * "/plugins/<key>/front/pgnsuite.settings.php" of the first active plugin that
     * ships the controller, in the order plugins registered their sections.
     */
    private static function pagePath(): ?string
    {
        global $PLUGIN_HOOKS;

        static $page = false;
        if ($page !== false) {
            return $page;
        }
        foreach (array_keys($PLUGIN_HOOKS[self::HOOK] ?? []) as $plugin) {
            if (!is_string($plugin) || !preg_match('/^[a-z0-9]+$/', $plugin) || !Plugin::isPluginActive($plugin)) {
                continue;
            }
            $dir = Plugin::getPhpDir($plugin);
            if (is_string($dir) && is_file($dir . '/' . self::PAGE_FILE)) {
                return $page = '/plugins/' . $plugin . '/' . self::PAGE_FILE;
            }
        }
        return $page = null;
    }

    /**
     * @param array<string, array{title: string, icon: string, order: int, render: string}> $sections
     */
    private static function renderSections(array $sections): void
    {
        $requested = $_GET['section'] ?? null;
        $active    = is_string($requested) ? $requested : ($_SESSION[self::SESSION_KEY] ?? null);
        unset($_SESSION[self::SESSION_KEY]);
        if (!isset($sections[$active])) {
            $active = array_key_first($sections);
        }

        // Not .nav-pills: its primary-tinted active state (#FEC95C on the default palette)
        // is unreadable. Core's --glpi-tabs-* variables match GLPI's own vertical tabs in
        // every palette; the active marker uses the active text color, which each
        // palette pairs with its active background.
        echo '<style>'
            . '.pgnsuite-subnav .nav-link{background:var(--glpi-tabs-bg);color:var(--glpi-tabs-fg);'
            . 'border:1px solid var(--glpi-tabs-border-color);border-left:5px solid transparent;'
            . 'border-radius:0;margin-bottom:-1px;text-align:left}'
            . '.pgnsuite-subnav .nav-link:hover{color:var(--glpi-tabs-active-fg)}'
            . '.pgnsuite-subnav .nav-link.active{background:var(--glpi-tabs-active-bg);'
            . 'color:var(--glpi-tabs-active-fg);border-left-color:var(--glpi-tabs-active-fg);font-weight:bold}'
            // The sub-nav is as wide as its longest title (capped), not a quarter of the page.
            . '.pgnsuite-config-nav{flex:0 0 auto}'
            . '.pgnsuite-config-nav .nav-link{white-space:nowrap}'
            . '@media (min-width:768px){.pgnsuite-config-nav{width:max-content;max-width:16rem}'
            . '.pgnsuite-config-nav .nav-link{overflow:hidden;text-overflow:ellipsis}}'
            . '.pgnsuite-config-body{flex:1 1 auto;min-width:0}'
            . '</style>';
        echo '<div class="d-flex flex-column flex-md-row gap-3 pgnsuite-config">';
        echo '<div class="pgnsuite-config-nav"><div class="nav flex-column pgnsuite-subnav" role="tablist">';
        foreach ($sections as $key => $section) {
            $is_active = $key === $active;
            echo sprintf(
                '<button type="button" class="nav-link%s" data-bs-toggle="pill" data-bs-target="#pgnsuite-%s" role="tab" aria-selected="%s" title="%s"><i class="%s me-2"></i>%s</button>',
                $is_active ? ' active' : '',
                htmlescape($key),
                $is_active ? 'true' : 'false',
                htmlescape($section['title']),
                htmlescape($section['icon']),
                htmlescape($section['title'])
            );
        }
        echo '</div></div>';

        echo '<div class="pgnsuite-config-body"><div class="tab-content">';
        foreach ($sections as $key => $section) {
            echo sprintf(
                '<div class="tab-pane fade%s" id="pgnsuite-%s" role="tabpanel">',
                $key === $active ? ' show active' : '',
                htmlescape($key)
            );
            call_user_func($section['render']);
            echo '</div>';
        }
        echo '</div></div></div>';
    }

    private static function canEditLabel(): bool
    {
        return Session::haveRight('config', UPDATE);
    }

    /**
     * Plugin sections plus, for core config UPDATE holders, the suite's General section.
     *
     * @return array<string, array{title: string, icon: string, order: int, render: string}>
     */
    private static function allSections(): array
    {
        $sections = self::sections();
        if ($sections !== [] && self::canEditLabel()) {
            $sections[self::GENERAL] = [
                'title'  => __('General'),
                'icon'   => 'ti ti-tag',
                'order'  => PHP_INT_MAX,
                'render' => self::class . '::renderGeneral',
            ];
        }
        return $sections;
    }

    /**
     * @return array<string, array{title: string, icon: string, order: int, render: string}>
     */
    private static function sections(): array
    {
        global $PLUGIN_HOOKS;

        $sections = [];
        foreach ($PLUGIN_HOOKS[self::HOOK] ?? [] as $plugin => $provider) {
            if (
                !is_string($plugin)
                || !preg_match('/^[a-z0-9]+$/', $plugin)
                || !is_string($provider)
                || !Plugin::isPluginActive($plugin)
                || !is_callable($provider)
            ) {
                continue;
            }
            // A provider returns null when the current user may not see its section.
            $section = call_user_func($provider);
            if (
                !is_array($section)
                || !is_string($section['title'] ?? null)
                || !is_string($section['render'] ?? null)
                || !is_callable($section['render'])
            ) {
                continue;
            }
            $sections[$plugin] = [
                'title'  => $section['title'],
                'icon'   => is_string($section['icon'] ?? null) ? $section['icon'] : 'ti ti-settings',
                'order'  => (int) ($section['order'] ?? 500),
                'render' => $section['render'],
            ];
        }

        uasort(
            $sections,
            static fn(array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]
        );

        return $sections;
    }
}
