<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short specialty line shown on vendor cards ("Bridal wear rentals",
 * "Event Planner"), more specific than the category name. Optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_profiles', function (Blueprint $table) {
            $table->string('headline', 80)->nullable()->after('business_name');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_profiles', function (Blueprint $table) {
            $table->dropColumn('headline');
        });
    }
};
