<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard got a row across the top (App\Support\DashboardLayout, column 0) where Needs attention starts. Admins who
 * had arranged their cards before it existed get the card moved there too; they can drag it back into a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('users')->whereNotNull('dashboard_layout')->get(['id', 'dashboard_layout']) as $user) {
            $rows = json_decode((string) $user->dashboard_layout, true);
            if (! is_array($rows)) {
                continue;
            }

            $changed = false;
            foreach ($rows as $i => $row) {
                if (is_array($row) && ($row['widget'] ?? null) === 'NeedsAttention' && ($row['column'] ?? 1) !== 0) {
                    $rows[$i]['column'] = 0;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('users')->where('id', $user->id)->update(['dashboard_layout' => json_encode($rows)]);
            }
        }
    }

    public function down(): void
    {
        // The cards stay where they are: an older version reads the top row as the first column.
    }
};
