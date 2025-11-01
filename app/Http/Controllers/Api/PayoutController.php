<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


class PayoutController extends Controller
{
    /**
     * 🏦 Ajukan permintaan penarikan
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method_id' => 'required|exists:payment_methods,id',
        ]);

        $method = $user->paymentMethods()->findOrFail($request->payment_method_id);

        if ($request->amount > $user->balance) {
            return response()->json(['error' => 'Insufficient balance.'], 400);
        }

        DB::transaction(function () use ($user, $request, $method) {
            // ✅ Hanya tambahkan ke "pending balance"
            $user->increment('pending_balance', $request->amount);

            $user->payouts()->create([
                'amount' => $request->amount,
                'payment_method_id' => $method->id,
                'status' => 'pending',
            ]);
        });

        return response()->json(['message' => 'Withdrawal request submitted.']);
    }

    /**
     * 📄 Lihat riwayat penarikan user
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        // ambil parameter ?page= atau ?per_page= dari request
        $perPage = $request->get('per_page', 3); // default 5 item per halaman

        $payouts = Payout::where('user_id', $user->id)
            ->with('paymentMethod:id,method_type,account_name,account_number,bank_name')
            ->latest()
            ->paginate($perPage, ['id', 'amount', 'payment_method_id', 'status', 'created_at']);

        return response()->json([
            'balance' => $user->balance,
            'payouts' => $payouts, // ini otomatis berisi data + info pagination
        ]);
    }


    /**
     * 🔧 Admin: ubah status payout (approve / reject / paid)
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected,paid',
            'note' => 'nullable|string',
        ]);

        $payout = Payout::findOrFail($id);

        if ($request->status === 'rejected') {
            $payout->user->increment('balance', $payout->amount);
        }

        $payout->update([
            'status' => $request->status,
            'note' => $request->note,
        ]);

        Cache::forget("dashboard:{$payout->user_id}");

        return response()->json(['message' => "Payout status updated to {$request->status}."]);
    }


    public function cancel($id)
    {
        $user = auth()->user();

        $payout = $user->payouts()->where('id', $id)->firstOrFail();

        if ($payout->status !== 'pending') {
            return response()->json([
                'error' => 'Only pending withdrawals can be cancelled.'
            ], 400);
        }

        DB::transaction(function () use ($payout, $user) {
            // Jika pakai sistem "pending_balance"
            if (Schema::hasColumn('users', 'pending_balance')) {
                $user->decrement('pending_balance', $payout->amount);
            }

            // Jika langsung mengurangi saldo saat request (tanpa pending_balance)
            // maka tambahkan saldo kembali ke user:
            else {
                $user->increment('balance', $payout->amount);
            }

            // Update status payout menjadi cancelled
            $payout->update(['status' => 'cancelled']);
        });

        return response()->json([
            'message' => 'Withdrawal has been cancelled successfully.',
            'payout' => $payout->fresh()
        ]);
    }


    public function destroy($id)
    {
        $user = auth()->user();

        $payout = $user->payouts()->where('id', $id)->firstOrFail();

        // Hanya boleh hapus jika status sudah cancelled
        if ($payout->status !== 'cancelled') {
            return response()->json([
                'error' => 'Only cancelled withdrawals can be permanently deleted.'
            ], 400);
        }

        DB::transaction(function () use ($payout) {
            // Hapus record secara permanen
            $payout->delete(); // gunakan forceDelete jika pakai SoftDeletes
        });

        return response()->json([
            'message' => 'Cancelled withdrawal deleted permanently.',
        ]);
    }



}
