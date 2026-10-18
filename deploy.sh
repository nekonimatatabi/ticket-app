#!/usr/bin/env bash
set -e

echo "==> アプリのディレクトリへ移動します"
cd /var/www/ticket-app

echo "==> main ブランチの最新を取得します"
git pull origin main

echo "==> PHP の依存パッケージをインストールします"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> フロントエンドをビルドします"
npm ci && npm run build

echo "==> マイグレーションを実行します"
php artisan migrate --force

echo "==> キャッシュを作成して最適化します"
php artisan optimize

echo "==> デプロイが完了しました"
