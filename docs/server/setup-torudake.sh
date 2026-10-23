#!/usr/bin/env bash
# 撮って送るだけ帳簿：本番サーバー（ABLENET VPS・Ubuntu）の初回の準備
#
# 使い方（VPS で本人が実行する。AI は本番に SSH しない）:
#   curl -fsSL https://raw.githubusercontent.com/TannaiTomoya/torudake-app/main/docs/server/setup-torudake.sh -o setup-torudake.sh
#   sudo ADMIN_EMAIL=あなたのメール bash setup-torudake.sh
#
# 何度実行しても壊れないように、入っているものは飛ばす。
# 途中で止まったら、表示された原因を直してもう一度実行すればよい。
set -euo pipefail

# ==== 設定（ここだけ見ればよい） ====
DOMAIN="torudake-app.com"
APP_DIR="/var/www/torudake"
REPO_URL="https://github.com/TannaiTomoya/torudake-app.git"
DB_NAME="torudake"
DB_USER="torudake"
DEPLOY_USER="${DEPLOY_USER:-${SUDO_USER:-$(whoami)}}"   # アプリのファイルの持ち主（GitHub Actions が SSH するユーザー）
ADMIN_EMAIL="${ADMIN_EMAIL:?ADMIN_EMAIL=証明書の連絡先メール を付けて実行してください}"
PHP_MIN="8.3"        # Laravel 13 の最低。OS に入っている PHP（8.3 以上）をそのまま使う
NODE_MAJOR="22"
SWAP_SIZE="2G"

step() { echo; echo "==> $*"; }
have() { command -v "$1" >/dev/null 2>&1; }

if [ "$(id -u)" -ne 0 ]; then echo "sudo を付けて実行してください"; exit 1; fi

step "1/10 スワップ（メモリ1.6GBなので、composer と npm の途中で止まらないように ${SWAP_SIZE} 足す）"
if swapon --show | grep -q .; then
  echo "スワップはすでにある：$(swapon --show --noheadings | awk '{print $1, $3}')"
else
  fallocate -l "$SWAP_SIZE" /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo "スワップを作った"
fi

step "2/10 必要なソフト（足りないものだけ入れる）"
apt-get update -qq
apt-get install -y -qq software-properties-common curl git unzip ca-certificates >/dev/null
# 外部の PPA は使わない（新しい Ubuntu に対応していないことがあるため）。OS の標準の PHP を入れる
apt-get install -y -qq php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-zip php-gd php-intl php-bcmath \
  nginx certbot python3-certbot-nginx >/dev/null
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if ! php -r "exit(version_compare(PHP_VERSION, '${PHP_MIN}', '>=') ? 0 : 1);"; then
  echo "PHP ${PHP_VERSION} は古い（${PHP_MIN} 以上が必要）。runbook の「PHP が古いとき」を見てください"; exit 1
fi
if [ ! -S "/run/php/php${PHP_VERSION}-fpm.sock" ] && ! systemctl list-unit-files | grep -q "php${PHP_VERSION}-fpm"; then
  echo "php${PHP_VERSION}-fpm が見つからない"; exit 1
fi
have mysql || apt-get install -y -qq mysql-server >/dev/null
node_ok() { have node && [ "$(node -v | sed 's/v\([0-9]*\).*/\1/')" -ge "$NODE_MAJOR" ]; }
node_ok || apt-get install -y -qq nodejs npm >/dev/null   # Ubuntu 26.04 の標準は Node 22
if ! node_ok; then
  curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - >/dev/null && apt-get install -y -qq nodejs >/dev/null
fi
if ! have composer; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet && rm /tmp/composer-setup.php
fi
echo "PHP: $(php -v | head -1 | cut -d' ' -f1-2) / Node: $(node -v) / Composer: $(composer --version 2>/dev/null | cut -d' ' -f3)"

step "3/10 PHP の上限（写真は10MBまで）"
cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/99-torudake.ini" <<'INI'
upload_max_filesize = 12M
post_max_size = 13M
memory_limit = 256M
expose_php = Off
INI
systemctl restart "php${PHP_VERSION}-fpm"

step "4/10 データベース（${DB_NAME} と専用ユーザー。パスワードは画面に出さず .env にだけ書く）"
DB_PASS_FILE="/root/.torudake-db-password"
if [ ! -f "$DB_PASS_FILE" ]; then
  umask 077 && openssl rand -base64 24 | tr -d '/+=' > "$DB_PASS_FILE"
fi
DB_PASS="$(cat "$DB_PASS_FILE")"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "DB ${DB_NAME} を用意した（パスワードは ${DB_PASS_FILE} にだけ保存）"

step "5/10 アプリを置く（${APP_DIR}、持ち主は ${DEPLOY_USER}）"
mkdir -p "$(dirname "$APP_DIR")"
if [ ! -d "$APP_DIR/.git" ]; then
  git clone -q "$REPO_URL" "$APP_DIR"
fi
chown -R "$DEPLOY_USER":www-data "$APP_DIR"

step "6/10 本番の .env（初回だけ作る。2回目からは触らない）"
if [ ! -f "$APP_DIR/.env" ]; then
  sudo -u "$DEPLOY_USER" cp "$APP_DIR/.env.example" "$APP_DIR/.env"
  set_env() { sed -i "s|^$1=.*|$1=$2|" "$APP_DIR/.env"; grep -q "^$1=" "$APP_DIR/.env" || echo "$1=$2" >> "$APP_DIR/.env"; }
  set_env APP_ENV production
  set_env APP_DEBUG false
  set_env APP_URL "https://${DOMAIN}"
  set_env LOG_LEVEL warning
  set_env DB_HOST 127.0.0.1
  set_env DB_DATABASE "$DB_NAME"
  set_env DB_USERNAME "$DB_USER"
  set_env DB_PASSWORD "$DB_PASS"
  set_env SESSION_SECURE_COOKIE true
  set_env MAIL_MAILER log
  sed -i '/^# Sail/,$d' "$APP_DIR/.env"   # 開発用（Sail）の行は本番では使わない
  chmod 640 "$APP_DIR/.env" && chown "$DEPLOY_USER":www-data "$APP_DIR/.env"
  echo ".env を作った（メールは当面 log。送れるようにするのは runbook の手順で）"
else
  echo ".env はすでにある（触らない）"
fi

step "7/10 依存を入れて組み立てる"
cd "$APP_DIR"
sudo -u "$DEPLOY_USER" composer install --no-dev --optimize-autoloader --no-interaction --quiet
grep -q '^APP_KEY=base64' .env || sudo -u "$DEPLOY_USER" php artisan key:generate --force
sudo -u "$DEPLOY_USER" npm ci --silent
sudo -u "$DEPLOY_USER" npm run build --silent
sudo -u "$DEPLOY_USER" php artisan migrate --force
sudo -u "$DEPLOY_USER" php artisan optimize
chmod -R ug+rwX storage bootstrap/cache
chgrp -R www-data storage bootstrap/cache

step "8/10 nginx（サイト1つ）"
cat > /etc/nginx/sites-available/torudake <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${APP_DIR}/public;
    index index.php;
    charset utf-8;
    client_max_body_size 13m;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/torudake /etc/nginx/sites-enabled/torudake
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

step "9/10 HTTPS（Let's Encrypt。Cloudflare は灰色の雲＝DNS only のこと）"
if [ ! -d "/etc/letsencrypt/live/${DOMAIN}" ]; then
  certbot --nginx -d "$DOMAIN" --redirect --agree-tos -m "$ADMIN_EMAIL" -n
else
  echo "証明書はすでにある（自動更新は certbot のタイマーが行う）"
fi

step "10/10 定期実行（写真の30日削除など）とジョブの常駐"
CRON_LINE="* * * * * cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1"
( crontab -u "$DEPLOY_USER" -l 2>/dev/null | grep -v "${APP_DIR} && php artisan schedule:run"; echo "$CRON_LINE" ) | crontab -u "$DEPLOY_USER" -
cat > /etc/systemd/system/torudake-queue.service <<UNIT
[Unit]
Description=torudake queue worker
After=network.target mysql.service

[Service]
User=${DEPLOY_USER}
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=1 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload && systemctl enable --now torudake-queue >/dev/null 2>&1
echo "cron とジョブの常駐を設定した"

echo
echo "==> 確認"
curl -s -o /dev/null -w "https://${DOMAIN}/login → %{http_code}\n" "https://${DOMAIN}/login" || true
echo "200 なら完了。次は runbook の「最初の owner を作る」と「自動デプロイを有効にする」へ。"
