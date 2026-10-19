<?php

use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientEnvController;
use App\Http\Controllers\ControlPlaneSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// Route::redirect rather than a closure: closures cannot be route-cached, and
// the entrypoint now runs `route:cache` on boot.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    /*
    |------------------------------------------------------------------
    | Read-only — any signed-in user (viewer and above)
    |------------------------------------------------------------------
    */
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');

    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
    Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');

    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');

    /*
    |------------------------------------------------------------------
    | Changing what runs — operator and above
    |------------------------------------------------------------------
    | Declared before `projects/{project}` on purpose: registered the other
    | way round, `/projects/create` matches the wildcard and binds "create"
    | as a project id.
    */
    Route::middleware('role:admin|operator')->group(function () {
        Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    });

    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

    Route::middleware('role:admin|operator')->group(function () {
        Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
        // One route for both verbs, as Route::resource declared it. Two
        // separate registrations make Wayfinder emit a duplicate key for the
        // same controller action.
        Route::match(['put', 'patch'], 'projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

        Route::get('projects/{project}/pipelines/create', [PipelineController::class, 'create'])->name('pipelines.create');
        Route::post('projects/{project}/pipelines', [PipelineController::class, 'store'])->name('pipelines.store');
        Route::get('pipelines/{pipeline}/edit', [PipelineController::class, 'edit'])->name('pipelines.edit');
        Route::put('pipelines/{pipeline}', [PipelineController::class, 'update'])->name('pipelines.update');

        // A rerun executes arbitrary shell as root on the host, so it is not
        // a read action however harmless the button looks.
        Route::post('runs/{run}/rerun', [RunController::class, 'rerun'])->name('runs.rerun');
    });

    /*
    |------------------------------------------------------------------
    | Clients and services — the topology
    |------------------------------------------------------------------
    | Readable by anyone signed in; changing who runs what is operator work,
    | and removing a client or service is admin work.
    */
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('services', [ServiceController::class, 'index'])->name('services.index');

    Route::middleware('role:admin|operator')->group(function () {
        Route::get('clients/create', [ClientController::class, 'create'])->name('clients.create');
        Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('services/create', [ServiceController::class, 'create'])->name('services.create');
        Route::post('services', [ServiceController::class, 'store'])->name('services.store');
    });

    Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');

    Route::middleware('role:admin|operator')->group(function () {
        Route::match(['put', 'patch'], 'clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::post('stacks/{stack}/entitlements', [ClientController::class, 'setEntitlement'])->name('stacks.entitlements');

        // Environment values: vendor credentials and per-tier overrides.
        // Secrets are write-only, but even the plain values are config a
        // viewer has no need for.
        Route::get('clients/{client}/env', [ClientEnvController::class, 'edit'])->name('clients.env.edit');
        Route::put('clients/{client}/env', [ClientEnvController::class, 'update'])->name('clients.env.update');
        Route::post('stacks/{stack}/apply', [ClientEnvController::class, 'apply'])->name('stacks.apply');

        Route::match(['put', 'patch'], 'services/{service}', [ServiceController::class, 'update'])->name('services.update');
        Route::put('services/{service}/catalog', [ServiceController::class, 'updateCatalog'])->name('services.catalog.update');
        Route::post('services/{service}/env', [ServiceController::class, 'storeDefinition'])->name('services.env.store');
        Route::delete('services/{service}/env/{definition}', [ServiceController::class, 'destroyDefinition'])->name('services.env.destroy');
    });

    /*
    |------------------------------------------------------------------
    | Releases and operations
    |------------------------------------------------------------------
    | Anyone signed in can read what happened. Planning and running a release
    | is operator work; an emergency and adopting drift are admin work,
    | because both rewrite what we believe is correct.
    */
    Route::get('releases', [ReleaseController::class, 'index'])->name('releases.index');
    Route::get('releases/{release}', [ReleaseController::class, 'show'])->name('releases.show');
    Route::get('operations', [OperationsController::class, 'index'])->name('operations.index');

    Route::middleware('role:admin|operator')->group(function () {
        Route::post('releases', [ReleaseController::class, 'store'])->name('releases.store');
        Route::post('releases/{release}/run', [ReleaseController::class, 'run'])->name('releases.run');
        Route::post('releases/{release}/rollback', [ReleaseController::class, 'rollback'])->name('releases.rollback');
        Route::post('releases/{release}/schedule', [ReleaseController::class, 'schedule'])->name('releases.schedule');
        Route::post('drift/{observation}/converge', [OperationsController::class, 'converge'])->name('drift.converge');
    });

    Route::middleware('role:admin')->group(function () {
        // Adopting rewrites what we believe should be deployed on the strength
        // of someone's judgement, so it sits with the destructive actions.
        Route::post('drift/{observation}/adopt', [OperationsController::class, 'adopt'])->name('drift.adopt');
    });

    /*
    |------------------------------------------------------------------
    | Servers — admin only, all of it
    |------------------------------------------------------------------
    | A server row holds a private key granting root on a client's machine.
    | Even reading the list tells you the estate, so this is not viewer
    | territory at any level.
    */
    Route::middleware('role:admin')->group(function () {
        Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
        Route::get('servers/create', [ServerController::class, 'create'])->name('servers.create');
        Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
        Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
        Route::get('servers/{server}/edit', [ServerController::class, 'edit'])->name('servers.edit');
        Route::match(['put', 'patch'], 'servers/{server}', [ServerController::class, 'update'])->name('servers.update');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
        Route::post('servers/{server}/check', [ServerController::class, 'check'])->name('servers.check');
        Route::post('servers/{server}/rescan', [ServerController::class, 'rescan'])->name('servers.rescan');
        Route::post('servers/{server}/regenerate-key', [ServerController::class, 'regenerateKey'])->name('servers.regenerate-key');
        Route::post('servers/{server}/provision', [ServerController::class, 'provision'])->name('servers.provision');

        // GitHub and registry tokens, the certificate email: credentials that
        // reach every client.
        Route::get('control-plane/settings', [ControlPlaneSettingsController::class, 'edit'])->name('control-plane-settings.edit');
        Route::put('control-plane/settings', [ControlPlaneSettingsController::class, 'update'])->name('control-plane-settings.update');
    });

    /*
    |------------------------------------------------------------------
    | Destructive — admin only
    |------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
        Route::delete('services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::delete('pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');
    });
});

require __DIR__.'/settings.php';
