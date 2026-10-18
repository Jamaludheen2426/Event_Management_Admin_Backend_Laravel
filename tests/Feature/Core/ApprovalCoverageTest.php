<?php

declare(strict_types=1);

use App\Core\Approvals\ApprovalRegistry;
use Symfony\Component\Finder\Finder;

/*
| v1 kept a hand-written map of approval services and forgot 8 modules, so
| approving their requests failed with "No service found". Here every
| Approvals::guard() call in the code must name a registered handler.
*/

it('has an approval handler for every module and resource the controllers send for approval', function () {
    $registered = app(ApprovalRegistry::class)->registered();
    $used = [];

    foreach ((new Finder)->files()->in(app_path('Modules'))->name('*Controller.php') as $file) {
        // RecordController subclasses declare their approval module and type as properties.
        if (preg_match("/protected string \\\$module = '([a-z_]+)';/", $file->getContents(), $module)
            && preg_match("/protected string \\\$type = '([a-z_]+)';/", $file->getContents(), $type)) {
            $used[] = "{$module[1]}/{$type[1]}";
        }

        preg_match_all("/->guard\(\s*[^,]+,\s*'([a-z_]+)',\s*'[a-z_]+',\s*(?:'([a-z_]+)'|\\\$level)/", $file->getContents(), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            // LocationController passes the level ($level): country, state, district, city.
            $types = ($match[2] ?? '') !== '' ? [$match[2]] : ['country', 'state', 'district', 'city'];
            foreach ($types as $type) {
                $used[] = "{$match[1]}/{$type}";
            }
        }
    }

    expect($used)->not->toBeEmpty();
    foreach (array_unique($used) as $pair) {
        expect($registered)->toContain($pair);
    }
});
