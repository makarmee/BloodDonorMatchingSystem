<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table): void {
            $table->id();
            $table->string('employee_id', 64)->unique();
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('full_name', 150);
            $table->string('professional_role', 64)->index('idx_staff_professional_role');
            $table->string('department', 100)->index('idx_staff_department');
            $table->string('phone', 32);
            $table->string('email', 191)->nullable()->unique();
            $table->enum('status', ['Active', 'Inactive'])->default('Active')->index('idx_staff_status');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
