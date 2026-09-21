<?php

use App\Jobs\ExecutePipelineJob;
use App\Models\PipelineRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const WEBHOOK_SECRET = 'a-forty-character-secret-value-for-tests';

function pushPayload(string $repo = 'RnD-Experts-Team/HiringPizza', string $branch = 'testing'): array
{
    [$owner, $name] = explode('/', $repo);

    return [
        'ref' => "refs/heads/{$branch}",
        'after' => 'a1b2c3d4e5f6',
        'repository' => [
            'full_name' => $repo,
            'name' => $name,
            'owner' => ['login' => $owner],
        ],
        'head_commit' => ['id' => 'a1b2c3d4e5f6', 'message' => 'a commit'],
        'pusher' => ['name' => 'someone'],
    ];
}

function sendWebhook(array $payload, ?string $secret): TestResponse
{
    $body = json_encode($payload);

    $headers = ['X-GitHub-Event' => 'push'];

    if ($secret !== null) {
        $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', '/webhook/github', [], [], [], transformHeaders($headers), $body);
}

function transformHeaders(array $headers): array
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $key => $value) {
        $server['HTTP_'.str_replace('-', '_', strtoupper($key))] = $value;
    }

    return $server;
}

/*
|--------------------------------------------------------------------------
| Nothing is knowable before the signature is verified
|--------------------------------------------------------------------------
|
| The lookup used to run first and answer with a descriptive 404 naming the
| repository and branch, which let an unauthenticated caller enumerate every
| repo configured here. All three pre-auth failures must now be identical.
|
*/

test('an unknown repository is rejected without revealing anything', function () {
    $response = sendWebhook(pushPayload('Someone/Unknown'), WEBHOOK_SECRET);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('Unknown');
});

test('a wrong signature is indistinguishable from an unknown repository', function () {
    aProject();

    $unknown = sendWebhook(pushPayload('Someone/Unknown'), WEBHOOK_SECRET);
    $wrongSignature = sendWebhook(pushPayload(), 'the-wrong-secret-entirely');

    $wrongSignature->assertNotFound();

    // Identical status and body, or the difference is an oracle.
    expect($wrongSignature->getContent())->toBe($unknown->getContent());
    expect($wrongSignature->status())->toBe($unknown->status());
});

test('a missing signature header is rejected', function () {
    aProject();

    sendWebhook(pushPayload(), null)->assertNotFound();
});

test('a project with no secret configured is rejected, not accepted', function () {
    $project = aProject();
    $project->forceFill(['github_secret' => null])->save();

    sendWebhook(pushPayload(), WEBHOOK_SECRET)->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| After the signature checks out
|--------------------------------------------------------------------------
*/

test('a valid signature with no matching pipeline gets a useful error', function () {
    aProject();

    // The caller has proven it holds the secret, so specificity is safe here.
    $response = sendWebhook(pushPayload(branch: 'no-such-branch'), WEBHOOK_SECRET);

    $response->assertNotFound();
    expect($response->getContent())->toContain('no-such-branch');
});

test('a valid signature with a matching pipeline queues a run', function () {
    Queue::fake();

    $project = aProject();
    $project->pipelines()->create([
        'branch' => 'testing',
        'steps' => [['id' => 'a', 'name' => 'noop', 'command' => 'true']],
        'enabled' => true,
    ]);

    sendWebhook(pushPayload(), WEBHOOK_SECRET)
        ->assertStatus(202)
        ->assertJson(['ok' => true]);

    expect(PipelineRun::count())->toBe(1);
    Queue::assertPushed(ExecutePipelineJob::class);
});

test('a disabled pipeline does not run', function () {
    Queue::fake();

    $project = aProject();
    $project->pipelines()->create([
        'branch' => 'testing',
        'steps' => [],
        'enabled' => false,
    ]);

    sendWebhook(pushPayload(), WEBHOOK_SECRET)->assertNotFound();

    expect(PipelineRun::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('non-push events are ignored without touching the database', function () {
    aProject();

    $body = json_encode(pushPayload());

    test()->call('POST', '/webhook/github', [], [], [], transformHeaders([
        'X-GitHub-Event' => 'ping',
    ]), $body)->assertOk()->assertJson(['pong' => true]);

    expect(PipelineRun::count())->toBe(0);
});
