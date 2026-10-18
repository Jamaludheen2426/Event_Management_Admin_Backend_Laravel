<?php

declare(strict_types=1);

/*
| Tenancy has two layers (see docs/LARAVEL_MIGRATION_PLAN.md, A3):
|   platform rows carry company_id, vendor rows carry vendor_id — never NULL.
|
| The HOUSE vendor is "us": the platform owner's own vendor account. Admin
| users act on it (website builder, clients). It is configuration, not a magic
| number scattered through the code — read it through Vendor::house().
*/

return [

    'house_vendor_id' => (int) env('HOUSE_VENDOR_ID', 1),

    'house_company_id' => (int) env('HOUSE_COMPANY_ID', 1),

];
