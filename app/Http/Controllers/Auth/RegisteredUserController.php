<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request)
    {
        // Auth::id();
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],

            // ✅ TAMBAHAN: Custom fields kamu
            'referral_code' => ['nullable', 'string', 'exists:users,referral_code'],
        ]);

        // ✅ TAMBAHAN: Logic referral kamu
        $referrer = null;
        if (!empty($request->referral_code)) {
            $referrer = User::where('referral_code', $request->referral_code)->first();
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),

            // ✅ TAMBAHAN: Custom fields
            'referral_code' => User::generateReferralCode(),
            'referred_by' => $referrer?->id,
        ]);

        // ✅ TAMBAHAN: Bonus referral
        if ($referrer) {
            $referrer->increment('balance', 1000);
        }

        event(new Registered($user));

        // ✅ Breeze default: auto login setelah register
        // Auth::login($user);

        // ✅ ATAU: Return token untuk API (recommended)
        $token = $user->createToken('api_token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'referrer' => $referrer ? $referrer->only(['id', 'name', 'email']) : null,
            'message' => $referrer
                ? 'Registration successful with referral bonus applied.'
                : 'Registration successful.',
        ], 201);
    }
}