<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceController extends Controller
{
    /**
     * Return all active marketplace categories.
     */
    public function categories(): JsonResponse
    {
        $categories = ServiceCategory::query()
            ->select([
                'id',
                'name',
                'slug',
                'description',
            ])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'categories' => $categories,
            'count' => $categories->count(),
        ]);
    }

    /**
     * Return verified and active service providers
     * for the public marketplace.
     */
    public function providers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'city' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'district' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $query = ServiceProvider::query()
            ->select([
                'id',
                'business_name',
                'business_slug',
                'description',
                'phone',
                'whatsapp',
                'email',
                'website',
                'address',
                'city',
                'district',
                'latitude',
                'longitude',
                'logo',
                'cover_image',
            ])
            ->where('verification_status', 'verified')
            ->where('is_active', true)
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->with([
                'categories' => function ($categoryQuery) {
                    $categoryQuery
                        ->select([
                            'service_categories.id',
                            'name',
                            'slug',
                        ])
                        ->where('is_active', true)
                        ->orderBy('name');
                },
            ]);

        if (! empty($validated['category'])) {
            $categorySlug = $validated['category'];

            $query->whereHas(
                'categories',
                function ($categoryQuery) use ($categorySlug) {
                    $categoryQuery
                        ->where(
                            'service_categories.is_active',
                            true
                        )
                        ->where(
                            'service_categories.slug',
                            $categorySlug
                        );
                }
            );
        }

        if (! empty($validated['city'])) {
            $query->whereRaw(
                'LOWER(city) = LOWER(?)',
                [$validated['city']]
            );
        }

        if (! empty($validated['district'])) {
            $query->whereRaw(
                'LOWER(district) = LOWER(?)',
                [$validated['district']]
            );
        }

        if (! empty($validated['search'])) {
            $search = '%' . $validated['search'] . '%';

            $query->where(
                function ($searchQuery) use ($search) {
                    $searchQuery
                        ->where(
                            'business_name',
                            'ilike',
                            $search
                        )
                        ->orWhere(
                            'description',
                            'ilike',
                            $search
                        );
                }
            );
        }

        $providers = $query
            ->orderBy('business_name')
            ->paginate(
                $validated['per_page'] ?? 12
            )
            ->withQueryString();

        $providers->through(
            fn (ServiceProvider $provider): array => $this->providerData($provider)
        );

        return response()->json($providers);
    }

    /**
     * Return one verified and active provider
     * by business slug.
     */
    public function provider(string $slug): JsonResponse
    {
        $provider = ServiceProvider::query()
            ->select([
                'id',
                'business_name',
                'business_slug',
                'description',
                'phone',
                'whatsapp',
                'email',
                'website',
                'address',
                'city',
                'district',
                'latitude',
                'longitude',
                'logo',
                'cover_image',
            ])
            ->where(
                'business_slug',
                $slug
            )
            ->where(
                'verification_status',
                'verified'
            )
            ->where(
                'is_active',
                true
            )
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->with([
                'categories' => function ($categoryQuery) {
                    $categoryQuery
                        ->select([
                            'service_categories.id',
                            'name',
                            'slug',
                        ])
                        ->where(
                            'is_active',
                            true
                        )
                        ->orderBy('name');
                },

                'services' => function ($serviceQuery) {
                    $serviceQuery
                        ->select([
                            'id',
                            'service_provider_id',
                            'service_category_id',
                            'name',
                            'slug',
                            'description',
                            'is_featured',
                        ])
                        ->where(
                            'status',
                            'published'
                        )
                        ->with([
                            'category:id,name,slug',

                            'packages' => function ($packageQuery) {
                                $packageQuery
                                    ->select([
                                        'id',
                                        'service_id',
                                        'name',
                                        'slug',
                                        'description',
                                        'price',
                                        'duration_minutes',
                                        'is_featured',
                                    ])
                                    ->where(
                                        'status',
                                        'published'
                                    )
                                    ->orderBy('price');
                            },
                        ])
                        ->orderByDesc(
                            'is_featured'
                        )
                        ->orderBy('name');
                },
            ])
            ->first();

        if (! $provider) {
            return response()->json([
                'message' => 'Provider not found.',
            ], 404);
        }

        return response()->json([
            'provider' => $this->providerData($provider),
        ]);
    }

    /**
     * Return published services belonging
     * to verified and active providers.
     */
    public function services(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'city' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'district' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'provider' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'search' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'featured' => [
                'sometimes',
                'boolean',
            ],

            'min_price' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'max_price' => [
                'sometimes',
                'nullable',
                'numeric',
                'min:0',
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        if (
            array_key_exists(
                'min_price',
                $validated
            )
            &&
            array_key_exists(
                'max_price',
                $validated
            )
            &&
            $validated['min_price'] !== null
            &&
            $validated['max_price'] !== null
            &&
            (float) $validated['max_price']
            <
            (float) $validated['min_price']
        ) {
            return response()->json([
                'message' =>
                    'The max price must be greater than or equal to the min price.',

                'errors' => [
                    'max_price' => [
                        'The max price must be greater than or equal to the min price.',
                    ],
                ],
            ], 422);
        }

        $query = Service::query()
            ->select([
                'id',
                'service_provider_id',
                'service_category_id',
                'name',
                'slug',
                'description',
                'is_featured',
                'created_at',
                'updated_at',
            ])
            ->where(
                'status',
                'published'
            )
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->whereHas(
                'provider',
                function ($providerQuery) {
                    $providerQuery
                        ->where(
                            'verification_status',
                            'verified'
                        )
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->whereHas(
                'category',
                function ($categoryQuery) {
                    $categoryQuery
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->with([
                'category:id,name,slug',

                'provider' => function ($providerQuery) {
                    $providerQuery
                        ->select([
                            'id',
                            'business_name',
                            'business_slug',
                            'city',
                            'district',
                            'logo',
                        ]);
                },

                'packages' => function ($packageQuery) {
                    $packageQuery
                        ->select([
                            'id',
                            'service_id',
                            'name',
                            'slug',
                            'price',
                            'duration_minutes',
                            'is_featured',
                        ])
                        ->where(
                            'status',
                            'published'
                        )
                        ->orderBy('price');
                },
            ]);

        if (! empty($validated['category'])) {
            $categorySlug =
                $validated['category'];

            $query->whereHas(
                'category',
                function ($categoryQuery) use ($categorySlug) {
                    $categoryQuery
                        ->where(
                            'is_active',
                            true
                        )
                        ->where(
                            'slug',
                            $categorySlug
                        );
                }
            );
        }

        if (! empty($validated['city'])) {
            $city = $validated['city'];

            $query->whereHas(
                'provider',
                function ($providerQuery) use ($city) {
                    $providerQuery
                        ->where(
                            'verification_status',
                            'verified'
                        )
                        ->where(
                            'is_active',
                            true
                        )
                        ->whereRaw(
                            'LOWER(city) = LOWER(?)',
                            [$city]
                        );
                }
            );
        }

        if (! empty($validated['district'])) {
            $district =
                $validated['district'];

            $query->whereHas(
                'provider',
                function ($providerQuery) use ($district) {
                    $providerQuery
                        ->where(
                            'verification_status',
                            'verified'
                        )
                        ->where(
                            'is_active',
                            true
                        )
                        ->whereRaw(
                            'LOWER(district) = LOWER(?)',
                            [$district]
                        );
                }
            );
        }

        if (! empty($validated['provider'])) {
            $providerSlug =
                $validated['provider'];

            $query->whereHas(
                'provider',
                function ($providerQuery) use ($providerSlug) {
                    $providerQuery
                        ->where(
                            'verification_status',
                            'verified'
                        )
                        ->where(
                            'is_active',
                            true
                        )
                        ->where(
                            'business_slug',
                            $providerSlug
                        );
                }
            );
        }

        if (! empty($validated['search'])) {
            $search =
                '%' . $validated['search'] . '%';

            $query->where(
                function ($searchQuery) use ($search) {
                    $searchQuery
                        ->where(
                            'name',
                            'ilike',
                            $search
                        )
                        ->orWhere(
                            'description',
                            'ilike',
                            $search
                        )
                        ->orWhereHas(
                            'provider',
                            function ($providerQuery) use ($search) {
                                $providerQuery
                                    ->where(
                                        'verification_status',
                                        'verified'
                                    )
                                    ->where(
                                        'is_active',
                                        true
                                    )
                                    ->where(
                                        'business_name',
                                        'ilike',
                                        $search
                                    );
                            }
                        )
                        ->orWhereHas(
                            'category',
                            function ($categoryQuery) use ($search) {
                                $categoryQuery
                                    ->where(
                                        'is_active',
                                        true
                                    )
                                    ->where(
                                        'name',
                                        'ilike',
                                        $search
                                    );
                            }
                        );
                }
            );
        }

        if (
            array_key_exists(
                'featured',
                $validated
            )
        ) {
            $query->where(
                'is_featured',
                $request->boolean('featured')
            );
        }

        if (
            array_key_exists(
                'min_price',
                $validated
            )
            ||
            array_key_exists(
                'max_price',
                $validated
            )
        ) {
            $query->whereHas(
                'packages',
                function ($packageQuery) use ($validated) {
                    $packageQuery
                        ->where(
                            'status',
                            'published'
                        );

                    if (
                        array_key_exists(
                            'min_price',
                            $validated
                        )
                    ) {
                        $packageQuery
                            ->where(
                                'price',
                                '>=',
                                $validated['min_price']
                            );
                    }

                    if (
                        array_key_exists(
                            'max_price',
                            $validated
                        )
                    ) {
                        $packageQuery
                            ->where(
                                'price',
                                '<=',
                                $validated['max_price']
                            );
                    }
                }
            );
        }

        $services = $query
            ->orderByDesc(
                'is_featured'
            )
            ->latest('id')
            ->paginate(
                $validated['per_page'] ?? 12
            )
            ->withQueryString();

        $services->through(
            fn (Service $service): array => $this->serviceData($service)
        );

        return response()->json($services);
    }

    /**
     * Return public reviews for one published service.
     */
    public function serviceReviews(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'rating' => [
                'sometimes',
                'integer',
                'min:1',
                'max:5',
            ],

            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $service = Service::query()
            ->select([
                'id',
                'name',
                'slug',
            ])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->where(
                'slug',
                $slug
            )
            ->where(
                'status',
                'published'
            )
            ->whereHas(
                'provider',
                function ($providerQuery) {
                    $providerQuery
                        ->where(
                            'verification_status',
                            'verified'
                        )
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->whereHas(
                'category',
                function ($categoryQuery) {
                    $categoryQuery
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->first();

        if (! $service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        $reviews = $service->reviews()
            ->select([
                'id',
                'service_id',
                'customer_id',
                'rating',
                'comment',
                'created_at',
                'updated_at',
            ])
            ->with('customer:id,name')
            ->when(
                array_key_exists('rating', $validated),
                function ($reviewQuery) use ($validated) {
                    $reviewQuery->where(
                        'rating',
                        $validated['rating']
                    );
                }
            )
            ->latest()
            ->paginate(
                $validated['per_page'] ?? 10
            )
            ->withQueryString();

        $reviews->through(function (Review $review): array {
            return [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at,
                'updated_at' => $review->updated_at,
                'customer' => [
                    'id' => $review->customer->id,
                    'name' => $review->customer->name,
                ],
            ];
        });

        return response()->json([
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'slug' => $service->slug,
            ],
            'rating_summary' => $this->ratingSummary($service),
            'reviews' => $reviews,
        ]);
    }

    /**
     * Return one published public service
     * by service slug.
     */
    public function service(string $slug): JsonResponse
    {
        $service = Service::query()
            ->select([
                'id',
                'service_provider_id',
                'service_category_id',
                'name',
                'slug',
                'description',
                'is_featured',
                'created_at',
                'updated_at',
            ])
            ->where(
                'slug',
                $slug
            )
            ->where(
                'status',
                'published'
            )
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->whereHas(
                'provider',
                function ($providerQuery) {
                    $providerQuery
                        ->where(
                            'verification_status',
                            'verified'
                        )
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->whereHas(
                'category',
                function ($categoryQuery) {
                    $categoryQuery
                        ->where(
                            'is_active',
                            true
                        );
                }
            )
            ->with([
                'category:id,name,slug',

                'provider' => function ($providerQuery) {
                    $providerQuery
                        ->select([
                            'id',
                            'business_name',
                            'business_slug',
                            'description',
                            'phone',
                            'whatsapp',
                            'email',
                            'website',
                            'address',
                            'city',
                            'district',
                            'latitude',
                            'longitude',
                            'logo',
                            'cover_image',
                        ]);
                },

                'packages' => function ($packageQuery) {
                    $packageQuery
                        ->select([
                            'id',
                            'service_id',
                            'name',
                            'slug',
                            'description',
                            'price',
                            'duration_minutes',
                            'is_featured',
                        ])
                        ->where(
                            'status',
                            'published'
                        )
                        ->orderByDesc(
                            'is_featured'
                        )
                        ->orderBy('price');
                },
            ])
            ->first();

        if (! $service) {
            return response()->json([
                'message' => 'Service not found.',
            ], 404);
        }

        return response()->json([
            'service' => $this->serviceData($service),
        ]);
    }

    /**
     * Build a public service response with normalized rating statistics.
     */
    private function serviceData(Service $service): array
    {
        $data = $service->toArray();

        unset($data['reviews_avg_rating']);

        $data['reviews_count'] = (int) ($service->reviews_count ?? 0);
        $data['average_rating'] = $this->normalizeAverageRating(
            $service->reviews_avg_rating ?? null
        );

        return $data;
    }

    /**
     * Build a public provider response with normalized rating statistics.
     */
    private function providerData(ServiceProvider $provider): array
    {
        $data = $provider->toArray();

        unset($data['reviews_avg_rating']);

        $data['reviews_count'] = (int) ($provider->reviews_count ?? 0);
        $data['average_rating'] = $this->normalizeAverageRating(
            $provider->reviews_avg_rating ?? null
        );

        return $data;
    }

    /**
     * Return a normalized rating summary for a service or provider.
     */
    private function ratingSummary(Service|ServiceProvider $model): array
    {
        return [
            'reviews_count' => (int) ($model->reviews_count ?? 0),
            'average_rating' => $this->normalizeAverageRating(
                $model->reviews_avg_rating ?? null
            ),
        ];
    }

    private function normalizeAverageRating(mixed $averageRating): ?float
    {
        if ($averageRating === null) {
            return null;
        }

        return round((float) $averageRating, 2);
    }
}
