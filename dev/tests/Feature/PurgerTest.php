<?php

use IanHobbs\CloudflareTools\Purger;

it('purges a page with its parents and the home page', function () {
    expect(Purger::urlsForPage(page('blog/hello')))->toBe([
        'https://example.com/blog/hello',
        'https://example.com/blog',
        'https://example.com',
    ]);
});

it('purges only the page itself without purgeParents', function () {
    bootKirby(['purgeParents' => false]);

    expect(Purger::urlsForPage(page('blog/hello')))->toBe(['https://example.com/blog/hello']);
});

it('lets the site say which URLs a page affects', function () {
    bootKirby(['pageUrls' => fn ($page) => [$page->url(), $page->url() . '.rss', '/relative-is-dropped']]);

    expect(Purger::urlsForPage(page('blog')))->toBe(['https://example.com/blog', 'https://example.com/blog.rss']);
});

it('purges on save only when autoPurge is on', function () {
    $fake = fake()->on('POST', 'zones/{zone}/purge_cache', ['result' => []]);

    kirby()->impersonate('kirby');
    kirby()->trigger('page.update:after', ['newPage' => page('blog'), 'oldPage' => page('blog')]);

    expect($fake->requests)->toBe([]);

    $fake = fake(['autoPurge' => true])->on('POST', 'zones/{zone}/purge_cache', ['result' => []]);

    kirby()->trigger('page.update:after', ['newPage' => page('blog'), 'oldPage' => page('blog')]);

    expect($fake->last()['body'])->toBe(['files' => ['https://example.com/blog', 'https://example.com']]);
});

it('purges the old URL too when a slug changes', function () {
    $fake = fake(['autoPurge' => true, 'purgeParents' => false])->on('POST', 'zones/{zone}/purge_cache', ['result' => []]);

    $old = page('blog/hello');
    $new = $old->clone(['slug' => 'hello-world']);

    kirby()->trigger('page.changeSlug:after', ['newPage' => $new, 'oldPage' => $old]);

    expect($fake->last()['body']['files'])->toBe(['https://example.com/blog/hello-world', 'https://example.com/blog/hello']);
});

it('never lets a failed purge break a save', function () {
    fake(['autoPurge' => true])->on('POST', 'zones/{zone}/purge_cache', ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403);

    $log = ini_set('error_log', '/dev/null');
    kirby()->trigger('page.update:after', ['newPage' => page('blog'), 'oldPage' => page('blog')]);
    ini_set('error_log', $log);

    expect(true)->toBeTrue();
});

it('purges everything when the site\'s own fields change', function () {
    $fake = fake(['autoPurge' => true])->on('POST', 'zones/{zone}/purge_cache', ['result' => []]);

    kirby()->trigger('site.update:after', ['newSite' => site(), 'oldSite' => site()]);

    expect($fake->last()['body'])->toBe(['purge_everything' => true]);
});
