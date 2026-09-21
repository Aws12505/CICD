<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webhook secrets were stored in plaintext. Encrypt them in place.
     *
     * The column has to widen first: a 40-character secret becomes roughly
     * 250 characters once encrypted, which does not reliably fit in the
     * VARCHAR(255) it used to live in.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('github_secret')->nullable()->change();
        });

        DB::table('projects')
            ->whereNotNull('github_secret')
            ->where('github_secret', '!=', '')
            ->orderBy('id')
            ->each(function (object $project) {
                if ($this->alreadyEncrypted($project->github_secret)) {
                    return;
                }

                DB::table('projects')
                    ->where('id', $project->id)
                    ->update(['github_secret' => Crypt::encryptString($project->github_secret)]);
            });
    }

    public function down(): void
    {
        DB::table('projects')
            ->whereNotNull('github_secret')
            ->where('github_secret', '!=', '')
            ->orderBy('id')
            ->each(function (object $project) {
                if (! $this->alreadyEncrypted($project->github_secret)) {
                    return;
                }

                DB::table('projects')
                    ->where('id', $project->id)
                    ->update(['github_secret' => Crypt::decryptString($project->github_secret)]);
            });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('github_secret')->nullable()->change();
        });
    }

    /**
     * Keeps this migration safe to re-run, and safe on a database that was
     * partially migrated before an interruption.
     */
    private function alreadyEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
