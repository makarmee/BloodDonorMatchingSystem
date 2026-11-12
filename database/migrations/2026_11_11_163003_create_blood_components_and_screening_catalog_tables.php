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
        Schema::createWithChecks('blood_components', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('default_volume_ml');
            $table->unsignedSmallInteger('shelf_life_days');
            $table->string('storage_requirements', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        }, [
            'chk_components_default_volume' => 'default_volume_ml > 0',
            'chk_components_shelf_life' => 'shelf_life_days > 0',
        ]);

        Schema::create('screening_test_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('component_screening_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('blood_component_id')
                ->constrained('blood_components')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('test_type_id')
                ->constrained('screening_test_types')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index('test_type_id', 'idx_comp_screen_req_test_type');
            $table->unique(['blood_component_id', 'test_type_id'], 'uq_component_screening_requirement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('component_screening_requirements');
        Schema::dropIfExists('screening_test_types');
        Schema::dropIfExists('blood_components');
    }
};
