<?php

use App\Contracts\Invocation;
use App\Contracts\Target;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** A user holding exactly one control-plane role. */
function actorWithRole(string $role): User
{
    return tap(User::factory()->create())->assignRole($role);
}

/**
 * A target that runs nothing. Tests that only care about which commands are
 * built pair it with a recording CommandRunner.
 */
final class NullTarget implements Target
{
    public function invoke(string $command, ?string $cwd, array $env): Invocation
    {
        return new Invocation(['true']);
    }

    public function prefix(): array
    {
        return ['ssh', 'deploy@test-server'];
    }

    public function label(): string
    {
        return 'null';
    }

    public function cleanup(): void {}
}

/** A project with a known webhook secret, for signature tests. */
function aProject(string $secret = 'a-forty-character-secret-value-for-tests'): Project
{
    return Project::create([
        'name' => 'Hiring',
        'repo_owner' => 'RnD-Experts-Team',
        'repo_name' => 'HiringPizza',
        'repo_full_name' => 'RnD-Experts-Team/HiringPizza',
        'description' => null,
        'github_secret' => $secret,
    ]);
}
