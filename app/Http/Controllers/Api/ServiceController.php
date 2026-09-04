<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceController extends Controller
{
    /**
     * Get all services belonging to the
     * authenticated service provider.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can access services.',
            ], 403);
        }

        $provider = $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        $services = $provider->services()
            ->with([
                'category:id,name,slug',
            ])
            ->latest()
            ->get();

        return response()->json([
            'services' => $services,
            'count' => $services->count(),
        ]);
    }

    /**
     * Get one service belonging to the
     * authenticated service provider.
     */
    public function show(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can access services.',
            ], 403);
        }

        $provider = $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        $service = $provider->services()
            ->with([
                'category:id,name,slug',
            ])
            ->where('id', $id)
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        return response()->json([
            'service' => $service,
        ]);
    }

    /**
     * Create a new service for the authenticated provider.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can create services.',
            ], 403);
        }

        $provider = $user->serviceProvider()
            ->with('categories')
            ->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        if (!$provider->is_active) {
            return response()->json([
                'message' =>
                    'Your provider account is inactive and cannot create services.',
            ], 403);
        }

        if ($provider->verification_status !== 'verified') {
            return response()->json([
                'message' =>
                    'Your business must be verified before you can create services.',
            ], 403);
        }

        $validated = $request->validate([
            'service_category_id' => [
                'required',
                'integer',
                'exists:service_categories,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                'in:draft,published,inactive',
            ],
        ]);

        $categoryBelongsToProvider = $provider->categories()
            ->where(
                'service_categories.id',
                $validated['service_category_id']
            )
            ->exists();

        if (!$categoryBelongsToProvider) {
            return response()->json([
                'message' =>
                    'You can only create services under categories assigned to your business.',
            ], 422);
        }

        $service = Service::create([
            'service_provider_id' => $provider->id,

            'service_category_id' =>
                $validated['service_category_id'],

            'name' =>
                $validated['name'],

            'slug' =>
                $this->generateUniqueSlug(
                    $provider->id,
                    $validated['name']
                ),

            'description' =>
                $validated['description'] ?? null,

            'status' =>
                $validated['status'] ?? 'draft',

            'is_featured' => false,
        ]);

        return response()->json([
            'message' =>
                'Service created successfully.',

            'service' =>
                $service->load([
                    'category:id,name,slug',
                ]),
        ], 201);
    }

    /**
     * Update a service belonging to the
     * authenticated provider.
     */
    public function update(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can update services.',
            ], 403);
        }

        $provider = $user->serviceProvider()
            ->with('categories')
            ->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        if (!$provider->is_active) {
            return response()->json([
                'message' =>
                    'Your provider account is inactive and cannot update services.',
            ], 403);
        }

        if ($provider->verification_status !== 'verified') {
            return response()->json([
                'message' =>
                    'Your business must be verified before you can update services.',
            ], 403);
        }

        $service = $provider->services()
            ->where('id', $id)
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $validated = $request->validate([
            'service_category_id' => [
                'sometimes',
                'required',
                'integer',
                'exists:service_categories,id',
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                'in:draft,published,inactive',
            ],
        ]);

        if (isset($validated['service_category_id'])) {
            $categoryBelongsToProvider =
                $provider->categories()
                    ->where(
                        'service_categories.id',
                        $validated['service_category_id']
                    )
                    ->exists();

            if (!$categoryBelongsToProvider) {
                return response()->json([
                    'message' =>
                        'You can only assign services to categories belonging to your business.',
                ], 422);
            }
        }

        if (
            isset($validated['name']) &&
            $validated['name'] !== $service->name
        ) {
            $service->slug =
                $this->generateUniqueSlug(
                    $provider->id,
                    $validated['name'],
                    $service->id
                );
        }

        $service->fill($validated);

        $service->save();

        return response()->json([
            'message' =>
                'Service updated successfully.',

            'service' =>
                $service->load([
                    'category:id,name,slug',
                ]),
        ]);
    }

    /**
     * Soft delete a service belonging to the
     * authenticated provider.
     */
    public function destroy(
        Request $request,
        int $id
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can delete services.',
            ], 403);
        }

        $provider = $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        if (!$provider->is_active) {
            return response()->json([
                'message' =>
                    'Your provider account is inactive and cannot delete services.',
            ], 403);
        }

        if ($provider->verification_status !== 'verified') {
            return response()->json([
                'message' =>
                    'Your business must be verified before you can delete services.',
            ], 403);
        }

        /*
         * Ownership protection:
         * only search inside the current provider's services.
         */
        $service = $provider->services()
            ->where('id', $id)
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        /*
         * Because Service uses SoftDeletes,
         * this sets deleted_at instead of
         * permanently removing the row.
         */
        $service->delete();

        return response()->json([
            'message' =>
                'Service deleted successfully.',
        ]);
    }

    /**
     * Generate a unique service slug
     * within one provider.
     */
    private function generateUniqueSlug(
        int $providerId,
        string $serviceName,
        ?int $ignoreServiceId = null
    ): string {
        $slug = Str::slug(
            $serviceName
        );

        if ($slug === '') {
            $slug = 'service';
        }

        $originalSlug = $slug;

        $counter = 1;

        while (true) {
            $query = Service::where(
                'service_provider_id',
                $providerId
            )
                ->where(
                    'slug',
                    $slug
                );

            if ($ignoreServiceId !== null) {
                $query->where(
                    'id',
                    '!=',
                    $ignoreServiceId
                );
            }

            if (!$query->exists()) {
                break;
            }

            $slug =
                $originalSlug .
                '-' .
                $counter;

            $counter++;
        }

        return $slug;
    }
}