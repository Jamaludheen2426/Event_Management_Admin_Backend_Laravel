<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console;

use App\Core\Contracts\ObjectStorage;
use App\Core\Tenancy\Tenancy;
use App\Modules\Identity\Database\Seeders\PermissionCatalogueSeeder;
use App\Modules\Legacy\Steps\BillingStep;
use App\Modules\Legacy\Steps\CatalogStep;
use App\Modules\Legacy\Steps\ClientsStep;
use App\Modules\Legacy\Steps\EventsStep;
use App\Modules\Legacy\Steps\IdentityStep;
use App\Modules\Legacy\Steps\MessagingStep;
use App\Modules\Legacy\Steps\PlatformStep;
use App\Modules\Legacy\Steps\SitesStep;
use App\Modules\Legacy\Steps\Step;
use App\Modules\Legacy\Steps\TemplatesStep;
use App\Modules\Legacy\Steps\VendorsStep;
use App\Modules\Legacy\Support\Import;
use App\Modules\Vendors\Database\Seeders\VendorPermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The one-time copy of the Node API's database (the `legacy` connection,
 * read only) into this one.
 *
 *   php artisan migrate --seed
 *   php artisan legacy:import --dry-run     # everything, then rolled back; prints the report
 *   php artisan legacy:import               # the real run
 *
 * The whole import is one transaction: it either lands completely or not at
 * all. Running it again updates the same rows (ids are kept, or given by
 * position the same way every time).
 */
final class ImportLegacy extends Command
{
    protected $signature = 'legacy:import
        {--dry-run : Run everything, report, and roll back}
        {--skip-locations : Leave out countries, states, districts and cities (~380,000 rows; quick re-checks)}
        {--report= : Also write the report to this file}';

    protected $description = 'Copy the old (Node) database into this one';

    /** @var list<class-string<Step>> dependency order */
    private const STEPS = [
        PlatformStep::class, CatalogStep::class, TemplatesStep::class, VendorsStep::class, IdentityStep::class,
        ClientsStep::class, BillingStep::class, EventsStep::class, MessagingStep::class, SitesStep::class,
    ];

    public function handle(ObjectStorage $storage): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if (! Schema::hasTable('legacy_id_map') || ! Schema::hasTable('sites')) {
            $this->error('Run the migrations first (php artisan migrate --seed).');

            return self::FAILURE;
        }
        try {
            $legacy = DB::connection('legacy');
            $legacy->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot reach the old database (LEGACY_DB_*): '.$e->getMessage());

            return self::FAILURE;
        }

        $import = new Import($dryRun, $storage, $legacy, skipLocations: (bool) $this->option('skip-locations'));
        $started = microtime(true);
        $this->info(($dryRun ? 'Dry run' : 'Import').' from '.$legacy->getDatabaseName().' at '.$legacy->getConfig('host'));

        DB::beginTransaction();
        try {
            Tenancy::bypass(function () use ($import): void {
                (new PermissionCatalogueSeeder)->run();
                (new VendorPermissionSeeder)->run();
                foreach (self::STEPS as $class) {
                    $step = $this->laravel->make($class);
                    $import->step($step->name());
                    $t = microtime(true);
                    $this->line(sprintf('  %-10s …', $step->name()));
                    $step->run($import);
                    $this->line(sprintf('  %-10s done in %.1fs', $step->name(), microtime(true) - $t));
                }
                $import->saveMaps();
            });
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Stopped, nothing was written: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }
        $dryRun ? DB::rollBack() : DB::commit();

        $report = $this->report($import, $dryRun, microtime(true) - $started);
        $this->line($report);
        $file = $this->option('report');
        if (is_string($file) && $file !== '') {
            file_put_contents($file, $report);
            $this->info("Report written to {$file}");
        }

        return self::SUCCESS;
    }

    private function report(Import $import, bool $dryRun, float $seconds): string
    {
        $lines = ['', ($dryRun ? 'DRY RUN — nothing was kept.' : 'IMPORT COMPLETE.').sprintf(' %.1fs', $seconds), ''];
        $lines[] = sprintf('%-70s %8s %8s %8s', 'old table → new table', 'read', 'written', 'skipped');
        foreach ($import->counts() as $pair => $c) {
            $lines[] = sprintf('%-70s %8d %8d %8s', mb_substr($pair, 0, 70), $c['read'], $c['written'], $c['skipped'] > 0 ? (string) $c['skipped'] : '');
        }
        foreach ($import->notes() as $step => $notes) {
            $lines[] = '';
            $lines[] = "Notes — {$step}:";
            foreach (array_count_values($notes) as $note => $times) {
                $lines[] = '  - '.$note.($times > 1 ? " (×{$times})" : '');
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }
}
