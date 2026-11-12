<?php

namespace App\Providers;

use App\Models\BloodRequest;
use App\Policies\BloodRequestPolicy;
use Closure;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(BloodRequest::class, BloodRequestPolicy::class);

        if (! Builder::hasMacro('createWithChecks')) {
            /**
             * Create a table while enforcing named CHECK constraints identically across MySQL and SQLite.
             *
             * @param  array<string, string>  $checks
             */
            Builder::macro('createWithChecks', function (string $table, Closure $callback, array $checks): void {
                /** @var Builder $this */
                $connection = $this->getConnection();

                if ($connection->getDriverName() === 'sqlite') {
                    $blueprint = $this->createBlueprint($table);
                    $blueprint->create();
                    $callback($blueprint);

                    $statements = $blueprint->toSql();
                    $constraintClauses = collect($checks)
                        ->map(fn (string $expression, string $name): string => sprintf('constraint "%s" check (%s)', str_replace('"', '""', $name), $expression))
                        ->implode(', ');

                    if ($constraintClauses !== '' && isset($statements[0])) {
                        $closingParenIndex = strrpos($statements[0], ')');

                        if ($closingParenIndex !== false) {
                            $statements[0] = substr($statements[0], 0, $closingParenIndex)
                                .', '.$constraintClauses
                                .substr($statements[0], $closingParenIndex);
                        }
                    }

                    foreach ($statements as $statement) {
                        $connection->statement($statement);
                    }

                    return;
                }

                $this->create($table, $callback);

                $grammar = $connection->getSchemaGrammar();
                $wrappedTable = $grammar->wrapTable($table);

                foreach ($checks as $name => $expression) {
                    $escapedName = str_replace('`', '``', $name);
                    $connection->statement("ALTER TABLE {$wrappedTable} ADD CONSTRAINT `{$escapedName}` CHECK ({$expression})");
                }
            });
        }

        if (! Builder::hasMacro('enforceMysqlRowChecks')) {
            /**
             * Enforce row invariants via MySQL BEFORE INSERT/UPDATE triggers for rules referencing FK columns.
             *
             * @param  array<string, array{fail_when: string, message: string}>  $guards
             */
            Builder::macro('enforceMysqlRowChecks', function (string $table, array $guards): void {
                /** @var Builder $this */
                $connection = $this->getConnection();

                if ($connection->getDriverName() !== 'mysql' || $guards === []) {
                    return;
                }

                $wrappedTable = $connection->getSchemaGrammar()->wrapTable($table);

                foreach (['bi' => 'BEFORE INSERT', 'bu' => 'BEFORE UPDATE'] as $suffix => $timing) {
                    foreach ($guards as $baseName => $guard) {
                        $triggerName = str_replace('`', '``', "{$baseName}_{$suffix}");
                        $message = str_replace("'", "''", $guard['message']);
                        $failWhen = $guard['fail_when'];

                        $connection->unprepared("DROP TRIGGER IF EXISTS `{$triggerName}`");
                        $connection->unprepared(
                            "CREATE TRIGGER `{$triggerName}` {$timing} ON {$wrappedTable} FOR EACH ROW "
                            ."BEGIN IF ({$failWhen}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END"
                        );
                    }
                }
            });
        }

        if (! Builder::hasMacro('dropMysqlRowChecks')) {
            /**
             * Drop BEFORE INSERT/UPDATE row guard triggers previously created on a MySQL table.
             *
             * @param  list<string>  $guardNames
             */
            Builder::macro('dropMysqlRowChecks', function (array $guardNames): void {
                /** @var Builder $this */
                $connection = $this->getConnection();

                if ($connection->getDriverName() !== 'mysql') {
                    return;
                }

                foreach ($guardNames as $baseName) {
                    foreach (['bi', 'bu'] as $suffix) {
                        $triggerName = str_replace('`', '``', "{$baseName}_{$suffix}");
                        $connection->unprepared("DROP TRIGGER IF EXISTS `{$triggerName}`");
                    }
                }
            });
        }
    }
}
