<?php

namespace IanHobbs\CloudflareTools\Panel;

use Closure;
use IanHobbs\CloudflareTools\ApiException;
use IanHobbs\CloudflareTools\Compat;
use IanHobbs\CloudflareTools\Options;
use Kirby\Cms\App;
use Kirby\Cms\User;
use Kirby\Exception\Exception;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;
use Kirby\Panel\Panel;
use Throwable;

/**
 * Who may do what in the Cloudflare area, and how failures reach the Panel.
 *
 * The permissions are Kirby role permissions, set per role in its user
 * blueprint (site/blueprints/users/editor.yml):
 *
 *   permissions:
 *     access:
 *       cloudflare-tools: true          # see the area at all
 *     ianhobbs.cloudflare-tools:
 *       purge: true
 *       developmentMode: true
 *       settings: false
 *
 * Admins may always do everything.
 */
final class Guard
{
    public const AREA = 'cloudflare-tools';

    /** Defaults for roles that don't say; registered as the plugin's permissions */
    public const PERMISSIONS = [
        'analytics'       => true,
        'purge'           => true,
        // switching Cloudflare's cache off for three hours while editing
        'developmentMode' => true,
        'settings'        => false,
        'security'        => false,
        'dns'             => false,
    ];

    public static function can(string $action, User|null $user = null): bool
    {
        $user ??= App::instance()->user();

        if ($user === null || Panel::hasAccess($user, self::AREA) === false) {
            return false;
        }

        return $user->role()->permissions()->for(Options::PREFIX, $action);
    }

    /** @return array<string, bool> every action for the current user */
    public static function all(User|null $user = null): array
    {
        $result = [];

        foreach (array_keys(self::PERMISSIONS) as $action) {
            $result[$action] = self::can($action, $user);
        }

        return $result;
    }

    /** Licence keys are for admins only, whatever the plugin permissions say */
    public static function requireAdmin(): void
    {
        if (App::instance()->user()?->isAdmin() !== true) {
            throw Compat::exception(PermissionException::class, 'Only admins can manage the Cloudflare Tools licence');
        }
    }

    public static function require(string $action): void
    {
        if (self::can($action) === false) {
            throw Compat::exception(PermissionException::class, 'Your role may not use Cloudflare › ' . $action);
        }
    }

    /**
     * Runs a Panel request: checks area access and the permission, then
     * turns plugin and Cloudflare errors into Kirby exceptions, whose
     * messages the Panel shows (it hides other exceptions' messages outside
     * debug mode).
     */
    public static function run(string|null $action, Closure $callback): mixed
    {
        // reads need no plugin permission, but always the area itself
        $user = App::instance()->user();

        if ($user === null || Panel::hasAccess($user, self::AREA) === false) {
            throw Compat::exception(PermissionException::class, 'Your role may not use the Cloudflare area');
        }

        if ($action !== null) {
            self::require($action);
        }

        try {
            return $callback();
        } catch (Exception $e) {
            throw $e;
        } catch (ApiException $e) {
            // always 502: passing on Cloudflare's 401 would make the Panel
            // think the Kirby session had expired and log the user out
            throw Compat::exception(Exception::class, $e->getMessage(), 502, $e);
        } catch (\InvalidArgumentException $e) {
            throw Compat::exception(InvalidArgumentException::class, $e->getMessage(), previous: $e);
        } catch (Throwable $e) {
            throw Compat::exception(Exception::class, 'Cloudflare request failed: ' . $e->getMessage(), 500, $e);
        }
    }
}
