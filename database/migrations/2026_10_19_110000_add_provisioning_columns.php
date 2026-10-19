<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What it takes to set a server up from bare, and to remember what was set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // The half the operator pastes into the VPS. The private half is
            // generated here and never shown.
            $table->text('public_key')->nullable()->after('ssh_private_key');

            // Passwords for the shared MySQL, Redis and NATS on this server.
            // Generated once and kept; changing them means touching every
            // stack on the box.
            $table->text('secrets')->nullable()->after('host_key');

            $table->json('capabilities')->nullable()->after('secrets');
            $table->timestamp('provisioned_at')->nullable()->after('capabilities');
        });

        Schema::table('stacks', function (Blueprint $table) {
            // This stack's own MySQL and Redis credentials, scoped so the
            // testing stack cannot reach live data on the same server.
            $table->text('secrets')->nullable()->after('status');
            // Excluded from multi-client updates until unpinned.
            $table->boolean('pinned')->default(false)->after('secrets');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            // Always encrypted: most of these are tokens.
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');

        Schema::table('stacks', function (Blueprint $table) {
            $table->dropColumn(['secrets', 'pinned']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['public_key', 'secrets', 'capabilities', 'provisioned_at']);
        });
    }
};
