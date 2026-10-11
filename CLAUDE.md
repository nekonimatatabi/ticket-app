@AGENTS.md

# まちチケ

主催者がイベントと券種を登録し、参加者が Stripe Checkout でチケットを買うアプリ 

## このプロジェクトの決まり

- 画面は Livewire 4 の単一ファイルコンポーネント（`resources/views/pages/**/⚡*.blade.php`）で作る。Volt は使わない
- UI は Flux UI の Free 版のコンポーネントだけを使う（Pro 専用は使わない）。無いものは Tailwind で作る
- 文言は日本語。日付は `isoFormat('M月D日(ddd) HH:mm')`、金額は「3,000円」の形式
- 認可は `app/Policies/` のポリシーで行い、画面側では `$this->authorize()` を使う
- 外部サービス（Stripe など）に触るコードは `app/Services/` に閉じ込める。テストではそのクラスをモックする
- 機能を追加したら `tests/Feature/` に Pest テストを追加し、`/check` が通ることを確認する
- 開発コマンドは Sail 経由（`sail artisan ...`、`sail composer ...`、`sail npm ...`）。ホストの php や composer は使わない
- ビューを追加・変更したら `sail npm run build` を実行する（Tailwind がクラスを拾うため）

## 作業の進め方

- 変更の前に、何をどのファイルに書くかを短く説明してから実装する
- 1回の指示で扱う範囲は1機能まで。大きい依頼は分割案を先に出す
- 既存のファイルの書き方に合わせる（同じ階層のファイルを先に読む）
- `.env` の値、API キー、パスワードはコードやコミットに含めない
- 失敗したコマンドは、原因の推測と対処案を1つずつ示してから再実行する

## 設計

詳細: [データベース設計](doc/design/database.md)（ER 図、カラム定義、残り枚数）／[画面一覧](doc/design/screens.md)（URL、ロール）／[要件](doc/spec/01_requirements.md)

- 用語: イベント（`events`）／券種（`ticket_types`、価格・定員・販売期間）／注文（`orders`、1注文＝1イベント）／明細（`order_items`）／チケット（`tickets`、1枚ずつ固有コード）
- ロールは `users.role`（`customer` / `organizer` / `admin`）。主催者は `events.organizer_id`、注文者は `orders.user_id`。名前を混同しない
- 文字列の状態（`role`、`status`）は DB の enum 型にせず `string` で持ち、PHP の Enum でキャストする
- イベントは `draft` / `published`。参加者に見せるのは `published` かつ開催前のものだけ
- 注文は `pending`（支払い待ち）→ `paid`（支払い済み）。チケットは `paid` になった時に枚数分を発行する
- 金額は円の整数。注文時点の単価を `order_items.unit_price` に保存し、券種の価格変更で注文を変えない
- 残り枚数は列に持たず計算する: `quantity` − 親の注文が `pending` / `paid` の `order_items.quantity` の合計
- 買う前に、販売期間内・残り枚数・イベントが `published` であることを確認する（満たさなければ注文を作らない）
- 注文のあるイベントと券種は削除不可（外部キーは restrict）。画面側でも先に弾き、DB のエラーを表示しない
- `stripe_checkout_session_id` は UNIQUE。Webhook は二重に届いても、発行済みの注文を再処理しない
- 主催者の画面は `/organizer/` 配下。他人のイベント・注文はポリシーで拒否する
- 画像は `public` ディスクの `events/` に保存する
