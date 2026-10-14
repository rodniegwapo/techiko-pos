<?php

namespace App\Notifications;

use App\Models\Domain;
use App\Models\Finance\MonthlyReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells owners and managers that last month's business review is ready, with its headline figures. */
class MonthlyReviewReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly MonthlyReview $review,
        public readonly Domain $domain,
    ) {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $f = $this->review->figures;
        $peso = fn ($v) => '₱'.number_format((float) $v, 2);

        $mail = (new MailMessage)
            ->subject('Your '.$this->review->label.' business review is ready')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Here is how '.$this->domain->name.' did in '.$this->review->label.':')
            ->line('Sales: '.$peso($f['sales']).' · Gross profit: '.$peso($f['gross_profit']).' · Net profit: '.$peso($f['net_profit']));

        foreach (array_slice($this->review->needs_attention, 0, 3) as $item) {
            $mail->line('• '.$item);
        }

        return $mail
            ->action('Read the full review', route('domains.finance.reviews.index', [
                'domain' => $this->domain->name_slug,
                'month' => $this->review->month,
            ]))
            ->line('Suggestions in the review are based on your POS records and are not professional financial advice.');
    }
}
