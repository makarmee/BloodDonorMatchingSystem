<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private const ELIGIBILITY_STATUSES = ['Eligible', 'Not Eligible', 'Pending Review', 'Unknown'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::createWithChecks('donors', function (Blueprint $table): void {
            $table->id();
            $table->string('donor_code', 32)->unique();
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('full_name', 150)->index('idx_donors_full_name');
            $table->string('email', 191)->unique();
            $table->string('phone', 32)->nullable()->index('idx_donors_phone');
            $table->enum('gender', ['Female', 'Male', 'Other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('blood_group', self::BLOOD_GROUPS)->nullable()->index('idx_donors_blood_group');
            $table->text('address')->nullable();
            $table->string('emergency_contact', 150)->nullable();
            $table->enum('availability_status', ['Available', 'Temporarily Unavailable', 'Inactive'])
                ->default('Available');
            $table->enum('eligibility_status', self::ELIGIBILITY_STATUSES)
                ->default('Pending Review')
                ->index('idx_donors_eligibility_status');
            $table->enum('account_status', ['Active', 'Inactive'])
                ->default('Active')
                ->index('idx_donors_account_status');
            $table->date('last_donation_date')->nullable();
            $table->date('next_eligible_date')->nullable();
            $table->unsignedInteger('total_completed_donations')->default(0);
            $table->boolean('profile_completed')->default(false);
            $table->date('registered_at')->index('idx_donors_registered_at');
            $table->timestamps();
        }, [
            'chk_donors_total_donations' => 'total_completed_donations >= 0',
        ]);

        Schema::createWithChecks('donor_medical_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donor_id')
                ->unique()
                ->constrained('donors')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->text('medical_notes')->nullable();
            $table->decimal('last_hemoglobin_g_dl', 4, 2)->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->text('deferral_notes')->nullable();
            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestamps();
        }, [
            'chk_medical_hemoglobin' => 'last_hemoglobin_g_dl IS NULL OR last_hemoglobin_g_dl > 0',
            'chk_medical_weight' => 'weight_kg IS NULL OR weight_kg > 0',
        ]);

        Schema::create('donor_eligibility_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donor_id')
                ->constrained('donors')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->enum('eligibility_status', self::ELIGIBILITY_STATUSES);
            $table->text('reason');
            $table->string('clearance_source', 191);
            $table->date('reviewed_at')->nullable();
            $table->date('next_eligible_date')->nullable();
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->boolean('is_current')->default(true);
            $table->boolean('current_record_guard')
                ->nullable()
                ->storedAs('CASE WHEN is_current = 1 THEN 1 ELSE NULL END');
            $table->timestamps();

            $table->index(['donor_id', 'is_current'], 'idx_donor_eligibility_current');
            $table->unique(['donor_id', 'current_record_guard'], 'uq_donor_current_eligibility_record');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donor_eligibility_records');
        Schema::dropIfExists('donor_medical_profiles');
        Schema::dropIfExists('donors');
    }
};

