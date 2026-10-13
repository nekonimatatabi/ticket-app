<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\OrderItem;
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
                'lines' => $this->order->items->map(fn (OrderItem $item): array => [
                    'name' => $this->escapeTableCell($item->ticketType->name),
                    'quantity' => $item->quantity,
                    'subtotal' => $item->quantity * $item->unit_price,
                ])->all(),
            ],
        );
    }

    /**
     * Markdown の表のセルに入れても、列がずれないようにする。主催者が入力した券種名に | や改行が入っていても、
     * 次の列に値が紛れ込まない。
     */
    private function escapeTableCell(string $value): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\|', ' ', ' ', ' '], $value);
    }
}
