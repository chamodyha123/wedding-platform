<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminReportController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $dates = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $bookings = Booking::query()->when($dates['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($dates['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
        $reviews = Review::query()->when($dates['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))->when($dates['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));
        $paid = Payment::query()->selectRaw('MAX(id)')->where('status', 'paid')->groupBy('booking_id');

        return response()->json(['bookings_created_at' => ['total' => $bookings->count(), 'statuses' => $bookings->selectRaw('booking_status, count(*) as count')->groupBy('booking_status')->pluck('count', 'booking_status')], 'revenue_paid_at' => ['successful_payment_count' => Payment::whereIn('id', $paid)->count(), 'realized_revenue' => Payment::whereIn('id', $paid)->sum('amount')], 'reviews_created_at' => ['total' => $reviews->count(), 'average_rating' => $reviews->avg('rating'), 'rating_distribution' => $reviews->selectRaw('rating, count(*) as count')->groupBy('rating')->pluck('count', 'rating')]]);
    }
}
