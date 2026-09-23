<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'provider_id' => ['nullable', 'integer', 'exists:service_providers,id'],
            'category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'status' => ['nullable', 'string', 'in:draft,published,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $services = $this->query()
            ->when($validated['provider_id'] ?? null, fn ($query, int $id) => $query->where('service_provider_id', $id))
            ->when($validated['category_id'] ?? null, fn ($query, int $id) => $query->where('service_category_id', $id))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(slug) LIKE ?', [$term]));
            })
            ->latest('created_at')->latest('id')->paginate($validated['per_page'] ?? 15);

        return response()->json(['services' => AdminServiceResource::collection($services), 'pagination' => $this->paginationData($services)]);
    }

    public function show(int $id): JsonResponse
    {
        $service = $this->query()->find($id);
        if (! $service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        return response()->json(['service' => new AdminServiceResource($service)]);
    }

    private function query()
    {
        return Service::query()->with(['provider:id,business_name,business_slug,verification_status,is_active', 'category:id,name,slug,is_active'])
            ->withCount(['packages', 'reviews'])->withAvg('reviews', 'rating');
    }

    private function paginationData($services): array
    {
        return ['current_page' => $services->currentPage(), 'per_page' => $services->perPage(), 'total' => $services->total(), 'last_page' => $services->lastPage()];
    }
}
