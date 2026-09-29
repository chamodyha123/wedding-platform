<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->customerProfile;

        if (! $profile) {
            return response()->json([
                'message' => 'Customer profile not found.',
            ], 404);
        }

        return response()->json([
            'profile' => new CustomerProfileResource($profile),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
        ]);

        $profile = $request->user()->customerProfile()->updateOrCreate(
            [],
            $validated
        );

        return response()->json([
            'message' => 'Customer profile saved successfully.',
            'profile' => new CustomerProfileResource($profile),
        ]);
    }
}
