<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CAMP_STATUSES = ['Draft', 'Upcoming', 'Ongoing', 'Completed', 'Cancelled'];

    private const APPOINTMENT_STATUSES = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::createWithChecks('donation_centers', function (Blueprint $table): void {
            $table->id();
            $table->string('center_code', 32)->unique();
            $table->string('name', 191);
            $table->string('address', 255);
            $table->string('city', 100)->index('idx_centers_city');
            $table->string('operating_hours', 100);
            $table->unsignedSmallInteger('default_slot_capacity')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        }, [
            'chk_centers_slot_capacity' => 'default_slot_capacity >= 1',
        ]);

        Schema::createWithChecks('donation_camps', function (Blueprint $table): void {
            $table->id();
            $table->string('camp_code', 32)->unique();
            $table->string('name', 191)->index('idx_camps_name');
            $table->text('description');
            $table->string('organizer_name', 150);
            $table->string('organizer_contact', 32);
            $table->string('organizer_email', 191);
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('venue_name', 191);
            $table->string('full_address', 255);
            $table->string('city', 100)->index('idx_camps_city');
            $table->text('location_notes')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('capacity');
            $table->date('registration_deadline');
            $table->boolean('allow_walk_in')->default(true);
            $table->text('donor_requirements');
            $table->text('additional_notes')->nullable();
            $table->enum('status', self::CAMP_STATUSES)->default('Draft')->index('idx_camps_status');
            $table->timestamps();

            $table->index(['start_date', 'status'], 'idx_camps_start_status');
        }, [
            'chk_camps_date_order' => 'end_date >= start_date',
            'chk_camps_time_order' => 'end_date > start_date OR end_time > start_time',
            'chk_camps_capacity' => 'capacity >= 1',
            'chk_camps_deadline' => 'registration_deadline <= start_date',
        ]);

        $appointmentChecks = Schema::getConnection()->getDriverName() === 'mysql'
            ? []
            : [
                'chk_appointment_single_target' => '(donation_center_id IS NOT NULL AND donation_camp_id IS NULL) OR (donation_center_id IS NULL AND donation_camp_id IS NOT NULL)',
            ];

        Schema::createWithChecks('donor_appointments', function (Blueprint $table): void {
            $table->id();
            $table->string('appointment_code', 32)->unique();
            $table->foreignId('donor_id')
                ->constrained('donors')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('donation_component_id')
                ->constrained('blood_components')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('donation_center_id')
                ->nullable()
                ->constrained('donation_centers')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('donation_camp_id')
                ->nullable()
                ->constrained('donation_camps')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->date('appointment_date');
            $table->time('slot_time');
            $table->boolean('active_slot_guard')
                ->nullable()
                ->storedAs("CASE WHEN status <> 'Cancelled' THEN 1 ELSE NULL END");
            $table->enum('status', self::APPOINTMENT_STATUSES)
                ->default('Pending')
                ->index('idx_appointments_status');
            $table->string('notes', 500)->nullable();
            $table->text('prep_summary')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['donor_id', 'status', 'appointment_date'], 'idx_appt_donor_status_date');
            $table->index(['donation_center_id', 'appointment_date', 'slot_time'], 'idx_appt_center_date_slot');
            $table->index(['donation_camp_id', 'appointment_date', 'slot_time'], 'idx_appt_camp_date_slot');
            $table->unique(['donor_id', 'appointment_date', 'slot_time', 'active_slot_guard'], 'uq_donor_active_slot');
        }, $appointmentChecks);

        // MySQL 8.4 rejects CHECK constraints on columns referenced by cascading FK actions (Error 3823).
        Schema::enforceMysqlRowChecks('donor_appointments', [
            'trg_donor_appointments_single_target' => [
                'fail_when' => 'NOT ((NEW.donation_center_id IS NOT NULL AND NEW.donation_camp_id IS NULL) OR (NEW.donation_center_id IS NULL AND NEW.donation_camp_id IS NOT NULL))',
                'message' => 'chk_appointment_single_target violated: exactly one of donation_center_id or donation_camp_id must be non-null.',
            ],
        ]);

        Schema::create('appointment_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')
                ->constrained('donor_appointments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('event_name', 120);
            $table->enum('previous_status', self::APPOINTMENT_STATUSES)->nullable();
            $table->enum('new_status', self::APPOINTMENT_STATUSES);
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at');

            $table->index(['appointment_id', 'occurred_at'], 'idx_appt_hist_appt_occurred');
        });

        Schema::create('camp_registrations', function (Blueprint $table): void {
            $table->id();
            $table->string('registration_code', 32)->unique();
            $table->foreignId('camp_id')
                ->constrained('donation_camps')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
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
            $table->foreignId('registered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->date('registration_date');
            $table->boolean('is_walk_in')->default(false);
            $table->enum('attendance_status', ['Registered', 'Confirmed', 'Attended', 'No-show', 'Cancelled'])
                ->default('Registered');
            $table->boolean('active_registration_guard')
                ->nullable()
                ->storedAs("CASE WHEN attendance_status <> 'Cancelled' THEN 1 ELSE NULL END");
            $table->dateTime('registered_at');
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['camp_id', 'attendance_status'], 'idx_camp_reg_camp_attendance');
            $table->index('donor_id', 'idx_camp_reg_donor');
            $table->unique(['camp_id', 'donor_id', 'active_registration_guard'], 'uq_camp_donor_active_reg');
        });

        Schema::create('camp_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('camp_id')
                ->constrained('donation_camps')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('event_name', 120);
            $table->enum('previous_status', self::CAMP_STATUSES)->nullable();
            $table->enum('new_status', self::CAMP_STATUSES)->nullable();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('actor_name', 150);
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at');

            $table->index(['camp_id', 'occurred_at'], 'idx_camp_hist_camp_occurred');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('camp_status_histories');
        Schema::dropIfExists('camp_registrations');
        Schema::dropIfExists('appointment_status_histories');
        Schema::dropMysqlRowChecks(['trg_donor_appointments_single_target']);
        Schema::dropIfExists('donor_appointments');
        Schema::dropIfExists('donation_camps');
        Schema::dropIfExists('donation_centers');
    }
};
