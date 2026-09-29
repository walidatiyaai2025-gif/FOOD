<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignId('legacy_customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable()->index();
            $table->string('email')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        if (! Schema::hasColumn('users', 'is_platform_customer')) {
            return;
        }

        DB::table('users')
            ->where('is_platform_customer', true)
            ->orderBy('id')
            ->chunkById(250, function ($users): void {
                foreach ($users as $user) {
                    $b2b = DB::table('b2b_customers')
                        ->where('user_id', $user->id)
                        ->first(['legacy_customer_id', 'name', 'phone', 'email']);

                    $legacy = $b2b?->legacy_customer_id === null
                        ? DB::table('customers')->where('user_id', $user->id)->first()
                        : DB::table('customers')->where('id', $b2b->legacy_customer_id)->first();

                    if ($legacy === null) {
                        continue;
                    }

                    DB::table('platform_customers')->updateOrInsert(
                        ['user_id' => (int) $user->id],
                        [
                            'legacy_customer_id' => (int) $legacy->id,
                            'name' => (string) ($b2b?->name ?? $legacy->name ?? $user->name),
                            'phone' => $b2b?->phone ?? $legacy->phone,
                            'email' => (string) ($b2b?->email ?? $legacy->email ?? $user->email),
                            'is_active' => true,
                            'created_at' => $legacy->created_at ?? now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_customers');
    }
};
