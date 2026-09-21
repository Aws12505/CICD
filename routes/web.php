<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\ServerController;
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
    });

    /*
    |------------------------------------------------------------------
    | Destructive — admin only
    |------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        Route::delete('pipelines/{pipeline}', [PipelineController::class, 'destroy'])->name('pipelines.destroy');
    });
});

require __DIR__.'/settings.php';
