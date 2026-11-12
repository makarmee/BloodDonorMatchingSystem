<?php

namespace Tests\Feature;

use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryTransactionsCheckMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_migration_installs_and_enforces_updated_units_delta_check_constraint(): void
    {
        $stock = InventoryStock::factory()->create([
            'available_units' => 1,
            'reserved_units' => 0,
        ]);
        $actor = User::factory()->create(['is_active' => true]);

        $discard = $this->createTransaction($stock, $actor, [
            'transaction_code' => 'TXN-FRESH-001',
            'operation_type' => 'Discarded',
            'units_delta' => 0,
            'available_before' => 1,
            'available_after' => 1,
            'reserved_before' => 0,
            'reserved_after' => 0,
        ]);

        $this->assertSame(0, $discard->units_delta);

        $this->assertQueryRejected(function () use ($stock, $actor): void {
            $this->createTransaction($stock, $actor, [
                'transaction_code' => 'TXN-FRESH-002',
                'operation_type' => 'Stock In',
                'units_delta' => 0,
                'available_before' => 1,
                'available_after' => 1,
                'reserved_before' => 0,
                'reserved_after' => 0,
            ]);
        });

        $this->assertQueryRejected(function () use ($stock, $actor): void {
            $this->createTransaction($stock, $actor, [
                'transaction_code' => 'TXN-FRESH-003',
                'operation_type' => 'Discarded',
                'units_delta' => 0,
                'available_before' => 1,
                'available_after' => 2,
                'reserved_before' => 0,
                'reserved_after' => 0,
            ]);
        });
    }

    public function test_forward_migration_upgrades_legacy_constraint_preserving_existing_rows_and_supports_clean_rollback(): void
    {
        $stock = InventoryStock::factory()->create([
            'available_units' => 1,
            'reserved_units' => 0,
        ]);
        $actor = User::factory()->create(['is_active' => true]);

        $existing = $this->createTransaction($stock, $actor, [
            'transaction_code' => 'TXN-LEGACY-001',
            'operation_type' => 'Stock In',
            'units_delta' => 1,
            'available_before' => 0,
            'available_after' => 1,
            'reserved_before' => 0,
            'reserved_after' => 0,
            'description' => 'Existing ledger row prior to migration upgrade.',
        ]);

        $migration = $this->loadForwardMigration();

        // Roll back to legacy check constraint (units_delta <> 0) without losing or altering existing rows.
        $migration->down();

        $this->assertSame('TXN-LEGACY-001', $existing->fresh()->transaction_code);
        $this->assertSame(1, InventoryTransaction::query()->count());

        $this->assertQueryRejected(function () use ($stock, $actor): void {
            $this->createTransaction($stock, $actor, [
                'transaction_code' => 'TXN-LEGACY-002',
                'operation_type' => 'Discarded',
                'units_delta' => 0,
                'available_before' => 1,
                'available_after' => 1,
                'reserved_before' => 0,
                'reserved_after' => 0,
            ]);
        });

        // Re-apply forward migration (simulating upgrading an already-migrated database).
        $migration->up();

        $preserved = $existing->fresh();
        $this->assertNotNull($preserved);
        $this->assertSame($existing->id, $preserved->id);
        $this->assertSame('TXN-LEGACY-001', $preserved->transaction_code);
        $this->assertSame('Existing ledger row prior to migration upgrade.', $preserved->description);

        $discard = $this->createTransaction($stock, $actor, [
            'transaction_code' => 'TXN-UPGRADED-003',
            'operation_type' => 'Discarded',
            'units_delta' => 0,
            'available_before' => 1,
            'available_after' => 1,
            'reserved_before' => 0,
            'reserved_after' => 0,
        ]);

        $this->assertSame(0, $discard->units_delta);
        $this->assertSame(2, InventoryTransaction::query()->count());

        $indexNames = collect(DB::select("PRAGMA index_list('inventory_transactions')"))
            ->pluck('name')
            ->all();

        $this->assertContains('idx_inv_txn_operation_type', $indexNames);
        $this->assertContains('idx_inv_txn_stock_occurred', $indexNames);
        $this->assertContains('idx_inv_txn_blood_unit', $indexNames);
        $this->assertContains('idx_inv_txn_reference', $indexNames);
        $this->assertContains('idx_inv_txn_occurred_at', $indexNames);
    }

    public function test_down_fails_safely_without_modifying_rows_when_zero_delta_discard_exists(): void
    {
        $stock = InventoryStock::factory()->create([
            'available_units' => 0,
            'reserved_units' => 0,
        ]);
        $actor = User::factory()->create(['is_active' => true]);

        $discard = $this->createTransaction($stock, $actor, [
            'transaction_code' => 'TXN-HOLD-001',
            'operation_type' => 'Discarded',
            'units_delta' => 0,
            'available_before' => 0,
            'available_after' => 0,
            'reserved_before' => 0,
            'reserved_after' => 0,
        ]);

        $migration = $this->loadForwardMigration();

        $this->assertQueryRejected(static function () use ($migration): void {
            $migration->down();
        });

        $stillPresent = $discard->fresh();
        $this->assertNotNull($stillPresent);
        $this->assertSame(0, $stillPresent->units_delta);
        $this->assertSame(1, InventoryTransaction::query()->count());
    }

    private function loadForwardMigration(): Migration
    {
        $migration = require database_path('migrations/2026_11_12_090030_update_chk_transactions_units_delta_on_inventory_transactions_table.php');
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTransaction(InventoryStock $stock, User $actor, array $overrides): InventoryTransaction
    {
        return InventoryTransaction::query()->create(array_merge([
            'inventory_stock_id' => $stock->id,
            'blood_unit_id' => null,
            'operation_type' => 'Stock In',
            'units_delta' => 1,
            'available_before' => 0,
            'available_after' => 1,
            'reserved_before' => 0,
            'reserved_after' => 0,
            'reference_type' => 'manual',
            'reference_id' => 1,
            'performed_by_user_id' => $actor->id,
            'actor_name' => $actor->name,
            'description' => 'Migration constraint test transaction.',
            'occurred_at' => now(),
        ], $overrides));
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected QueryException due to chk_transactions_units_delta enforcement.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
