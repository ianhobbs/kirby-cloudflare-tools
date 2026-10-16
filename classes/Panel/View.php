<?php

namespace IanHobbs\CloudflareTools\Panel;

use IanHobbs\CloudflareTools\Compat;
use IanHobbs\CloudflareTools\Options;

/**
 * The tabs of the Cloudflare area. A site hides some with the `panel.hide`
 * option, e.g. ['dns', 'speed']; the DNS tab also goes when `dns` is off.
 */
final class View
{
    public const TABS = [
        'overview'    => ['label' => 'Overview', 'icon' => 'chart'],
        'cache'       => ['label' => 'Caching', 'icon' => 'refresh'],
        'security'    => ['label' => 'Security', 'icon' => 'shield'],
        'ssl'         => ['label' => 'SSL/TLS', 'icon' => 'lock'],
        'speed'       => ['label' => 'Speed & Network', 'icon' => 'bolt'],
        'dns'         => ['label' => 'DNS', 'icon' => 'globe'],
        'diagnostics' => ['label' => 'Diagnostics', 'icon' => 'check'],
    ];

    /** @return array<string, array{label: string, icon: string}> */
    public static function tabs(): array
    {
        // the Kirby 4 edition: Development Mode, Purge everything, traffic
        if (Compat::lite() === true) {
            return array_intersect_key(self::TABS, ['overview' => true]);
        }

        $hidden = (array)Options::get('panel.hide', []);

        if (in_array(Options::get('dns', 'read'), ['read', 'write'], true) === false) {
            $hidden[] = 'dns';
        }

        // overview always stays, so the area never ends up empty
        $hidden = array_diff($hidden, ['overview']);

        return array_diff_key(self::TABS, array_flip($hidden));
    }
}
