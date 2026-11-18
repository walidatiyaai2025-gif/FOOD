<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Supported v1.0.125 installations predate the explicit user
        // archive/delete permission. The signed updater migrates before
        // reconciling built-in roles; existing permissions and role grants
        // must remain untouched. The canonical name/code come from the
        // v1.0.127 permission catalog, not a caller-supplied ZIP field.
        DB::table('permissions')->insertOrIgnore([
            'code' => 'users.delete',
            'name' => 'Delete or archive user accounts after protected lifecycle approval',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Never destroy existing role grants or permission/audit references
        // during a failed update rollback.
    }
};
