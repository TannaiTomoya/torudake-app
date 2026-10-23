# 撮って送るだけ帳簿（torudake）
家族で経営する小さな会社の経理アプリ。現場はレシートを撮って送るだけ、経理担当は直すだけ、社長は承認して締めるだけ。今作っているのは八幡平市副市長向けの**デモ版（LLM編）**で、計算と判断は Claude に任せる。

## 設計書（食い違いがあれば上が優先）
- [docs/04-demo-llm-edition.md](docs/04-demo-llm-edition.md)：今作るデモ版の決まり・時間割（§8）・画面の流れ（§7.6）・UIの決まり（§7.7）
- [docs/03-detailed-design.md](docs/03-detailed-design.md)：テーブル・権限・ルート・テスト
- [docs/02-technical-spec.md](docs/02-technical-spec.md)：技術の決まり
- [docs/01-requirements.md](docs/01-requirements.md)：要件

## コードの決まり
- 画面は Livewire 4 の単一ファイルコンポーネント（`resources/views/pages/**/⚡*.blade.php`）。Volt は使わない
- UI は Flux UI の Free 版。足りない部品は Tailwind で組む。**色・文字の大きさは `resources/css/app.css` の `@theme` のトークン（`ink`・`brand` など）だけを使い、画面で生の色を書かない**。文字は本文16px以上、コントラストは4.5以上
- 画面の文言は日本語。日付は `isoFormat('M月D日(ddd)')`、金額は「5,200円」
- 権限は `app/Policies/` に書き、画面からは `$this->authorize()`。一覧も必ず会社から引く（`$company->sourceRecords()`）。他社のものは 404
- 状態を変える処理は `app/Services/` の `DB::transaction` の中で `lockForUpdate()` してから確かめる。業務テーブルに一括 `update()` / `delete()` を使わない
- `company_id`・`role`・各種 `status` は一括代入させない。Livewire のプロパティの ID は `#[Locked]`
- Claude を呼ぶのは `app/Services/Ai/` のクラスだけ。テストでは偽物（`AI_DRIVER=fake`）に差し替え、本物は呼ばない
- 機能を足したら `tests/Feature/` に Pest のテストも足し、`/check` を通す
- コマンドは必ず Sail から（`vendor/bin/sail artisan`、`sail composer`、`sail npm`）。ホストの php・composer は使わない
- `.env` の値・APIキー・パスワードは、コードにもコミットにも入れない

## 開発環境
- フォルダ名が日本語なので `.env` に `COMPOSE_PROJECT_NAME=torudake` が必要
- ポートは ticket-app とずらしてある：画面 http://localhost:8081、MySQL 3307、Mailpit http://localhost:8026

## 進め方
- 実装の前に、変更するファイルの一覧を示す
- 1回の依頼で扱うのは1機能まで。工程は1つずつ進め、終えるたびに `/check` を通してコミットする
- 本番での操作は `deploy.sh` だけ。本番の SSH・DB の資格情報は AI のツールに渡さない
