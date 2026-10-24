# 撮って送るだけ帳簿（torudake）— 開発の決まり

家族で経営する小さな会社の経理アプリ。現場はレシートを撮ってまとめて送るだけ、経理担当は AI の下書きを直して保存（＝確定、Excel にも入る）するだけ、**社長は承認も締めもせず**、通知と社長の画面（三期比較・ひとこと・気になる仕訳・来月の見込み）を見て決めるだけ。
今作っているのは八幡平市副市長向けの**デモ版（LLM編）**で、読み取り・仕訳・検算・社長へのひとこと・見込みは Claude に任せる（**確定は必ず経理の保存**）。
Cursor・Claude Code など、どの AI エージェントもこのファイルに従う（`CLAUDE.md` はこのファイルを読み込むだけ）。

## 設計書（必ず先に読む）
- [docs/01-requirements.md](docs/01-requirements.md)：要件（機能 F01〜F18、完成の基準 AC01〜AC11）
- [docs/02-technical-spec.md](docs/02-technical-spec.md)：技術の決まり（構成・フォルダ・デザイントークン・AI・本番）
- [docs/03-detailed-design.md](docs/03-detailed-design.md)：テーブル・処理・権限・ルート・画面・テスト・**作る順番（§10）**。**版3.0（10/24 14:30）で社長の承認・締めをなくした。§0 の表を先に読む**
- [docs/04-demo-llm-edition.md](docs/04-demo-llm-edition.md)：デモの見せ場・台本・絵コンテ
- [docs/runbook.md](docs/runbook.md)：本番の手順
- 画面のイメージ：[docs/mockups/](docs/mockups/)

## 今の状態
- 工程0〜6は済み（土台・本番・会社と権限・送る＋AIのジョブ・手直し・確定・忙しい月・Excel の仕訳帳・まとめて送る・ライブ画面）
- **工程7（社長の画面）の途中**。7b（社長の画面・seed-demo）・7c（ひとこと・見込み）・7d（戻す・見た）は済み、次は工程8→7a（取り込み）→7e（通知）→7f（03 §0）。1工程ずつ進める
- 実装済みのテーブルは本番に出ている。**変更は新しい移行ファイルで行い、既存の移行ファイルは書き換えない**（03 §2.2）

## コードの決まり
- 画面は Livewire 4 の単一ファイルコンポーネント（`resources/views/pages/**/⚡*.blade.php`）。Volt は使わない
- UI は Flux UI の Free 版。足りない部品は Tailwind で組む。**色・文字の大きさは `resources/css/app.css` の `@theme` のトークン（`ink`・`brand`・`ok` など）だけを使い、画面で生の色を書かない**。本文16px以上、ライブ画面32px以上、文字のコントラスト4.5以上
- 画面の文言は日本語。日付は `isoFormat('M月D日(ddd)')`、金額は「5,200円」
- 会社の画面はすべて `/c/{company}/...`。子は会社から引く（`$company->sourceRecords()->findOrFail()`）。他社のものは 404
- 権限は `app/Policies/` に書き、Livewire のアクションのたびに `$this->authorize()`。一覧も必ず会社から引く
- 状態を変える処理は `app/Services/` の `DB::transaction` の中で `lockForUpdate()` してから確かめる。業務テーブルに一括 `update()` / `delete()` を使わない
- `company_id`・`role`・各種 `status` は一括代入させない。Livewire のプロパティの ID は `#[Locked]`
- 「あと n 件」は `app/Support/RemainingCount` の1か所だけで数える（未処理の原資料＋下書きの仕訳）
- Claude を呼ぶのは `app/Services/Ai/` のクラスだけ。テストでは `AI_DRIVER=fake` の偽物を使い、本物は呼ばない。写真は base64 でその場で送り、Files API・Batch は使わない
- AI は下書きまで。**確定（confirmed）にするのは経理の保存だけ**。社長は事後に見て、違えば理由を付けて「経理に戻す」
- **実在の帳票（財務王・販売王）の写真・数字は、リポジトリ・テスト・デモ用データに入れない**。テストとデモは架空の数字だけ（リポジトリは公開）
- 機能を足したら `tests/Feature/` に Pest のテストも足す（03 §8 の T 番号）
- `.env` の値・API キー・パスワードは、コードにもコミットにも入れない（**リポジトリは公開**）

## コマンド（必ず Sail から。ホストの php・composer・npm は使わない）
- 起動：`vendor/bin/sail up -d`（画面 http://localhost:8081、MySQL 3307、Mailpit http://localhost:8026）
- 検査（コミットの前に毎回）：`vendor/bin/sail composer test`（Pint → Larastan → Pest）
- 整形：`vendor/bin/sail bin pint --dirty`
- ビューを触ったら：`vendor/bin/sail npm run build`
- `.env` を変えたら：`vendor/bin/sail artisan queue:restart`（キューの常駐は起動時の設定のまま動くため）
- フォルダ名が日本語なので `.env` に `COMPOSE_PROJECT_NAME=torudake` が必要

## 進め方
- 実装の前に、変更するファイルの一覧を示す
- 1回の依頼で扱うのは1機能まで。工程は1つずつ進め、終えるたびに検査を通してコミットする
- main に push すると、テストのあと本番に自動で反映される（反映前に DB をバックアップ）。push の前に検査が通っていること
- コマンドが失敗したら、原因の見立てと対処を1つずつ示してからやり直す

## 本番（触ってよい範囲）
- 本番の VPS（`ssh camp-vps`、ubuntu）は**本人が操作する**。AI エージェントは本番に SSH しない。本番の DB の削除・資格情報には触れない
- 本番への反映は push → GitHub Actions の `deploy.sh` だけ
