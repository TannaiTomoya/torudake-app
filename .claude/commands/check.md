---
description: コミット前のチェック（整形、静的解析、全テスト、ビルド）を流して結果を報告する
---

コミット前のチェックを、次の順に実行してください。コマンドはすべて Sail 経由です。

1. `vendor/bin/sail bin pint --dirty --format agent`（整形。直した場合はその旨を報告）
2. `vendor/bin/sail npm run build`（ビューを変えたときに Tailwind のクラスを拾うため）
3. `vendor/bin/sail composer test`（設定キャッシュのクリア、pint のチェック、phpstan、全テスト。CI の `composer ci:check` と同じ内容）

報告は次の形で短く:

- 結果: 通った / 失敗した
- pint・phpstan のエラー数・テストの件数（通過 / 全体）
- 失敗した場合: どのステップで何が起きたか、原因の推測、直し方の案を1つ。勝手に直さず、直してよいか確認する

`composer test` は出力が長いので、ファイルに書き出してから最後の数行と失敗の箇所だけを見ること。パイプでつないで終了コードを見失わないこと。
