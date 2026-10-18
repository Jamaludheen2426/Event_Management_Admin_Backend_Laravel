<?php

declare(strict_types=1);

namespace App\Modules\Clients\Enums;

/** Where a client account came from. */
enum ClientSource: string
{
    case Website = 'website';
    case Google = 'google';
    case Facebook = 'facebook';
    case Admin = 'admin';
    case Vendor = 'vendor';
}
