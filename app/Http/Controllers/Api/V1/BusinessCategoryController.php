<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusinessCategoryController extends BaseApiController
{
    public function main(): JsonResponse
    {
        if (! Schema::hasTable('circle_categories')) {
            return $this->error('Circle categories table not found.', 404);
        }

        $query = DB::table('circle_categories')
            ->select(['id', 'name'])
            ->where('level', 1);

        if (Schema::hasColumn('circle_categories', 'is_active')) {
            $query->where('is_active', true);
        }

        $items = $this->orderCategoryQuery($query, 'circle_categories')
            ->get()
            ->map(fn ($category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
            ])
            ->values();

        return $this->success($items, 'Main business categories fetched successfully.');
    }

    public function children(string $parentId): JsonResponse
    {
        if (! Schema::hasTable('circle_categories')) {
            return $this->error('Circle categories table not found.', 404);
        }

        $selfChildren = $this->selfReferencingChildren($parentId);
        if ($selfChildren->isNotEmpty()) {
            return $this->success($selfChildren, 'Business sub categories fetched successfully.');
        }

        $legacyChildren = $this->legacyLevelChildren($parentId);

        return $this->success($legacyChildren, 'Business sub categories fetched successfully.');
    }

    private function selfReferencingChildren(string $parentId)
    {
        if (! Schema::hasColumn('circle_categories', 'parent_id')) {
            return collect();
        }

        $query = DB::table('circle_categories')
            ->select(['id', 'name', 'level', 'parent_id'])
            ->where('parent_id', $parentId);

        if (Schema::hasColumn('circle_categories', 'is_active')) {
            $query->where('is_active', true);
        }

        return $this->orderCategoryQuery($query, 'circle_categories')
            ->get()
            ->map(fn ($category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'level' => (int) $category->level,
                'parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null,
            ])
            ->values();
    }

    private function legacyLevelChildren(string $parentId)
    {
        $parent = DB::table('circle_categories')->where('id', $parentId)->first();
        if ($parent) {
            return $this->legacyLevel2Children($parentId);
        }

        $level2Table = $this->level2CategoriesTable();
        if (Schema::hasTable($level2Table)) {
            $level2 = DB::table($level2Table)->where('id', $parentId)->first();
            if ($level2) {
                return $this->legacyLevel3Children($parentId);
            }
        }

        $level3Table = $this->level3CategoriesTable();
        if (Schema::hasTable($level3Table)) {
            $level3 = DB::table($level3Table)->where('id', $parentId)->first();
            if ($level3) {
                return $this->legacyLevel4Children($parentId);
            }
        }

        return collect();
    }

    private function legacyLevel2Children(string $parentId)
    {
        $table = $this->level2CategoriesTable();
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $query = DB::table($table)
            ->select(['id', 'name', 'circle_category_id'])
            ->where('circle_category_id', $parentId);

        if (Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }

        return $this->orderCategoryQuery($query, $table)
            ->get()
            ->map(fn ($category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'level' => 2,
                'parent_id' => (int) $category->circle_category_id,
            ])
            ->values();
    }

    private function legacyLevel3Children(string $parentId)
    {
        $table = $this->level3CategoriesTable();
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $query = DB::table($table)
            ->select(['id', 'name', 'level2_id'])
            ->where('level2_id', $parentId);

        if (Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }

        return $this->orderCategoryQuery($query, $table)
            ->get()
            ->map(fn ($category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'level' => 3,
                'parent_id' => (int) $category->level2_id,
            ])
            ->values();
    }

    private function legacyLevel4Children(string $parentId)
    {
        $table = $this->level4CategoriesTable();
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $query = DB::table($table)
            ->select(['id', 'name', 'level3_id'])
            ->where('level3_id', $parentId);

        if (Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }

        return $this->orderCategoryQuery($query, $table)
            ->get()
            ->map(fn ($category): array => [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'level' => 4,
                'parent_id' => (int) $category->level3_id,
            ])
            ->values();
    }


    private function level2CategoriesTable(): string
    {
        return $this->firstExistingTable(['level2_categories', 'circle_category_level2']);
    }

    private function level3CategoriesTable(): string
    {
        return $this->firstExistingTable(['level3_categories', 'circle_category_level3']);
    }

    private function level4CategoriesTable(): string
    {
        return $this->firstExistingTable(['level4_categories', 'circle_category_level4']);
    }

    private function firstExistingTable(array $tables): string
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }

        return $tables[0];
    }

    private function orderCategoryQuery($query, string $table)
    {
        if (Schema::hasColumn($table, 'sort_order')) {
            $query->orderBy('sort_order');
        }

        return $query->orderBy('name')->orderBy('id');
    }
}
