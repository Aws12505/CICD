<?php

namespace App\Services\Build;

use App\Models\Service;
use App\Models\ServiceFile;

/**
 * The files that build a service's image and run it on a stack, and where
 * each one comes from (decision 27).
 *
 * Every file has a default in resources/build-recipes/<recipe>/. A service
 * may replace one with contents edited in the control plane, or, for the
 * files that take part in the image build, point at a file in its
 * repository. Templates (the compose blocks and the nginx vhost) carry
 * `{{placeholders}}` the renderer fills per stack.
 */
class BuildFiles
{
    public const DOCKERFILE = 'Dockerfile';

    public const ENTRYPOINT = 'entrypoint.sh';

    public const DOCKERIGNORE = '.dockerignore';

    public const COMPOSE_SERVICE = 'compose.service.yml';

    public const COMPOSE_PROCESS = 'compose.process.yml';

    public const NGINX = 'nginx.conf';

    /** Files that go into the image build, and so may come from the repository. */
    public const BUILD_FILES = [self::DOCKERFILE, self::ENTRYPOINT, self::DOCKERIGNORE];

    /** How each file is stored on disk under the recipe folder. */
    private const RECIPE_FILENAME = [self::DOCKERIGNORE => 'dockerignore'];

    /** Placeholders a template may use: scalars, and blocks that stand alone on a line. */
    private const PLACEHOLDERS = [
        self::COMPOSE_SERVICE => [
            'scalars' => ['slug', 'image', 'container', 'host_port', 'container_port', 'env_file', 'storage_host', 'storage_container', 'network'],
            'blocks' => [],
        ],
        self::COMPOSE_PROCESS => [
            'scalars' => ['slug', 'name', 'image', 'container', 'host_port', 'container_port', 'env_file', 'storage_host', 'storage_container', 'network', 'command'],
            'blocks' => ['ports_block'],
        ],
        self::NGINX => [
            'scalars' => ['domain', 'host_port', 'cert_dir'],
            'blocks' => ['acme_location', 'process_locations'],
        ],
    ];

    /** What each file is for, in one line, for the UI. */
    public const DESCRIPTIONS = [
        self::DOCKERFILE => 'How the image is built from the repository.',
        self::ENTRYPOINT => 'What runs when a container from the image starts. With no arguments it serves HTTP; given a command (a process) it runs that as www-data.',
        self::DOCKERIGNORE => 'What is left out of the build context.',
        self::COMPOSE_SERVICE => "The web container's block in the stack's docker-compose.yml.",
        self::COMPOSE_PROCESS => 'One block per process (queue, scheduler, nats, reverb…): the same image, a different command. This is the supervisord.conf of the old setup, one container per program.',
        self::NGINX => "The TLS vhost for this service's domain. The maintenance page and the pre-certificate plain-HTTP vhost are generated and cannot be edited.",
    ];

    /**
     * The files a service has, in display order.
     *
     * @return list<string>
     */
    public function names(Service $service): array
    {
        return $service->build_recipe === Service::RECIPE_NEXTJS
            ? [self::DOCKERFILE, self::DOCKERIGNORE, self::COMPOSE_SERVICE, self::COMPOSE_PROCESS, self::NGINX]
            : [self::DOCKERFILE, self::ENTRYPOINT, self::DOCKERIGNORE, self::COMPOSE_SERVICE, self::COMPOSE_PROCESS, self::NGINX];
    }

    public static function recipeFor(Service $service): string
    {
        return $service->build_recipe === Service::RECIPE_NEXTJS ? 'nextjs' : 'laravel';
    }

    /**
     * The file's contents for this service, or null when it comes from the
     * repository (the caller uses the path instead) or has no default.
     */
    public function resolve(Service $service, string $name): ?string
    {
        $row = $this->row($service, $name);

        return match ($row?->source) {
            ServiceFile::SOURCE_CUSTOM => (string) $row->contents,
            ServiceFile::SOURCE_REPO => null,
            default => $this->defaultContents($service, $name),
        };
    }

    public function defaultContents(Service $service, string $name): ?string
    {
        $path = resource_path('build-recipes/'.self::recipeFor($service).'/'.(self::RECIPE_FILENAME[$name] ?? $name));

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * @return array{source: string, repo_path: ?string}
     */
    public function source(Service $service, string $name): array
    {
        $row = $this->row($service, $name);

        return [
            'source' => $row?->source ?? ServiceFile::SOURCE_DEFAULT,
            'repo_path' => $row?->source === ServiceFile::SOURCE_REPO ? $row->repo_path : null,
        ];
    }

    /**
     * @return array{scalars: list<string>, blocks: list<string>}
     */
    public function placeholdersFor(string $name): array
    {
        return self::PLACEHOLDERS[$name] ?? ['scalars' => [], 'blocks' => []];
    }

    public function isTemplate(string $name): bool
    {
        return isset(self::PLACEHOLDERS[$name]);
    }

    /**
     * Problems with a template, in words. Empty when it will render safely.
     *
     * @return list<string>
     */
    public function validate(string $name, string $contents): array
    {
        if (! $this->isTemplate($name)) {
            return [];
        }

        ['scalars' => $scalars, 'blocks' => $blocks] = $this->placeholdersFor($name);
        $allowed = [...$scalars, ...$blocks];
        $problems = [];

        preg_match_all('/\{\{([^}]*)\}\}/', $contents, $matches);

        foreach (array_unique($matches[1]) as $raw) {
            if (! in_array($raw, $allowed, true)) {
                $problems[] = '{{'.$raw.'}} is not a placeholder here. Allowed: '.implode(', ', array_map(fn ($p) => '{{'.$p.'}}', $allowed)).'.';
            }
        }

        foreach ($blocks as $block) {
            if (str_contains($contents, '{{'.$block.'}}') && ! preg_match('/^\h*\{\{'.$block.'\}\}\h*$/m', $contents)) {
                $problems[] = '{{'.$block.'}} must be alone on its line: it expands to several lines.';
            }
        }

        if ($name === self::COMPOSE_PROCESS && ! preg_match('/^\h*command:\h*\{\{command\}\}\h*$/m', $contents)) {
            $problems[] = 'The process block needs a line exactly like `    command: {{command}}` (unquoted: it expands to a JSON array).';
        }

        if (in_array($name, [self::COMPOSE_SERVICE, self::COMPOSE_PROCESS], true)) {
            foreach (['image: {{image}}', 'container_name: {{container}}'] as $required) {
                if (! preg_match('/^\h*'.preg_quote($required, '/').'\h*$/m', $contents)) {
                    $problems[] = "The block must keep a line `{$required}`: releases, drift checks and the services' own settings all rely on it.";
                }
            }

            if (preg_match('/(?<!\$)\$(?!\$)/', $contents)) {
                $problems[] = 'A single `$` in a compose file is expanded by docker compose on the server. Write `$$` for a literal dollar.';
            }
        }

        return $problems;
    }

    /**
     * Fill a template. Scalars are substituted in one pass; a block is a
     * list of lines that replaces its placeholder line, re-indented to that
     * line's depth, and the line disappears when the block is empty.
     *
     * @param  array<string, string|int>  $scalars
     * @param  array<string, list<string>>  $blocks
     * @param  bool  $composeEscape  double `$` in values (compose interpolates them)
     */
    public function render(string $template, array $scalars, array $blocks = [], bool $composeEscape = false): string
    {
        $template = preg_replace_callback(
            '/^(\h*)\{\{([a-z_]+)\}\}\h*(?:\n|$)/m',
            function (array $m) use ($blocks) {
                if (! array_key_exists($m[2], $blocks)) {
                    return $m[0];
                }

                $lines = $blocks[$m[2]];

                return $lines === [] ? '' : implode("\n", array_map(fn (string $l) => $l === '' ? '' : $m[1].$l, $lines))."\n";
            },
            $template,
        );

        $replacements = [];

        foreach ($scalars as $key => $value) {
            $value = (string) $value;
            $replacements['{{'.$key.'}}'] = $composeEscape ? str_replace('$', '$$', $value) : $value;
        }

        return strtr($template, $replacements);
    }

    private function row(Service $service, string $name): ?ServiceFile
    {
        return $service->files->firstWhere('name', $name);
    }
}
