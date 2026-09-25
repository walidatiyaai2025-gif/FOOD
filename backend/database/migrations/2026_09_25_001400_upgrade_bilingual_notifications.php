<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('title_ar')->nullable()->after('title');
            $table->string('title_en')->nullable()->after('title_ar');
            $table->text('body_ar')->nullable()->after('body');
            $table->text('body_en')->nullable()->after('body_ar');
            $table->string('audience')->default('all')->after('type');
            $table->string('app')->default('all')->after('audience');
            $table->string('target_channel')->default('all')->after('app');
            $table->string('status')->default('draft')->after('target_channel');
            $table->foreignId('created_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('read_at');
            $table->index(['status', 'published_at']);
            $table->index(['audience', 'app', 'target_channel']);
        });

        DB::table('notifications')->orderBy('id')->each(function (object $row): void {
            DB::table('notifications')->where('id', $row->id)->update([
                'title_ar' => $row->title,
                'title_en' => $row->title,
                'body_ar' => $row->body,
                'body_en' => $row->body,
                'status' => 'published',
                'published_at' => $row->created_at,
            ]);
        });

        Schema::create('notification_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['notification_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_reads');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['status', 'published_at']);
            $table->dropIndex(['audience', 'app', 'target_channel']);
            $table->dropColumn([
                'title_ar', 'title_en', 'body_ar', 'body_en', 'audience',
                'app', 'target_channel', 'status', 'published_at',
            ]);
        });
    }
};
