<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentMethodVerificationMail;

class PaymentMethodController extends Controller
{
    /**
     * 🧾 List semua metode pembayaran user
     */
    public function index()
    {
        $methods = auth()->user()->paymentMethods()->get();
        return response()->json($methods);
    }

    /**
     * ➕ Tambah metode pembayaran
     */
    public function store(Request $request)
    {
        $request->validate([
            'method_type' => 'required|string', // Disarankan ganti 'type' jadi 'method_type'
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'email' => 'nullable|email',
        ]);

        // $user = auth()->user();
        $user = Auth::user();


        // ❌ Logika token dan pengiriman email Dihapus Seluruhnya

        $payment = PaymentMethod::create([
            'user_id' => $user->id,
            'type' => $request->type ?? 'default',
            'method_type' => $request->method_type,
            'account_name' => $request->account_name,
            'account_number' => $request->account_number,
            'bank_name' => $request->bank_name,
            // 'is_verified' => true, // Langsung diatur menjadi 'true'
        ]);

        // ✅ Kirim respons sukses yang lebih sederhana
        return response()->json([
            'message' => 'Payment method added successfully.',
            'payment_method' => $payment
        ], 201);
    }


    public function update(Request $request, $id)
    {
        $user = auth()->user();

        if ($user->payouts()->where('payment_method_id', $id)->where('status', 'pending')->exists()) {
            return response()->json(['error' => 'Cannot edit payment method currently used in pending withdrawal.'], 400);
        }


        // Validasi input
        $request->validate([
            'method_type' => 'required|string',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            // 'email' => 'nullable|email',
        ]);

        // Pastikan user hanya bisa edit miliknya sendiri
        $paymentMethod = $user->paymentMethods()->findOrFail($id);

        // Update data
        $paymentMethod->update([
            'method_type' => $request->method_type,
            'account_name' => $request->account_name,
            'account_number' => $request->account_number,
            'bank_name' => $request->bank_name,
            // 'email' => $request->email,
        ]);

        return response()->json([
            'message' => 'Payment method updated successfully.',
            'payment_method' => $paymentMethod
        ]);
    }



    /**
     * 🧩 Set sebagai default
     */
    public function setDefault($id)
    {
        $user = auth()->user();

        $user->paymentMethods()->update(['is_default' => false]);
        $method = $user->paymentMethods()->findOrFail($id);
        $method->update(['is_default' => true]);

        return response()->json(['message' => 'Default payment method updated']);
    }



    public function destroy($id)
    {
        $method = auth()->user()->paymentMethods()->findOrFail($id);
        $method->delete();

        return response()->json(['message' => 'Payment method deleted']);
    }
}
