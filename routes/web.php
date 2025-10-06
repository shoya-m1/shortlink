<?php

use Illuminate\Support\Facades\Route;
use App\Models\Link;

Route::get('/', function () {
    return response()->json(['message' => 'App is running!']);
});

Route::get('/{code}', function ($code) {
    $link = Link::where('short_code', $code)->firstOrFail();
    // Redirect ke blogspot landing page, bukan langsung ke original_url
    // return redirect()->away("https://shoyam1.blogspot.com/?c=".$code);
    return redirect()->away("http://localhost:5173/{$code}");
});
