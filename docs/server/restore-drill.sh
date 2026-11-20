#!/usr/bin/env bash
# 撮って送るだけ帳簿：復元の練習（#35）
#
# 架空のデータだけで「取る → 暗号化 → 送る → 開ける → 戻す → 確かめる」を通し、
# バックアップと復元のスクリプトが本当に働くかを確かめる。開発の Sail の中で動かす（本番では動かさない）。
# 保管先は一時フォルダ（rclone はフォルダも保管先にできる）。本物の保管先・本物の鍵・本物のデータは使わない。
#
# 前準備（Mac で。開発のコンテナに道具と練習用の DB を足すだけ）:
#   docker compose exec -T -u root laravel.test bash -c "apt-get update -qq && apt-get install -y -qq age rclone"
#   docker compose exec -T mysql bash -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS torudake_drill_src; CREATE DATABASE IF NOT EXISTS torudake_drill_dst; GRANT ALL ON \`torudake\_drill\_%\`.* TO \"$MYSQL_USER\"@\"%\";"'
# 実行（Mac で）:
#   vendor/bin/sail bash docs/server/restore-drill.sh
set -euo pipefail
umask 077

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
SRC="torudake_drill_src"
DST="torudake_drill_dst"
cd "$APP_DIR"

fail() { echo "NG $*" >&2; exit 1; }
step() { echo; echo "==> $*"; }
env_get() { grep "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

[ "$(env_get APP_ENV)" != "production" ] || fail "本番では動かしません"
for tool in age age-keygen rclone mysqldump mysql php; do
  command -v "$tool" >/dev/null 2>&1 || fail "$tool がありません（このファイルの先頭の「前準備」を実行してください）"
done

# 練習のあいだは、本物の AI・メール・キューを使わない。DB は練習用だけを指す
export AI_DRIVER=fake MAIL_MAILER=array QUEUE_CONNECTION=sync CACHE_STORE=array

# 大事な守り：アプリが本当に練習用の DB を見ていることを確かめてから、作り直し・消去をする。
# 設定のキャッシュ（bootstrap/cache/config.php）があると、DB_DATABASE を渡しても開発の DB を見たままになる
[ ! -f bootstrap/cache/config.php ] || fail "設定のキャッシュがあります。php artisan config:clear のあとでやり直してください"
assert_db() {
  local seen
  seen="$(DB_DATABASE="$1" php artisan tinker --execute='echo Illuminate\Support\Facades\DB::connection()->getDatabaseName();' | tail -1 | tr -d '[:space:]')"
  [ "$seen" = "$1" ] || fail "アプリが $1 ではなく「$seen」を見ています。開発の DB を守るため、ここで止めます"
  case "$seen" in torudake_drill_*) ;; *) fail "練習用ではない DB（$seen）です" ;; esac
}
assert_db "$SRC"
assert_db "$DST"

WORK="$(mktemp -d)"
RESULT=()
cleanup() {
  # Excel の作り直しでできたファイル（練習用の会社のもの）を消す
  if [ -f "$WORK/company-ids" ]; then
    while read -r id; do
      [ -n "$id" ] && rm -f "$APP_DIR/storage/app/private/workbooks/$id.xlsx"
    done < "$WORK/company-ids"
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT
export HOME="$WORK/home"   # 復元のスクリプトが置く「バックアップの .env」を、練習のあとに残さない
mkdir -p "$HOME" "$WORK/remote" "$WORK/photos/company-a" "$WORK/restored-photos"

step "鍵を作る（練習用。正しい鍵と、わざと違う鍵）"
age-keygen -o "$WORK/key.txt" 2>/dev/null
age-keygen -o "$WORK/wrong-key.txt" 2>/dev/null
RECIPIENT="$(age-keygen -y "$WORK/key.txt")"

printf 'BACKUP_REMOTE=%s\nBACKUP_AGE_RECIPIENT=%s\n' "$WORK/remote" "$RECIPIENT" > "$WORK/backup.env"
chmod 600 "$WORK/backup.env"
printf '架空の写真1\n' > "$WORK/photos/company-a/receipt-1.jpg"
head -c 4096 /dev/urandom > "$WORK/photos/company-a/receipt-2.jpg"

step "架空のデータを作る（$SRC）"
DB_DATABASE="$SRC" php artisan migrate:fresh --force --seed --seeder='Database\Seeders\RestoreDrillSeeder' >/dev/null
DB_DATABASE="$SRC" php artisan torudake:backup-manifest > "$WORK/source-manifest.json"

step "取って、暗号化して、送る"
APP_DIR="$APP_DIR" BACKUP_CONFIG="$WORK/backup.env" DB_DATABASE="$SRC" EVIDENCE_DIR="$WORK/photos" \
  bash docs/server/backup-torudake.sh
BUNDLE="$(find "$WORK/remote" -name 'torudake-*.tar.gz.age' | head -1)"
[ -n "$BUNDLE" ] || fail "保管先に束がありません"
RESULT+=("取る・暗号化・送る：成功（$(basename "$BUNDLE")、$(stat -c %s "$BUNDLE") バイト）")

step "束は暗号化されている（中に DB の文字が見えない）・違う鍵では開かない"
if grep -q -a 'CREATE TABLE' "$BUNDLE"; then fail "束の中に DB の文字がそのまま見えます"; fi
if age -d -i "$WORK/wrong-key.txt" "$BUNDLE" >/dev/null 2>&1; then fail "違う鍵で開いてしまいました"; fi
RESULT+=("暗号化：束に DB の文字は見えず、違う鍵では開かない")

step "空の DB に戻す（$DST）"
DB_DATABASE="$DST" php artisan db:wipe --force >/dev/null
if APP_DIR="$APP_DIR" DB_DATABASE="$DST" bash docs/server/restore-torudake.sh "$BUNDLE" "$WORK/key.txt" >/dev/null 2>&1; then
  fail "確認の指定（RESTORE_CONFIRM）なしで復元が進みました"
fi
APP_DIR="$APP_DIR" DB_DATABASE="$DST" RESTORE_CONFIRM="$DST" RESTORE_EVIDENCES=1 EVIDENCE_DIR="$WORK/restored-photos" \
  bash docs/server/restore-torudake.sh "$BUNDLE" "$WORK/key.txt" | tee "$WORK/restore.log"
grep -q '復元の確認：すべて合っています' "$WORK/restore.log" || fail "復元の確認が通っていません"
DB_DATABASE="$DST" php artisan tinker --execute='App\Models\Company::query()->pluck("id")->each(fn ($id) => print($id.PHP_EOL));' > "$WORK/company-ids"
RESULT+=("復元：$(grep -E '^OK ' "$WORK/restore.log" | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/／/g')")

step "写真が同じ中身で戻っている"
diff -r "$WORK/photos" "$WORK/restored-photos" >/dev/null || fail "戻した写真が元と違います"
RESULT+=("写真：2枚とも同じ中身で戻った")

step "表がある DB には、上書きの指定なしでは戻さない"
if APP_DIR="$APP_DIR" DB_DATABASE="$DST" RESTORE_CONFIRM="$DST" bash docs/server/restore-torudake.sh "$BUNDLE" "$WORK/key.txt" >/dev/null 2>&1; then
  fail "表がある DB に、上書きの指定なしで戻してしまいました"
fi
RESULT+=("上書きの守り：確認の指定なし・上書きの指定なしでは止まる")

step "Excel が壊れていると確認が気づき、作り直すと直る"
FIRST_ID="$(head -1 "$WORK/company-ids")"
age -d -i "$WORK/key.txt" "$BUNDLE" | tar -xz -C "$WORK" manifest.json
printf 'これは Excel ではない' > "$APP_DIR/storage/app/private/workbooks/$FIRST_ID.xlsx"
if DB_DATABASE="$DST" php artisan torudake:verify-restore "$WORK/manifest.json" >/dev/null 2>&1; then
  fail "Excel が壊れているのに、確認が通ってしまいました"
fi
DB_DATABASE="$DST" php artisan torudake:verify-restore "$WORK/manifest.json" --rebuild-workbooks >/dev/null \
  || fail "Excel を作り直しても、確認が通りません（2回目の確認が通らない）"
RESULT+=("Excel：壊れたファイルで確認が失敗し、DB から作り直すと通る（確認は2回目も通る）")

step "戻したあとに中身が変わると、確認が気づく"
DB_DATABASE="$DST" php artisan tinker --execute='$e = App\Models\JournalEntry::query()->where("status", "draft")->firstOrFail(); $e->forceFill(["status" => "confirmed", "confirmed_at" => now()])->save(); echo "changed";' | grep -q changed \
  || fail "練習用の変更（下書きを1件、確定にする）ができませんでした"
if DB_DATABASE="$DST" php artisan torudake:verify-restore "$WORK/manifest.json" --skip-workbooks > "$WORK/changed.log" 2>&1; then
  fail "中身を変えたのに、確認が通ってしまいました"
fi
grep -q 'journal_entries_by_status' "$WORK/changed.log" && grep -q 'confirmed_debit_yen' "$WORK/changed.log" || fail "どこが違うかが表示されていません"
RESULT+=("変化の検出：下書きを1件だけ確定に変えると、件数と貸借の合計の違いとして出る")

step "戻した環境で、ログイン → 権限 → 送る → 下書き → 保存 → Excel を通す（AI は偽物。写真と Excel は捨てる置き場）"
DB_DATABASE="$DST" php artisan tinker --execute='require base_path("docs/server/restore-drill-walk.php");' > "$WORK/walk.log" 2>&1 || true
if ! grep -q '^WALK-OK$' "$WORK/walk.log"; then
  cat "$WORK/walk.log" >&2
  fail "戻した環境で、いつもの流れが通りません"
fi
RESULT+=("通し（処理を直接呼ぶ。画面は開かない）：$(grep -E '^OK ' "$WORK/walk.log" | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/／/g')")

step "中身が変わった DB に上書きで戻すと、また控えと同じになる（控えにない表も消える）"
DB_DATABASE="$DST" php artisan tinker --execute='Illuminate\Support\Facades\DB::statement("create table drill_leftover (id int)"); echo "made";' | grep -q made \
  || fail "練習用の余分な表を作れませんでした"
APP_DIR="$APP_DIR" DB_DATABASE="$DST" RESTORE_CONFIRM="$DST" RESTORE_OVERWRITE=1 \
  bash docs/server/restore-torudake.sh "$BUNDLE" "$WORK/key.txt" > "$WORK/overwrite.log" 2>&1 \
  || { cat "$WORK/overwrite.log" >&2; fail "上書きの復元が失敗しました"; }
grep -q '復元の確認：すべて合っています' "$WORK/overwrite.log" || fail "上書きのあとの確認が通っていません"
LEFT="$(DB_DATABASE="$DST" php artisan tinker --execute='echo Illuminate\Support\Facades\Schema::hasTable("drill_leftover") ? "left" : "gone";' | tail -1 | tr -d '[:space:]')"
[ "$LEFT" = "gone" ] || fail "上書きで戻したのに、余分な表が残っています"
RESULT+=("上書き：変わった DB（確定が2件増え、余分な表が1つ）に上書きで戻すと、控えと同じに戻る")

step "保管先の確認：期限切れが残っている・最新が古い、に気づく"
BACKUP_CONFIG="$WORK/backup.env" bash docs/server/backup-torudake.sh --check >/dev/null || fail "正常な保管先で確認が失敗しました"
OLD="torudake-$(date -u -d '40 days ago' +%Y%m%dT%H%M%SZ).tar.gz.age"
cp "$BUNDLE" "$WORK/remote/$OLD"
if BACKUP_CONFIG="$WORK/backup.env" bash docs/server/backup-torudake.sh --check >/dev/null 2>&1; then
  fail "40日前の束が残っているのに、確認が通りました"
fi
rm -f "$WORK/remote/$OLD"
STALE="torudake-$(date -u -d '2 days ago' +%Y%m%dT%H%M%SZ).tar.gz.age"
mv "$BUNDLE" "$WORK/remote/$STALE"
if BACKUP_CONFIG="$WORK/backup.env" bash docs/server/backup-torudake.sh --check >/dev/null 2>&1; then
  fail "最新が2日前なのに、確認が通りました"
fi
RESULT+=("保管先の確認：期限切れ（40日前）が残っている・最新が古い（2日前）で失敗する")

step "設定がほかの人に読めると止まる・秘密鍵を公開鍵の欄に入れると止まる"
chmod 644 "$WORK/backup.env"
if BACKUP_CONFIG="$WORK/backup.env" bash docs/server/backup-torudake.sh --check >/dev/null 2>&1; then
  fail "設定がほかの人に読めるのに進みました"
fi
chmod 600 "$WORK/backup.env"
printf 'BACKUP_REMOTE=%s\nBACKUP_AGE_RECIPIENT=%s\n' "$WORK/remote" "$(grep -v '^#' "$WORK/key.txt")" > "$WORK/bad.env"
chmod 600 "$WORK/bad.env"
if BACKUP_CONFIG="$WORK/bad.env" bash docs/server/backup-torudake.sh --check >/dev/null 2>&1; then
  fail "秘密鍵を公開鍵の欄に入れたのに進みました"
fi
RESULT+=("設定の守り：読める人が多い設定・秘密鍵の置き間違いで止まる")

step "後片付け（練習用の DB を空にする）"
DB_DATABASE="$SRC" php artisan db:wipe --force >/dev/null
DB_DATABASE="$DST" php artisan db:wipe --force >/dev/null

echo
echo "==== 復元の練習の記録（$(date -u +%Y-%m-%dT%H:%MZ)、コミット $(git rev-parse --short HEAD 2>/dev/null || echo '不明')） ===="
echo "控え（架空のデータ）：$(tr -d ' \n' < "$WORK/source-manifest.json")"
for line in "${RESULT[@]}"; do echo "- $line"; done
echo "すべて通りました。"
