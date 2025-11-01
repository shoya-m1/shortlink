<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Link;
use App\Models\Withdrawal;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    // Statistik global
    public function overview()
    {
        $totalUsers = User::count();
        // $activeUsers = User::whereNotNull('email_verified_at')->count();
        $totalLinks = Link::count();
        $totalClicks = DB::table('views')->count(); // opsional
        $pendingWithdrawals = payout::where('status', 'pending')->count();
        $totalWithdrawalsAmount = payout::where('status', 'approved')->sum('amount');

        return response()->json([
            'total_users' => $totalUsers,
            'active_users' => 0,
            'total_links' => $totalLinks,
            'total_clicks' => $totalClicks,
            'pending_withdrawals' => $pendingWithdrawals,
            'total_withdrawals_amount' => $totalWithdrawalsAmount,
        ]);
    }

    // Grafik tren user & transaksi
    public function trends()
    {
        $userGrowth = User::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $transactionVolume = Withdrawal::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(amount) as amount')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return response()->json([
            'user_growth' => $userGrowth,
            'transaction_volume' => $transactionVolume,
        ]);
    }
}
