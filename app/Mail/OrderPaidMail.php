<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 購入完了メール。注文が支払い済みになった直後に、注文者へ1通だけ送る。キューには載せず、そのまま送る。
 */
class OrderPaidMail extends Mailable
{
    use SerializesModels;

    public function __construct(public Order $order)
    {
        $this->order->loadMissing('user', 'event', 'items.ticketType');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "【まちチケ】チケットの購入が完了しました（{$this->order->event->title}）",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.orders.paid',
            with: [
                'url' => route('orders.show', $this->order),
            ],
        );
    }
}
