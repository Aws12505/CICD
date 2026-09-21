<?php

use App\Broadcasting\PipelineRunChannel;
use App\Broadcasting\PipelineRunsChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Pipeline log streams
|--------------------------------------------------------------------------
|
| These carry raw command output — env values, hostnames, stack traces. Both
| channels used to `return $user !== null`, so any account could subscribe to
| any run, including one it had no business seeing.
|
| Registration is now closed, so every user exists because someone created
| them; role membership is therefore the meaningful check today. Runs do not
| yet belong to a client, so that is as far as this can go — when runs gain a
| stack, scope both channels to the stacks the user is allowed to see.
|
| Channel classes rather than closures so the rules are testable: see
| App\Broadcasting.
|
*/
Broadcast::channel('pipeline.run.{runId}', PipelineRunChannel::class);

Broadcast::channel('pipeline.runs', PipelineRunsChannel::class);
