<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use Illuminate\Http\JsonResponse;

class PhoneNumberController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'phone_numbers' => PhoneNumber::orderBy('created_at')->get()
                ->map(fn (PhoneNumber $n) => $n->only(['id', 'display_number', 'display_name', 'quality', 'status'])),
        ]);
    }
}
