# torudake-app — 撮って送るだけ帳簿

> レシートは、撮って送るだけ。

家族で経営する小さな会社のための経理アプリです。会計ソフトが「経理担当1人の道具」なのに対し、torudake は「家族が1人1つの仕事だけで回す道具」を目指します。現場の人はレシートを撮って送るだけ、経理担当は通帳の明細と写真をつないで直すだけ、社長は確かめて締めるだけです。決算書や申告は税理士・会計ソフトに任せ、その手前の入口を担います。

最初の利用者は有限会社 丹内建機サービス（産業用機械の修理・溶接・加工）です。会社名・決算月・分類・口座などの会社ごとの値はコードに書かず、会社設定として持ちます。

## 状態

デモ版（LLM編）を実装中です。工程0〜3（土台・本番 https://torudake-app.com ・会社と権限・送る＋AI）は済み。10/24 14:30 に設計を版3.0（社長は承認・締めをせず、通知と社長の画面で見て決める）に改めました。作る順番は [詳細設計書 §10](docs/03-detailed-design.md) のとおりです。

## 主な機能（予定）

1. **集める**：レシートは撮って送る。通帳はネットバンキングの明細データを取り込む。同じ写真・同じ明細は自動で止める
2. **直して承認**：明細と写真を日付・金額で突き合わせて仕訳の下書きを作る。承認するまで帳簿に入らない
3. **締めナビ**：未処理・下書き・通帳との差を「あと何件」で表示。残高は明細から自動で比べ、0件で月を締める

## 技術構成（予定）

| 役割 | 採用 |
|---|---|
| API・業務ロジック | PHP 8.4 + Laravel 13 |
| 画面 | Livewire 4 ＋ Flux UI（スマホのブラウザで使う） |
| DB | MySQL 8.4（金額は整数円） |
| 認証 | Laravel Fortify（スターターキット。メール確認・2段階認証） |
| 権限 | Policy / Gate（会社所属とロールを先に検証） |
| 証憑 | 非公開ディスク（SHA-256で重複を止め、30日で削除。S3互換は次の週） |
| ジョブ | Laravel のスケジューラ（写真の30日削除） |
| テスト | Pest / Larastan / Pint |

Laravel は「間違った数字を確定させない」ために使います。権限は Policy でサーバー側に判定し、承認・取消・締めは `DB::transaction` と行ロックで1回だけ確定させます。

## 開発環境

`vendor/bin/sail up -d` で起動し、http://localhost:8081 を開きます。詳しくは [技術仕様書 §8.2](docs/02-technical-spec.md)。

- 初期データ（`vendor/bin/sail artisan migrate:fresh --seed`）のログイン：owner@ / editor@ / submitter@ / other@example.com、パスワード `password`、2段階認証の鍵 `TORUDAKEDEVLOCAL`（認証アプリに手入力）、復旧コード `recovery-code-1`（1回使うと消えるので、流し直すと戻る）。手元の開発専用の架空の値で、本番では使えません（本番の人は `torudake:add-member` で作り、パスワードと2段階認証は本人が設定します）。

## ドキュメント

| ファイル | 内容 |
|---|---|
| [docs/01-requirements.md](docs/01-requirements.md) | **要件定義書**：目的・利用者とロール・機能（F/A/E）・データの扱い・完成の基準・確かめること |
| [docs/02-technical-spec.md](docs/02-technical-spec.md) | **技術仕様書**：技術の構成・リポジトリ・コードの決まり・認証・APIの規約・写真とAIの安全・環境とリリース |
| [docs/03-detailed-design.md](docs/03-detailed-design.md) | **詳細設計書**：テーブル・状態・処理・権限・API・画面・テスト・初期データ・作る順番 |
| [docs/04-demo-llm-edition.md](docs/04-demo-llm-edition.md) | **デモ版（LLM編）**：計算・判断をすべて Claude に任せ、Excel に出力し、学習データを Python編へ渡す。10/25午前までの時間割 |
| [docs/10-first-journal-and-learning.md](docs/10-first-journal-and-learning.md) | 経緯の記録：一次仕訳の蓄積・AIの学習・写真の30日削除・Anthropicの規約の確認結果 |
| [docs/presentation-script-7min.pdf](docs/presentation-script-7min.pdf) | 発表台本（7分）。Laravelを使うポイントと台本本文 |

各PDFの元は同じ名前の `.html` です。Chrome のヘッドレス印刷で PDF にしています。

01〜03（版3.0）が開発の正本です。04 はデモの演出の記録です。AI エージェント（Cursor・Claude Code）向けの決まりは [AGENTS.md](AGENTS.md) にあります。
