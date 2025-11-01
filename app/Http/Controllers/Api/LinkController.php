<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use App\Models\Link;
use App\Models\View;
use Stevebauman\Location\Facades\Location;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class LinkController extends Controller
{
    // ==============================
    // 1️⃣ STORE — Buat Shortlink & Simpan di Redis
    // ==============================
    public function store(Request $request)
    {
        $validated = $request->validate([
            'original_url' => 'required|url',
            'title' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'expired_at' => 'nullable|date|after_or_equal:today', // format: YYYY-MM-DD atau YYYY-MM-DD HH:MM:SS
            'alias' => 'nullable|string|max:20|alpha_dash|unique:links,code',
        ]);

        $user = null;
        $token = $request->bearerToken(); // ambil token dari header
        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable; // user valid
            }
        }
        $userId = $user ? $user->id : null;

        $tries = 0;
        $maxTries = 5;
        $link = null;

        do {
            $code = $validated['alias'] ?? Str::random(7);

            try {
                $link = Link::create([
                    'user_id' => $userId,
                    'original_url' => $validated['original_url'],
                    'code' => $code,
                    'title' => $validated['title'] ?? null,
                    'expired_at' => $validated['expired_at'] ?? null,
                    'password' => $validated['password'] ?? null,
                    'earn_per_click' => $user ? (float) 0.10 : (float) 0.00,
                    'status' => 'active',
                ]);
                $created = true;
            } catch (QueryException $e) {
                // 23000 = duplicate entry
                if ($e->getCode() === '23000') {
                    $created = false;
                    $tries++;
                } else {
                    throw $e;
                }
            }
        } while (!$created && $tries < $maxTries);

        if (!$link) {
            return response()->json([
                'error' => 'Gagal membuat short link setelah beberapa percobaan.',
            ], 500);
        }

        // Simpan sementara di Redis (10 menit)
        Cache::put("link:{$link->code}", [
            'original_url' => $link->original_url,
            'user_id' => $link->user_id,
            'password' => $link->password,
            'expired_at' => $link->expired_at,
            'earn_per_click' => $link->earn_per_click,
        ], now()->addMinutes(10));

        // Jika user login, bisa menambahkan logika referral bonus
        if ($user && $user->referred_by) {
            // Contoh: bonus kecil untuk referral aktif
            $referrer = User::where('referral_code', $user->referred_by)->first();
            if ($referrer) {
                $referrer->increment('balance', 0.01);
            }
        }

        return response()->json([
            'short_url' => url("/{$link->code}"),
            'code' => $link->code,
            'title' => $link->title,
            'expired_at' => $link->expired_at,
            'user_id' => $link->user_id,
            'is_guest' => !$user,
            'earn_per_click' => (float) $link->earn_per_click,
            'source' => 'database',
            'message' => $user
                ? 'Shortlink created successfully (eligible for earnings).'
                : 'Shortlink created as guest (no earnings, stored temporarily).',
        ], 201);
    }

    public function checkAlias($alias)
    {
        // Normalisasi alias agar konsisten (huruf kecil, tanpa spasi)
        $alias = strtolower(trim($alias));

        // Simpan cache hasil pengecekan selama 10 detik
        $exists = Cache::remember("alias_check:{$alias}", 10, function () use ($alias) {
            return Link::where('code', $alias)->exists();
        });

        return response()->json(['exists' => $exists]);
    }



    // ==============================
    // 2️⃣ SHOW — Generate Token & Simpan ke Redis
    // ==============================
    public function show($code)
    {
        // Coba ambil link dari Redis dulu
        $cachedLink = Cache::get("link:{$code}");

        if (!$cachedLink) {
            $link = Link::where('code', $code)->firstOrFail();
            $cachedLink = [
                'id' => $link->id,
                'original_url' => $link->original_url,
                'user_id' => $link->user_id,
                'password' => $link->password,
                // 'expired_at' => $link->expired_at, 
                'earn_per_click' => $link->earn_per_click,
            ];
            // Simpan ulang di Redis agar cepat diakses berikutnya
            Cache::put("link:{$code}", $cachedLink, now()->addMinutes(10));
        }

        // Generate token unik & simpan ke Redis 60 detik
        $token = Str::uuid()->toString();

        // menyimpan token
        Cache::put("token:{$code}", [
            'token' => $token,
            'created_at' => now()
        ], now()->addSeconds(120));


        return response()->json([
            'ads' => [
                'https://example.com/ads1',
                'https://example.com/ads2',
            ],
            'token' => $token,
            'wait_time' => 10,
            'pw' => $cachedLink['password'],
            'message' => 'Please wait 10 seconds before continuing.',
        ]);
    }

    // ==============================
    // 3️⃣ CONTINUE — Validasi Token dari Redis
    // ==============================
    public function continue($code, Request $request)
    {
        $cachedToken = Cache::get("token:{$code}");
        $cachedLink = Cache::get("link:{$code}");

        // Ambil data link dari Redis atau DB jika cache kosong
        $link = $cachedLink
            ? (object) $cachedLink
            : Link::where('code', $code)->firstOrFail();

        if (!isset($link->id)) {
            $link = Link::where('code', $code)->first();
        }


        // ✅ Tambahan: Cek apakah link memiliki password
        if (!empty($link->password)) {
            $inputPassword = $request->input('password');

            // Jika tidak ada password dikirim → minta password dulu
            if (!$inputPassword) {
                return response()->json([
                    'requires_password' => true,
                    'message' => 'This link is protected by a password.',
                ], 401);
            }

            // Jika password salah
            if ($inputPassword !== $link->password) {
                return response()->json([
                    'error' => 'Incorrect password.',
                ], 403);
            }
        }

        Log::info("Token cached for {$code}", [
            'token' => $cachedToken,
            'expires_in' => 60
        ]);

        // $ip = $request->ip();
        // debug testing ganti jika mau production
        $ip = $request->ip() == '127.0.0.1' ? '36.84.69.10' : $request->ip(); // 8.8.8.8 = IP Google
        $userAgent = $request->header('User-Agent');
        $referer = $request->headers->get('referer');


        // // --- Validasi token
        if (!$cachedToken || $cachedToken['token'] !== $request->token) {
            View::create([
                'link_id' => $link->id ?? null,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'referer' => $referer,
                'is_valid' => false,
                'earned' => 0,
            ]);
            return response()->json(['error' => 'Invalid or missing token.'], 403);
        }

        // // --- Cek token expired
        if (Carbon::parse($cachedToken['created_at'])->diffInSeconds(now()) > 120) {
            Cache::forget("token:{$code}");
            return response()->json(['error' => 'Token expired. Please reload the page.'], 403);
        }

        // --- Ambil lokasi (opsional)
        $position = Location::get($ip);
        $country = $position ? $position->countryName : 'Unknown';

        // --- Cek view unik per hari
        $existing = View::where('link_id', $link->id ?? null)
            ->where('ip_address', $ip)
            ->whereDate('created_at', now()->toDateString())
            ->first();

        $isUnique = !$existing;
        $isOwnedByUser = !is_null($link->user_id ?? null);

        // --- Hitung penghasilan
        $earn = ($isUnique && $isOwnedByUser) ? ($link->earn_per_click ?? 1) : 0;

        // jika muncul error 500 kemungkinan chache nya bentrok dengan yang lain, jadi perlu clear di artisan

        // --- Simpan log view
        View::create([
            'link_id' => $link->id ?? null,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'referer' => $referer,
            'country' => $country,
            'device' => $this->detectDevice($userAgent),
            'browser' => $this->detectBrowser($userAgent),
            'is_unique' => $isUnique,
            'is_valid' => true,
            'earned' => $earn,
        ]);

        // --- Tambah saldo user (jika valid)
        if ($isUnique && $isOwnedByUser) {
            $user = isset($link->user)
                ? $link->user
                : \App\Models\User::find($link->user_id);

            if ($user) {
                $user->increment('balance', $earn);
            }

            // Tambahkan pendapatan ke link
            if (isset($link->id)) {
                $linkModel = \App\Models\Link::find($link->id);
                if ($linkModel) {
                    $linkModel->increment('earn_per_click', $earn);
                }
            }
        }



        // --- Hapus token dari Redis setelah digunakan
        Cache::forget("token:{$code}");

        return response()->json([
            'original_url' => $link->original_url,
            'ads' => [
                'https://ads1.example.com',
                'https://ads2.example.com',
            ],
            'is_guest_link' => !$isOwnedByUser,
            'message' => $isOwnedByUser
                ? 'Valid view recorded, earnings updated.'
                : 'Guest link viewed (no earnings).',
        ]);
    }

    // ==============================
    // 4 STATS - Validasi fungsi statistk
    // ==============================
    public function stats($code)
    {
        $cacheKey = "link_stats:{$code}:user:" . auth()->id();

        // 1️⃣ Coba ambil dari cache Redis dulu
        if (Cache::has($cacheKey)) {
            return response()->json([
                'source' => 'redis',
                'data' => Cache::get($cacheKey),
            ]);
        }

        // 2️⃣ Kalau belum ada, ambil dari DB
        $link = Link::where('code', $code)
            ->where('user_id', auth()->id())
            ->with('views')
            ->firstOrFail();

        if (auth()->id() !== $link->user_id) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $data = [
            'total_views' => $link->views->count(),
            'unique_views' => $link->views->where('is_unique', true)->count(),
            'earned_total' => $link->views->sum('earned'),
            'updated_at' => now()->toDateTimeString(),
        ];

        // 3️⃣ Simpan hasilnya ke Redis selama 2 menit
        Cache::put($cacheKey, $data, now()->addMinutes(2));

        // 4️⃣ Kembalikan response ke frontend
        if (Cache::has($cacheKey)) {
            return response()->json([
                'source' => 'redis',
                'data' => [
                    'summary' => Cache::get($cacheKey),
                    'meta' => [
                        'link_code' => $code,
                        'last_updated' => now()->toDateTimeString(),
                    ]
                ]
            ]);
        }


    }


    // ==============================
    // 🔍 Helper: Deteksi Device & Browser
    // ==============================
    private function detectDevice($userAgent)
    {
        if (preg_match('/mobile/i', $userAgent))
            return 'Mobile';
        if (preg_match('/tablet/i', $userAgent))
            return 'Tablet';
        return 'Desktop';
    }

    private function detectBrowser($userAgent)
    {
        if (preg_match('/chrome/i', $userAgent))
            return 'Chrome';
        if (preg_match('/firefox/i', $userAgent))
            return 'Firefox';
        if (preg_match('/safari/i', $userAgent))
            return 'Safari';
        if (preg_match('/edge/i', $userAgent))
            return 'Edge';
        if (preg_match('/msie|trident/i', $userAgent))
            return 'Internet Explorer';
        return 'Other';
    }
}
