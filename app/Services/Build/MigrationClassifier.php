<?php

namespace App\Services\Build;

/**
 * Decides, at build time, whether a commit's migrations can be applied while
 * the service keeps serving.
 *
 * Deliberately done when the image is built rather than when it is deployed,
 * so nobody discovers that tonight's release needs a maintenance window at
 * six o'clock on a Friday.
 *
 * It errs towards caution in both directions: anything it cannot read
 * confidently is destructive, because the cost of an unnecessary window is a
 * few minutes and the cost of a missed one is data.
 */
class MigrationClassifier
{
    public const KIND_NONE = 'none';

    public const KIND_ADDITIVE = 'additive';

    public const KIND_DESTRUCTIVE = 'destructive';

    /**
     * Operations that can lose data or rewrite a column in place. `->change()`
     * is here because narrowing a column silently truncates every value that
     * no longer fits.
     */
    private const DESTRUCTIVE = [
        'dropColumn',
        'dropColumns',
        'renameColumn',
        'dropTable',
        'dropIfExists',
        'dropAllTables',
        'dropForeign',
        'dropPrimary',
        'dropUnique',
        'dropIndex',
        'dropSoftDeletes',
        'dropMorphs',
        'dropRememberToken',
        'rename',
        'truncate',
        '->change(',
    ];

    /**
     * Raw SQL. We cannot parse it, so we do not pretend to: a migration
     * containing any of these is destructive whether or not it actually is.
     */
    private const RAW = [
        'DB::statement',
        'DB::unprepared',
        'DB::raw',
    ];

    /**
     * @param  array<string, string>  $migrations  path => file contents
     * @return array{kind: string, tables: list<string>, reasons: list<string>}
     */
    public function classify(array $migrations): array
    {
        if ($migrations === []) {
            return ['kind' => self::KIND_NONE, 'tables' => [], 'reasons' => []];
        }

        $reasons = [];
        $tables = [];

        foreach ($migrations as $path => $contents) {
            $body = $this->withoutComments($contents);
            $name = basename($path);

            foreach (self::DESTRUCTIVE as $needle) {
                if (str_contains($body, $needle)) {
                    $reasons[] = "{$name}: {$needle}";
                }
            }

            foreach (self::RAW as $needle) {
                if (str_contains($body, $needle)) {
                    $reasons[] = "{$name}: {$needle} — raw SQL cannot be read, treated as destructive";
                }
            }

            $tables = array_merge($tables, $this->tablesIn($body));
        }

        return [
            'kind' => $reasons === [] ? self::KIND_ADDITIVE : self::KIND_DESTRUCTIVE,
            'tables' => array_values(array_unique($tables)),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * Which tables the migration touches.
     *
     * This is what makes a maintenance window bearable: the pre-migration
     * dump covers these tables only, so a window is megabytes and a couple of
     * minutes rather than a full dump of the reporting database.
     *
     * @return list<string>
     */
    public function tablesIn(string $contents): array
    {
        $patterns = [
            "/Schema::(?:table|create|drop|dropIfExists|rename)\s*\(\s*['\"]([A-Za-z0-9_]+)['\"]/",
            "/Schema::connection\s*\([^)]*\)\s*->\s*(?:table|create|drop|dropIfExists)\s*\(\s*['\"]([A-Za-z0-9_]+)['\"]/",
        ];

        $found = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $contents, $matches)) {
                $found = array_merge($found, $matches[1]);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Comments are stripped before matching, so a migration that merely
     * mentions dropColumn in a note is not forced into a window.
     */
    private function withoutComments(string $contents): string
    {
        $stripped = preg_replace('!/\*.*?\*/!s', '', $contents) ?? $contents;

        return preg_replace('!^\s*(//|#).*$!m', '', $stripped) ?? $stripped;
    }
}
