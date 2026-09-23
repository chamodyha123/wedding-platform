<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminCategoryResource;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $categories = ServiceCategory::query()->withCount('services')
            ->when(array_key_exists('is_active', $validated) && $validated['is_active'] !== null, fn ($query) => $query->where('is_active', $validated['is_active']))
            ->orderBy('name')->orderBy('id')->paginate($validated['per_page'] ?? 15);

        return response()->json(['categories' => AdminCategoryResource::collection($categories), 'pagination' => $this->paginationData($categories)]);
    }

    public function show(int $id): JsonResponse
    {
        $category = ServiceCategory::query()->withCount('services')->find($id);
        if (! $category) {
            return response()->json(['message' => 'Service category not found.'], 404);
        }

        return response()->json(['category' => new AdminCategoryResource($category)]);
    }

    private function paginationData($categories): array
    {
        return ['current_page' => $categories->currentPage(), 'per_page' => $categories->perPage(), 'total' => $categories->total(), 'last_page' => $categories->lastPage()];
    }
}
