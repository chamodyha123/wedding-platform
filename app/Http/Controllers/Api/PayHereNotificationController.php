<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\PayHerePaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayHereNotificationController extends Controller
{
    public function store(Request $request, PayHerePaymentGateway $payHere): JsonResponse
    {
        $validated = $request->validate([
            'merchant_id' => ['required', 'string', 'max:100'],
            'order_id' => ['required', 'string', 'max:100'],
            'payment_id' => ['required', 'string', 'max:100'],
            'payhere_amount' => ['required', 'string', 'max:32'],
            'payhere_currency' => ['required', 'string', 'max:10'],
            'status_code' => ['required', 'string', 'max:10'],
            'md5sig' => ['required', 'string', 'size:32'],
        ]);

        if (! $payHere->verifyNotification($validated)) {
            return response()->json(['message' => 'Notification rejected.'], 403);
        }

        $payment = Payment::query()
            ->where('payment_reference', $validated['order_id'])
            ->where('gateway', 'payhere')
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'Notification rejected.'], 404);
        }

        return response()->json(['message' => 'Notification acknowledged.']);
    }
}
