<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LegacyAuthController extends Controller
{
    // ✅ Register
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'referral_code' => 'nullable|string|exists:users,referral_code',
        ]);

        $referrer = null;
        if (!empty($validated['referral_code'])) {
            $referrer = User::where('referral_code', $validated['referral_code'])->first();
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'referral_code' => User::generateReferralCode(),
            'referred_by' => $referrer?->id,
        ]);

        if ($referrer) {
            $bonusAmount = 1000;
            $referrer->increment('balance', $bonusAmount);
        }

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'referrer' => $referrer ? $referrer->only(['id', 'name', 'email']) : null,
            'token' => $token,
            'message' => $referrer
                ? 'Registration successful with referral bonus applied.'
                : 'Registration successful.',
        ], 201);  // ✅ 201 untuk resource baru
    }

    // ✅ Login
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'Login successful.',
        ]);
    }

    // ✅ Logout
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        
        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
    
    // ✅ BONUS: Get current user (untuk cek auth)
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }
}