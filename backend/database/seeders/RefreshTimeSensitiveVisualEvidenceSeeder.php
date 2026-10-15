<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RefreshTimeSensitiveVisualEvidenceSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $driverId = (int) DB::table('drivers')
            ->join('users', 'users.id', '=', 'drivers.user_id')
            ->where('users.email', 'driver.b2b@foodex.test')
            ->value('drivers.id');

        if ($driverId > 0) {
            // The bilingual screenshot suite can exceed two minutes before it reaches
            // mixed live tracking. Shift this deterministic fixture slightly forward so
            // it is stale (45-180s old) during that evidence window instead of turning offline.
            $staleAt = $now->copy()->addSeconds(50);
            DB::table('driver_current_locations')
                ->where('driver_id', $driverId)
                ->update([
                    'captured_at' => $staleAt,
                    'received_at' => $staleAt,
                    'updated_at' => $now,
                ]);
        }

        $vanId = (int) DB::table('vans')->where('code', 'VAN-EVID-01')->value('id');

        if ($vanId > 0) {
            // Keep the Van fixture inside the 45-second online window for the
            // full bilingual screenshot run; the suite can take several minutes.
            $vanOnlineAt = $now->copy()->addMinutes(10);

            DB::table('fleet_current_locations')
                ->where('actor_type', 'van')
                ->where('actor_id', $vanId)
                ->update([
                    'captured_at' => $vanOnlineAt,
                    'received_at' => $vanOnlineAt,
                    'updated_at' => $now,
                ]);
        }
    }
}
