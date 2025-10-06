<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use App\Models\Link;
use App\Models\View;
use Stevebauman\Location\Facades\Location;

class LinkController extends Controller
{
    // ==============================
    // 1️⃣ STORE — Buat Shortlink
    // ==============================
    public function store(Request $request)
    {
        $validated = $request->validate([
            'original_url' => 'required|url',
        ]);

        $tries = 0;
        $maxTries = 5;
        $link = null;

        do {
            $code = Str::random(7);
            try {
                $link = Link::create([
                    'user_id' => auth()->id(), // null jika guest
                    'original_url' => $validated['original_url'],
                    'code' => $code,
                    'earn_per_click' => (float) auth()->check() ? 0.05 : 0.00, // guest = 0
                ]);
                $created = true;
            } catch (QueryException $e) {
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

        return response()->json([
            'short_url' => url("/links/{$link->code}"),
            'code' => $link->code,
            'user_id' => $link->user_id,
            'is_guest' => auth()->guest(),
            'earn_per_click' => (float) $link->earn_per_click, // tambahkan ini
            'message' => auth()->check()
                ? 'Shortlink created and associated with your account (eligible for earnings).'
                : 'Shortlink created as guest (no earnings).',
        ], 201);
    }

    // ==============================
    // 2️⃣ SHOW — Generate Token (Halaman Iklan)
    // ==============================
    public function show($code)
    {
        $link = Link::where('code', $code)->firstOrFail();

        // Generate token unik (1x pakai)
        $token = Str::uuid()->toString();
        $link->update([
            'token' => $token,
            'token_created_at' => now(),
        ]);

        return response()->json([
            'ads' => [
                'https://example.com/ads1',
                'https://example.com/ads2',
            ],
            'token' => $token,
            'wait_time' => 10, // waktu tunggu di frontend
            'message' => 'Please wait 10 seconds before continuing.',
        ]);
    }

    // ==============================
    // 3️⃣ CONTINUE — Validasi Token & Monetisasi
    // ==============================
    public function continue($code, Request $request)
    {
        $link = Link::where('code', $code)->firstOrFail();
        $ip = $request->ip();
        $userAgent = $request->header('User-Agent');
        $referer = $request->headers->get('referer');

        // --- Validasi token
        if (!$request->has('token') || $link->token !== $request->token) {
            View::create([
                'link_id' => $link->id,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'referer' => $referer,
                'is_valid' => false,
                'earned' => 0,
            ]);
            return response()->json(['error' => 'Invalid or missing token.'], 403);
        }

        // --- Token kadaluarsa
        if (Carbon::parse($link->token_created_at)->diffInSeconds(now()) > 60) {
            $link->update(['token' => null, 'token_created_at' => null]);
            return response()->json(['error' => 'Token expired. Please reload the page.'], 403);
        }

        // --- Ambil informasi lokasi (opsional)
        $position = Location::get($ip);
        $country = $position ? $position->countryName : 'Unknown';

        // --- Cek view unik per hari
        $existing = View::where('link_id', $link->id)
            ->where('ip_address', $ip)
            ->whereDate('created_at', now()->toDateString())
            ->first();

        $isUnique = !$existing;
        $isOwnedByUser = !is_null($link->user_id); // bedakan guest dan user login

        // --- Hitung penghasilan (hanya user login + view unik)
        $earn = ($isUnique && $isOwnedByUser) ? $link->earn_per_click : 0;

        // --- Simpan log view
        View::create([
            'link_id' => $link->id,
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

        // --- Tambah saldo user jika valid
        if ($isUnique && $isOwnedByUser && $link->user) {
            $link->user->increment('balance', $earn);
        }

        // --- Reset token (biar tidak reuse)
        $link->update([
            'token' => null,
            'token_created_at' => null,
        ]);

        // --- Kembalikan data redirect
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
        $link = Link::where('code', $code)
            ->where('user_id', auth()->id())
            ->with('views')
            ->firstOrFail();

        if (auth()->id() !== $link->user_id) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'total_views' => $link->views->count(),
            'unique_views' => $link->views->where('is_unique', true)->count(),
            'earned_total' => $link->views->sum('earned'),
        ]);
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
