<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('van_inventory_history_documents')) {
            Schema::table('van_inventory_history_documents', function (Blueprint $table): void {
                if (! Schema::hasColumn('van_inventory_history_documents', 'link_status')) {
                    $table->string('link_status', 40)->default('unclassified')->index();
                }
                if (! Schema::hasColumn('van_inventory_history_documents', 'link_note')) {
                    $table->text('link_note')->nullable();
                }
            });
        }

        if (Schema::hasTable('van_inventory_history_lines')) {
            Schema::table('van_inventory_history_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('van_inventory_history_lines', 'link_status')) {
                    $table->string('link_status', 40)->default('unclassified')->index();
                }
                if (! Schema::hasColumn('van_inventory_history_lines', 'link_note')) {
                    $table->text('link_note')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('van_inventory_history_lines')) {
            Schema::table('van_inventory_history_lines', function (Blueprint $table): void {
                if (Schema::hasColumn('van_inventory_history_lines', 'link_note')) {
                    $table->dropColumn('link_note');
                }
                if (Schema::hasColumn('van_inventory_history_lines', 'link_status')) {
                    $table->dropColumn('link_status');
                }
            });
        }

        if (Schema::hasTable('van_inventory_history_documents')) {
            Schema::table('van_inventory_history_documents', function (Blueprint $table): void {
                if (Schema::hasColumn('van_inventory_history_documents', 'link_note')) {
                    $table->dropColumn('link_note');
                }
                if (Schema::hasColumn('van_inventory_history_documents', 'link_status')) {
                    $table->dropColumn('link_status');
                }
            });
        }
    }
};
