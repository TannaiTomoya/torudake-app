#!/usr/bin/env bash
# 本番サーバーで実行する更新の手順（GitHub Actions の deploy.yml から呼ばれる。手でも実行できる）
# 先に DB をバックアップしてから更新する。戻し方は docs/runbook.md
set -euo pipefail

APP_DIR="/var/www/torudake"
BACKUP_DIR="$HOME/backups/torudake"
KEEP_DAYS=30

cd "$APP_DIR"

echo "==> DB をバックアップ（${KEEP_DAYS}日分を残す）"
mkdir -p "$BACKUP_DIR"
DB_DATABASE=$(grep '^DB_DATABASE=' .env | cut -d= -f2-)
DB_USERNAME=$(grep '^DB_USERNAME=' .env | cut -d= -f2-)
DB_PASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-)
STAMP=$(date +%Y%m%d-%H%M%S)
MYSQL_PWD="$DB_PASSWORD" mysqldump --no-tablespaces --single-transaction -u "$DB_USERNAME" "$DB_DATABASE" | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz"
find "$BACKUP_DIR" -name 'db-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
echo "    $BACKUP_DIR/db-$STAMP.sql.gz"

echo "==> 今のコミットを記録（戻すときに使う）"
git rev-parse HEAD > "$BACKUP_DIR/previous-commit"

echo "==> main の最新を取得"
git pull --ff-only origin main

echo "==> PHP の依存をインストール（本番用）"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> 画面の部品を入れて組み立てる"
npm ci && npm run build

echo "==> マイグレーションを実行"
php artisan migrate --force

echo "==> 設定・ルート・ビューをキャッシュ"
php artisan optimize

echo "==> ジョブの常駐を新しいコードで起動し直す"
php artisan queue:restart

echo "==> デプロイ完了（$(git rev-parse --short HEAD)）"
