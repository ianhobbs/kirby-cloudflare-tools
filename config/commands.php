<?php

/**
 * Kirby CLI commands, also usable as Janitor buttons (bnomei/kirby-janitor):
 *
 *   vendor/bin/kirby cloudflare-tools:check        token, zone, and a purge of the probe file only
 *   vendor/bin/kirby cloudflare-tools:purge        Kirby's page cache, then the whole Cloudflare zone
 *   vendor/bin/kirby cloudflare-tools:api GET 'zones/{zone}/settings/http3'
 *                                                  any API call, for trying out a gate
 *   vendor/bin/kirby cloudflare-tools:license [status|activate KEY|check|remove]
 *                                                  this site's licence
 */

use Bnomei\Janitor;
use IanHobbs\CloudflareTools\Cloudflare;
use IanHobbs\CloudflareTools\Compat;
use IanHobbs\CloudflareTools\Diagnostics;
use IanHobbs\CloudflareTools\Licensing\LicenseManager;
use Kirby\CLI\CLI;
use Kirby\Toolkit\Escape;

// page, file, user, site, data, model — only when Janitor is installed
$janitorArgs = class_exists(Janitor::class) ? Janitor::ARGS : [];

/** Prints the report in the CLI and hands it to the Janitor button */
$report = static function (CLI $cli, Diagnostics $diagnostics, string $passed, string $failed): void {
    $summary = $diagnostics->ok ? $passed : $failed;

    foreach ($diagnostics->lines as $line) {
        $cli->out($line);
    }

    $diagnostics->ok ? $cli->success($summary) : $cli->error($summary);

    if (function_exists('janitor') === true && $cli->arg('command') !== null) {
        $data = [
            'status'  => $diagnostics->ok ? 200 : 500,
            'message' => $summary,
            // the button label resets after the cooldown, the help text stays until reload
            'help'    => implode('<br>', array_map(Escape::html(...), $diagnostics->lines)),
        ];

        if ($diagnostics->ok === false) {
            $data['notification'] = ['error', $summary];
        }

        janitor()->data($cli->arg('command'), $data);
    }
};

return [
    'cloudflare-tools:license' => [
        'description' => 'Show, activate, re-check or remove this site\'s Cloudflare Tools licence',
        'args'        => [
            'action' => [
                'description'  => 'status, activate, check or remove',
                'defaultValue' => 'status',
            ],
            'key' => [
                'description' => 'The licence key (activate only; defaults to CLOUDFLARE_TOOLS_LICENSE)',
            ],
        ],
        'command' => static function (CLI $cli): void {
            if (Compat::lite() === true) {
                $cli->success('Kirby 4 sites need no Cloudflare Tools licence');
                return;
            }

            $license = LicenseManager::fromOptions();
            $action  = (string)$cli->arg('action');
            $key     = is_string($cli->arg('key')) && $cli->arg('key') !== '' ? $cli->arg('key') : null;

            try {
                match ($action) {
                    'status'   => null,
                    'activate' => $license->activate($key),
                    'check'    => $license->refresh(true),
                    'remove'   => $license->deactivate(),
                    default    => throw new InvalidArgumentException('Unknown action "' . $action . '": use status, activate, check or remove'),
                };
            } catch (Throwable $e) {
                $cli->error($e->getMessage());
                return;
            }

            $info = $license->info();

            foreach (['Website' => $info['website'], 'Key' => $info['display'], 'Covers' => $info['limit'] !== null ? $info['limit'] . ' websites' : null] as $label => $value) {
                if ($value !== null) {
                    $cli->out($label . ': ' . $value);
                }
            }

            $line = $info['label'] . ' — ' . $info['message'];
            in_array($info['status'], ['active', 'demo'], true) ? $cli->success($line) : $cli->out($line);
        },
    ],
    'cloudflare-tools:check' => [
        'description' => 'Test the Cloudflare token and zone, purging only the probe file',
        'args'        => [] + $janitorArgs,
        'command'     => static function (CLI $cli) use ($report): void {
            $report($cli, Diagnostics::fromOptions()->check(), 'Cloudflare token and zone work', 'Cloudflare test failed');
        },
    ],
    'cloudflare-tools:purge' => [
        'description' => 'Flush the Kirby pages cache, then purge the whole Cloudflare zone',
        'args'        => [] + $janitorArgs,
        'command'     => static function (CLI $cli) use ($report): void {
            $report($cli, Diagnostics::fromOptions()->purgeAll(), 'Kirby + Cloudflare cache purged', 'Purge failed, see the report above');
        },
    ],
    'cloudflare-tools:api' => [
        'description' => 'Call any Cloudflare API path; {zone} and {account} are filled in',
        'args'        => [
            'method' => [
                'description'  => 'GET, POST, PUT, PATCH or DELETE',
                'defaultValue' => 'GET',
            ],
            'path' => [
                'description' => 'Relative to /client/v4, e.g. zones/{zone}/settings/http3',
                'required'    => true,
            ],
            'body' => [
                'prefix'      => 'b',
                'longPrefix'  => 'body',
                'description' => 'JSON request body, e.g. \'{"value":"on"}\'',
            ],
        ],
        'command' => static function (CLI $cli): void {
            $method = strtoupper((string)$cli->arg('method'));
            // CLImate gives an unset option a non-null default, so only a
            // non-empty string counts as a body
            $body   = is_string($cli->arg('body')) && $cli->arg('body') !== '' ? $cli->arg('body') : null;

            if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) === false) {
                $cli->error('Unknown method "' . $method . '"');
                return;
            }

            if ($body !== null) {
                $body = json_decode((string)$body, true);

                if (is_array($body) === false) {
                    $cli->error('--body is not valid JSON');
                    return;
                }
            }

            try {
                $response = Cloudflare::fromOptions()->request($method, (string)$cli->arg('path'), [], $body);
            } catch (Throwable $e) {
                $cli->error($e->getMessage());
                return;
            }

            $cli->out(json_encode($response->json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $response->ok() ? $cli->success('HTTP ' . $response->status) : $cli->error($response->error());
        },
    ],
];
