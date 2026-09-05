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
         *
         * Only search for the service inside
         * this authenticated provider.
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

            'name' =>
                $validated['name'],

            'slug' =>
                $this->generateUniqueSlug(
                    $service->id,
                    $validated['name']
                ),

            'description' =>
                $validated['description'] ?? null,

            'price' =>
                $validated['price'],

            'duration_minutes' =>
                $validated['duration_minutes'] ?? null,

            'status' =>
                $validated['status'] ?? 'draft',

            /*
             * Provider cannot feature package directly.
             */
            'is_featured' => false,
        ]);

        return response()->json([
            'message' =>
                'Service package created successfully.',

            'package' =>
                $package->load([
                    'service:id,name,slug',
                ]),
        ], 201);
    }

    /**
     * Generate a unique package slug
     * within one service.
     */
    private function generateUniqueSlug(
        int $serviceId,
        string $packageName
    ): string {
        $slug = Str::slug(
            $packageName
        );

        if ($slug === '') {
            $slug = 'package';
        }

        $originalSlug = $slug;

        $counter = 1;

        while (
            ServicePackage::where(
                'service_id',
                $serviceId
            )
                ->where(
                    'slug',
                    $slug
                )
                ->exists()
        ) {
            $slug =
                $originalSlug .
                '-' .
                $counter;

            $counter++;
        }

        return $slug;
    }
}