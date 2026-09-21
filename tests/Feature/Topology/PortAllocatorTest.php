<?php

use App\Models\Service;
use App\Models\Stack;
use App\Models\StackService;
use App\Services\Stack\PortAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('each stack gets its own block, so ports on a machine stay readable', function () {
    config(['deploy.ports.base' => 40000, 'deploy.ports.block_size' => 100]);

    $allocator = app(PortAllocator::class);

    expect($allocator->allocateBlock())->toBe(40000);

    Stack::factory()->create(['port_range_start' => 40000]);
    expect($allocator->allocateBlock())->toBe(40100);

    Stack::factory()->create(['port_range_start' => 40100]);
    expect($allocator->allocateBlock())->toBe(40200);
});

test('a service keeps the same port across deploys', function () {
    config(['deploy.ports.base' => 40000, 'deploy.ports.block_size' => 100]);

    $stack = Stack::factory()->create(['port_range_start' => 40000]);
    $entitlement = StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create()->id,
        'enabled' => true,
    ]);

    $allocator = app(PortAllocator::class);

    // nginx and the compose file both point at this, so it must not move.
    $first = $allocator->assign($entitlement);
    expect($allocator->assign($entitlement->fresh()))->toBe($first);
});

test('services on one stack never collide', function () {
    config(['deploy.ports.base' => 40000, 'deploy.ports.block_size' => 100]);

    $stack = Stack::factory()->create(['port_range_start' => 40000]);
    $allocator = app(PortAllocator::class);

    $ports = collect(range(1, 5))->map(function () use ($stack, $allocator) {
        return $allocator->assign(StackService::create([
            'stack_id' => $stack->id,
            'service_id' => Service::factory()->create()->id,
            'enabled' => true,
        ]));
    });

    expect($ports->unique())->toHaveCount(5);
    expect($ports->every(fn (int $p) => $p >= 40000 && $p < 40100))->toBeTrue();
});

test('running out of block space fails loudly rather than reusing a port', function () {
    config(['deploy.ports.base' => 40000, 'deploy.ports.block_size' => 2]);

    $stack = Stack::factory()->create(['port_range_start' => 40000]);
    $allocator = app(PortAllocator::class);

    foreach (range(1, 2) as $ignored) {
        $allocator->assign(StackService::create([
            'stack_id' => $stack->id,
            'service_id' => Service::factory()->create()->id,
            'enabled' => true,
        ]));
    }

    // Two services quietly sharing a host port is a stack that half works.
    $allocator->assign(StackService::create([
        'stack_id' => $stack->id,
        'service_id' => Service::factory()->create()->id,
        'enabled' => true,
    ]));
})->throws(RuntimeException::class, 'no free port left');

test('running out of blocks fails loudly too', function () {
    config(['deploy.ports.base' => 40000, 'deploy.ports.block_size' => 100, 'deploy.ports.max' => 40200]);

    Stack::factory()->create(['port_range_start' => 40000]);
    Stack::factory()->create(['port_range_start' => 40100]);

    app(PortAllocator::class)->allocateBlock();
})->throws(RuntimeException::class, 'No free port block');
