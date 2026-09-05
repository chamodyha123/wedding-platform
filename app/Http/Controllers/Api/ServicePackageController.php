<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServicePackageController extends Controller
{
    /**
     * Get all packages belonging to one of the
     * authenticated provider's services.
     */
    public function index(
        Request $request,
        int $serviceId
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can access packages.',
            ], 403);
        }

        $provider = $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        /*
         * Ownership protection.
         *
         * We search for the service through the
         * authenticated provider.
         */
        $service = $provider->services()
            ->where('id', $serviceId)
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $packages = $service->packages()
            ->latest()
            ->get();

        return response()->json([
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'slug' => $service->slug,
            ],

            'packages' => $packages,
        ]);
    }

    /**
     * Create a package under a service
     * belonging to the authenticated provider.
     */
    public function store(
        Request $request,
        int $serviceId
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can create packages.',
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
                    'Your provider account is inactive and cannot create packages.',
            ], 403);
        }

        if ($provider->verification_status !== 'verified') {
            return response()->json([
                'message' =>
                    'Your business must be verified before you can create packages.',
            ], 403);
        }

        /*
         * Ownership protection.
         */
        $service = $provider->services()
            ->where('id', $serviceId)
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'duration_minutes' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                'in:draft,published,inactive',
            ],
        ]);

        $package = ServicePackage::create([
            'service_id' => $service->id,

            'name' => $validated['name'],

            'slug' => $this->generateUniqueSlug(
                $service->id,
                $validated['name']
            ),

            'description' =>
                $validated['description'] ?? null,

            'price' => $validated['price'],

            'duration_minutes' =>
                $validated['duration_minutes'] ?? null,

            'status' =>
                $validated['status'] ?? 'draft',

            /*
             * Providers cannot feature their
             * own packages directly.
             */
            'is_featured' => false,
        ]);

        return response()->json([
            'message' =>
                'Service package created successfully.',

            'package' => $package->load([
                'service:id,name,slug',
            ]),
        ], 201);
    }

    /**
     * Get one package belonging to one of the
     * authenticated provider's services.
     */
    public function show(
        Request $request,
        int $serviceId,
        int $packageId
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can access packages.',
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
            ->where('id', $serviceId)
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        /*
         * Search through this service's packages.
         *
         * This prevents access to packages
         * belonging to another service/provider.
         */
        $package = $service->packages()
            ->where('id', $packageId)
            ->first();

        if (!$package) {
            return response()->json([
                'message' => 'Service package not found.',
            ], 404);
        }

        return response()->json([
            'package' => $package->load([
                'service:id,name,slug',
            ]),
        ]);
    }

    /**
     * Update a service package.
     */
    public function update(
        Request $request,
        int $serviceId,
        int $packageId
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can update packages.',
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
                    'Your provider account is inactive and cannot update packages.',
            ], 403);
        }

        if ($provider->verification_status !== 'verified') {
            return response()->json([
                'message' =>
                    'Your business must be verified before you can update packages.',
            ], 403);
        }

        $service = $provider->services()
            ->where('id', $serviceId)
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $package = $service->packages()
            ->where('id', $packageId)
            ->first();

        if (!$package) {
            return response()->json([
                'message' => 'Service package not found.',
            ], 404);
        }

        $validated = $request->validate([
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

            'price' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
            ],

            'duration_minutes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],

            'status' => [
                'sometimes',
                'required',
                'string',
                'in:draft,published,inactive',
            ],
        ]);

        /*
         * Regenerate the slug only when
         * the package name changes.
         */
        if (
            isset($validated['name']) &&
            $validated['name'] !== $package->name
        ) {
            $package->slug =
                $this->generateUniqueSlug(
                    $service->id,
                    $validated['name'],
                    $package->id
                );
        }

        $package->fill($validated);

        $package->save();

        return response()->json([
            'message' =>
                'Service package updated successfully.',

            'package' => $package->load([
                'service:id,name,slug',
            ]),
        ]);
    }

    /**
     * Soft delete a service package.
     */
    public function destroy(
        Request $request,
        int $serviceId,
        int $packageId
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can delete packages.',
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
                    'Your provider account is inactive and cannot delete packages.',
            ], 403);
        }

        $service = $provider->services()
            ->where('id', $serviceId)
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $package = $service->packages()
            ->where('id', $packageId)
            ->first();

        if (!$package) {
            return response()->json([
                'message' => 'Service package not found.',
            ], 404);
        }

        /*
         * ServicePackage uses SoftDeletes,
         * so this sets deleted_at rather
         * than permanently removing the row.
         */
        $package->delete();

        return response()->json([
            'message' =>
                'Service package deleted successfully.',
        ]);
    }

    /**
     * Generate a unique package slug
     * within one service.
     *
     * $ignorePackageId is used during updates
     * so the package does not conflict with itself.
     */
    private function generateUniqueSlug(
        int $serviceId,
        string $packageName,
        ?int $ignorePackageId = null
    ): string {
        $slug = Str::slug($packageName);

        if ($slug === '') {
            $slug = 'package';
        }

        $originalSlug = $slug;

        $counter = 1;

        while (true) {
            $query = ServicePackage::where(
                'service_id',
                $serviceId
            )
                ->where(
                    'slug',
                    $slug
                );

            if ($ignorePackageId !== null) {
                $query->where(
                    'id',
                    '!=',
                    $ignorePackageId
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