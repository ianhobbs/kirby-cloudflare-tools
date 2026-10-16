<?php

use IanHobbs\CloudflareTools\Compat;
use Kirby\Cms\App;
use Kirby\Exception\Exception;
use Kirby\Exception\PermissionException;

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

it('knows which Kirby versions it runs on, and which edition', function (string $version, bool $supported, bool $lite) {
    pretendKirby($version);

    expect(Compat::supported())->toBe($supported)
        ->and(Compat::lite())->toBe($lite);
})->with([
    'Kirby 3'        => ['3.10.1', false, false],
    'Kirby 4.2'      => ['4.2.0', false, true],
    'Kirby 4.3'      => ['4.3.0', true, true],
    'Kirby 4.9'      => ['4.9.6', true, true],
    'Kirby 5'        => ['5.6.1', true, false],
    'Kirby 6 beta'   => ['6.0.0-beta.1', false, false],
    'Kirby 6'        => ['6.0.0', false, false],
]);

it('builds exceptions the Panel shows as they are', function () {
    $previous  = new RuntimeException('from Cloudflare');
    $exception = Compat::exception(Exception::class, 'Cloudflare said no', 502, $previous);

    // Kirby 4 keeps the class's default code, see Compat::exception()
    expect($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('Cloudflare said no')
        ->and($exception->getHttpCode())->toBe(lite() ? 500 : 502)
        ->and($exception->isTranslated())->toBeFalse();
});

it('passes messages on unchanged, braces included', function () {
    $message = 'Unknown path zones/{zone}/settings/{id}';

    expect(Compat::exception(Exception::class, $message)->getMessage())->toBe($message);
});

it('keeps each exception type\'s own HTTP code by default', function () {
    $exception = Compat::exception(PermissionException::class, 'Not you');

    expect($exception)->toBeInstanceOf(PermissionException::class)
        ->and($exception->getMessage())->toBe('Not you')
        ->and($exception->getHttpCode())->toBe(403);
});
