<?php
namespace Glpi\RichText {
    if (!class_exists(RichText::class, false)) {
        final class RichText
        {
            public static function getTextFromHtml(string $html): string { return strip_tags($html); }
        }
    }
}
