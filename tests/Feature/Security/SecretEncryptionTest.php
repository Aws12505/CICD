<?php

use App\Models\Project;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('the webhook secret is never written to the database in plaintext', function () {
    $project = aProject('super-secret-value');

    $stored = DB::table('projects')->where('id', $project->id)->value('github_secret');

    expect($stored)->not->toBe('super-secret-value')
        ->and($stored)->not->toContain('super-secret-value');

    // And it still round-trips through the model.
    expect(Project::find($project->id)->github_secret)->toBe('super-secret-value');
});

test('the migration encrypts existing plaintext secrets and is safe to re-run', function () {
    // Written the way the old code did, straight into the column.
    $id = DB::table('projects')->insertGetId([
        'name' => 'Legacy',
        'repo_owner' => 'RnD-Experts-Team',
        'repo_name' => 'Legacy',
        'repo_full_name' => 'RnD-Experts-Team/Legacy',
        'github_secret' => 'legacy-plaintext-secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require database_path('migrations/2026_09_21_183000_encrypt_project_github_secret.php');

    $migration->up();

    $stored = DB::table('projects')->where('id', $id)->value('github_secret');
    expect($stored)->not->toBe('legacy-plaintext-secret');
    expect(Crypt::decryptString($stored))->toBe('legacy-plaintext-secret');

    // An interrupted migration gets re-run. It must not encrypt twice.
    $migration->up();
    expect(Crypt::decryptString(
        DB::table('projects')->where('id', $id)->value('github_secret')
    ))->toBe('legacy-plaintext-secret');
});

test('a viewer is not sent the webhook secret', function () {
    $project = aProject('super-secret-value');

    $this->actingAs(actorWithRole('viewer'))
        ->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.github_secret', null)
        );
});

test('an operator is sent the webhook secret so they can configure the hook', function () {
    $project = aProject('super-secret-value');

    $this->actingAs(actorWithRole('operator'))
        ->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.github_secret', 'super-secret-value')
        );
});
