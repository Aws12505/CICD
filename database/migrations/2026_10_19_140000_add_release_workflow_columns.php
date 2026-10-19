<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
        });

        Schema::table('stack_services', function (Blueprint $table) {
            // Set once the service's first-run commands have succeeded on this
            // stack. A failed first deploy leaves it null, so they run again.
            $table->timestamp('initialized_at')->nullable()->after('last_deployed_at');
        });

        Schema::table('services', function (Blueprint $table) {
            // The service every other one verifies tokens against (pizzasys).
            // Deployed first; registers the others' service clients and the
            // client's first admin.
            $table->boolean('identity_provider')->default(false)->after('per_stack_build');
        });

        Schema::table('clients', function (Blueprint $table) {
            // The first admin account created on the client's identity
            // provider. The password is generated per stack and revealed to
            // admins on the client page.
            $table->string('admin_email')->nullable()->after('domain');
            $table->string('admin_name')->nullable()->after('admin_email');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['admin_email', 'admin_name']));
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('identity_provider'));
        Schema::table('stack_services', fn (Blueprint $table) => $table->dropColumn('initialized_at'));
        Schema::table('releases', fn (Blueprint $table) => $table->dropConstrainedForeignId('task_id'));
    }
};
