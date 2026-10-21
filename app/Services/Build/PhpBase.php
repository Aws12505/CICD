<?php

namespace App\Services\Build;

use App\Services\Remote\RemoteFiles;
use App\Support\Shell;

/**
 * The PHP base image every Laravel service is built on.
 *
 * PHP with its extensions is the slow part of a Laravel image and is the same
 * for all of them, so the control plane builds it once, tags it with a hash of
 * its Dockerfile, and each service build starts FROM it. Changing the file
 * changes the tag, which is the whole invalidation story: no tag is ever
 * rewritten, so nothing already built can change under a service.
 */
final class PhpBase
{
    private const FILE = 'build-recipes/laravel/base.Dockerfile';

    public static function contents(): string
    {
        return (string) file_get_contents(resource_path(self::FILE));
    }

    /** ghcr.io/<org>/php-base:<hash> */
    public static function reference(): string
    {
        return rtrim((string) config('deploy.registry'), '/').'/php-base:'.substr(sha1(self::contents()), 0, 12);
    }

    /** Is it in the registry already? Needs a logged-in docker. */
    public static function existsScript(): string
    {
        return 'docker manifest inspect '.Shell::quote(self::reference()).' >/dev/null';
    }

    /**
     * Build and push it. `--pull` here and only here: the base is where a newer
     * php image is picked up, once, instead of on every service build.
     */
    public static function buildScript(string $workspace): string
    {
        $file = $workspace.'/.control-plane/base.Dockerfile';
        $reference = Shell::quote(self::reference());

        return implode("\n", [
            'set -e',
            'mkdir -p '.Shell::quote($workspace.'/.control-plane'),
            RemoteFiles::write($file, self::contents(), '644'),
            // An empty context: the base copies nothing from the repository.
            'context=$(mktemp -d)',
            'trap \'rm -rf "$context"\' EXIT',
            'docker build --pull -f '.Shell::quote($file).' -t '.$reference.' "$context"',
            'docker push '.$reference,
        ]);
    }
}
