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
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 64)->unique();
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 100)->unique();
            $table->string('module', 64)->index('idx_permissions_module');
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('role_user', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('role_id')
                ->constrained('roles')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('assigned_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('assigned_at')->useCurrent();

            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('permission_id')
                ->constrained('permissions')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->timestamp('created_at')->nullable();

            $table->primary(['permission_id', 'role_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
