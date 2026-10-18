<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Small no-login endpoints the admin frontend calls before sign-in.
 *
 *  GET /setup/status   the admin middleware redirects to /install unless
 *                      installed = true. A Laravel install is set up by
 *                      migrations + seeders, so the v1 web installer (check,
 *                      test-db, configure, finalize) is not ported.
 *  GET /timezones      the fixed list the timezone pickers show (v1 list).
 */
final class SystemController
{
    private const TIMEZONES = [
        ['Pacific/Midway', 'Midway Island (GMT-11:00)', -11], ['Pacific/Honolulu', 'Hawaii (GMT-10:00)', -10],
        ['America/Anchorage', 'Alaska (GMT-09:00)', -9], ['America/Los_Angeles', 'Pacific Time - US & Canada (GMT-08:00)', -8],
        ['America/Denver', 'Mountain Time - US & Canada (GMT-07:00)', -7], ['America/Chicago', 'Central Time - US & Canada (GMT-06:00)', -6],
        ['America/New_York', 'Eastern Time - US & Canada (GMT-05:00)', -5], ['America/Caracas', 'Caracas (GMT-04:30)', -4.5],
        ['America/Halifax', 'Atlantic Time - Canada (GMT-04:00)', -4], ['America/Sao_Paulo', 'Brasilia (GMT-03:00)', -3],
        ['America/Argentina/Buenos_Aires', 'Buenos Aires (GMT-03:00)', -3], ['Atlantic/South_Georgia', 'Mid-Atlantic (GMT-02:00)', -2],
        ['Atlantic/Azores', 'Azores (GMT-01:00)', -1], ['UTC', 'UTC (GMT+00:00)', 0],
        ['Europe/London', 'London, Edinburgh (GMT+00:00)', 0], ['Europe/Paris', 'Paris, Brussels, Madrid (GMT+01:00)', 1],
        ['Europe/Berlin', 'Berlin, Amsterdam, Rome (GMT+01:00)', 1], ['Africa/Lagos', 'West Central Africa (GMT+01:00)', 1],
        ['Europe/Athens', 'Athens, Istanbul (GMT+02:00)', 2], ['Africa/Cairo', 'Cairo (GMT+02:00)', 2],
        ['Africa/Johannesburg', 'Johannesburg (GMT+02:00)', 2], ['Europe/Moscow', 'Moscow, St. Petersburg (GMT+03:00)', 3],
        ['Asia/Kuwait', 'Kuwait, Riyadh (GMT+03:00)', 3], ['Africa/Nairobi', 'Nairobi (GMT+03:00)', 3],
        ['Asia/Tehran', 'Tehran (GMT+03:30)', 3.5], ['Asia/Dubai', 'Dubai, Abu Dhabi (GMT+04:00)', 4],
        ['Asia/Baku', 'Baku (GMT+04:00)', 4], ['Asia/Kabul', 'Kabul (GMT+04:30)', 4.5],
        ['Asia/Karachi', 'Karachi, Islamabad (GMT+05:00)', 5], ['Asia/Tashkent', 'Tashkent (GMT+05:00)', 5],
        ['Asia/Kolkata', 'Mumbai, Kolkata, New Delhi (GMT+05:30)', 5.5], ['Asia/Colombo', 'Sri Lanka (GMT+05:30)', 5.5],
        ['Asia/Kathmandu', 'Kathmandu (GMT+05:45)', 5.75], ['Asia/Dhaka', 'Dhaka (GMT+06:00)', 6],
        ['Asia/Almaty', 'Almaty (GMT+06:00)', 6], ['Asia/Yangon', 'Yangon (GMT+06:30)', 6.5],
        ['Asia/Bangkok', 'Bangkok, Jakarta (GMT+07:00)', 7], ['Asia/Ho_Chi_Minh', 'Ho Chi Minh (GMT+07:00)', 7],
        ['Asia/Shanghai', 'Beijing, Shanghai (GMT+08:00)', 8], ['Asia/Hong_Kong', 'Hong Kong (GMT+08:00)', 8],
        ['Asia/Singapore', 'Singapore (GMT+08:00)', 8], ['Asia/Taipei', 'Taipei (GMT+08:00)', 8],
        ['Australia/Perth', 'Perth (GMT+08:00)', 8], ['Asia/Tokyo', 'Tokyo, Osaka (GMT+09:00)', 9],
        ['Asia/Seoul', 'Seoul (GMT+09:00)', 9], ['Australia/Adelaide', 'Adelaide (GMT+09:30)', 9.5],
        ['Australia/Sydney', 'Sydney, Melbourne (GMT+10:00)', 10], ['Pacific/Guam', 'Guam (GMT+10:00)', 10],
        ['Asia/Vladivostok', 'Vladivostok (GMT+10:00)', 10], ['Pacific/Auckland', 'Auckland, Wellington (GMT+12:00)', 12],
        ['Pacific/Fiji', 'Fiji (GMT+12:00)', 12], ['Pacific/Tongatapu', "Nuku'alofa (GMT+13:00)", 13],
    ];

    public function setupStatus(): JsonResponse
    {
        return ApiResponse::success(['installed' => true, 'disabled' => true]);
    }

    /** v1 answered this one as { success, data } with no message or timestamp. */
    public function timezones(): JsonResponse
    {
        $timezones = array_map(
            static fn (array $t): array => ['value' => $t[0], 'label' => $t[1], 'offset' => $t[2]],
            self::TIMEZONES,
        );

        return new JsonResponse(['success' => true, 'data' => ['timezones' => $timezones]]);
    }
}
