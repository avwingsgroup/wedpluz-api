<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Old public slugs of renamed events and vendors. Each row points at the
 * record (not at the next slug), so a chain of renames always resolves to the
 * current slug in one lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // event | vendor
            $table->string('old_slug', 200);
            $table->string('target_id', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['type', 'old_slug']);
            $table->index(['type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_redirects');
    }
};
