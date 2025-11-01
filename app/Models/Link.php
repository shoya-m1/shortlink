<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'original_url',
        'code',
        'title',
        'password',
        'expired_at',
        'status',
        'admin_comment',
        'earn_per_click',
        'token',
        'token_created_at',
    ];

    protected $casts = [
        'earn_per_click' => 'float',
        'token_created_at' => 'datetime',
    ];

    // Relasi ke user (opsional, jika pakai autentikasi)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relasi ke view
    public function views()
    {
        return $this->hasMany(View::class);
    }
}
