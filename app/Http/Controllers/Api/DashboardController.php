<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Link;
use App\Models\View;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function overview(Request $request)
    {
        $user = $request->user();
        $period = $request->query('period', 'weekly');
        $linkCode = $request->query('link', null);

        $startDate = match ($period) {
            'daily' => Carbon::now()->startOfDay(),
            'monthly' => Carbon::now()->startOfMonth(),
            default => Carbon::now()->subWeek(),
        };

        $cacheKey = "dashboard:overview:user:{$user->id}:{$period}:" . ($linkCode ?? 'all');
        if (Cache::has($cacheKey)) {
            return response()->json([
                'source' => 'redis',
                'data' => Cache::get($cacheKey)
            ]);
        }

        $balance = $user->balance ?? 0;
        $payout = Payout::where('user_id', $user->id)->where('status', 'paid')->sum('amount');
        $avgCpc = Link::where('user_id', $user->id)->avg('earn_per_click') ?? 0;

        $viewQuery = View::whereHas('link', function ($q) use ($user, $linkCode) {
            $q->where('user_id', $user->id);
            if ($linkCode)
                $q->where('code', $linkCode);
        })->where('created_at', '>=', $startDate);

        $views = $viewQuery->get();

        $totalEarnings = $views->sum('earned');
        $totalClicks = $views->count();
        $validClicks = $views->where('is_valid', true)->count();

        $previousPeriodViews = View::whereHas('link', function ($q) use ($user, $linkCode) {
            $q->where('user_id', $user->id);
            if ($linkCode)
                $q->where('code', $linkCode);
        })->whereBetween('created_at', [
                    $startDate->copy()->subWeek(),
                    $startDate->copy()
                ])->get();

        $prevEarnings = $previousPeriodViews->sum('earned');
        $earningChange = $prevEarnings > 0 ? round((($totalEarnings - $prevEarnings) / $prevEarnings) * 100, 2) : 0;
        $trend = $earningChange >= 0 ? 'up' : 'down';

        $links = [];
        if (!$linkCode) {
            $links = Link::where('user_id', $user->id)
                ->with('views')
                ->get()
                ->map(fn($link) => [
                    'code' => $link->code,
                    'total_views' => $link->views->count(),
                    'unique_views' => $link->views->where('is_unique', true)->count(),
                    'earned' => $link->views->sum('earned'),
                ])
                ->values();
        }

        // ====================================
        // 🔹 TOP LINKS SECTION
        // ====================================
        $topLinks = Link::where('user_id', $user->id)
            ->withSum('views as total_views', 'id')
            ->withSum('views as total_earned', 'earned')
            ->orderByDesc('total_earned')
            ->limit(4)
            ->get()
            ->map(function ($link) {
                $views = $link->total_views ?? 0;
                $earnings = $link->total_earned ?? 0;
                $cpm = $views > 0 ? round(($earnings / $views) * 1000, 2) : 0;

                return [
                    'short_url' => url("/links/{$link->code}"),
                    'views' => (int) $views,
                    'earnings' => round($earnings, 2),
                    'cpm' => $cpm,
                ];
            });

        // ====================================
        // 🔹 TOP EARNING SECTION
        // ====================================
        $topEarning = View::selectRaw('MONTH(created_at) as month, YEAR(created_at) as year, COUNT(*) as total_clicks, SUM(earned) as total_earnings')
            ->whereHas('link', fn($q) => $q->where('user_id', $user->id))
            ->groupBy('year', 'month')
            ->orderByDesc('total_earnings')
            ->first();

        $topEarningData = $topEarning ? [
            'top_month' => Carbon::create()->month($topEarning->month)->format('F'),
            'top_year' => $topEarning->year,
            'top_clicks' => (int) $topEarning->total_clicks,
        ] : [
            'top_month' => null,
            'top_year' => null,
            'top_clicks' => 0,
        ];

        // ====================================
        //  REFERRAL SECTION
        // ====================================
        if (!$user->referral_code) {
            $user->referral_code = Str::random(8);
            $user->save();
        }

        $referralData = [
            'code' => $user->referral_code,
            'users' => $user->referrals()->count() ?? 0, // pastikan relasi `referrals()` sudah ada di model User
            'referral_links' => [
                [
                    'platform' => 'whatsapp',
                    'url' => "https://wa.me/?text=Join+using+{$user->referral_code}"
                ],
                [
                    'platform' => 'facebook',
                    'url' => "https://facebook.com/share?code={$user->referral_code}"
                ],
                [
                    'platform' => 'instagram',
                    'url' => "https://instagram.com/share?code={$user->referral_code}"
                ],
                [
                    'platform' => 'telegram',
                    'url' => "https://t.me/share/url?url=" . urlencode("https://shortenlinks.com/ref/{$user->referral_code}")
                ]
            ]
        ];

        // ====================================
        // 🔹 FINAL STRUCTURED RESPONSE
        // ====================================
        $data = [
            'summary' => [
                'balance' => (float) $balance,
                'payout' => (float) $payout,
                'cpc' => (float) $avgCpc,
            ],
            'stats' => [
                'total_earnings' => [
                    'value' => round($totalEarnings, 2),
                    'change_percentage' => $earningChange,
                    'change_trend' => $trend,
                    'period' => $period,
                ],
                'total_clicks' => [
                    'value' => $totalClicks,
                    'change_percentage' => 0,
                    'change_trend' => 'neutral',
                    'period' => $period,
                ],
                'valid_clicks' => [
                    'value' => $validClicks,
                    'change_percentage' => 0,
                    'target_achieved' => min(100, ($validClicks / max(1, $totalClicks)) * 100),
                ],
            ],
            'top_links' => $topLinks,
            'top_earning' => $topEarningData,
            'referral' => $referralData,
        ];

        if (!$linkCode)
            $data['links'] = $links;

        Cache::put($cacheKey, $data, now()->addMinutes(3));

        return response()->json([
            'source' => 'database',
            'data' => $data
        ]);
    }


    // 🔹 NEW ENDPOINT: statistik tren (harian/mingguan)
    public function trends(Request $request)
    {
        $user = $request->user();
        $period = $request->query('period', 'weekly');
        $linkCode = $request->query('link', null);

        $startDate = match ($period) {
            'daily' => Carbon::now()->startOfDay(),
            'monthly' => Carbon::now()->startOfMonth(),
            default => Carbon::now()->subWeek(),
        };

        $cacheKey = "dashboard:trends:user:{$user->id}:{$period}:" . ($linkCode ?? 'all');

        if (Cache::has($cacheKey)) {
            return response()->json([
                'source' => 'redis',
                'data' => Cache::get($cacheKey)
            ]);
        }

        // 🔹 Ambil views berdasarkan periode
        $views = View::whereHas('link', function ($q) use ($user, $linkCode) {
            $q->where('user_id', $user->id);
            if ($linkCode)
                $q->where('code', $linkCode);
        })
            ->where('created_at', '>=', $startDate)
            ->get()
            ->groupBy(fn($v) => $v->created_at->format('Y-m-d'));

        // 🔹 Format data per hari
        $trendData = $views->map(function ($items, $date) {
            return [
                'date' => $date,
                'earnings' => round($items->sum('earned'), 2),
                'clicks' => $items->count(),
                'valid_clicks' => $items->where('is_valid', true)->count(),
            ];
        })->values();

        // Pastikan urutan berdasarkan tanggal
        $trendData = $trendData->sortBy('date')->values();

        $data = [
            'period' => $period,
            'link' => $linkCode,
            'trends' => $trendData,
        ];

        Cache::put($cacheKey, $data, now()->addMinutes(3));

        return response()->json([
            'source' => 'database',
            'data' => $data
        ]);
    }
}
