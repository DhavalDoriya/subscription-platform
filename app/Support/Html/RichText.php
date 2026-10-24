<?php

namespace App\Support\Html;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * The only way rich text from a provider reaches storage (FR-S7). It is a whitelist: anything not listed, including
 * scripts, styles, iframes, images, inline handlers and non-http(s)/mailto links, is removed, not escaped.
 */
final class RichText
{
    private static ?HTMLPurifier $purifier = null;

    public static function clean(string $html): string
    {
        $clean = trim(self::purifier()->purify($html));

        // Links in provider content are untrusted: no referrer leverage, no window.opener.
        return str_replace('rel="nofollow"', 'rel="nofollow noopener"', $clean);
    }

    private static function purifier(): HTMLPurifier
    {
        if (! self::$purifier) {
            $cache = storage_path('framework/cache/htmlpurifier');
            is_dir($cache) || mkdir($cache, 0775, true);

            $config = HTMLPurifier_Config::createDefault();
            $config->set('Cache.SerializerPath', $cache);
            $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,h2,h3,h4,ul,ol,li,blockquote,a[href|title]');
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('HTML.Nofollow', true);
            $config->set('AutoFormat.RemoveEmpty', true);

            self::$purifier = new HTMLPurifier($config);
        }

        return self::$purifier;
    }
}
