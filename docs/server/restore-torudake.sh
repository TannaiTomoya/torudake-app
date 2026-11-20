#!/usr/bin/env bash
# 撮って送るだけ帳簿：バックアップから戻す（#35）
#
# backup-torudake.sh が作った束（torudake-日時.tar.gz.age）を、秘密鍵で開けて、DB（と写真）に戻し、
# 控えと比べて確かめる。手順は docs/runbook.md の「バックアップから戻す」。
#
# 使い方（戻す先のサーバー、または練習用の環境で。本番は本人が実行する）:
#   RESTORE_CONFIRM=戻す先のDB名 bash docs/server/restore-torudake.sh 束のファイル 秘密鍵のファイル
#
# 環境変数:
#   APP_DIR                 アプリの場所（省略すると /var/www/torudake）
#   RESTORE_CONFIRM         戻す先の DB 名をもう一度書く（書かないと、何を上書きするかを表示して止まる）
#   RESTORE_OVERWRITE=1     戻す先の DB に表がすでにあるとき（今ある表をすべて消してから戻す）
#   RESTORE_EVIDENCES=1     写真も戻す（省略すると DB だけ）
#   RESTORE_TO_PRODUCTION=1 本番の設定（APP_ENV=production・本物の AI・本物のメール）の環境に戻すとき
#   RESTORE_USE_BACKUP_KEY=1 練習・試しのときだけ。確かめるあいだ、束の中のアプリの鍵（APP_KEY）を使う（.env は変えず、
#                           束の .env も残さない）。本番の束を手元で試すと、手元の鍵は本番と違うので、これが要る
#   DB_DATABASE             練習のときだけ、.env と違う DB に戻す
set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-/var/www/torudake}"
BUNDLE="${1:?使い方：restore-torudake.sh 束のファイル 秘密鍵のファイル}"
IDENTITY="${2:?使い方：restore-torudake.sh 束のファイル 秘密鍵のファイル}"

fail() { echo "NG $*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || fail "$1 が入っていません（sudo apt install $2）"; }

need age age
need mysql mysql-client
need php php-cli
[ -f "$BUNDLE" ] || fail "束のファイルがありません：$BUNDLE"
[ -f "$IDENTITY" ] || fail "秘密鍵のファイルがありません：$IDENTITY"
BUNDLE="$(readlink -f "$BUNDLE")"
IDENTITY="$(readlink -f "$IDENTITY")"

cd "$APP_DIR"
[ -f .env ] || fail "$APP_DIR/.env がありません（先にアプリを置く。docs/runbook.md §1）"

env_get() { grep "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_DATABASE="${DB_DATABASE:-$(env_get DB_DATABASE)}"
DB_USERNAME="$(env_get DB_USERNAME)"
DB_PASSWORD="$(env_get DB_PASSWORD)"
DB_HOST="$(env_get DB_HOST)"
DB_PORT="$(env_get DB_PORT)"
export DB_DATABASE

# 練習の環境では、本物の AI とメールを使わせない（復元したデータで、実在の人に連絡が飛ばないように）
# 外から渡した値（練習のスクリプトが AI_DRIVER=fake などを渡す）があればそれを、なければ .env の値を見る
setting() { local name="$1"; if [ -n "${!name:-}" ]; then printf '%s' "${!name}"; else env_get "$name"; fi; }
if [ "${RESTORE_TO_PRODUCTION:-0}" != "1" ]; then
  [ "$(setting APP_ENV)" != "production" ] || fail "戻す先が本番の設定です。本番に戻すなら RESTORE_TO_PRODUCTION=1 を付けてください"
  [ "$(setting AI_DRIVER)" != "claude" ] || fail "戻す先の AI が本物（AI_DRIVER=claude）です。練習では fake にしてください"
  case "$(setting MAIL_MAILER)" in
    log|array) ;;
    *) echo "注意：戻す先のメールが log・array ではありません。開発用の受け皿（Mailpit など）であることを確かめてください" ;;
  esac
fi

if [ "${RESTORE_USE_BACKUP_KEY:-0}" = "1" ]; then
  [ "${RESTORE_TO_PRODUCTION:-0}" != "1" ] || fail "RESTORE_USE_BACKUP_KEY は練習・試し用です。本番では、束の APP_KEY を .env に写してください（docs/runbook.md §3.4）"
  [ ! -f bootstrap/cache/config.php ] || fail "設定のキャッシュがあります。php artisan config:clear のあとでやり直してください"
fi

mysql_do() { MYSQL_PWD="$DB_PASSWORD" mysql -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" "$@"; }
TABLES="$(mysql_do -N -B -e "select count(*) from information_schema.tables where table_schema = '$DB_DATABASE'")"

if [ "${RESTORE_CONFIRM:-}" != "$DB_DATABASE" ]; then
  echo "戻す先：DB「$DB_DATABASE」（今ある表：$TABLES 個）、アプリ $APP_DIR"
  echo "この DB の中身は、バックアップの中身で上書きされます。"
  echo "進めるには、RESTORE_CONFIRM=$DB_DATABASE を付けてもう一度実行してください。"
  exit 1
fi
if [ "$TABLES" != "0" ] && [ "${RESTORE_OVERWRITE:-0}" != "1" ]; then
  fail "DB「$DB_DATABASE」には表が $TABLES 個あります。上書きしてよければ RESTORE_OVERWRITE=1 を付けてください"
fi

# アプリが見ている DB と、これから戻す DB が同じであること（設定のキャッシュが古いと、別の DB を確かめてしまう）
APP_DB="$(php artisan tinker --execute='echo Illuminate\Support\Facades\DB::connection()->getDatabaseName();' | tail -1 | tr -d '[:space:]')"
[ "$APP_DB" = "$DB_DATABASE" ] || fail "アプリが見ている DB（$APP_DB）と戻す先（$DB_DATABASE）が違います。php artisan config:clear のあとでやり直してください"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "==> 開ける"
age -d -i "$IDENTITY" "$BUNDLE" | tar -xz -C "$WORK" || fail "束を開けられません（秘密鍵が違うか、ファイルが壊れています）"
[ -f "$WORK/db.sql" ] && [ -f "$WORK/manifest.json" ] || fail "束の中に db.sql か manifest.json がありません"
if [ -d "$WORK/evidences" ]; then
  echo "    束の中の写真：$(find "$WORK/evidences" -type f | wc -l | tr -d ' ') 個"
else
  echo "    束の中の写真：なし"
fi

if [ "$TABLES" != "0" ]; then
  # 今ある表をすべて消してから戻す（バックアップにない表が残ると、あとの移行が「表がすでにある」で止まる）
  echo "==> 今ある表を消す（$DB_DATABASE、$TABLES 個）"
  php artisan db:wipe --force >/dev/null
fi

echo "==> DB に戻す（$DB_DATABASE）"
mysql_do "$DB_DATABASE" < "$WORK/db.sql"

if [ "${RESTORE_EVIDENCES:-0}" = "1" ] && [ -d "$WORK/evidences" ]; then
  EVIDENCE_DIR="${EVIDENCE_DIR:-$APP_DIR/storage/app/private/evidences}"
  echo "==> 写真を戻す（$EVIDENCE_DIR）"
  mkdir -p "$EVIDENCE_DIR"
  cp -a "$WORK/evidences/." "$EVIDENCE_DIR/"
  if getent group www-data >/dev/null 2>&1; then
    # Web とキューの両方が読めるように（docs/runbook.md の「写真保存先の権限復旧」と同じ）
    chgrp -R www-data "$EVIDENCE_DIR" 2>/dev/null || echo "    グループを www-data にできませんでした（sudo で「写真保存先の権限復旧」を実行してください）"
    find "$EVIDENCE_DIR" -type d -exec chmod 2770 {} +
    find "$EVIDENCE_DIR" -type f -exec chmod 660 {} +
  fi
fi

# アプリの鍵が違うと、2段階認証の秘密を読めずにログインできない。値は表示せず、同じかどうかだけ見る
if [ "${RESTORE_USE_BACKUP_KEY:-0}" = "1" ]; then
  [ -f "$WORK/env" ] || fail "束の中に .env がありません"
  APP_KEY="$(grep '^APP_KEY=' "$WORK/env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//')"
  [ -n "$APP_KEY" ] || fail "束の中の .env に APP_KEY がありません"
  export APP_KEY
  echo "    アプリの鍵：確かめるあいだだけ、束の中のものを使います（.env は変えません。束の .env は残しません）"
elif [ -f "$WORK/env" ]; then
  KEPT="$HOME/torudake-restored-env-$(date -u +%Y%m%dT%H%M%SZ)"
  cp "$WORK/env" "$KEPT" && chmod 600 "$KEPT"
  if [ "$(grep '^APP_KEY=' "$WORK/env" | sha256sum)" != "$(grep '^APP_KEY=' .env | sha256sum)" ]; then
    echo "注意：戻す先の APP_KEY が、バックアップを取ったときと違います。"
    echo "      $KEPT の APP_KEY の行を .env に写し、php artisan config:cache のあと、RESTORE_OVERWRITE=1 を足してもう一度実行してください。"
  fi
  echo "    バックアップの .env は $KEPT に置きました（持ち主だけが読める）。使い終わったら消してください"
fi

echo "==> 確かめる（控えと比べる・アプリの鍵・Excel の作り直し）"
php artisan torudake:verify-restore "$WORK/manifest.json" --rebuild-workbooks

echo "==> あとから足された移行があれば当てる"
php artisan migrate --force

echo "==> 復元完了"
