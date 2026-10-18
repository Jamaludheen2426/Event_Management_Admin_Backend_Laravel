<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Support;

use App\Core\Contracts\ObjectStorage;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What every import step works with: the old database (read only), the new
 * one (written in batches), the old → new id maps, and the report.
 *
 * Rows are plain arrays. Writes are upserts keyed by the new id, so running
 * the import twice gives the same result; merged tables get their new ids by
 * position (source order, then old id), which is the same on every run.
 */
final class Import
{
    private const WRITE_CHUNK = 500;

    /** @var array<string, array<int, int>> entity => old id => new id */
    private array $maps = [];

    /** @var array<string, array{read: int, written: int, skipped: int}> "old → new" => counts */
    private array $counts = [];

    /** @var array<string, list<string>> step => notes */
    private array $notes = [];

    private string $step = '';

    public function __construct(
        public readonly bool $dryRun,
        private readonly ObjectStorage $storage,
        private readonly Connection $legacy,
        public readonly bool $skipLocations = false,
    ) {}

    public function step(string $name): void
    {
        $this->step = $name;
    }

    // ---- reading ----------------------------------------------------------

    /** @var array<string, array<string, true>>|null table => columns, read once (each question costs a round trip) */
    private ?array $schema = null;

    public function has(string $table): bool
    {
        return isset($this->schema()[$table]);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return isset($this->schema()[$table][$column]);
    }

    /** @return array<string, array<string, true>> */
    private function schema(): array
    {
        if ($this->schema === null) {
            $this->schema = [];
            $rows = $this->legacy->select('SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
            foreach ($rows as $row) {
                $this->schema[(string) $row->t][(string) $row->c] = true;
            }
        }

        return $this->schema;
    }

    /**
     * Every row of an old table (small tables), oldest id first.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, bool $withDeleted = true): array
    {
        if (! $this->has($table)) {
            $this->note("{$table}: not in the old database, skipped");

            return [];
        }
        $query = $this->legacy->table($table);
        if (! $withDeleted && $this->hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return array_map(static fn (object $row): array => (array) $row, $query->orderBy('id')->get()->all());
    }

    /**
     * A big table in id-ordered chunks (locations: ~380,000 rows).
     *
     * @param  Closure(list<array<string, mixed>>): void  $each
     */
    public function chunks(string $table, int $size, Closure $each): void
    {
        $last = 0;
        do {
            $rows = $this->legacy->table($table)->where('id', '>', $last)->orderBy('id')->limit($size)->get()
                ->map(static fn (object $row): array => (array) $row)->all();
            if ($rows !== []) {
                $each($rows);
                $last = (int) end($rows)['id'];
            }
        } while (count($rows) === $size);
    }

    // ---- writing ----------------------------------------------------------

    /**
     * Upsert rows into a new table and count them under "source → target".
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     */
    public function put(string $source, string $table, array $rows, int $read, array $uniqueBy = ['id']): void
    {
        foreach (array_chunk($rows, self::WRITE_CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy);
        }
        $this->count($source, $table, $read, count($rows));
    }

    /**
     * Insert-or-ignore (pivot rows with no id).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function link(string $source, string $table, array $rows, int $read): void
    {
        foreach (array_chunk($rows, self::WRITE_CHUNK) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk);
        }
        $this->count($source, $table, $read, count($rows));
    }

    public function count(string $source, string $target, int $read, int $written): void
    {
        $key = "{$source} → {$target}";
        $this->counts[$key] ??= ['read' => 0, 'written' => 0, 'skipped' => 0];
        $this->counts[$key]['read'] += $read;
        $this->counts[$key]['written'] += $written;
        $this->counts[$key]['skipped'] = $this->counts[$key]['read'] - $this->counts[$key]['written'];
    }

    public function note(string $message): void
    {
        $this->notes[$this->step][] = $message;
    }

    // ---- id maps ----------------------------------------------------------

    public function map(string $entity, int $old, int $new): void
    {
        $this->maps[$entity][$old] = $new;
    }

    public function id(string $entity, mixed $old): ?int
    {
        return is_numeric($old) ? ($this->maps[$entity][(int) $old] ?? null) : null;
    }

    /** @return array<int, int> */
    public function mapOf(string $entity): array
    {
        return $this->maps[$entity] ?? [];
    }

    /** Write the maps of entities whose ids changed (identity maps are left out). */
    public function saveMaps(): void
    {
        $now = now();
        $rows = [];
        foreach ($this->maps as $entity => $ids) {
            foreach ($ids as $old => $new) {
                if ($old !== $new) {
                    $rows[] = ['entity' => $entity, 'old_id' => $old, 'new_id' => $new, 'created_at' => $now];
                }
            }
        }
        foreach (array_chunk($rows, self::WRITE_CHUNK) as $chunk) {
            DB::table('legacy_id_map')->upsert($chunk, ['entity', 'old_id'], ['new_id']);
        }
    }

    // ---- values -----------------------------------------------------------

    /** A DATETIME as stored, with MySQL's zero dates as null. */
    public static function at(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return $value;
    }

    /** JSON for a JSON column: decoded text re-encoded, arrays encoded, junk null. */
    public static function json(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (is_string($decoded) && is_array($again = json_decode($decoded, true))) {
            $decoded = $again; // double-encoded by v1
        }

        return $decoded === null ? null : (string) json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<array-key, mixed> a JSON column as an array ([] when empty or broken) */
    public static function list(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }

    public static function bit(mixed $value, int $fallback = 1): int
    {
        return $value === null || $value === '' ? $fallback : ((int) $value !== 0 ? 1 : 0);
    }

    /**
     * v1's tri-state is_active: 2 meant "waiting for approval".
     *
     * @return array{is_active: int, approval_status: string}
     */
    public static function approval(mixed $isActive): array
    {
        return (int) $isActive === 2
            ? ['is_active' => 0, 'approval_status' => 'pending']
            : ['is_active' => self::bit($isActive), 'approval_status' => 'approved'];
    }

    public static function encrypt(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Crypt::encryptString((string) $value);
    }

    public static function text(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr((string) $value, 0, $max);
    }

    /**
     * A pasted picture (data: URI) becomes a stored file; on a dry run it is
     * only counted. Anything else is returned as it is.
     */
    public function file(mixed $value, string $folder, string $what): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (! str_starts_with($value, 'data:')) {
            return mb_strlen($value) > 500 ? null : $value;
        }
        if ($this->dryRun) {
            $this->note("{$what}: pasted picture would be stored as a file");

            return null;
        }
        try {
            return $this->storage->storeDataUri($value, $folder, basename($folder));
        } catch (Throwable $e) {
            $this->note("{$what}: pasted picture could not be stored ({$e->getMessage()})");

            return null;
        }
    }

    // ---- report -----------------------------------------------------------

    /** @return array<string, array{read: int, written: int, skipped: int}> */
    public function counts(): array
    {
        return $this->counts;
    }

    /** @return array<string, list<string>> */
    public function notes(): array
    {
        return $this->notes;
    }
}
