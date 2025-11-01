<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Withdrawal;

class NewWithdrawalRequest extends Notification implements ShouldQueue
{
    use Queueable;

    protected $withdrawal;

    /**
     * Buat instance notifikasi baru.
     */
    public function __construct(Withdrawal $withdrawal)
    {
        $this->withdrawal = $withdrawal;
    }

    /**
     * Tentukan saluran notifikasi (di sini hanya lewat email).
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Format pesan email yang akan dikirim ke admin.
     */
    public function toMail($notifiable)
    {
        $user = $this->withdrawal->user;
        $method = $this->withdrawal->paymentMethod;

        return (new MailMessage)
            ->subject('🔔 New Withdrawal Request Pending Review')
            ->greeting('Hello Admin,')
            ->line('A new withdrawal request has been submitted and is pending review.')
            ->line('**User:** ' . $user->name . ' (' . $user->email . ')')
            ->line('**Amount:** $' . number_format($this->withdrawal->amount, 2))
            ->line('**Payment Method:** ' . ($method->type ?? 'N/A'))
            ->line('**Status:** ' . ucfirst($this->withdrawal->status))
            ->action('View Withdrawal', url('/admin/withdrawals/' . $this->withdrawal->id))
            ->line('Please log in to the admin panel to approve or reject this withdrawal request.');
    }

    /**
     * Format data untuk notifikasi database (opsional, jika ingin disimpan).
     */
    public function toArray($notifiable)
    {
        return [
            'withdrawal_id' => $this->withdrawal->id,
            'user_id' => $this->withdrawal->user_id,
            'amount' => $this->withdrawal->amount,
            'status' => $this->withdrawal->status,
        ];
    }
}
