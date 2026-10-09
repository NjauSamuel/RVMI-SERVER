<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Models\InventoryCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => InventoryCategory::query()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:inventory_categories,name']]);

        return response()->json(['data' => InventoryCategory::query()->create($data)], 201);
    }

    public function destroy(InventoryCategory $category): JsonResponse
    {
        $category->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}

