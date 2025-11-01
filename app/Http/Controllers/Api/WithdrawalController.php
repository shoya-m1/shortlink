<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use App\Notifications\NewWithdrawalRequest;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\DB;


class WithdrawalController extends Controller
{
    public function index(Request $request)
    {
        $withdrawals = Withdrawal::where('user_id', $request->user()->id)
            ->with('paymentMethod')
            ->latest()
            ->get();

        return response()->json($withdrawals);
    }

    public function store(Request $request)
    {
        DB::enableQueryLog();
        $request->validate([
            'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:1',
        ]);

        // $user = $request->user();
        $user = Auth::user();

        // Cari metode pembayaran hanya berdasarkan ID dan kepemilikan user
        $method = PaymentMethod::where('id', $request->payment_method_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$method) {
            // Ubah pesan error menjadi lebih umum
            return response()->json(['error' => 'Invalid payment method.'], 422);
        }

        if ($user->balance < $request->amount) {
            return response()->json(['error' => 'Insufficient balance.'], 422);
        }

        // Kurangi saldo user sementara
        $user->decrement('balance', $request->amount);

        $withdrawal = Withdrawal::create([
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'amount' => $request->amount,
            'status' => 'pending',
        ]);
 dd(DB::getQueryLog());
        // Kirim notifikasi ke admin
        // Notification::route('mail', config('app.admin_email'))
        //     ->notify(new NewWithdrawalRequest($withdrawal));

        return response()->json([
            'message' => 'Withdrawal request submitted successfully.',
            'withdrawal' => $withdrawal,
        ]);
    }
}
