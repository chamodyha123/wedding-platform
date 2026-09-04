<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderVerificationHistory;
use App\Models\ServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProviderVerificationController extends Controller
{
    /**
     * List providers waiting for admin review.
     */
    public function pending(): JsonResponse
    {
        $providers = ServiceProvider::with([
            'user:id,name,email',
            'categories:id,name',
        ])
            ->whereIn('verification_status', [
                'pending',
                'under_review',
                'changes_requested',
            ])
            ->latest()
            ->paginate(20);

        return response()->json([
            'providers' => $providers,
        ]);
    }

    /**
     * Show a provider for admin review.
     */
    public function show(int $id): JsonResponse
    {
        $provider = ServiceProvider::with([
            'user:id,name,email',
            'categories:id,name',
            'verificationHistory.admin:id,name,email',
        ])->find($id);

        if (!$provider) {
            return response()->json([
                'message' => 'Service provider not found.',
            ], 404);
        }

        return response()->json([
            'provider' => $provider,
        ]);
    }

    /**
     * Approve a provider.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->updateVerificationStatus(
            $request,
            $id,
            'verified'
        );
    }

    /**
     * Reject a provider.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->updateVerificationStatus(
            $request,
            $id,
            'rejected'
        );
    }

    /**
     * Request changes from a provider.
     */
    public function requestChanges(Request $request, int $id): JsonResponse
    {
        return $this->updateVerificationStatus(
            $request,
            $id,
            'changes_requested'
        );
    }

    /**
     * Suspend a provider.
     */
    public function suspend(Request $request, int $id): JsonResponse
    {
        return $this->updateVerificationStatus(
            $request,
            $id,
            'suspended'
        );
    }

    /**
     * Update verification status and create an audit record.
     */
    private function updateVerificationStatus(
        Request $request,
        int $id,
        string $newStatus
    ): JsonResponse {
        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $provider = ServiceProvider::find($id);

        if (!$provider) {
            return response()->json([
                'message' => 'Service provider not found.',
            ], 404);
        }

        $previousStatus = $provider->verification_status;

        // Prevent unnecessary status changes.
        if ($previousStatus === $newStatus) {
            return response()->json([
                'message' => "Provider is already {$newStatus}.",
            ], 422);
        }

        $provider->update([
            'verification_status' => $newStatus,
            'verification_notes' => $request->input('notes'),
            'verified_at' => $newStatus === 'verified'
                ? now()
                : null,
            'verified_by' => $newStatus === 'verified'
                ? $request->user()->id
                : null,
        ]);

        ProviderVerificationHistory::create([
            'service_provider_id' => $provider->id,
            'admin_id' => $request->user()->id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'notes' => $request->input('notes'),
        ]);

        return response()->json([
            'message' => "Provider {$newStatus} successfully.",
            'provider' => $provider->load([
                'user:id,name,email',
                'categories:id,name',
            ]),
        ]);
    }
}