<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $level4Table = $this->level4CategoriesTable();

        Schema::table('users', function (Blueprint $table) use ($level4Table): void {
            if (! Schema::hasColumn('users', 'main_business_category_id')) {
                $this->addNullableReferenceColumn($table, 'main_business_category_id', 'circle_categories');
            }

            if (! Schema::hasColumn('users', 'business_category_id')) {
                $this->addNullableReferenceColumn($table, 'business_category_id', $level4Table);
            }

            if (! Schema::hasColumn('users', 'city_of_residence')) {
                $table->string('city_of_residence', 150)->nullable();
            }

            if (! Schema::hasColumn('users', 'referred_by_user_id')) {
                $this->addNullableReferenceColumn($table, 'referred_by_user_id', 'users');
            }
        });

        $this->addForeignKeyIfSafe('users', 'main_business_category_id', 'circle_categories', 'id');
        $this->addForeignKeyIfSafe('users', 'business_category_id', $level4Table, 'id');
        $this->addForeignKeyIfSafe('users', 'referred_by_user_id', 'users', 'id');
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            foreach (['main_business_category_id', 'business_category_id', 'referred_by_user_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $this->dropForeignIfExists('users', $column);
                }
            }

            foreach (['main_business_category_id', 'business_category_id', 'city_of_residence', 'referred_by_user_id'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }


    private function level4CategoriesTable(): string
    {
        foreach (['level4_categories', 'circle_category_level4'] as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return 'level4_categories';
    }

    private function addNullableReferenceColumn(Blueprint $table, string $column, string $referencedTable): void
    {
        $referencedType = $this->columnDataType($referencedTable, 'id');

        if ($referencedType === null) {
            $table->unsignedInteger($column)->nullable();
            return;
        }

        if ($referencedType === 'uuid') {
            $table->uuid($column)->nullable();
            return;
        }

        if (in_array($referencedType, ['bigint', 'bigserial'], true)) {
            $table->unsignedBigInteger($column)->nullable();
            return;
        }

        if (in_array($referencedType, ['integer', 'serial'], true)) {
            $table->unsignedInteger($column)->nullable();
            return;
        }

        $table->uuid($column)->nullable();
    }

    private function addForeignKeyIfSafe(string $table, string $column, string $referencedTable, string $referencedColumn): void
    {
        if (! Schema::hasTable($referencedTable) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        if ($this->columnDataType($table, $column) !== $this->columnDataType($referencedTable, $referencedColumn)) {
            return;
        }

        $constraint = $table . '_' . $column . '_foreign';
        if ($this->foreignKeyExists($constraint)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $referencedColumn): void {
            $blueprint->foreign($column)
                ->references($referencedColumn)
                ->on($referencedTable)
                ->nullOnDelete();
        });
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        $constraint = $table . '_' . $column . '_foreign';
        if (! $this->foreignKeyExists($constraint)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropForeign([$column]);
        });
    }

    private function columnDataType(string $table, string $column): ?string
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return null;
        }

        $schema = config('database.connections.' . config('database.default') . '.schema', 'public');

        $row = DB::table('information_schema.columns')
            ->where('table_schema', $schema)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->select(['data_type'])
            ->first();

        return $row?->data_type;
    }

    private function foreignKeyExists(string $constraint): bool
    {
        $schema = config('database.connections.' . config('database.default') . '.schema', 'public');

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $schema)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
