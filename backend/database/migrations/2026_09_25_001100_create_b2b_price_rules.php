<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('b2b_price_rules', function (Blueprint $table): void { $table->id(); $table->foreignId('price_tier_id')->constrained('b2b_price_tiers')->cascadeOnDelete(); $table->foreignId('store_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->constrained()->cascadeOnDelete(); $table->decimal('unit_price',14,3); $table->decimal('minimum_quantity',14,3)->default(1); $table->boolean('is_active')->default(true); $table->timestamps(); $table->unique(['price_tier_id','store_id','product_id']); }); } public function down(): void { Schema::dropIfExists('b2b_price_rules'); } };
