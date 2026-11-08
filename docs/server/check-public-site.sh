#!/usr/bin/env bash
# 公開サイトの外形確認（デプロイのあと・定期監視で使う）。VPS には入らず、外から HTTPS で見るだけ。
#   使い方：bash docs/server/check-public-site.sh [サイトのURL]
#   環境変数：RETRIES（確認の回数、既定1）・WAIT（回の間の秒、既定15）・TIMEOUT（1回の待ち秒、既定15）
# 次を区別して報告し、1つでも悪ければ終了コード 1。
#   - /login が 200 で、ログイン画面（パスワード欄）が出ている。別のサイトへのリダイレクトや、別のページの 200 は成功にしない
#   - /health が 200 で、このアプリの印（"app":"torudake"・"ok":true）がある
#   - 500・接続できない・時間切れ・証明書エラー・定期処理やキューの「生きている印」が古い（503）
# これは「いま動いている」の確認であって、稼働の保証ではない。
set -uo pipefail

BASE_URL="${1:-${SITE_URL:-https://torudake-app.com}}"
BASE_URL="${BASE_URL%/}"
RETRIES="${RETRIES:-1}"
WAIT="${WAIT:-15}"
TIMEOUT="${TIMEOUT:-15}"

EXPECTED_HOST="${BASE_URL#*://}"
EXPECTED_HOST="${EXPECTED_HOST%%/*}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
BODY="$WORK/body"
HEADERS="$WORK/headers"

CODE=000
CURL_EXIT=0
LOCATION=""

fetch() {
  : >"$BODY"
  : >"$HEADERS"
  CODE="$(curl -sS -m "$TIMEOUT" -o "$BODY" -D "$HEADERS" -w '%{http_code}' "$BASE_URL$1" 2>/dev/null)"
  CURL_EXIT=$?
  LOCATION="$(awk 'tolower($1)=="location:" {print $2}' "$HEADERS" | tr -d '\r' | tail -n 1)"
}

# 通信そのものが失敗したときの説明
describe_curl_error() {
  case "$CURL_EXIT" in
    60 | 35 | 51 | 58 | 59 | 77 | 83 | 90 | 91) echo "HTTPS の証明書または接続に問題があります（curl 終了コード $CURL_EXIT）" ;;
    28) echo "${TIMEOUT}秒以内に応答がありません" ;;
    6 | 7) echo "接続できません（名前解決できない、または接続を断られました。curl 終了コード $CURL_EXIT）" ;;
    *) echo "接続に失敗しました（curl 終了コード $CURL_EXIT）" ;;
  esac
}

# 3xx のとき、どこへ移ろうとしているか
describe_redirect() {
  local host
  host="${LOCATION#*://}"
  host="${host%%/*}"

  if [[ "$LOCATION" == *"://"* && "$host" != "$EXPECTED_HOST" ]]; then
    echo "別のサイトへ移動しています（HTTP $CODE → $host）。これは成功にしません"
  else
    echo "想定外のリダイレクトです（HTTP $CODE）"
  fi
}

# /login と /health に共通の判定。悪ければ説明を出して 1。良ければ何も出さず 0。
common_failure() {
  if [ "$CURL_EXIT" -ne 0 ]; then
    describe_curl_error
    return 1
  fi

  case "$CODE" in
    3??)
      describe_redirect
      return 1
      ;;
    5??)
      return 2
      ;;
  esac

  return 0
}

check_login() {
  fetch /login
  local message
  message="$(common_failure)"
  local status=$?

  if [ "$status" -eq 1 ]; then
    echo "NG /login：$message"
    return 1
  fi

  if [ "$status" -eq 2 ]; then
    echo "NG /login：サーバーエラーです（HTTP $CODE）"
    return 1
  fi

  if [ "$CODE" != "200" ]; then
    echo "NG /login：想定外の応答です（HTTP $CODE）"
    return 1
  fi

  if ! grep -q 'type="password"' "$BODY"; then
    echo "NG /login：200 ですが、ログイン画面ではありません（別のページの可能性）"
    return 1
  fi

  echo "OK /login：ログイン画面が出ています"
}

check_health() {
  fetch /health
  local message
  message="$(common_failure)"
  local status=$?

  if [ "$status" -eq 1 ]; then
    echo "NG /health：$message"
    return 1
  fi

  if [ "$status" -eq 2 ]; then
    if [ "$CODE" = "503" ] && grep -q '"app":"torudake"' "$BODY"; then
      echo "NG /health：アプリは応答していますが、DB・定期処理・キューのどれかが止まっています（サーバーの storage/logs の「稼働確認：問題あり」を確認）"
    else
      echo "NG /health：サーバーエラーです（HTTP $CODE）"
    fi

    return 1
  fi

  if [ "$CODE" = "404" ]; then
    echo "NG /health：見つかりません（古い版のままの可能性）"
    return 1
  fi

  if [ "$CODE" = "200" ] && grep -q '"app":"torudake"' "$BODY" && grep -q '"ok":true' "$BODY"; then
    echo "OK /health：アプリの印があり、DB・定期処理・キューは新しい"
    return 0
  fi

  echo "NG /health：HTTP $CODE ですが、このアプリの印がありません（別のサイトの応答の可能性）"
  return 1
}

attempt=1
while true; do
  echo "== 確認 ${attempt}/${RETRIES}：${BASE_URL}（$(date '+%Y-%m-%d %H:%M:%S %Z')）"

  failed=0
  check_login || failed=1
  check_health || failed=1

  if [ "$failed" -eq 0 ]; then
    echo "すべて正常です。"
    exit 0
  fi

  if [ "$attempt" -ge "$RETRIES" ]; then
    echo "異常があります。"
    exit 1
  fi

  attempt=$((attempt + 1))
  sleep "$WAIT"
done
