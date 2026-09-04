<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceProviderController extends Controller
{
    /**
     * Create a service provider business profile.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can create a business profile.',
            ], 403);
        }

        if ($user->serviceProvider()->exists()) {
            return response()->json([
                'message' =>
                    'You already have a service provider profile.',
            ], 409);
        }

        $validated = $request->validate([
            'business_name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'whatsapp' => [
                'nullable',
                'string',
                'max:30',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'website' => [
                'nullable',
                'url',
                'max:255',
            ],

            'address' => [
                'nullable',
                'string',
                'max:500',
            ],

            'city' => [
                'nullable',
                'string',
                'max:100',
            ],

            'district' => [
                'nullable',
                'string',
                'max:100',
            ],

            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'category_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'category_ids.*' => [
                'integer',
                'distinct',
                'exists:service_categories,id',
            ],
        ]);

        $provider = ServiceProvider::create([
            'user_id' => $user->id,

            'business_name' =>
                $validated['business_name'],

            'business_slug' =>
                $this->generateUniqueSlug(
                    $validated['business_name']
                ),

            'description' =>
                $validated['description'] ?? null,

            'phone' =>
                $validated['phone'] ?? null,

            'whatsapp' =>
                $validated['whatsapp'] ?? null,

            'email' =>
                $validated['email'] ?? null,

            'website' =>
                $validated['website'] ?? null,

            'address' =>
                $validated['address'] ?? null,

            'city' =>
                $validated['city'] ?? null,

            'district' =>
                $validated['district'] ?? null,

            'latitude' =>
                $validated['latitude'] ?? null,

            'longitude' =>
                $validated['longitude'] ?? null,

            'verification_status' => 'pending',

            'is_active' => true,
        ]);

        $provider->categories()->sync(
            $validated['category_ids']
        );

        return response()->json([
            'message' =>
                'Business profile created successfully. Your profile is pending verification.',

            'provider' =>
                $provider->load('categories'),
        ], 201);
    }

    /**
     * Get the authenticated service provider profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can access this profile.',
            ], 403);
        }

        $provider = $user->serviceProvider()
            ->with('categories')
            ->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found.',
            ], 404);
        }

        return response()->json([
            'provider' => $provider,
        ]);
    }

    /**
     * Update the authenticated service provider business profile.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can update a business profile.',
            ], 403);
        }

        $provider =
            $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        $validated = $request->validate([
            'business_name' => [
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

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],

            'whatsapp' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],

            'website' => [
                'sometimes',
                'nullable',
                'url',
                'max:255',
            ],

            'address' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            'city' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'district' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'latitude' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'sometimes',
                'nullable',
                'numeric',
                'between:-180,180',
            ],
        ]);

        if (
            isset($validated['business_name']) &&
            $validated['business_name'] !==
                $provider->business_name
        ) {
            $provider->business_slug =
                $this->generateUniqueSlug(
                    $validated['business_name'],
                    $provider->id
                );
        }

        $provider->fill($validated);

        $provider->save();

        return response()->json([
            'message' =>
                'Business profile updated successfully.',

            'provider' =>
                $provider->load('categories'),
        ]);
    }

    /**
     * Get the authenticated provider's selected categories.
     */
    public function categories(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can access provider categories.',
            ], 403);
        }

        $provider = $user->serviceProvider()
            ->with([
                'categories:id,name,slug',
            ])
            ->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        return response()->json([
            'categories' =>
                $provider->categories,
        ]);
    }

    /**
     * Update the authenticated provider's selected categories.
     */
    public function updateCategories(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can update provider categories.',
            ], 403);
        }

        $provider =
            $user->serviceProvider()->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        $validated = $request->validate([
            'category_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'category_ids.*' => [
                'integer',
                'distinct',
                'exists:service_categories,id',
            ],
        ]);

        $provider->categories()->sync(
            $validated['category_ids']
        );

        return response()->json([
            'message' =>
                'Provider categories updated successfully.',

            'categories' =>
                $provider->categories()
                    ->select(
                        'service_categories.id',
                        'service_categories.name',
                        'service_categories.slug'
                    )
                    ->get(),
        ]);
    }

    /**
     * Get the authenticated service provider dashboard.
     */
    public function dashboard(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' =>
                    'Only service provider accounts can access the provider dashboard.',
            ], 403);
        }

        /*
         * Load categories and also calculate
         * the number of services belonging
         * to this provider.
         */
        $provider = $user->serviceProvider()
            ->with([
                'categories:id,name,slug',
            ])
            ->withCount('services')
            ->first();

        if (!$provider) {
            return response()->json([
                'message' =>
                    'Business profile not found. Please create your business profile first.',
            ], 404);
        }

        return response()->json([
            'message' =>
                'Provider dashboard loaded successfully.',

            'dashboard' => [

                'provider' => [
                    'id' =>
                        $provider->id,

                    'business_name' =>
                        $provider->business_name,

                    'business_slug' =>
                        $provider->business_slug,
                ],

                'verification' => [
                    'status' =>
                        $provider->verification_status,

                    'notes' =>
                        $provider->verification_notes,

                    'verified_at' =>
                        $provider->verified_at,
                ],

                'business' => [
                    'description' =>
                        $provider->description,

                    'phone' =>
                        $provider->phone,

                    'whatsapp' =>
                        $provider->whatsapp,

                    'email' =>
                        $provider->email,

                    'website' =>
                        $provider->website,

                    'address' =>
                        $provider->address,

                    'city' =>
                        $provider->city,

                    'district' =>
                        $provider->district,

                    'latitude' =>
                        $provider->latitude,

                    'longitude' =>
                        $provider->longitude,

                    'logo' =>
                        $provider->logo,

                    'cover_image' =>
                        $provider->cover_image,

                    'is_active' =>
                        $provider->is_active,
                ],

                'categories' =>
                    $provider->categories,

                'statistics' => [
                    'categories_count' =>
                        $provider->categories->count(),

                    'services_count' =>
                        $provider->services_count,

                    /*
                     * These modules do not exist yet.
                     */
                    'packages_count' => 0,

                    'bookings_count' => 0,

                    'reviews_count' => 0,
                ],
            ],
        ]);
    }

    /**
     * Generate a unique business slug.
     */
    private function generateUniqueSlug(
        string $businessName,
        ?int $ignoreProviderId = null
    ): string {
        $slug = Str::slug(
            $businessName
        );

        if ($slug === '') {
            $slug = 'business';
        }

        $originalSlug = $slug;

        $counter = 1;

        while (true) {
            $query =
                ServiceProvider::where(
                    'business_slug',
                    $slug
                );

            if (
                $ignoreProviderId !== null
            ) {
                $query->where(
                    'id',
                    '!=',
                    $ignoreProviderId
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