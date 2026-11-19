<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_categories', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('vendor_profiles', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('user_id', 40)->unique();
            $table->string('business_name', 200);
            $table->string('slug', 220)->unique();
            $table->string('category_id', 40)->nullable();
            $table->string('location', 200);
            $table->text('description');
            $table->string('avatar_url', 1024)->nullable();
            $table->string('cover_url', 1024)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->timestampTz('contact_email_verified_at')->nullable();
            $table->string('website', 512)->nullable();
            $table->jsonb('socials')->nullable();
            $table->string('status', 30)->default('pending_verification');
            $table->string('availability', 20)->default('available');
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('ratings_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('works_count')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->string('rejection_reason', 1000)->nullable();
            $table->string('suspended_reason', 1000)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['status', 'rating_avg']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('vendor_categories')->nullOnDelete();
        });
        DB::statement('CREATE INDEX vendor_profiles_name_trgm ON vendor_profiles USING gin (business_name gin_trgm_ops)');

        Schema::create('vendor_verification_documents', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('vendor_profile_id', 40)->nullable();
            $table->string('user_id', 40);
            $table->string('type', 60);
            $table->string('upload_id', 40)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampsTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('vendor_works', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('vendor_profile_id', 40);
            $table->string('title', 200);
            $table->text('description');
            $table->unsignedInteger('likes_count')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['vendor_profile_id', 'created_at']);
            $table->foreign('vendor_profile_id')->references('id')->on('vendor_profiles')->cascadeOnDelete();
        });

        Schema::create('work_likes', function (Blueprint $table) {
            $table->string('user_id', 40);
            $table->string('work_id', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'work_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('work_id')->references('id')->on('vendor_works')->cascadeOnDelete();
        });

        Schema::create('vendor_reviews', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('vendor_profile_id', 40);
            $table->string('user_id', 40);
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 1000);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->foreign('vendor_profile_id')->references('id')->on('vendor_profiles')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX vendor_reviews_one_per_user ON vendor_reviews (vendor_profile_id, user_id) WHERE deleted_at IS NULL');
        DB::statement('ALTER TABLE vendor_reviews ADD CONSTRAINT vendor_reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');

        Schema::create('vendor_promos', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('vendor_profile_id', 40);
            $table->string('title', 200);
            $table->string('description', 1000);
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->string('cover_url', 1024)->nullable();
            $table->timestampsTz();
            $table->foreign('vendor_profile_id')->references('id')->on('vendor_profiles')->cascadeOnDelete();
        });

        Schema::create('saved_vendors', function (Blueprint $table) {
            $table->string('user_id', 40);
            $table->string('vendor_profile_id', 40);
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'vendor_profile_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('vendor_profile_id')->references('id')->on('vendor_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['saved_vendors', 'vendor_promos', 'vendor_reviews', 'work_likes', 'vendor_works', 'vendor_verification_documents',
            'vendor_profiles', 'vendor_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
