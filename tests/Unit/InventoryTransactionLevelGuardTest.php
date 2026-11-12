<?php

namespace Tests\Unit;

use App\Models\BloodUnit;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class InventoryTransactionLevelGuardTest extends TestCase
{
    public function test_internal_locked_bucket_primitives_reject_calls_outside_an_active_database_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());

        $inventoryService = app(InventoryService::class);
        $stock = new InventoryStock(['blood_group' => 'O+', 'blood_component_id' => 1]);
        $unit = new BloodUnit(['blood_group' => 'O+', 'blood_component_id' => 1, 'unit_status' => 'Available']);
        $actor = new User(['name' => 'Staff User']);
        $now = CarbonImmutable::now();

        $emptyUnits = new EloquentCollection;
        $oneUnit = new EloquentCollection([$unit]);

        try {
            $inventoryService->reserveLockedBucketUnits($stock, $oneUnit, $actor, 'Should fail.', 'blood_request', 1, $now);
            $this->fail('Expected LogicException when reserveLockedBucketUnits runs outside DB::transaction.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('active database transaction', $exception->getMessage());
        }

        try {
            $inventoryService->releaseLockedBucketUnits($stock, $oneUnit, $actor, 'Should fail.', 'blood_request', 1, $now);
            $this->fail('Expected LogicException when releaseLockedBucketUnits runs outside DB::transaction.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('active database transaction', $exception->getMessage());
        }

        try {
            $inventoryService->dispatchLockedBucketUnits($stock, $emptyUnits, $oneUnit, $actor, 'Should fail.', 1, $now);
            $this->fail('Expected LogicException when dispatchLockedBucketUnits runs outside DB::transaction.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('active database transaction', $exception->getMessage());
        }
    }
}
