<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_ordering_menus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('smart_ordering_revisions')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->uuid('public_id');
            $table->string('name_ar')->nullable();
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['revision_id', 'public_id'], 'so_menu_rev_public_uidx');
            $table->index(['site_id', 'revision_id', 'is_visible']);
        });

        Schema::create('smart_ordering_menu_branches', function (Blueprint $table): void {
            $table->foreignId('menu_id')->constrained('smart_ordering_menus')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->primary(['menu_id', 'branch_id']);
        });

        Schema::create('smart_ordering_menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('smart_ordering_revisions')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained('smart_ordering_menus')->cascadeOnDelete();
            $table->uuid('public_id');
            $table->string('name_ar')->nullable();
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image_path')->nullable();
            $table->string('icon', 64)->nullable();
            $table->boolean('is_visible')->default(true);
            $table->boolean('sold_out')->default(false);
            $table->json('availability_schedule')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['revision_id', 'public_id'], 'so_category_rev_public_uidx');
            $table->index(['menu_id', 'sort_order']);
        });

        Schema::create('smart_ordering_category_branch_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('smart_ordering_menu_categories')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->boolean('is_visible')->nullable();
            $table->boolean('sold_out')->nullable();
            $table->json('availability_schedule')->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'branch_id'], 'so_category_branch_uidx');
        });

        Schema::create('smart_ordering_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('smart_ordering_revisions')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('smart_ordering_menu_categories')->cascadeOnDelete();
            $table->uuid('public_id');
            $table->foreignId('source_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name_ar')->nullable();
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image_path')->nullable();
            $table->json('gallery')->nullable();
            $table->decimal('price_override', 14, 3)->nullable();
            $table->boolean('featured')->default(false);
            $table->boolean('best_seller')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->boolean('sold_out')->default(false);
            $table->json('availability_schedule')->nullable();
            $table->unsignedSmallInteger('prep_time_minutes')->nullable();
            $table->unsignedInteger('calories')->nullable();
            $table->json('allergens')->nullable();
            $table->unsignedTinyInteger('spicy_level')->default(0);
            $table->boolean('vegetarian')->default(false);
            $table->boolean('allow_customer_notes')->default(true);
            $table->json('removable_ingredients')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['revision_id', 'public_id'], 'so_item_rev_public_uidx');
            $table->index(['category_id', 'sort_order']);
            $table->index(['site_id', 'source_product_id']);
        });

        Schema::create('smart_ordering_item_branch_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('smart_ordering_menu_items')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('smart_ordering_branches')->cascadeOnDelete();
            $table->decimal('price_override', 14, 3)->nullable();
            $table->boolean('is_visible')->nullable();
            $table->boolean('sold_out')->nullable();
            $table->json('availability_schedule')->nullable();
            $table->unsignedSmallInteger('prep_time_minutes')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'branch_id'], 'so_item_branch_uidx');
        });

        Schema::create('smart_ordering_modifier_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('smart_ordering_revisions')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('smart_ordering_sites')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('smart_ordering_menu_items')->cascadeOnDelete();
            $table->uuid('public_id');
            $table->string('name_ar')->nullable();
            $table->string('name_en');
            $table->string('kind', 24)->default('modifier');
            $table->string('selection_type', 16)->default('single');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('min_selections')->default(0);
            $table->unsignedSmallInteger('max_selections')->default(1);
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['revision_id', 'public_id'], 'so_group_rev_public_uidx');
            $table->index(['item_id', 'sort_order']);
        });

        Schema::create('smart_ordering_modifier_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('smart_ordering_modifier_groups')->cascadeOnDelete();
            $table->uuid('public_id');
            $table->foreignId('source_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('name_ar')->nullable();
            $table->string('name_en');
            $table->decimal('price_delta', 14, 3)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_visible')->default(true);
            $table->boolean('sold_out')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['group_id', 'public_id'], 'so_option_group_public_uidx');
            $table->index(['group_id', 'sort_order']);
        });

        Schema::create('smart_ordering_modifier_option_branch_overrides', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('option_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreign('option_id', 'so_option_branch_option_fk')->references('id')->on('smart_ordering_modifier_options')->cascadeOnDelete();
            $table->foreign('branch_id', 'so_option_branch_branch_fk')->references('id')->on('smart_ordering_branches')->cascadeOnDelete();
            $table->decimal('price_delta', 14, 3)->nullable();
            $table->boolean('is_visible')->nullable();
            $table->boolean('sold_out')->nullable();
            $table->timestamps();
            $table->unique(['option_id', 'branch_id'], 'so_option_branch_uidx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_ordering_modifier_option_branch_overrides');
        Schema::dropIfExists('smart_ordering_modifier_options');
        Schema::dropIfExists('smart_ordering_modifier_groups');
        Schema::dropIfExists('smart_ordering_item_branch_overrides');
        Schema::dropIfExists('smart_ordering_menu_items');
        Schema::dropIfExists('smart_ordering_category_branch_overrides');
        Schema::dropIfExists('smart_ordering_menu_categories');
        Schema::dropIfExists('smart_ordering_menu_branches');
        Schema::dropIfExists('smart_ordering_menus');
    }
};
