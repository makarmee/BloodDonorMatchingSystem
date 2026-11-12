<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::createWithChecks('inventory_stocks', function (Blueprint $table): void {
            $table->id();
            $table->enum('blood_group', self::BLOOD_GROUPS);
            $table->foreignId('blood_component_id')
                ->constrained('blood_components')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedInteger('available_units')->default(0);
            $table->unsignedInteger('reserved_units')->default(0);
            $table->unsignedInteger('minimum_level')->default(20);
            $table->unsignedInteger('target_capacity')->default(100);
            $table->dateTime('last_adjusted_at')->nullable();
            $table->timestamps();

            $table->unique(['blood_group', 'blood_component_id'], 'uq_inventory_group_component');
        }, [
            'chk_stocks_available_units' => 'available_units >= 0',
            'chk_stocks_reserved_units' => 'reserved_units >= 0',
            'chk_stocks_minimum_level' => 'minimum_level >= 1',
            'chk_stocks_target_capacity' => 'target_capacity >= 1',
        ]);

        Schema::createWithChecks('blood_units', function (Blueprint $table): void {
            $table->id();
            $table->string('unit_code', 40)->unique();
            $table->foreignId('donation_id')
                ->constrained('donations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('parent_unit_id')
                ->nullable()
                ->constrained('blood_units')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->enum('blood_group', self::BLOOD_GROUPS);
            $table->foreignId('blood_component_id')
                ->constrained('blood_components')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedSmallInteger('volume_ml');
            $table->string('storage_location', 120)->nullable();
            $table->enum('unit_status', ['Quarantined', 'Available', 'Reserved', 'Dispatched', 'Transfused', 'Expired', 'Discarded'])
                ->default('Quarantined');
            $table->dateTime('collected_at');
            $table->dateTime('expires_at');
            $table->dateTime('released_at')->nullable();
            $table->foreignId('released_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestamps();

            $table->index('donation_id', 'idx_blood_units_donation');
            $table->index('parent_unit_id', 'idx_blood_units_parent_unit');
            $table->index(['blood_group', 'blood_component_id', 'unit_status', 'expires_at'], 'idx_units_fifo_lookup');
            $table->index(['unit_status', 'expires_at'], 'idx_units_status_expires');
        }, [
            'chk_blood_units_volume_ml' => 'volume_ml > 0',
        ]);

        Schema::createWithChecks('inventory_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('transaction_code', 36)->unique();
            $table->foreignId('inventory_stock_id')
                ->constrained('inventory_stocks')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('blood_unit_id')
                ->nullable()
                ->constrained('blood_units')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->enum('operation_type', ['Stock In', 'Reserve', 'Release Reservation', 'Dispatch', 'Removed', 'Expired', 'Discarded', 'Manual Adjustment'])
                ->index('idx_inv_txn_operation_type');
            $table->integer('units_delta');
            $table->unsignedInteger('available_before');
            $table->unsignedInteger('available_after');
            $table->unsignedInteger('reserved_before');
            $table->unsignedInteger('reserved_after');
            $table->string('reference_type', 64)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('performed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('actor_name', 150);
            $table->text('description');
            $table->dateTime('occurred_at')->index('idx_inv_txn_occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['inventory_stock_id', 'occurred_at'], 'idx_inv_txn_stock_occurred');
            $table->index('blood_unit_id', 'idx_inv_txn_blood_unit');
            $table->index(['reference_type', 'reference_id'], 'idx_inv_txn_reference');
        }, [
            'chk_transactions_units_delta' => "units_delta <> 0 OR (operation_type = 'Discarded' AND available_after = available_before AND reserved_after = reserved_before)",
            'chk_transactions_available_before' => 'available_before >= 0',
            'chk_transactions_available_after' => 'available_after >= 0',
            'chk_transactions_reserved_before' => 'reserved_before >= 0',
            'chk_transactions_reserved_after' => 'reserved_after >= 0',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('blood_units');
        Schema::dropIfExists('inventory_stocks');
    }
};
