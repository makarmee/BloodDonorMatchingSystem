<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE_NAME = 'inventory_transactions';

    private const string CONSTRAINT_NAME = 'chk_transactions_units_delta';

    private const string UPDATED_CHECK_EXPRESSION = "units_delta <> 0 OR (operation_type = 'Discarded' AND available_after = available_before AND reserved_after = reserved_before)";

    private const string PREVIOUS_CHECK_EXPRESSION = 'units_delta <> 0';

    /**
     * Ordered columns preserved during SQLite table rebuilds.
     *
     * @var list<string>
     */
    private const array COLUMNS = [
        'id',
        'transaction_code',
        'inventory_stock_id',
        'blood_unit_id',
        'operation_type',
        'units_delta',
        'available_before',
        'available_after',
        'reserved_before',
        'reserved_after',
        'reference_type',
        'reference_id',
        'performed_by_user_id',
        'actor_name',
        'description',
        'occurred_at',
        'created_at',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replaceUnitsDeltaCheckConstraint(self::UPDATED_CHECK_EXPRESSION);
    }

    /**
     * Reverse the migrations without modifying any existing transaction rows.
     */
    public function down(): void
    {
        $this->replaceUnitsDeltaCheckConstraint(self::PREVIOUS_CHECK_EXPRESSION);
    }

    /**
     * Replace `chk_transactions_units_delta` using the active database driver's safe DDL path.
     */
    private function replaceUnitsDeltaCheckConstraint(string $expression): void
    {
        if (! Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $this->replaceMysqlCheckConstraint($connection, $expression);

            return;
        }

        if ($driver === 'sqlite') {
            $this->replaceSqliteCheckConstraint($connection, $expression);

            return;
        }

        throw new RuntimeException("Unsupported database driver [{$driver}] for updating [".self::CONSTRAINT_NAME.'].');
    }

    /**
     * Atomically swap `chk_transactions_units_delta` on MySQL 8.4+.
     */
    private function replaceMysqlCheckConstraint(Connection $connection, string $expression): void
    {
        $wrappedTable = $connection->getSchemaGrammar()->wrapTable(self::TABLE_NAME);
        $escapedName = str_replace('`', '``', self::CONSTRAINT_NAME);

        $existingConstraint = $connection->selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [self::TABLE_NAME, self::CONSTRAINT_NAME, 'CHECK']
        );

        if ($existingConstraint !== null) {
            $connection->statement(
                "ALTER TABLE {$wrappedTable} DROP CHECK `{$escapedName}`, ADD CONSTRAINT `{$escapedName}` CHECK ({$expression})"
            );

            return;
        }

        $connection->statement(
            "ALTER TABLE {$wrappedTable} ADD CONSTRAINT `{$escapedName}` CHECK ({$expression})"
        );
    }

    /**
     * Rebuild `inventory_transactions` inside a SQLite transaction to update the table CHECK constraint.
     */
    private function replaceSqliteCheckConstraint(Connection $connection, string $expression): void
    {
        $existingTableSql = $connection->selectOne(
            'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
            ['table', self::TABLE_NAME]
        )?->sql;

        $expectedClause = sprintf('constraint "%s" check (%s)', self::CONSTRAINT_NAME, $expression);

        if (is_string($existingTableSql) && str_contains(strtolower($existingTableSql), strtolower($expectedClause))) {
            return;
        }

        $tempTable = '__tmp_inventory_transactions_check_migration';
        $columnList = implode(', ', array_map(
            static fn (string $column): string => '"'.str_replace('"', '""', $column).'"',
            self::COLUMNS
        ));

        $connection->transaction(function () use ($connection, $expression, $tempTable, $columnList): void {
            $connection->statement("DROP TABLE IF EXISTS \"{$tempTable}\"");
            $connection->statement("CREATE TABLE \"{$tempTable}\" AS SELECT {$columnList} FROM \"".self::TABLE_NAME.'"');

            Schema::drop(self::TABLE_NAME);

            Schema::createWithChecks(self::TABLE_NAME, function (Blueprint $table): void {
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
                self::CONSTRAINT_NAME => $expression,
                'chk_transactions_available_before' => 'available_before >= 0',
                'chk_transactions_available_after' => 'available_after >= 0',
                'chk_transactions_reserved_before' => 'reserved_before >= 0',
                'chk_transactions_reserved_after' => 'reserved_after >= 0',
            ]);

            $connection->statement('INSERT INTO "'.self::TABLE_NAME."\" ({$columnList}) SELECT {$columnList} FROM \"{$tempTable}\"");
            $connection->statement("DROP TABLE \"{$tempTable}\"");
        });
    }
};
