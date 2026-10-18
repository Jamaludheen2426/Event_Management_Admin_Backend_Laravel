# Event Management API (Laravel)

Laravel 12 rewrite of `Event_Management_Admin_Backend` (Node/Express).
Serves the admin panel, vendor portal, staff portal, website builder, public site,
client portal and the Flutter app — with the **same `/api/v1` URLs, JSON shapes and
cookie names**, so the frontends only change `API_URL`.

The full plan, the reasons behind it and the findings in the old backend:
`D:\Jamal\Event_Management_Admin_Backend\docs\LARAVEL_MIGRATION_PLAN.md`.

## Status

| Phase | Scope | State |
|---|---|---|
| 1 | Skeleton + Core kernel (envelope, errors, middleware, tenancy, auth plumbing, QR codec, list queries, module system, tests, CI) | **done** |
| 2 | Platform + Identity (admin auth, users, roles, approvals, settings, locations, languages, currencies, media, activity, UI translations) | **done** — 132 admin routes |
| 3 | Catalog + Templates | **done** — 117 more admin routes (249 total) |
| 4 | Vendors + Clients (vendor users, merged clients, every login path) | **done** — 133 more routes (382 total) |
| 5 | Billing (subscriptions, invoices, payment methods, admin payments) | **done** — 18 more routes (400 total) |
| 6 | Events (events, QR, guests, participants, RSVPs, agenda, family, gallery, wishes, splash) | **done** — 119 more routes (519 total) |
| 7 | Messaging + `chat-socket/` (e-mail and push providers, notification feed, messages and notifications to guests, in-app mail, chat, e-mail templates, vendor newsletter) | **done** — 106 more routes (625 total) |
| 8 | Sites (one website builder for the marketing site and vendor sites, public site resolve/bundle, content translation, admin FAQs, colour palettes) | **done** — 279 more routes (904 total) |
| 9 | `legacy:import` (one-time copy of the Node database, dry run + report) | **ready** — dry run against production clean; the real run happens at cutover |
| 10 | Cutover | **prepared** — Dockerfile, `render.yaml`, step-by-step `docs/DEPLOY.md`; not deployed yet |

## Run it locally

PHP 8.3 and Composer are installed portably in `D:\Jamal\_tools\php83` (nothing on
the system PATH). Put it first on PATH in your terminal:

```powershell
$env:Path = "D:\Jamal\_tools\php83;$env:Path"
```

```bash
composer install
cp .env.example .env            # then fill DB_*, LEGACY_DB_*, EVENT_QR_KEY
php artisan key:generate
php artisan migrate --seed      # house company + vendor (ids 1/1), permission catalogue, built-in roles;
                                # SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD in .env add a local developer login
php artisan serve --port=8000
```

### Copy the old database (`legacy:import`)

Point `LEGACY_DB_*` at the Node API's database (production needs `LEGACY_DB_SSL=true`).
The import only reads it.

```bash
php artisan migrate --seed
php artisan legacy:import --dry-run --report=import-report.txt   # does everything, then rolls back
php artisan legacy:import                                       # the real run (one transaction)
```

The report lists, per old table, rows read / written / skipped and every decision taken
(duplicate logins retired, unknown tier labels, pasted pictures stored as files...).
Running it again updates the same rows. `--skip-locations` leaves out the ~380,000
location rows for quick re-checks. Old → new ids that changed are kept in `legacy_id_map`.

Two MySQL databases are used locally: `event_laravel` (the app) and
`event_laravel_test` (tests). The old Node database `event_` is only read, through the
`legacy` connection.

## Checks (same as CI)

```bash
vendor/bin/pint --test                              # code style
vendor/bin/phpstan analyse --memory-limit=1G        # Larastan level 6
vendor/bin/pest                                     # feature, unit and architecture tests
```

## How the code is organised

```
app/Core/        shared kernel, no business rules
  Http/            ApiResponse (v1 envelope), V1Resource, middleware
  Tenancy/         CurrentCompany, CurrentVendor, BelongsToCompany, BelongsToVendor, Tenancy::bypass()
  Exceptions/      ApiException + the single exception map
  Queries/         ListQuery (v1 ?search ?is_active ?sort_by ?sort_order ?page ?limit)
  Support/         ModuleServiceProvider, EventQr (printed-invitation QR codec)
app/Modules/<Name>/  one folder per business module
  Models/ Actions/ Data/ Queries/ Policies/ Enums/ Events/ Listeners/ Jobs/
  Http/Controllers/{Admin,Vendor,Client,Public}/  Http/Requests/  Http/Resources/V1/
  Database/{Migrations,Factories,Seeders}/
  routes/{admin,vendor,staff,client}.php  (signed in)  routes/<portal>.guest.php, public.php (no login)
  <Name>ServiceProvider.php  (listed in bootstrap/providers.php)
```

Rules (enforced by `tests/Arch`):

- Controllers are thin: validate (FormRequest) → one Action → one Resource.
- Platform rows carry `company_id`, vendor rows carry `vendor_id`; never NULL, never both.
  **Vendor #1 is the house vendor (ours)** — `config/tenancy.php`, `Vendor::house()`.
- Tenant filtering is automatic (global scopes); reading tenant data before the tenant is
  known throws. Cross-tenant work must say so: `Tenancy::bypass(fn () => ...)`.
- `App\Core` never imports a module; modules depend one way
  (Platform ← Identity ← Catalog ← Templates ← Vendors ← Clients ← Billing/Events ← Messaging/Sites).
- Every file declares `strict_types`.

## Request pipeline

```
CORS (public site: any origin, no credentials | portals: FRONTEND_URL only, with credentials)
→ SnakeCaseInput (camelCase body keys → snake_case, the Node rule)
→ portal group: CookieToBearer → auth:<guard> (Sanctum) → ResolveTenant
→ route model binding (already tenant-scoped) → FormRequest → Controller → Action → Resource
→ ApiResponse {success, message, data, pagination?, timestamp}
```

## Permissions and approvals

- Admin permissions are one global catalogue (`AdminPermissionCatalogue`, guard `admin`),
  seeded by `PermissionCatalogueSeeder`. Roles belong to a company (spatie team = company id).
  Built-in roles per company: developer (1000), super_admin (100), admin (50, default).
- Routes check `perm:<slug>` (any of several slugs), `level:<n>` or `developer`;
  developer and super admin pass every permission check.
- An approvable change calls `Approvals::guard(...)` after validation. Each approvable
  resource has one handler (a "Writer" in its module's `Actions/`), registered in the module
  provider. `tests/Feature/Core/ApprovalCoverageTest.php` fails if a controller sends a
  resource for approval that has no handler.

## Adding a v1 list screen

Most admin screens are one table that people list, create, edit, switch on/off and delete.
Three Core pieces carry that, so a module only writes what differs:

- `Core\Crud\RecordWriter` — create / update / delete / status / default / reorder plus the
  approval handler. A subclass names the writable columns, normalises them (`prepare()`), states
  uniqueness rules with v1 messages, and syncs pivots in `afterSave()`.
- `Core\Http\Controllers\RecordController` — index / show / store / update / status / destroy /
  reorder with the approval workflow; a subclass sets `$key`, `$module`, `$type`, `$messages`.
- `Core\Http\Resources\RowResource` — the whole row as v1 sent it (dates, tri-state `is_active`,
  `has_pending_approval`), plus `extra()` for includes.

Route parameters reach controller methods **by position**, with route defaults last: read a
route default with `$request->route('name')`, never as a method argument.

## Compatibility notes

- **Passwords:** old bcryptjs `$2a$` hashes verify (`config/hashing.php` `verify=false`);
  they are rehashed to `$2y$` on next login.
- **QR codes:** `EVENT_QR_KEY` must be derived from the secret the Node API used in the
  same environment. On production that is `EVENT_QR_SECRET` if set on Render, otherwise
  `ACCESS_TOKEN_SECRET`. `tests/Feature/Legacy` proves every stored code still decrypts.
