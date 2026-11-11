<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private const REQUEST_STATUSES = ['Pending', 'Approved', 'Rejected', 'Partially Fulfilled', 'Fulfilled', 'Cancelled'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hospitals', function (Blueprint $table): void {
            $table->id();
            $table->string('hospital_code', 32)->unique();
            $table->string('name', 191)->index('idx_hospitals_name');
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable()->index('idx_hospitals_city');
            $table->string('primary_contact_name', 150)->nullable();
            $table->string('primary_phone', 32)->nullable();
            $table->string('primary_email', 191)->nullable();
            $table->enum('verification_status', ['Pending', 'Verified', 'Suspended'])
                ->default('Pending')
                ->index('idx_hospitals_verification_status');
            $table->foreignId('verified_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->dateTime('verified_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hospital_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hospital_id')
                ->constrained('hospitals')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('contact_name', 150);
            $table->string('department', 150)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('work_phone', 32)->nullable();
            $table->string('work_email', 191)->nullable();
            $table->boolean('is_primary_contact')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('hospital_id', 'idx_hospital_users_hospital');
        });

        Schema::create('blood_compatibility_rules', function (Blueprint $table): void {
            $table->id();
            $table->enum('recipient_blood_group', self::BLOOD_GROUPS);
            $table->enum('donor_blood_group', self::BLOOD_GROUPS)->index('idx_compat_donor_group');
            $table->foreignId('blood_component_id')
                ->constrained('blood_components')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->enum('compatibility_level', ['Exact Match', 'Compatible Substitution', 'Emergency Only']);
            $table->boolean('requires_medical_override')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['recipient_blood_group', 'donor_blood_group', 'blood_component_id'], 'uq_compat_recipient_donor_component');
        });

        Schema::createWithChecks('blood_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('request_code', 32)->unique();
            $table->foreignId('hospital_id')
                ->constrained('hospitals')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('department_or_ward', 150)->nullable();
            $table->string('contact_person_snapshot', 150);
            $table->string('contact_phone_snapshot', 32);
            $table->string('contact_email_snapshot', 191);
            $table->enum('blood_group', self::BLOOD_GROUPS);
            $table->foreignId('blood_component_id')
                ->nullable()
                ->constrained('blood_components')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedInteger('requested_units');
            $table->unsignedInteger('reserved_units')->default(0);
            $table->unsignedInteger('fulfilled_units')->default(0);
            $table->enum('priority', ['Low', 'Normal', 'High', 'Critical'])->index('idx_requests_priority');
            $table->enum('status', self::REQUEST_STATUSES)->default('Pending');
            $table->date('request_date');
            $table->date('required_date')->index('idx_requests_required_date');
            $table->text('request_reason');
            $table->text('additional_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('cancelled_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['hospital_id', 'status'], 'idx_req_hospital_status');
            $table->index(['blood_group', 'priority', 'status'], 'idx_req_group_priority_status');
            $table->index(['status', 'request_date'], 'idx_req_status_date');
        }, [
            'chk_requests_requested_units' => 'requested_units >= 1',
            'chk_requests_reserved_units' => 'reserved_units >= 0',
            'chk_requests_fulfilled_bounds' => 'fulfilled_units >= 0 AND fulfilled_units <= requested_units AND (fulfilled_units + reserved_units) <= requested_units',
        ]);

        Schema::create('blood_request_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blood_request_id')
                ->constrained('blood_requests')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('event_name', 120);
            $table->enum('previous_status', self::REQUEST_STATUSES)->nullable();
            $table->enum('new_status', self::REQUEST_STATUSES);
            $table->unsignedInteger('units_affected')->default(0);
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('actor_name', 150);
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at');

            $table->index(['blood_request_id', 'occurred_at'], 'idx_req_hist_req_occurred');
        });

        $overrideAuditCheck = Schema::getConnection()->getDriverName() === 'mysql'
            ? "is_compatibility_override = 0 OR (override_approved_at IS NOT NULL AND override_reason IS NOT NULL AND TRIM(override_reason) <> '')"
            : "is_compatibility_override = 0 OR (override_approved_by_user_id IS NOT NULL AND override_approved_at IS NOT NULL AND override_reason IS NOT NULL AND TRIM(override_reason) <> '')";

        Schema::createWithChecks('request_fulfillments', function (Blueprint $table): void {
            $table->id();
            $table->string('fulfillment_code', 32)->unique();
            $table->foreignId('blood_request_id')
                ->constrained('blood_requests')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('dispatched_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->enum('dispatched_blood_group', self::BLOOD_GROUPS);
            $table->foreignId('blood_component_id')
                ->constrained('blood_components')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedInteger('units_dispatched');
            $table->date('dispatch_date');
            $table->dateTime('dispatched_at');
            $table->string('courier_reference', 64)->nullable();
            $table->enum('delivery_status', ['Dispatched', 'In Transit', 'Delivered', 'Returned'])
                ->default('Dispatched')
                ->index('idx_fulfillments_delivery_status');
            $table->dateTime('delivered_at')->nullable();
            $table->string('received_by_name', 150)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_compatibility_override')->default(false);
            $table->foreignId('override_approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->dateTime('override_approved_at')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->index(['blood_request_id', 'dispatch_date'], 'idx_fulfillments_req_date');
        }, [
            'chk_fulfillments_units_dispatched' => 'units_dispatched >= 1',
            'chk_fulfillment_override_audit' => $overrideAuditCheck,
        ]);

        // Enforce override_approved_by_user_id via trigger on MySQL due to Error 3823 with ON DELETE SET NULL.
        Schema::enforceMysqlRowChecks('request_fulfillments', [
            'trg_request_fulfillments_override_audit' => [
                'fail_when' => 'NEW.is_compatibility_override = 1 AND NEW.override_approved_by_user_id IS NULL',
                'message' => 'chk_fulfillment_override_audit violated: override_approved_by_user_id is required when is_compatibility_override is enabled.',
            ],
        ]);

        Schema::createWithChecks('inventory_allocations', function (Blueprint $table): void {
            $table->id();
            $table->string('allocation_code', 32)->unique();
            $table->foreignId('blood_request_id')
                ->constrained('blood_requests')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('inventory_stock_id')
                ->constrained('inventory_stocks')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('blood_unit_id')
                ->constrained('blood_units')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('fulfillment_id')
                ->nullable()
                ->constrained('request_fulfillments')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->unsignedInteger('units_allocated')->default(1);
            $table->enum('allocation_status', ['Reserved', 'Dispatched', 'Released', 'Cancelled'])
                ->default('Reserved')
                ->index('idx_allocations_status');
            $table->boolean('active_unit_guard')
                ->nullable()
                ->storedAs("CASE WHEN allocation_status IN ('Reserved', 'Dispatched') THEN 1 ELSE NULL END");
            $table->foreignId('allocated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->dateTime('allocated_at');
            $table->dateTime('released_at')->nullable();
            $table->dateTime('dispatched_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['blood_request_id', 'allocation_status'], 'idx_allocations_req_status');
            $table->index('inventory_stock_id', 'idx_allocations_stock');
            $table->index(['blood_unit_id', 'allocation_status'], 'idx_allocations_unit_status');
            $table->index('fulfillment_id', 'idx_allocations_fulfillment');
            $table->unique(['blood_unit_id', 'active_unit_guard'], 'uq_alloc_active_unit');
        }, [
            'chk_allocations_units_allocated' => 'units_allocated = 1',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_allocations');
        Schema::dropMysqlRowChecks(['trg_request_fulfillments_override_audit']);
        Schema::dropIfExists('request_fulfillments');
        Schema::dropIfExists('blood_request_status_histories');
        Schema::dropIfExists('blood_requests');
        Schema::dropIfExists('blood_compatibility_rules');
        Schema::dropIfExists('hospital_users');
        Schema::dropIfExists('hospitals');
    }
};
