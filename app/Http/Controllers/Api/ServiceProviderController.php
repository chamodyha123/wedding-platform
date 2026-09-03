<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
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
                'message' => 'Only service provider accounts can create a business profile.',
            ], 403);
        }

        if ($user->serviceProvider()->exists()) {
            return response()->json([
                'message' => 'You already have a service provider profile.',
            ], 409);
        }

        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => [
                'integer',
                'distinct',
                'exists:service_categories,id',
            ],
        ]);

        $provider = ServiceProvider::create([
            'user_id' => $user->id,
            'business_name' => $validated['business_name'],
            'business_slug' => $this->generateUniqueSlug(
                $validated['business_name']
            ),
            'description' => $validated['description'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'whatsapp' => $validated['whatsapp'] ?? null,
            'email' => $validated['email'] ?? null,
            'website' => $validated['website'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'district' => $validated['district'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'verification_status' => 'pending',
        ]);

        $provider->categories()->sync($validated['category_ids']);

        return response()->json([
            'message' => 'Business profile created successfully. Your profile is pending verification.',
            'provider' => $provider->load('categories'),
        ], 201);
    }

    /**
     * Get the authenticated provider's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasRole('service_provider')) {
            return response()->json([
                'message' => 'Only service provider accounts can access this profile.',
            ], 403);
        }

        $provider = $user->serviceProvider()
            ->with('categories')
            ->first();

        if (!$provider) {
            return response()->json([
                'message' => 'Business profile not found.',
            ], 404);
        }

        return response()->json([
            'provider' => $provider,
        ]);
    }

    /**
     * Generate a unique business slug.
     */
    private function generateUniqueSlug(string $businessName): string
    {
        $slug = Str::slug($businessName);
        $originalSlug = $slug;
        $counter = 1;

        while (ServiceProvider::where('business_slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}