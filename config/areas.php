<?php

/**
 * The Cloudflare area in the Panel menu, the "Purge" view button for pages,
 * and the licence dialog that the System view's licence status opens. The
 * area's data loads through the API routes (config/api.php).
 *
 * The Kirby 4 edition keeps the area and leaves out the dialogs and the
 * button: it has no licence, and Kirby 4 has no view buttons.
 */

use IanHobbs\CloudflareTools\Cloudflare;
use IanHobbs\CloudflareTools\Compat;
use IanHobbs\CloudflareTools\Licensing\LicenseManager;
use IanHobbs\CloudflareTools\Options;
use IanHobbs\CloudflareTools\Panel\Guard;
use IanHobbs\CloudflareTools\Panel\View;
use IanHobbs\CloudflareTools\Purger;
use Kirby\Cms\App;
use Kirby\Cms\Find;
use Kirby\Cms\Page;
use Kirby\Exception\Exception;
use Kirby\Toolkit\Escape;

return [
    Guard::AREA => function () {
        $label = Options::get('panel.label', 'Cloudflare');

        $area = [
            'label' => $label,
            'icon'  => Options::get('panel.icon', 'cloud'),
            'menu'  => Options::get('panel.menu', true),
            'link'  => Guard::AREA,
            'views' => [
                [
                    'pattern' => [Guard::AREA, Guard::AREA . '/(:any)'],
                    'action'  => function (string $tab = 'overview') use ($label) {
                        $tabs = View::tabs();
                        $tab  = isset($tabs[$tab]) ? $tab : array_key_first($tabs);

                        return [
                            'component' => 'k-cloudflare-tools-view',
                            'title'     => $label . ' · ' . $tabs[$tab]['label'],
                            'breadcrumb' => [
                                ['label' => $tabs[$tab]['label'], 'link' => '/' . Guard::AREA . '/' . $tab],
                            ],
                            'props' => [
                                'tab'   => $tab,
                                'label' => $label,
                                'tabs'  => array_map(
                                    fn (string $name, array $definition) => ['name' => $name] + $definition,
                                    array_keys($tabs),
                                    $tabs
                                ),
                            ],
                        ];
                    },
                ],
            ],
            'dialogs' => [
                // opened from the licence status in Panel › System, and from
                // the notice on the Overview tab; admins only
                'cloudflare-tools.license' => [
                    'pattern' => Guard::AREA . '/license',
                    'load'    => function () {
                        return Guard::run(null, function () {
                            Guard::requireAdmin();

                            $license = LicenseManager::fromOptions();
                            $info    = $license->info();
                            $theme   = match ($info['status']) {
                                'active'   => 'positive',
                                'inactive' => 'negative',
                                'demo'     => 'info',
                                default    => 'notice',
                            };

                            $details = array_filter([
                                'Website' => $info['website'],
                                'Key'     => $info['display'],
                                'Covers'  => $info['limit'] !== null ? $info['limit'] . ' ' . ($info['limit'] === 1 ? 'website' : 'websites') : null,
                                'Expires' => $info['expires'] ? substr($info['expires'], 0, 10) : null,
                            ]);

                            $text = '<p><strong>' . Escape::html($info['label']) . '.</strong> ' . Escape::html($info['message']) . '</p>';

                            if ($details !== []) {
                                $text .= '<p>' . implode('<br>', array_map(
                                    fn ($label, $value) => Escape::html($label) . ': <code>' . Escape::html((string)$value) . '</code>',
                                    array_keys($details),
                                    $details
                                )) . '</p>';
                            }

                            if ($info['shop'] !== null && in_array($info['status'], ['active', 'demo'], true) === false) {
                                $text .= '<p><a href="' . Escape::attr($info['shop']) . '" target="_blank" rel="noopener">Buy a licence</a></p>';
                            }

                            $fields = ['info' => ['type' => 'info', 'theme' => $theme, 'text' => $text]];

                            // development sites need nothing: show the status and a Close button
                            if ($info['status'] === 'demo') {
                                return [
                                    'component' => 'k-form-dialog',
                                    'props'     => ['fields' => $fields, 'submitButton' => false, 'cancelButton' => 'Close'],
                                ];
                            }

                            $fields['key'] = [
                                'type'        => 'text',
                                'label'       => $info['display'] ? 'Replace licence key' : 'Licence key',
                                'icon'        => 'key',
                                'counter'     => false,
                                'disabled'    => $info['source'] === 'config',
                                'placeholder' => $info['display'] ?? 'Paste the key from your purchase email',
                                'help'        => $info['source'] === 'config'
                                    ? 'Set in this site\'s configuration (CLOUDFLARE_TOOLS_LICENSE).'
                                    : 'From your purchase email, or the customer portal.',
                            ];

                            if ($info['source'] !== null) {
                                $fields['remove'] = [
                                    'type' => 'toggle',
                                    'label' => 'Remove from this site',
                                    'text' => ['Keep', 'Remove'],
                                    'help' => 'Frees the activation for another website.' . ($info['source'] === 'config' ? ' Remove CLOUDFLARE_TOOLS_LICENSE too, or the key is activated again.' : ''),
                                ];
                            }

                            return [
                                'component' => 'k-form-dialog',
                                'props'     => [
                                    'fields'       => $fields,
                                    'value'        => ['key' => '', 'remove' => false],
                                    'submitButton' => $info['source'] === null
                                        ? ['text' => 'Activate', 'icon' => 'key', 'theme' => 'love']
                                        : ['text' => 'Save', 'icon' => 'check', 'theme' => 'positive'],
                                ],
                            ];
                        });
                    },
                    'submit'  => function () {
                        return Guard::run(null, function () {
                            Guard::requireAdmin();

                            $request = App::instance()->request();
                            $license = LicenseManager::fromOptions();

                            if (filter_var($request->get('remove'), FILTER_VALIDATE_BOOL) === true) {
                                $license->deactivate();

                                return ['message' => 'Licence removed from this site'];
                            }

                            $key = Options::filled($request->get('key'));

                            if ($key !== null || $license->key() === null) {
                                $info = $license->activate($key);
                            } else {
                                $license->refresh(true);
                                $info = $license->info();
                            }

                            return match ($info['status']) {
                                'active'   => ['message' => 'Licence active for ' . $info['website']],
                                'unknown'  => ['message' => 'Licence key saved. ' . $info['message']],
                                default    => throw Compat::exception(Exception::class, $info['message']),
                            };
                        });
                    },
                ],

                // the page view button opens this; ids use "+" for "/"
                'cloudflare-tools.purge-page' => [
                    'pattern' => Guard::AREA . '/purge-page/(:any)',
                    'load'    => function (string $id) {
                        return Guard::run('purge', function () use ($id) {
                            $page = Find::page($id);
                            $urls = Purger::urlsForPage($page);

                            if ($urls === []) {
                                $text = '<p>This page has no full URL to purge. Set Kirby\'s <code>url</code> option to the site\'s address.</p>';
                            } else {
                                $list = implode('', array_map(fn ($url) => '<li><code>' . Escape::html($url) . '</code></li>', $urls));
                                $text = '<p>Clear these URLs from Cloudflare\'s cache, so visitors get the current version:</p><ul>' . $list . '</ul>';
                            }

                            return [
                                'component' => 'k-text-dialog',
                                'props'     => [
                                    'text'         => $text,
                                    'size'         => 'large',
                                    'submitButton' => [
                                        'text'  => 'Purge ' . count($urls) . ' ' . (count($urls) === 1 ? 'URL' : 'URLs'),
                                        'icon'  => 'cloud',
                                        'theme' => 'positive',
                                    ],
                                ],
                            ];
                        });
                    },
                    'submit'  => function (string $id) {
                        return Guard::run('purge', function () use ($id) {
                            $count = (new Purger(Cloudflare::fromOptions()))->purgePage(Find::page($id));

                            return ['message' => 'Cloudflare is clearing ' . $count . ' ' . ($count === 1 ? 'URL' : 'URLs')];
                        });
                    },
                ],
            ],
            // add `- cloudflare-purge` to a page blueprint's `buttons`, or to
            // the `panel.viewButtons.page` option for every page
            'buttons' => [
                'cloudflare-purge' => function (Page $page) {
                    if (Guard::can('purge') === false) {
                        return null;
                    }

                    return [
                        'icon'   => 'cloud',
                        'text'   => 'Purge',
                        'title'  => 'Clear this page from the Cloudflare cache',
                        'dialog' => Guard::AREA . '/purge-page/' . str_replace('/', '+', $page->id()),
                    ];
                },
            ],
        ];

        return Compat::lite() ? array_diff_key($area, ['dialogs' => true, 'buttons' => true]) : $area;
    },
];
