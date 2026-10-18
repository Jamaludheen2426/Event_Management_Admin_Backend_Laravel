<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Steps;

use App\Modules\Legacy\Support\Import;

/** One module's share of legacy:import. Steps run in dependency order. */
interface Step
{
    public function name(): string;

    public function run(Import $import): void;
}
