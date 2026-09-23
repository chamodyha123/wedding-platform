<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminCustomerResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminCustomerController extends Controller
{
    /**
     * List customers with pagination and search.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'customer');
            })
            ->withCount(['bookings', 'reviews'])
            ->latest('created_at')
            ->latest('id');

        if (! empty($validated['search'])) {
            $search = '%'.$validated['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search);
            });
        }

        $customers = $query->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'customers' => AdminCustomerResource::collection($customers),
            'pagination' => [
                'current_page' => $customers->currentPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
                'last_page' => $customers->lastPage(),
            ],
        ]);
    }

    /**
     * Show a single customer with aggregate counts.
     */
    public function show(int $id): JsonResponse
    {
        $customer = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', 'customer');
            })
            ->withCount(['bookings', 'reviews'])
            ->find($id);

        if (! $customer) {
            return response()->json([
                'message' => 'Customer not found.',
            ], 404);
        }

        return response()->json([
            'customer' => new AdminCustomerResource($customer),
        ]);
    }
}
