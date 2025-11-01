<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public $withdrawal;

    public function __construct($withdrawal)
    {
        $this->withdrawal = $withdrawal;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Withdrawal Status Update')
            ->line("Your withdrawal of {$this->withdrawal->amount} has been {$this->withdrawal->status}.")
            ->line("Notes: " . ($this->withdrawal->notes ?? '-'))
            ->action('View Withdrawal', url('/dashboard/withdrawals'));
    }
}
