<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            if (! Schema::hasColumn('donations', 'staff_id')) {
                $table->foreignId('staff_id')
                    ->nullable()
                    ->after('recorded_by_user_id')
                    ->constrained('staff')
                    ->restrictOnDelete()
                    ->cascadeOnUpdate();
                $table->index('staff_id', 'idx_donations_staff');
            }
        });

        // Safely backfill staff_id only when an existing staff record unambiguously matches staff_member_name.
        // Historical staff_member_name snapshots remain intact for all rows with no matching staff record.
        if (Schema::hasTable('staff') && Schema::hasColumn('donations', 'staff_id')) {
            $donations = DB::table('donations')
                ->whereNull('staff_id')
                ->whereNotNull('staff_member_name')
                ->select(['id', 'staff_member_name'])
                ->get();

            foreach ($donations as $donation) {
                $matchingStaffIds = DB::table('staff')
                    ->where('full_name', $donation->staff_member_name)
                    ->limit(2)
                    ->pluck('id');

                if ($matchingStaffIds->count() === 1) {
                    DB::table('donations')
                        ->where('id', $donation->id)
                        ->update(['staff_id' => $matchingStaffIds->first()]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table): void {
            if (Schema::hasColumn('donations', 'staff_id')) {
                $table->dropForeign(['staff_id']);

                if (Schema::hasIndex('donations', 'idx_donations_staff')) {
                    $table->dropIndex('idx_donations_staff');
                }

                $table->dropColumn('staff_id');
            }
        });
    }
};
