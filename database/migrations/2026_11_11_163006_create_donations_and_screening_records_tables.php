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
        Schema::createWithChecks('donations', function (Blueprint $table): void {
            $table->id();
            $table->string('donation_code', 32)->unique();
            $table->foreignId('donor_id')
                ->constrained('donors')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('appointment_id')
                ->nullable()
                ->unique()
                ->constrained('donor_appointments')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('camp_id')
                ->nullable()
                ->constrained('donation_camps')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('center_id')
                ->nullable()
                ->constrained('donation_centers')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('collection_location_name', 191);
            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('staff_member_name', 150);
            $table->enum('blood_group', self::BLOOD_GROUPS)->index('idx_donations_blood_group');
            $table->foreignId('blood_component_id')
                ->constrained('blood_components')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->date('donation_date')->index('idx_donations_date');
            $table->dateTime('collected_at')->nullable();
            $table->unsignedSmallInteger('quantity_ml');
            $table->enum('collection_status', ['Scheduled', 'Completed', 'Cancelled'])
                ->index('idx_donations_collection_status');
            $table->enum('screening_status', ['Pending', 'Passed', 'Failed'])
                ->default('Pending')
                ->index('idx_donations_screening_status');
            $table->text('collection_notes')->nullable();
            $table->text('screening_notes')->nullable();
            $table->dateTime('released_to_inventory_at')->nullable()->index('idx_donations_released_at');
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestamps();

            $table->index(['donor_id', 'donation_date'], 'idx_donations_donor_date');
            $table->index('camp_id', 'idx_donations_camp');
            $table->index('center_id', 'idx_donations_center');
        }, [
            'chk_donations_quantity_ml' => 'quantity_ml BETWEEN 150 AND 800',
        ]);

        Schema::create('screening_records', function (Blueprint $table): void {
            $table->id();
            $table->string('screening_code', 32)->unique();
            $table->foreignId('donation_id')
                ->constrained('donations')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('test_type_id')
                ->constrained('screening_test_types')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->date('test_date')->nullable()->index('idx_screening_test_date');
            $table->enum('screening_result', ['Pending', 'Passed', 'Failed'])
                ->default('Pending')
                ->index('idx_screening_result');
            $table->boolean('is_confirmatory')->default(false);
            $table->foreignId('performed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('lab_staff_name', 150);
            $table->string('assay_method', 191)->nullable();
            $table->string('specimen_tube_code', 64)->nullable()->index('idx_screening_specimen_tube');
            $table->text('test_notes')->nullable();
            $table->dateTime('collected_at')->nullable();
            $table->dateTime('logged_at')->nullable();
            $table->dateTime('result_recorded_at')->nullable();
            $table->timestamps();

            $table->index(['donation_id', 'test_type_id'], 'idx_screening_donation_test');
            $table->index('test_type_id', 'idx_screening_test_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('screening_records');
        Schema::dropIfExists('donations');
    }
};

