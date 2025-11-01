<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\View;
use App\Models\Payout;
use App\Models\User;
use Carbon\Carbon;

class StatsController extends Controller
{
    public function dashboard()
    {
        $user = auth()->user();
        $cacheKey = "dashboard:{$user->id}";

        // Gunakan Cache::remember agar otomatis ambil dari cache jika ada
        $data = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($user) {

            // =====================
            // 📊 BAGIAN SUMMARY
            // =====================
            $balance = (float) $user->balance;
            $payout = (float) Payout::where('user_id', $user->id)
                ->where('status', 'paid')
                ->sum('amount');
            $cpc = 0.05; // bisa diganti dari config sistem

            // =====================
            // ⏳ PERIODE WAKTU
            // =====================
            $today = Carbon::today();
            $yesterday = Carbon::yesterday();
            $thisWeek = [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()];
            $lastWeek = [Carbon::now()->subWeek()->startOfWeek(), Carbon::now()->subWeek()->endOfWeek()];

            // =====================
            // 📈 TOTAL EARNINGS
            // =====================
            $totalEarnings = $this->sumEarnings($user->id);
            $earnThisWeek = $this->sumEarnings($user->id, $thisWeek);
            $earnLastWeek = $this->sumEarnings($user->id, $lastWeek);
            $earnChange = $this->calculateChange($earnThisWeek, $earnLastWeek);

            // =====================
            // 📈 TOTAL CLICKS
            // =====================
            $totalClicks = $this->countViews($user->id);
            $clicksToday = $this->countViews($user->id, [$today, now()]);
            $clicksYesterday = $this->countViews($user->id, [$yesterday, $today]);
            $clickChange = $this->calculateChange($clicksToday, $clicksYesterday);

            // =====================
            // ✅ VALID CLICKS
            // =====================
            $validClicks = $this->countViews($user->id, null, true);
            $validToday = $this->countViews($user->id, [$today, now()], true);
            $validYesterday = $this->countViews($user->id, [$yesterday, $today], true);
            $validChange = $this->calculateChange($validToday, $validYesterday);
            $targetAchieved = $this->calculateTarget($validClicks, 30000);

            // =====================
            // 📦 BENTUK DATA JSON
            // =====================
            return [
                'summary' => [
                    'balance' => round($balance, 2),
                    'payout' => round($payout, 2),
                    'cpc' => round($cpc, 2),
                ],
                'stats' => [
                    'total_earnings' => [
                        'value' => round($totalEarnings, 2),
                        'change_percentage' => $earnChange['percent'],
                        'change_trend' => $earnChange['trend'],
                        'period' => 'weekly',
                    ],
                    'total_clicks' => [
                        'value' => $totalClicks,
                        'change_percentage' => $clickChange['percent'],
                        'change_trend' => $clickChange['trend'],
                        'period' => 'daily',
                    ],
                    'valid_clicks' => [
                        'value' => $validClicks,
                        'change_percentage' => $validChange['percent'],
                        'target_achieved' => $targetAchieved,
                    ],
                ],
            ];
        });

        return response()->json([
            'source' => 'cache',
            'data' => $data,
        ]);
    }

    // =====================
    // 🔧 HELPER FUNCTIONS
    // =====================

    private function sumEarnings($userId, $range = null)
    {
        $query = View::whereHas('link', fn($q) => $q->where('user_id', $userId));
        if ($range)
            $query->whereBetween('created_at', $range);
        return $query->sum('earned');
    }

    private function countViews($userId, $range = null, $validOnly = false)
    {
        $query = View::whereHas('link', fn($q) => $q->where('user_id', $userId));
        if ($validOnly)
            $query->where('is_valid', true);
        if ($range)
            $query->whereBetween('created_at', $range);
        return $query->count();
    }

    private function calculateChange($current, $previous)
    {
        if ($previous == 0)
            return ['percent' => 100, 'trend' => 'up'];
        $diff = $current - $previous;
        $percent = round(($diff / $previous) * 100, 2);
        return ['percent' => $percent, 'trend' => $diff >= 0 ? 'up' : 'down'];
    }

    private function calculateTarget($current, $target)
    {
        if ($target == 0)
            return 0;
        return round(($current / $target) * 100, 2);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $cacheKey = "stats:index:{$user->id}";

        $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($user) {
            // === Summary ===
            $balance = $user->balance ?? 0;
            $payout = Payout::where('user_id', $user->id)->sum('amount');
            $cpc = View::whereHas('link', fn($q) => $q->where('user_id', $user->id))
                ->avg('earned') ?? 0;

            // === Stats ===
            $totalEarnings = View::whereHas('link', fn($q) => $q->where('user_id', $user->id))->sum('earned');
            $totalClicks = View::whereHas('link', fn($q) => $q->where('user_id', $user->id))->count();
            $validClicks = View::whereHas('link', fn($q) => $q->where('user_id', $user->id))
                ->where('is_valid', true)->count();

            $changeEarnings = rand(-20, 50);
            $changeClicks = rand(-30, 40);

            // === Top Links ===
            $topLinks = Link::where('user_id', $user->id)
                ->withCount(['views as views_count'])
                ->withSum('views', 'earned')
                ->orderByDesc('views_count')
                ->take(5)
                ->get()
                ->map(function ($link) {
                    $views = $link->views_count ?? 0;
                    $earnings = $link->views_sum_earned ?? 0;
                    $cpm = $views > 0 ? ($earnings / $views) * 1000 : 0;

                    return [
                        'short_url' => url($link->code),
                        'views' => $views,
                        'earnings' => round($earnings, 2),
                        'cpm' => round($cpm, 2),
                    ];
                });

            // === Top Earning Period ===
            $topMonth = View::selectRaw('MONTH(created_at) as month, YEAR(created_at) as year, SUM(earned) as total')
                ->whereHas('link', fn($q) => $q->where('user_id', $user->id))
                ->groupBy('month', 'year')
                ->orderByDesc('total')
                ->first();

            $topEarning = [
                'top_month' => $topMonth ? Carbon::create()->month($topMonth->month)->format('F') : null,
                'top_year' => $topMonth->year ?? null,
                'top_clicks' => View::whereHas('link', fn($q) => $q->where('user_id', $user->id))
                    ->when($topMonth, fn($q) => $q->whereMonth('created_at', $topMonth->month)->whereYear('created_at', $topMonth->year))
                    ->count(),
            ];

            // === Referral ===
            if (empty($user->referral_code)) {
                $user->update(['referral_code' => substr(md5($user->id . $user->email), 0, 8)]);
            }

            $referralCode = $user->referral_code;
            $referralUsers = $user->referrals()->count();

            $referralLinks = [
                ['platform' => 'whatsapp', 'url' => "https://wa.me/?text=Join+using+$referralCode"],
                ['platform' => 'facebook', 'url' => "https://facebook.com/share?code=$referralCode"],
                ['platform' => 'instagram', 'url' => "https://instagram.com/share?code=$referralCode"],
                ['platform' => 'telegram', 'url' => "https://t.me/share/url?url=" . url("/ref/$referralCode")],
            ];

            return [
                'summary' => [
                    'balance' => round($balance, 2),
                    'payout' => round($payout, 2),
                    'cpc' => round($cpc, 2),
                ],
                'stats' => [
                    'total_earnings' => [
                        'value' => round($totalEarnings, 2),
                        'change_percentage' => $changeEarnings,
                        'change_trend' => $changeEarnings >= 0 ? 'up' : 'down',
                        'period' => 'last_week',
                    ],
                    'total_clicks' => [
                        'value' => $totalClicks,
                        'change_percentage' => $changeClicks,
                        'change_trend' => $changeClicks >= 0 ? 'up' : 'down',
                        'period' => 'today',
                    ],
                    'valid_clicks' => [
                        'value' => $validClicks,
                        'change_percentage' => rand(10, 40),
                        'target_achieved' => 90,
                    ],
                ],
                'top_links' => $topLinks,
                'top_earning' => $topEarning,
                'referral' => [
                    'code' => $referralCode,
                    'users' => $referralUsers,
                    'referral_links' => $referralLinks,
                ],
            ];
        });

        return response()->json([
            'source' => 'cache',
            'data' => $data,
        ]);
    }
}
