<x-mail::message>
# チケットの購入が完了しました

{{ $order->user->name }} 様

ご購入ありがとうございます。お支払いを確認し、チケットを発行しました。

## イベント

**{{ $order->event->title }}**

- 日時: {{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}
- 会場: {{ $order->event->venue }}

## ご購入内容

<x-mail::table>
| 券種 | 枚数 | 小計 |
| :-- | --: | --: |
@foreach ($order->items as $item)
| {{ $item->ticketType->name }} | {{ $item->quantity }}枚 | {{ number_format($item->quantity * $item->unit_price) }}円 |
@endforeach
</x-mail::table>

**合計: {{ number_format($order->total_amount) }}円**

チケットのコードは、注文の詳細ページで確認できます。当日はこの画面をお見せください。

<x-mail::button :url="$url">
注文の詳細を見る
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
