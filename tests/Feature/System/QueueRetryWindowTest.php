<?php

/*
 * A queued job is handed to another worker once `retry_after` passes, even
 * while the first is still running it. With `$tries = 1` that second pickup
 * fails at once as "attempted too many times" and the job's failed() handler
 * logs FAILED over a build or release that is about to succeed.
 */
test('the queue waits longer than the longest job before handing it to another worker', function () {
    $longest = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $file) => 'App\\Jobs\\'.basename($file, '.php'))
        ->filter(fn (string $class) => class_exists($class) && property_exists($class, 'timeout'))
        ->map(fn (string $class) => (new ReflectionClass($class))->getProperty('timeout')->getDefaultValue() ?? 0)
        ->max();

    expect($longest)->toBeGreaterThan(3600);

    foreach (['database', 'redis'] as $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan($longest);
    }
});
