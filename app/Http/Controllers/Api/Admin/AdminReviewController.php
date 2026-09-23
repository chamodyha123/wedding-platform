<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['rating' => ['nullable', 'integer', 'between:1,5'], 'service_id' => ['nullable', 'integer', 'exists:services,id'], 'provider_id' => ['nullable', 'integer', 'exists:service_providers,id'], 'customer_id' => ['nullable', 'integer', 'exists:users,id'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $reviews = $this->query()->when($data['rating'] ?? null, fn ($q, $v) => $q->where('rating', $v))->when($data['service_id'] ?? null, fn ($q, $v) => $q->where('service_id', $v))->when($data['provider_id'] ?? null, fn ($q, $v) => $q->where('service_provider_id', $v))->when($data['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))->latest('created_at')->latest('id')->paginate($data['per_page'] ?? 15);

        return response()->json(['reviews' => $reviews, 'pagination' => ['current_page' => $reviews->currentPage(), 'per_page' => $reviews->perPage(), 'total' => $reviews->total(), 'last_page' => $reviews->lastPage()]]);
    }

    public function show(int $id): JsonResponse
    {
        $review = $this->query()->find($id);
        if (! $review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        return response()->json(['review' => $review]);
    }

    private function query()
    {
        return Review::query()->with(['customer:id,name,email', 'provider:id,business_name,business_slug', 'service:id,name,slug', 'booking:id,booking_reference']);
    }
}
