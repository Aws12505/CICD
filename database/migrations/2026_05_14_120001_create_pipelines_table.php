<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('branch');
            $table->string('github_secret');
            $table->json('steps');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'branch']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};
