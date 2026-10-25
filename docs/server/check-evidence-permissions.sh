#!/usr/bin/env bash
# 写真の共有グループと非公開権限を検査する。権限の変更・sudo は行わない。
set -euo pipefail

EVIDENCE_DIR="${1:-/var/www/torudake/storage/app/private/evidences}"
SHARED_GROUP="${2:-www-data}"

if [ ! -d "$EVIDENCE_DIR" ] || [ -L "$EVIDENCE_DIR" ]; then
  echo "写真保存先が通常のディレクトリではありません。docs/runbook.md の写真保存先の権限復旧を確認してください。" >&2
  exit 1
fi

MODE=$(stat -c '%a' -- "$EVIDENCE_DIR")
GROUP=$(stat -c '%G' -- "$EVIDENCE_DIR")

if [ "$MODE" != '2770' ] || [ "$GROUP" != "$SHARED_GROUP" ]; then
  echo "写真保存先の権限が不正です（mode=$MODE group=$GROUP、期待値: 2770 $SHARED_GROUP）。docs/runbook.md の写真保存先の権限復旧を確認してください。" >&2
  exit 1
fi

echo "写真保存先の権限を確認しました（2770 $SHARED_GROUP、その他への公開なし）。"
