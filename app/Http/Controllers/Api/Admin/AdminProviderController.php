<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminProviderResource;
use App\Models\ServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'verification_status' => ['nullable', 'string', 'in:pending,under_review,verified,rejected,changes_requested,suspended'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $providers = ServiceProvider::query()
            ->with(['user:id,name,email', 'categories:id,name,slug'])
            ->withCount(['services', 'bookings', 'reviews'])
            ->withAvg('reviews', 'rating')
            ->when($validated['verification_status'] ?? null, fn ($query, string $status) => $query->where('verification_status', $status))
            ->when(array_key_exists('is_active', $validated) && $validated['is_active'] !== null, fn ($query) => $query->where('is_active', $validated['is_active']))
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q->whereRaw('LOWER(business_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(business_slug) LIKE ?', [$term])
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(email) LIKE ?', [$term])));
            })
            ->latest('created_at')->latest('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'providers' => AdminProviderResource::collection($providers),
            'pagination' => $this->paginationData($providers),
        ]);
    }

    private function paginationData($providers): array
    {
        return ['current_page' => $providers->currentPage(), 'per_page' => $providers->perPage(), 'total' => $providers->total(), 'last_page' => $providers->lastPage()];
    }
}
