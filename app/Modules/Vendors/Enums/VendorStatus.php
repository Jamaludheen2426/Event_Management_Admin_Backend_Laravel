<?php

declare(strict_types=1);

namespace App\Modules\Vendors\Enums;

enum VendorStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
