<?php

/**
 * Purge on save, when the `autoPurge` option is on (it is off by default).
 *
 * Pages purge their own URLs (and their parents' and the home page's, see
 * Purger) when they change or go live; drafts aren't public, so creating one
 * purges nothing. Files purge their page. A change to the site's own fields purges
 * everything, as those usually show on every page — set `autoPurgeSite` to
 * false to skip that. Failures are logged and never block a save.
 *
 * Kirby flushes its own pages cache on every change already.
 */

use IanHobbs\CloudflareTools\Options;
use IanHobbs\CloudflareTools\Purger;
use Kirby\Cms\File;
use Kirby\Cms\Page;

$purge = static function (Page|File|null ...$models): void {
    if (Options::get('autoPurge', false) === true) {
        Purger::fromOptions()->purgeQuietly(...$models);
    }
};

return [
    'page.update:after'       => fn (Page $newPage) => $purge($newPage),
    'page.changeTitle:after'  => fn (Page $newPage) => $purge($newPage),
    'page.changeStatus:after' => fn (Page $newPage) => $purge($newPage),
    'page.changeNum:after'    => fn (Page $newPage) => $purge($newPage),
    // the old URL is the one Cloudflare has cached
    'page.changeSlug:after'   => fn (Page $newPage, Page $oldPage) => $purge($newPage, $oldPage),
    'page.delete:after'       => fn (bool $status, Page $page) => $purge($page),

    'file.create:after'       => fn (File $file) => $purge($file),
    'file.update:after'       => fn (File $newFile) => $purge($newFile),
    'file.replace:after'      => fn (File $newFile) => $purge($newFile),
    'file.changeName:after'   => fn (File $newFile) => $purge($newFile),
    'file.changeSort:after'   => fn (File $newFile) => $purge($newFile),
    'file.delete:after'       => fn (bool $status, File $file) => $purge($file),

    // not static: Kirby binds hooks to the app
    'site.update:after' => function () {
        if (Options::get('autoPurge', false) === true && Options::get('autoPurgeSite', true) === true) {
            Purger::fromOptions()->purgeEverythingQuietly();
        }
    },
];
