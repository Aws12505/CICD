<?php

use App\Models\Service;
use App\Models\ServiceFile;
use App\Services\Build\BuildFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function buildFiles(): BuildFiles
{
    return app(BuildFiles::class);
}

/*
|--------------------------------------------------------------------------
| Which files, and where each comes from
|--------------------------------------------------------------------------
*/

test('a laravel service has six files and a next.js service has no entrypoint', function () {
    $laravel = Service::factory()->create();
    $nextjs = Service::factory()->perStackBuild()->create();

    expect(buildFiles()->names($laravel))->toBe([
        'Dockerfile', 'entrypoint.sh', '.dockerignore', 'compose.service.yml', 'compose.process.yml', 'nginx.conf',
    ]);
    // The node image's CMD is the server; there is nothing to switch on.
    expect(buildFiles()->names($nextjs))->not->toContain('entrypoint.sh');
});

test('with no row a file is the recipe default', function () {
    $service = Service::factory()->create();

    expect(buildFiles()->source($service, 'Dockerfile'))->toBe(['source' => 'default', 'repo_path' => null]);
    expect(buildFiles()->resolve($service, 'Dockerfile'))->toContain('FROM php:');
    expect(buildFiles()->resolve($service, 'compose.service.yml'))->toContain('image: {{image}}');
});

test('a customised file replaces the default; a repository file is a path, not contents', function () {
    $service = Service::factory()->create();
    $service->files()->create(['name' => 'Dockerfile', 'source' => ServiceFile::SOURCE_CUSTOM, 'contents' => "FROM scratch\n"]);
    $service->files()->create(['name' => '.dockerignore', 'source' => ServiceFile::SOURCE_REPO, 'repo_path' => 'docker/.dockerignore']);
    $service->load('files');

    expect(buildFiles()->resolve($service, 'Dockerfile'))->toBe("FROM scratch\n");
    expect(buildFiles()->source($service, 'Dockerfile'))->toBe(['source' => 'custom', 'repo_path' => null]);

    expect(buildFiles()->resolve($service, '.dockerignore'))->toBeNull();
    expect(buildFiles()->source($service, '.dockerignore'))->toBe(['source' => 'repo', 'repo_path' => 'docker/.dockerignore']);
    // The default is still there to go back to.
    expect(buildFiles()->defaultContents($service, 'Dockerfile'))->toContain('FROM php:');
});

/*
|--------------------------------------------------------------------------
| Validation: a saved template must render safely on every stack
|--------------------------------------------------------------------------
*/

test('only the known placeholders are accepted, spelled exactly', function () {
    $problems = buildFiles()->validate('nginx.conf', "server_name {{domain}};\n{{bogus}}\n{{ host_port }}\n");

    expect($problems)->toHaveCount(2);
    expect($problems[0])->toContain('{{bogus}}')->toContain('Allowed:')->toContain('{{domain}}');
    expect($problems[1])->toContain('{{ host_port }}');
});

test('a block placeholder has to be alone on its line', function () {
    expect(buildFiles()->validate('nginx.conf', "    {{process_locations}}\n"))->toBeEmpty();
    expect(buildFiles()->validate('nginx.conf', "    location / {} {{process_locations}}\n"))
        ->toHaveCount(1)
        ->and(buildFiles()->validate('nginx.conf', "    location / {} {{process_locations}}\n")[0])->toContain('alone on its line');
});

test('the compose blocks must keep the lines everything else keys on', function () {
    $process = "  x:\n    container_name: {{container}}\n    command: \"{{command}}\"\n";

    $problems = buildFiles()->validate('compose.process.yml', $process);

    expect(implode(' ', $problems))
        ->toContain('command: {{command}}')
        ->toContain('image: {{image}}');

    expect(buildFiles()->validate('compose.service.yml', "  x:\n    image: {{image}}\n    container_name: {{container}}\n"))->toBeEmpty();
});

test('a single dollar in a compose block is refused because compose would expand it', function () {
    $block = "  x:\n    image: {{image}}\n    container_name: {{container}}\n    environment:\n      - A=\$HOME\n";

    expect(implode(' ', buildFiles()->validate('compose.service.yml', $block)))->toContain('$$');
    expect(buildFiles()->validate('compose.service.yml', str_replace('$HOME', '$$HOME', $block)))->toBeEmpty();
});

test('files that are not templates are not validated', function () {
    expect(buildFiles()->validate('Dockerfile', 'RUN echo {{anything}} $HOME'))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Rendering
|--------------------------------------------------------------------------
*/

test('a block is re-indented to its placeholder line and the line disappears when the block is empty', function () {
    $template = "server {\n    {{acme_location}}\n    {{process_locations}}\n    listen 80;\n}\n";

    $out = buildFiles()->render($template, [], [
        'acme_location' => ['location /.well-known/ {', '    root /var/www;', '}'],
        'process_locations' => [],
    ]);

    expect($out)->toBe("server {\n    location /.well-known/ {\n        root /var/www;\n    }\n    listen 80;\n}\n");
});

test('scalars are substituted in one pass and dollars are doubled only for compose', function () {
    $template = "name: {{slug}}\ncmd: {{command}}\n";
    $scalars = ['slug' => 'a$b', 'command' => '{{slug}}'];

    // A value containing a placeholder is a value, never expanded again.
    expect(buildFiles()->render($template, $scalars, [], composeEscape: true))->toBe("name: a\$\$b\ncmd: {{slug}}\n");
    expect(buildFiles()->render($template, $scalars))->toBe("name: a\$b\ncmd: {{slug}}\n");
});
