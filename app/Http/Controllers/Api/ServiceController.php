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
     * Create a new service for the authenticated provider.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
         * Additional authorization check.
         *
         * The route will also be protected by:
         * auth:sanctum
         * role:service_provider
         */
        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can create services.',
            ], 403);
        }

        /*
         * Find the business profile belonging
         * to the authenticated provider.
         */
        $provider = $user->serviceProvider()
            ->with('categories')
            ->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        /*
         * Suspended or inactive providers
         * cannot create marketplace services.
         */
        if (!$provider->is_active) {
            return response()->json([
                'message' =>
                    'Your provider account is inactive and cannot create services.',
            ], 403);
        }

        /*
         * Only verified providers can publish/create
         * marketplace services.
         */
        if ($provider->verification_status !== 'verified') {
            return response()->json([
                'message' =>
                    'Your business must be verified before you can create services.',
            ], 403);
        }

        /*
         * Validate service input.
         */
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

        /*
         * Ensure the selected service category is
         * actually attached to this provider.
         */
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

        /*
         * Create the service.
         */
        $service = Service::create([
            'service_provider_id' => $provider->id,

            'service_category_id' =>
                $validated['service_category_id'],

            'name' => $validated['name'],

            'slug' => $this->generateUniqueSlug(
                $provider->id,
                $validated['name']
            ),

            'description' =>
                $validated['description'] ?? null,

            'status' =>
                $validated['status'] ?? 'draft',

            /*
             * Providers cannot directly mark
             * their own service as featured.
             *
             * Featured status can later be controlled
             * by admin/advertisement logic.
             */
            'is_featured' => false,
        ]);

        return response()->json([
            'message' => 'Service created successfully.',

            'service' => $service->load([
                'category:id,name,slug',
            ]),
        ], 201);
    }

    /**
     * Generate a unique service slug
     * within one provider.
     */
    private function generateUniqueSlug(
        int $providerId,
        string $serviceName
    ): string {
        $slug = Str::slug($serviceName);

        if ($slug === '') {
            $slug = 'service';
        }

        $originalSlug = $slug;

        $counter = 1;

        while (
            Service::where(
                'service_provider_id',
                $providerId
            )
                ->where(
                    'slug',
                    $slug
                )
                ->exists()
        ) {
            $slug = $originalSlug . '-' . $counter;

            $counter++;
        }

        return $slug;
    }
}