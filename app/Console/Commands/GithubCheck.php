<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Setting;
use App\Services\GitHub\AccessCheck;
use App\Support\TokenInfo;
use Illuminate\Console\Command;

/**
 * The Settings page's "Test GitHub access", from the server's own shell, so it
 * works even when the web app is the thing that is broken.
 */
class GithubCheck extends Command
{
    protected $signature = 'github:check';

    protected $description = 'Check that the saved GitHub token can fetch every service repository';

    public function handle(AccessCheck $check): int
    {
        $token = Setting::get(Setting::GITHUB_TOKEN);

        if (blank($token)) {
            $this->error('No GitHub token is saved. Add one under Settings → GitHub token.');

            return self::FAILURE;
        }

        $this->line('Saved token: '.TokenInfo::describe($token));
        $failures = 0;

        foreach (Service::query()->orderBy('position')->orderBy('name')->get() as $service) {
            $result = $check->service($service);
            $failures += $result['ok'] ? 0 : 1;

            $this->line(sprintf('  %s  %-24s %s', $result['ok'] ? '<info>OK  </info>' : '<error>FAIL</error>', $result['service'], $result['ok'] ? $result['repository'].'@'.$result['branch'] : ''));

            if (! $result['ok']) {
                $this->line('        '.$result['message']);
            }
        }

        $this->newLine();
        $failures === 0
            ? $this->info('Every repository can be fetched.')
            : $this->error("{$failures} repository(ies) cannot be fetched. Fix those on GitHub, then run this again.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
