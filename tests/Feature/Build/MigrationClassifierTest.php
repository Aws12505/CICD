<?php

use App\Services\Build\MigrationClassifier;

function classify(string $body, string $name = '2026_01_01_000000_example.php'): array
{
    return (new MigrationClassifier)->classify([$name => "<?php\n".$body]);
}

test('a commit with no migrations needs no decision at all', function () {
    expect((new MigrationClassifier)->classify([])['kind'])
        ->toBe(MigrationClassifier::KIND_NONE);
});

test('adding a column is additive and deploys without a window', function () {
    $result = classify(<<<'PHP'
    Schema::table('goals', function (Blueprint $table) {
        $table->string('band')->nullable();
        $table->index('band');
    });
    PHP);

    expect($result['kind'])->toBe(MigrationClassifier::KIND_ADDITIVE);
    expect($result['tables'])->toBe(['goals']);
});

test('creating a table is additive', function () {
    expect(classify(<<<'PHP'
    Schema::create('shift_bands', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });
    PHP)['kind'])->toBe(MigrationClassifier::KIND_ADDITIVE);
});

/*
|--------------------------------------------------------------------------
| Anything that can lose data
|--------------------------------------------------------------------------
*/

test('dropping a column needs a window', function () {
    $result = classify(<<<'PHP'
    Schema::table('goals', function (Blueprint $table) {
        $table->dropColumn('band');
    });
    PHP);

    expect($result['kind'])->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
    expect($result['tables'])->toBe(['goals']);
});

test('renaming a column needs a window', function () {
    expect(classify("Schema::table('goals', fn (\$t) => \$t->renameColumn('a', 'b'));")['kind'])
        ->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
});

test('changing a column type needs a window', function () {
    // Narrowing a column silently truncates every value that no longer fits,
    // which is data loss with no error.
    expect(classify("Schema::table('goals', fn (\$t) => \$t->string('name', 20)->change());")['kind'])
        ->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
});

test('dropping a table needs a window', function () {
    expect(classify("Schema::dropIfExists('goals');")['kind'])
        ->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
});

test('dropping an index needs a window', function () {
    // Cheap on a small table, an exclusive lock for minutes on a large one.
    expect(classify("Schema::table('daily_store_summaries', fn (\$t) => \$t->dropIndex('idx'));")['kind'])
        ->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
});

/*
|--------------------------------------------------------------------------
| Raw SQL — fail safe
|--------------------------------------------------------------------------
*/

test('raw SQL is destructive even when it plainly is not', function () {
    $result = classify("DB::statement('CREATE INDEX CONCURRENTLY idx ON goals (band)');");

    // We cannot read SQL, so we do not pretend to. An unnecessary window
    // costs minutes; a missed one costs data.
    expect($result['kind'])->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
    expect($result['reasons'][0])->toContain('raw SQL cannot be read');
});

/*
|--------------------------------------------------------------------------
| Not fooled by prose
|--------------------------------------------------------------------------
*/

test('a comment mentioning a destructive operation does not force a window', function () {
    $result = classify(<<<'PHP'
    // We deliberately avoid dropColumn here; the column stays for now.
    /* A later migration will handle dropTable('goals'). */
    Schema::table('goals', function (Blueprint $table) {
        $table->string('band')->nullable();
    });
    PHP);

    expect($result['kind'])->toBe(MigrationClassifier::KIND_ADDITIVE);
});

/*
|--------------------------------------------------------------------------
| What the window has to dump
|--------------------------------------------------------------------------
*/

test('every touched table is reported, across several migrations', function () {
    $result = (new MigrationClassifier)->classify([
        'a.php' => "<?php Schema::table('goals', fn (\$t) => \$t->dropColumn('x'));",
        'b.php' => "<?php Schema::create('shift_bands', fn (\$t) => \$t->id());",
        'c.php' => "<?php Schema::table('goals', fn (\$t) => \$t->string('y'));",
    ]);

    // This list is what the pre-migration dump covers. Getting it wrong means
    // either dumping the whole database or not dumping what mattered.
    expect($result['tables'])->toBe(['goals', 'shift_bands']);
    expect($result['kind'])->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
});

test('one destructive migration makes the whole commit destructive', function () {
    $result = (new MigrationClassifier)->classify([
        'a.php' => "<?php Schema::create('a', fn (\$t) => \$t->id());",
        'b.php' => "<?php Schema::table('b', fn (\$t) => \$t->dropColumn('x'));",
        'c.php' => "<?php Schema::create('c', fn (\$t) => \$t->id());",
    ]);

    expect($result['kind'])->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
});

test('a connection-qualified schema call still reports its table', function () {
    $result = classify("Schema::connection('archive')->table('old_rows', fn (\$t) => \$t->dropColumn('x'));");

    expect($result['tables'])->toContain('old_rows');
    expect($result['kind'])->toBe(MigrationClassifier::KIND_DESTRUCTIVE);
});

test('the reason names the file and the operation', function () {
    $result = classify('Schema::table("goals", fn ($t) => $t->dropColumn("x"));', '2026_10_01_120000_drop_band.php');

    // Whoever schedules the window has to be able to say what it is for.
    expect($result['reasons'][0])->toBe('2026_10_01_120000_drop_band.php: dropColumn');
});
