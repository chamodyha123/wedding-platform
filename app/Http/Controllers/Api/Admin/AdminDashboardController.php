<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceProvider;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    /**
     * Get the admin dashboard data with aggregate statistics.
     */
    public function dashboard(): JsonResponse
    {
        $totalCustomers = User::role('customer')->count();

        // Total service providers
        $totalProviders = ServiceProvider::count();

        // Verified provider count
        $verifiedProviders = ServiceProvider::where('verification_status', 'verified')->count();

        // Unverified provider count
        $unverifiedProviders = ServiceProvider::where('verification_status', '!=', 'verified')->count();

        // Active provider count
        $activeProviders = ServiceProvider::where('is_active', true)->count();

        // Total services
        $totalServices = Service::count();

        // Published service count
        $publishedServices = Service::where('status', 'published')->count();

        // Total bookings
        $totalBookings = Booking::count();

        // Booking counts grouped by the project's actual booking statuses
        $bookingStatusCounts = Booking::query()
            ->selectRaw('booking_status, count(*) as count')
            ->groupBy('booking_status')
            ->pluck('count', 'booking_status')
            ->toArray();

        // Completed booking count
        $completedBookings = Booking::where('booking_status', 'completed')->count();

        $successfulPaymentIds = Payment::query()
            ->selectRaw('MAX(id)')
            ->where('status', 'paid')
            ->groupBy('booking_id');

        $revenue = Payment::query()
            ->whereIn('id', $successfulPaymentIds)
            ->sum('amount');

        // Total reviews
        $totalReviews = Review::count();

        // Overall review average
        $averageRating = Review::avg('rating');

        return response()->json([
            'total_customers' => $totalCustomers,
            'total_service_providers' => $totalProviders,
            'verified_provider_count' => $verifiedProviders,
            'unverified_provider_count' => $unverifiedProviders,
            'active_provider_count' => $activeProviders,
            'total_services' => $totalServices,
            'published_service_count' => $publishedServices,
            'total_bookings' => $totalBookings,
            'booking_status_counts' => $bookingStatusCounts,
            'completed_booking_count' => $completedBookings,
            'payment_revenue_summary' => $revenue,
            'total_reviews' => $totalReviews,
            'average_review_rating' => $averageRating,
        ]);
    }
}
