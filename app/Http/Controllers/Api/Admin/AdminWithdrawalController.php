<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Notifications\WithdrawalStatusChanged;

class AdminWithdrawalController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10); // default 10
        $withdrawals = Payout::with(['user', 'paymentMethod'])
            ->latest()
            ->paginate($perPage);

        return response()->json($withdrawals);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected,paid',
            'notes' => 'nullable|string|max:255',
        ]);

        $withdrawal = Payout::with('user')->findOrFail($id);
        $user = $withdrawal->user;

        DB::transaction(function () use ($request, $withdrawal, $user) {

            // Update status & catatan
            $withdrawal->update([
                'status' => $request->status,
                'notes' => $request->notes,
            ]);

            // Logika saldo
            switch ($request->status) {
                case 'approved':
                    // Saat disetujui, pindahkan saldo aktif ke pending (dikunci)
                    if ($user->balance >= $withdrawal->amount) {
                        $user->decrement('balance', $withdrawal->amount);
                        $user->increment('pending_balance', $withdrawal->amount);
                    }
                    break;

                case 'rejected':
                    // Jika ditolak, pastikan pending dikurangi (jika sebelumnya disetujui)
                    if ($user->pending_balance >= $withdrawal->amount) {
                        $user->decrement('pending_balance', $withdrawal->amount);
                    }

                    // Kembalikan saldo ke balance utama
                    $user->increment('balance', $withdrawal->amount);
                    break;

                case 'paid':
                    // Jika sudah dibayar, keluarkan dari pending balance
                    if ($user->pending_balance >= $withdrawal->amount) {
                        $user->decrement('pending_balance', $withdrawal->amount);
                    }
                    // Tidak perlu kembalikan ke balance karena dana sudah keluar
                    break;
            }

            // Kirim notifikasi ke user
            $user->notify(new WithdrawalStatusChanged($withdrawal));
        });

        return response()->json([
            'message' => 'Withdrawal status updated successfully.',
            'withdrawal' => $withdrawal->load(['user', 'paymentMethod']),
        ]);

    }

}
