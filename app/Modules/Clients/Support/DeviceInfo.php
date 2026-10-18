<?php

declare(strict_types=1);

namespace App\Modules\Clients\Support;

use Illuminate\Http\Request;

/**
 * What a sign-in request says about its device, for the Sessions screen.
 *
 * The Flutter app's user-agent names no device, so it may send
 * `X-Device-Info: iPhone 14 · iOS 17.4`; that wins when present. Nothing is
 * guessed: an unknown device stays unknown. There is no IP-location service,
 * so location is always null (the screen shows the IP instead).
 */
final class DeviceInfo
{
    /** @return array<string, string|null> client_sessions columns */
    public static function of(Request $request): array
    {
        $agent = $request->userAgent();
        $browser = $agent !== null ? self::browser($agent) : null;
        $os = $agent !== null ? self::os($agent) : null;
        $declared = $request->header('X-Device-Info');
        $declared = is_string($declared) && trim($declared) !== '' ? mb_substr(trim($declared), 0, 120) : null;

        return [
            'transport' => $request->bearerToken() !== null || $request->header('X-Client') === 'app' ? 'app' : 'web',
            'ip_address' => $request->ip(),
            'user_agent' => $agent !== null ? mb_substr($agent, 0, 500) : null,
            'browser' => $browser,
            'os' => $os,
            'device_type' => $agent !== null ? self::type($agent, $os) : null,
            'device_name' => $declared ?? ($browser !== null && $os !== null ? "{$os} · {$browser}" : ($os ?? $browser)),
            'location' => null,
        ];
    }

    private static function browser(string $agent): ?string
    {
        foreach ([
            'Edge' => '/Edg(e|A|iOS)?\//', 'Opera' => '/OPR\/|Opera/', 'Samsung Internet' => '/SamsungBrowser/',
            'Firefox' => '/Firefox\/|FxiOS/', 'Chrome' => '/Chrome\/|CriOS/', 'Safari' => '/Safari\//',
        ] as $name => $pattern) {
            if (preg_match($pattern, $agent)) {
                return $name;
            }
        }

        return null;
    }

    private static function os(string $agent): ?string
    {
        foreach ([
            'iOS' => '/iPhone|iPad|iPod/', 'Android' => '/Android/', 'Windows' => '/Windows/',
            'macOS' => '/Mac OS X|Macintosh/', 'Chrome OS' => '/CrOS/', 'Linux' => '/Linux/',
        ] as $name => $pattern) {
            if (preg_match($pattern, $agent)) {
                return $name;
            }
        }

        return null;
    }

    private static function type(string $agent, ?string $os): ?string
    {
        return match (true) {
            (bool) preg_match('/iPad|Tablet/', $agent) => 'tablet',
            (bool) preg_match('/Mobile|iPhone|Android/', $agent) => 'mobile',
            $os !== null => 'desktop',
            default => null,
        };
    }
}
