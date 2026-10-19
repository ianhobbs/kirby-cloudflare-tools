<?php

use IanHobbs\CloudflareTools\Compat;
use Kirby\Cms\App;

/** Pretends another Kirby is running; null goes back to the real one */
function pretendKirby(string|null $version): void
{
    (new ReflectionProperty(Compat::class, 'version'))->setValue(null, $version);
}

afterEach(fn () => pretendKirby(null));

it('reads the running Kirby\'s version', function () {
    expect(Compat::version())->toBe(App::version())
        ->and(Compat::supported())->toBeTrue();
});

it('runs on Kirby 6 only', function (string $version, bool $supported) {
    pretendKirby($version);

    expect(Compat::supported())->toBe($supported);
})->with([
    'Kirby 4'      => ['4.9.7', false],
    'Kirby 5'      => ['5.6.1', false],
    'Kirby 6 beta' => ['6.0.0-beta.1', true],
    'Kirby 6'      => ['6.0.0', true],
    'Kirby 7'      => ['7.0.0', false],
]);

it('says why it switched itself off, and which plugin Kirby 5 needs', function () {
    pretendKirby('5.6.1');

    expect(Compat::message())
        ->toContain('runs on Kirby 6 only')
        ->toContain('Kirby 5.6.1')
        ->toContain('Cloudflare Tools 1.x');

    pretendKirby('4.9.7');

    expect(Compat::message())
        ->toContain('Kirby 4.9.7')
        ->not->toContain('1.x');
});
