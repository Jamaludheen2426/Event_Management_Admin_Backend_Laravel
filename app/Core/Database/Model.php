<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Base for every model in the app (authenticatable models use the
 * SerializesV1Dates trait directly, because they extend Laravel's User).
 */
abstract class Model extends Eloquent
{
    use SerializesV1Dates;
}
