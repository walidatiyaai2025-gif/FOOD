<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('categories') && ! Schema::hasColumn('categories', 'image_path')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->string('image_path', 1024)->nullable();
            });
        }

        if (Schema::hasTable('b2c_customers') && ! Schema::hasColumn('b2c_customers', 'image_path')) {
            Schema::table('b2c_customers', function (Blueprint $table): void {
                $table->string('image_path', 1024)->nullable()->after('email');
            });
        }

        if (Schema::hasTable('banners') && ! Schema::hasColumn('banners', 'target_type')) {
            Schema::table('banners', function (Blueprint $table): void {
                $table->string('target_type', 32)->nullable()->after('target_url');
            });
        }

        if (Schema::hasTable('banners') && ! Schema::hasColumn('banners', 'target_id')) {
            Schema::table('banners', function (Blueprint $table): void {
                $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('banners') && Schema::hasColumn('banners', 'target_id')) {
            Schema::table('banners', fn (Blueprint $table) => $table->dropColumn('target_id'));
        }
        if (Schema::hasTable('banners') && Schema::hasColumn('banners', 'target_type')) {
            Schema::table('banners', fn (Blueprint $table) => $table->dropColumn('target_type'));
        }
        if (Schema::hasTable('b2c_customers') && Schema::hasColumn('b2c_customers', 'image_path')) {
            Schema::table('b2c_customers', fn (Blueprint $table) => $table->dropColumn('image_path'));
        }
    }
};
