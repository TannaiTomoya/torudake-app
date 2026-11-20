#!/usr/bin/env bash
# 撮って送るだけ帳簿：本番の束を、手元で1回戻してみる（#35。docs/runbook.md §1.4 の最後）
#
# 保管先から落とした本物の束を、Mac に置いた本物の秘密鍵で開け、開発の Sail の「練習用の DB」に戻して確かめる。
# 「束が開く・DB が戻る・件数と貸借の合計が控えと同じ・アプリの鍵が合う・Excel を作り直せる」までを見る。
# 本番には触らない。開発の DB（ふだん使うほう）にも触らない。終わると、戻した中身・作った Excel・コンテナに写した束と鍵を消す。
# 件数や金額は表示しない（合っているかどうかだけ）。写真は戻さない（束に入っている数だけ表示する）。
#
# 前準備（Mac で。初めてのときだけ。道具と練習用の DB）：docs/server/restore-drill.sh の先頭の「前準備」と同じ
# 実行（Mac で。束と鍵は作業フォルダに置かず、コンテナの /tmp に写す）:
#   docker compose cp ~/Downloads/torudake-（日時）.tar.gz.age laravel.test:/tmp/trial.tar.gz.age
#   docker compose cp ~/torudake-backup-key.txt laravel.test:/tmp/trial-key.txt
#   vendor/bin/sail bash docs/server/restore-trial.sh /tmp/trial.tar.gz.age /tmp/trial-key.txt
set -euo pipefail
umask 077

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
DST="torudake_drill_dst"
BUNDLE="${1:?使い方：restore-trial.sh 束のファイル 秘密鍵のファイル}"
IDENTITY="${2:?使い方：restore-trial.sh 束のファイル 秘密鍵のファイル}"
cd "$APP_DIR"

fail() { echo "NG $*" >&2; exit 1; }
env_get() { grep "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

[ "$(env_get APP_ENV)" != "production" ] || fail "本番では動かしません（Mac の開発環境で動かす）"
[ ! -f bootstrap/cache/config.php ] || fail "設定のキャッシュがあります。php artisan config:clear のあとでやり直してください"
for tool in age mysql php; do
  command -v "$tool" >/dev/null 2>&1 || fail "$tool がありません（docs/server/restore-drill.sh の先頭の「前準備」を実行してください）"
done
[ -f "$BUNDLE" ] || fail "束のファイルがありません：$BUNDLE"
[ -f "$IDENTITY" ] || fail "秘密鍵のファイルがありません：$IDENTITY"

# 本物のデータを戻すので、AI・メール・キューは必ず偽物にする
export AI_DRIVER=fake MAIL_MAILER=array QUEUE_CONNECTION=sync CACHE_STORE=array

seen="$(DB_DATABASE="$DST" php artisan tinker --execute='echo Illuminate\Support\Facades\DB::connection()->getDatabaseName();' | tail -1 | tr -d '[:space:]')"
[ "$seen" = "$DST" ] || fail "アプリが $DST ではなく「$seen」を見ています。開発の DB を守るため、ここで止めます"

WORK="$(mktemp -d)"
cleanup() {
  # 戻した会社の Excel（作り直したもの）→ 戻した DB → コンテナに写した束と鍵、の順に消す
  DB_DATABASE="$DST" php artisan tinker --execute='if (Illuminate\Support\Facades\Schema::hasTable("companies")) { App\Models\Company::query()->pluck("id")->each(fn ($id) => print($id.PHP_EOL)); }' > "$WORK/company-ids" 2>/dev/null || true
  while read -r id; do
    case "$id" in
      [0-9a-f]*-*-*-*-*) rm -f "$APP_DIR/storage/app/private/workbooks/$id.xlsx" ;;
    esac
  done < "$WORK/company-ids"
  DB_DATABASE="$DST" php artisan db:wipe --force >/dev/null 2>&1 || echo "注意：練習用の DB（$DST）を空にできませんでした。手で空にしてください" >&2
  if [ "${RESTORE_TRIAL_KEEP_FILES:-0}" != "1" ]; then
    rm -f "$BUNDLE" "$IDENTITY"
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT
export HOME="$WORK/home"
mkdir -p "$HOME"

echo "==> 練習用の DB を空にする（$DST）"
DB_DATABASE="$DST" php artisan db:wipe --force >/dev/null

echo "==> 開けて、戻して、確かめる"
if ! APP_DIR="$APP_DIR" DB_DATABASE="$DST" RESTORE_CONFIRM="$DST" RESTORE_USE_BACKUP_KEY=1 \
  bash docs/server/restore-torudake.sh "$BUNDLE" "$IDENTITY" > "$WORK/restore.log" 2>&1; then
  # 違いの行には件数や金額が出るので、ここでは見出しだけ出す
  grep -E '^(NG|注意|-- |   .*：バックアップ )' "$WORK/restore.log" | sed -e 's/：バックアップ .*$//' >&2 || true
  fail "復元の試しが通りませんでした（上の行が理由）"
fi
grep -q '復元の確認：すべて合っています' "$WORK/restore.log" || fail "復元の確認が通っていません"

echo
echo "==== 復元の試しの記録（$(TZ=JST-9 date +%Y-%m-%dT%H:%M) 日本時間） ===="
echo "- 束：$(basename "$BUNDLE")（$(stat -c %s "$BUNDLE") バイト）を、手元の秘密鍵で開けた"
grep -E '束の中の写真' "$WORK/restore.log" | sed -e 's/^ */- /'
grep -E '^(OK |-- )' "$WORK/restore.log" | sed -e 's/^/- /'
echo "- 戻した中身・作り直した Excel・コンテナに写した束と鍵は、このあと消します"
echo "すべて通りました。issue に書くのは、日付と「通った」だけにしてください（件数や金額は書かない）。"
