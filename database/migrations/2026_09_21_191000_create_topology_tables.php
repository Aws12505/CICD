<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The topology: who the clients are, what they run, and where.
 *
 * Created as one migration because the tables are meaningless apart — a stack
 * without a client, or an entitlement without a service, is not a state worth
 * being able to roll back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Their domain. Subdomains are derived: <service><tier>.<domain>
            $table->string('domain');
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('stacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->enum('tier', ['live', 'testing']);
            // Both tiers point at one server today. Moving testing to its own
            // machine later is an UPDATE here, not a refactor.
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->string('compose_project')->unique();
            // Host ports are allocated from this range, one per service.
            $table->unsignedInteger('port_range_start');
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending');
            $table->timestamps();

            $table->unique(['client_id', 'tier']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('repo_full_name');
            $table->string('default_branch')->default('testing');
            $table->enum('build_recipe', ['laravel-api', 'laravel-worker', 'nextjs']);
            $table->string('dockerfile_path')->nullable();
            // <subdomain><tier suffix>.<client domain>
            $table->string('subdomain');
            // e.g. "hiringpizza" — the tier suffix is appended for testing.
            $table->string('database_name')->nullable();
            $table->string('health_path')->default('/up');
            // True for anything whose client-specific values are frozen at
            // build time, so it cannot share one image across stacks. The
            // Next.js dashboard is the reason this exists.
            $table->boolean('per_stack_build')->default(false);
            $table->json('nats_publishes')->nullable();
            $table->json('nats_consumes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        /*
         * What a service needs in its environment. The schema lives here
         * because services are defined in this UI rather than by a manifest
         * in each repo; the values live per stack.
         */
        Schema::create('service_env_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->enum('source', [
                'literal',      // typed in per stack
                'secret',       // typed in per stack, never displayed
                'generated',    // minted during provisioning (tokens, passwords)
                'service_url',  // derived from another service's public URL
                'stack',        // derived from the stack (db name, redis index…)
            ])->default('literal');
            $table->boolean('required')->default(true);
            // Baked into the image rather than read at runtime — the deploy
            // has to rebuild when one of these changes.
            $table->boolean('is_build_arg')->default(false);
            $table->text('default_value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'key']);
        });

        /*
         * Entitlement: whether a client runs a service at all, and what it is
         * currently running.
         */
        Schema::create('stack_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            // Null means the template. Otherwise the edition branch this
            // stack's image is built from.
            $table->string('edition')->nullable();
            $table->unsignedInteger('host_port')->nullable();
            // Digests, not tags: a tag can be moved, a digest cannot.
            $table->string('current_digest')->nullable();
            $table->string('desired_digest')->nullable();
            $table->enum('state', ['pending', 'deployed', 'failed', 'drifted'])->default('pending');
            $table->timestamp('last_deployed_at')->nullable();
            $table->timestamps();

            $table->unique(['stack_id', 'service_id']);
            $table->index('state');
        });

        /*
         * Environment values for one stack. service_id null means the value
         * applies to every service on the stack.
         */
        Schema::create('stack_env_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();

            // Not unique: MySQL treats NULLs as distinct, so a unique index
            // including the nullable service_id would not actually prevent
            // duplicate stack-wide keys. Enforced in the application instead.
            $table->index(['stack_id', 'service_id', 'key']);
        });

        /*
         * Config: the tier of client difference that needs no deploy.
         */
        Schema::create('client_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'service_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_settings');
        Schema::dropIfExists('stack_env_values');
        Schema::dropIfExists('stack_services');
        Schema::dropIfExists('service_env_definitions');
        Schema::dropIfExists('services');
        Schema::dropIfExists('stacks');
        Schema::dropIfExists('clients');
    }
};
