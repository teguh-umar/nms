<?php

namespace GlpiPlugin\Nms;

use Session;

/**
 * OpManager's entry in the shared suite sidebar menu (hub §5, lib/pgnsuite/Menu.php;
 * v1.2.0). Before, the plugin had its own top-level sector `opmanager`. No Setup entry:
 * settings live in Setup > General > (suite tab).
 */
class Menu
{
    public const ORDER = 65;

    /** Provider for \GlpiPlugin\Pgnsuite\Menu::HOOK; null hides the entry. */
    public static function entry(): ?array
    {
        if (!Session::haveRight(RightDefinition::MONITORING, READ)) {
            return null;
        }
        return [
            'title'   => 'NMS Integration',
            'icon'    => 'ti ti-activity',
            'page'    => Device::getSearchURL(false),
            'order'   => self::ORDER,
            'options' => [
                'device'   => [
                    'title'          => Device::getTypeName(2),
                    'page'           => Device::getSearchURL(false),
                    'icon'           => Device::getIcon(),
                    'links'          => ['search' => Device::getSearchURL(false)],
                    'lists_itemtype' => Device::class,
                ],
                'alarm'    => [
                    'title'          => Alarm::getTypeName(2),
                    'page'           => Alarm::getSearchURL(false),
                    'icon'           => Alarm::getIcon(),
                    'links'          => ['search' => Alarm::getSearchURL(false)],
                    'lists_itemtype' => Alarm::class,
                ],
                'coverage' => [
                    'title' => \GlpiPlugin\Nms\Coverage\Page::getTypeName(),
                    'page'  => \GlpiPlugin\Nms\Coverage\Page::getSearchURL(false),
                    'icon'  => \GlpiPlugin\Nms\Coverage\Page::getIcon(),
                ],
                // v1.3.0-rc4: the user manual (suite rule 2026-11-09).
                'manual'   => [
                    'title' => __('Manual', 'nms'),
                    'page'  => '/plugins/opmanager/front/manual.php',
                    'icon'  => 'ti ti-book',
                ],
            ],
        ];
    }
}
