#!/usr/bin/env bash
# 撮って送るだけ帳簿：サーバーの外へのバックアップ（#35）
#
# DB・写真・.env を1つに束ね、age の公開鍵で暗号化して、rclone でサーバーの外の保管先へ送る。
# サーバーに置くのは公開鍵だけ。開ける鍵（秘密鍵）はサーバーに置かない（サーバーごと失っても、鍵は手元に残る）。
#
# 使い方（VPS で本人が設定する。AI は本番に SSH しない。手順は docs/runbook.md の「サーバーの外へのバックアップ」）:
#   bash /var/www/torudake/docs/server/backup-torudake.sh           取って、送って、確かめる
#   bash /var/www/torudake/docs/server/backup-torudake.sh --check   保管先の中身だけ確かめる（最新が新しいか・期限切れが消えているか・大きさ）
#   bash /var/www/torudake/docs/server/backup-torudake.sh --jst-hour 03
#       日本時間の3時台のときだけ取る（それ以外の時刻は何もせずに終わる）。cron で毎時30分に呼ぶと、
#       サーバーの時計がどの時刻帯でも、日本時間の 3:30 に1回だけ動く
#
# 設定は ~/.config/torudake-backup.env に書く（持ち主だけが読める 600。値はリポジトリ・issue・チャットに貼らない）:
#   BACKUP_REMOTE=r2:バケット名/torudake        rclone の「保管先:場所」
#   BACKUP_AGE_RECIPIENT=age1...                暗号化に使う公開鍵
#   RCLONE_CONFIG_R2_TYPE=s3                    ここから下は rclone の保管先 r2 の設定（Cloudflare R2）
#   RCLONE_CONFIG_R2_PROVIDER=Cloudflare
#   RCLONE_CONFIG_R2_ACCESS_KEY_ID=...
#   RCLONE_CONFIG_R2_SECRET_ACCESS_KEY=...
#   RCLONE_CONFIG_R2_ENDPOINT=https://（アカウントID）.r2.cloudflarestorage.com
#   BACKUP_RETENTION_DAYS=30                    保管先の自動削除（ライフサイクル）と同じ日数。省略すると 30
#   BACKUP_MAX_AGE_HOURS=26                     最新がこれより古いと --check が失敗する。省略すると 26
#   BACKUP_QUOTA_MB=10240                       保管先の大きさの目安（R2 の無料の範囲は 10GB）。見込みが8割を超えると注意を出す。省略すると 10240
#
# 古いバックアップを消すのは保管先の自動削除（ライフサイクル）で、このスクリプトは消さない。
set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-/var/www/torudake}"
CONFIG="${BACKUP_CONFIG:-$HOME/.config/torudake-backup.env}"
PREFIX="torudake-"
SUFFIX=".tar.gz.age"

fail() { echo "NG $*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || fail "$1 が入っていません（sudo apt install $2）"; }

# 日本時間の指定した時だけ進む。JST-9 は「UTC より9時間進んだ JST」という書き方で、サーバーに時刻帯のデータがなくても働く
if [ "${1:-}" = "--jst-hour" ]; then
  WANT_HOUR="${2:-}"
  case "$WANT_HOUR" in
    [01][0-9]|2[0-3]) ;;
    *) fail "--jst-hour には 00〜23 の2桁を指定してください（例：--jst-hour 03）" ;;
  esac
  [ "$(TZ=JST-9 date +%H)" = "$WANT_HOUR" ] || exit 0
  shift 2
fi

[ -f "$CONFIG" ] || fail "設定がありません：$CONFIG（docs/runbook.md の「サーバーの外へのバックアップ」）"
PERM="$(stat -c %a "$CONFIG")"
case "$PERM" in
  600|400) ;;
  *) fail "設定がほかの人にも読めます（$PERM）。chmod 600 $CONFIG を実行してください" ;;
esac
set -a
# shellcheck disable=SC1090
. "$CONFIG"
set +a

: "${BACKUP_REMOTE:?設定に BACKUP_REMOTE がありません}"
: "${BACKUP_AGE_RECIPIENT:?設定に BACKUP_AGE_RECIPIENT がありません}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
MAX_AGE_HOURS="${BACKUP_MAX_AGE_HOURS:-26}"
QUOTA_MB="${BACKUP_QUOTA_MB:-10240}"

case "$BACKUP_AGE_RECIPIENT" in
  age1*) ;;
  AGE-SECRET-KEY-*) fail "BACKUP_AGE_RECIPIENT に秘密鍵が入っています。サーバーには公開鍵（age1...）だけを置いてください" ;;
  *) fail "BACKUP_AGE_RECIPIENT が age の公開鍵（age1...）ではありません" ;;
esac

need age age
need rclone rclone
# rclone の設定は上の RCLONE_CONFIG_R2_* だけを使う（設定ファイルは使わないので、「見つからない」の表示を出さない）
export RCLONE_CONFIG="${RCLONE_CONFIG:-/dev/null}"

# 名前の日時（UTC）を秒にする：torudake-20261121T031500Z.tar.gz.age
stamp_seconds() {
  local stamp="${1#"$PREFIX"}"
  stamp="${stamp%"$SUFFIX"}"
  date -u -d "${stamp:0:4}-${stamp:4:2}-${stamp:6:2} ${stamp:9:2}:${stamp:11:2}:${stamp:13:2}" +%s
}

mb() { echo $(( ($1 + 524288) / 1048576 )); }

# 保管先の大きさと、このまま増えたときの見込み。失敗にはしない（注意を出すだけ）。
# 束には毎回「その時点の写真の全部」が入る。写真はアプリが30日で消すので、束は30日分の写真で頭打ちになり、
# 保管先にはそれが保管の日数ぶん並ぶ。見込み＝（いちばん小さい束＋1日に増える大きさ×30）×保管の日数
report_size() {
  local listing="$1" span_days="$2" total newest_size smallest growth steady projected
  total="$(printf '%s\n' "$listing" | awk -F';' '{ s += $2 } END { printf "%d", s }')"
  newest_size="$(printf '%s\n' "$listing" | tail -1 | cut -d';' -f2)"
  smallest="$(printf '%s\n' "$listing" | awk -F';' 'NR == 1 || $2 < m { m = $2 } END { printf "%d", m }')"

  if [ "$span_days" -lt 1 ]; then
    echo "大きさ：合計 $(mb "$total") MB／最新 $(mb "$newest_size") MB（見込みは、2日分たまってから出ます）"
    return 0
  fi

  growth=$(( (newest_size - $(printf '%s\n' "$listing" | head -1 | cut -d';' -f2)) / span_days ))
  [ "$growth" -gt 0 ] || growth=0
  steady=$(( smallest + growth * 30 ))
  [ "$steady" -ge "$newest_size" ] || steady="$newest_size"
  projected=$(( steady * RETENTION_DAYS ))

  echo "大きさ：合計 $(mb "$total") MB／最新 $(mb "$newest_size") MB／1日に約 $(mb "$growth") MB ずつ増加／このままだと ${RETENTION_DAYS} 日分で約 $(mb "$projected") MB（目安 ${QUOTA_MB} MB）"
  if [ "$(mb "$projected")" -gt $(( QUOTA_MB * 8 / 10 )) ]; then
    echo "注意：このまま増えると、保管先の目安（${QUOTA_MB} MB）の8割を超えます。docs/runbook.md §1.4 の「大きさが目安を超えそうなとき」を見てください"
  fi
}

# 保管先の中身を確かめる：1つ以上ある・最新が新しい・期限切れが消えている
check_remote() {
  local listing names newest oldest now newest_age_hours oldest_age_days count
  listing="$(rclone lsf "$BACKUP_REMOTE" --files-only --format ps --separator ';' --include "${PREFIX}*${SUFFIX}" | sort)"
  names="$(printf '%s\n' "$listing" | cut -d';' -f1)"
  [ -n "$names" ] || fail "保管先にバックアップが1つもありません"

  count="$(printf '%s\n' "$names" | wc -l | tr -d ' ')"
  newest="$(printf '%s\n' "$names" | tail -1)"
  oldest="$(printf '%s\n' "$names" | head -1)"
  now="$(date -u +%s)"
  newest_age_hours=$(( (now - $(stamp_seconds "$newest")) / 3600 ))
  oldest_age_days=$(( (now - $(stamp_seconds "$oldest")) / 86400 ))

  echo "保管先：${count} 個／最新 ${newest}（${newest_age_hours} 時間前）／最古 ${oldest}（${oldest_age_days} 日前）"
  report_size "$listing" $(( ($(stamp_seconds "$newest") - $(stamp_seconds "$oldest")) / 86400 ))

  [ "$newest_age_hours" -le "$MAX_AGE_HOURS" ] || fail "最新のバックアップが ${newest_age_hours} 時間前です（${MAX_AGE_HOURS} 時間以内のはず）"
  # 自動削除は1日に1回なので、2日の余裕を見る
  [ "$oldest_age_days" -le $(( RETENTION_DAYS + 2 )) ] || fail "${RETENTION_DAYS} 日を過ぎたバックアップが残っています（保管先の自動削除が働いていません）"
  echo "OK 保管先の確認"
}

if [ "${1:-}" = "--check" ]; then
  check_remote
  exit 0
fi

need mysqldump mysql-client
need php php-cli
cd "$APP_DIR"
[ -f .env ] || fail "$APP_DIR/.env がありません"

env_get() { grep "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_DATABASE="${DB_DATABASE:-$(env_get DB_DATABASE)}"   # 復元の練習のときだけ、外から別の DB を指定できる
DB_USERNAME="$(env_get DB_USERNAME)"
DB_PASSWORD="$(env_get DB_PASSWORD)"
DB_HOST="$(env_get DB_HOST)"
DB_PORT="$(env_get DB_PORT)"
EVIDENCE_DIR="${EVIDENCE_DIR:-$APP_DIR/storage/app/private/evidences}"
export DB_DATABASE

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="${PREFIX}${STAMP}${SUFFIX}"

echo "==> 控え（件数と貸借の合計だけ）"
php artisan torudake:backup-manifest > "$WORK/manifest.json"

echo "==> DB（$DB_DATABASE）"
MYSQL_PWD="$DB_PASSWORD" mysqldump --no-tablespaces --single-transaction \
  -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" "$DB_DATABASE" > "$WORK/db.sql"

echo "==> 設定（.env。アプリの鍵が入っている）"
cp "${BACKUP_ENV_FILE:-.env}" "$WORK/env"   # 復元の練習のときだけ、外から別のファイルを指定できる

echo "==> 束ねて暗号化（写真を含む）"
tar -C "$WORK" -cf "$WORK/bundle.tar" manifest.json db.sql env
if [ -d "$EVIDENCE_DIR" ]; then
  tar -C "$(dirname "$EVIDENCE_DIR")" --transform "s|^$(basename "$EVIDENCE_DIR")|evidences|" -rf "$WORK/bundle.tar" "$(basename "$EVIDENCE_DIR")"
else
  echo "    写真の置き場がありません（$EVIDENCE_DIR）。DB と設定だけを送ります"
fi
gzip -c "$WORK/bundle.tar" | age -r "$BACKUP_AGE_RECIPIENT" -o "$WORK/$NAME"
rm -f "$WORK/bundle.tar" "$WORK/db.sql" "$WORK/env"
SIZE="$(stat -c %s "$WORK/$NAME")"

echo "==> 送る（$NAME、$SIZE バイト）"
rclone copyto "$WORK/$NAME" "$BACKUP_REMOTE/$NAME"
REMOTE_SIZE="$(rclone lsf "$BACKUP_REMOTE" --files-only --format s --include "$NAME" | tr -d ' \n')"
[ "$REMOTE_SIZE" = "$SIZE" ] || fail "送ったあとの大きさが違います（手元 $SIZE／保管先 ${REMOTE_SIZE:-なし}）"

echo "==> 成功を記録（/health がこの時刻を見る）"
php artisan torudake:backup-recorded >/dev/null

check_remote
echo "==> バックアップ完了（$NAME）"
