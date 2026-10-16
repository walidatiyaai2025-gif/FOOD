<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_ordering_floor_areas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['branch_id', 'sort_order']);
        });

        Schema::table('smart_ordering_tables', function (Blueprint $table): void {
            $table->foreignId('floor_area_id')->nullable()->after('branch_id')
                ->constrained('smart_ordering_floor_areas')->nullOnDelete();
            $table->unsignedSmallInteger('capacity')->default(2)->after('label');
            $table->unsignedSmallInteger('position_x')->default(60);
            $table->unsignedSmallInteger('position_y')->default(60);
            $table->unsignedSmallInteger('visual_width')->default(160);
            $table->unsignedSmallInteger('visual_height')->default(120);
            $table->string('shape', 20)->default('rectangle');
            $table->uuid('qr_reference')->nullable()->unique();
            $table->foreignId('qr_code_id')->nullable()->constrained('smart_ordering_qr_codes')->nullOnDelete();
            $table->timestamp('qr_bound_at')->nullable();
            $table->index(['branch_id', 'floor_area_id', 'is_active']);
        });

        Schema::create('smart_ordering_table_combinations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->foreignId('primary_table_id')->constrained('smart_ordering_tables')->cascadeOnDelete();
            $table->foreignId('member_table_id')->constrained('smart_ordering_tables')->cascadeOnDelete();
            $table->foreignId('combined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('split_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('combined_at');
            $table->timestamp('split_at')->nullable()->index();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'split_at']);
            $table->index(['primary_table_id', 'split_at']);
            $table->index(['member_table_id', 'split_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_table_combinations');

        Schema::table('smart_ordering_tables', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('floor_area_id');
            $table->dropConstrainedForeignId('qr_code_id');
            $table->dropColumn([
                'capacity',
                'position_x',
                'position_y',
                'visual_width',
                'visual_height',
                'shape',
                'qr_reference',
                'qr_bound_at',
            ]);
        });

        Schema::dropIfExists('smart_ordering_floor_areas');
    }
};
