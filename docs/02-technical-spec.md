# 02｜技術仕様書 — 撮って送るだけ帳簿（torudake）

版3.0・2026-10-24 14:30（社長の画面・通知・Excel の1冊・帳票の取り込み・確定の考え方を反映）。要件は [01 要件定義書](01-requirements.md)、テーブル・画面・処理・テストは [03 詳細設計書](03-detailed-design.md)、本番の手順は [runbook](runbook.md)。

---

## 1. 技術の構成（実装済みの版）

| 分類 | 採用 | 状態・メモ |
|---|---|---|
| 言語・フレームワーク | **PHP 8.4（開発）／8.5（本番・CI）＋ Laravel 13**（`laravel/framework ^13.17`） | 実装済み。Laravel 13 は PHP 8.3 以上 |
| 画面 | **Livewire 4** の単一ファイルコンポーネント（`resources/views/pages/**/⚡*.blade.php`） | 実装済み（スターターキット）。Volt は使わない |
| UI | **Flux UI 無料版 ＋ Tailwind CSS 4** | 色・文字はデザイントークン（§4.3）だけを使う |
| 画面の組み立て | Vite（`vite-plus 1.4.0`） | `npm run build` |
| 認証 | **Laravel Fortify**（Livewire スターターキット） | 実装済み：ログイン・パスワード再設定・メール確認・2段階認証・パスワード再確認。**会員登録とパスキーは外した** |
| 権限 | Policy / Gate ＋ `company` ミドルウェア | 工程2で作る |
| DB | **MySQL 8.4** | 金額は `BIGINT` の整数円。ID は UUIDv7（`HasUuids`）。users は既定の bigint のまま |
| 写真 | `evidence` ディスク（`storage/app/private/evidences`、非公開） | S3互換は将来 |
| ジョブ | Laravel のキュー（`database` ドライバ）＋ スケジューラ（cron 毎分） | 本番に設定済み（systemd `torudake-queue@1`・`@2` の2つ、cron `schedule:run`） |
| AI | Anthropic Messages API、`claude-opus-5-5`、構造化出力（JSON スキーマ） | 工程3で作る。呼ぶのは `app/Services/Ai/` だけ |
| Excel | `phpoffice/phpspreadsheet`。**アプリが1冊を持ち続ける**（`storage/app/private/workbooks/{company}.xlsx`）。確定のたびに追記し、社長が戻したら状態の列を書き換える | これから |
| 通知 | Laravel の通知（`database` チャネル、`notifications` テーブル）。画面右上のベル | これから。メール・LINE は将来 |
| グラフ | Chart.js（npm で入れ、Vite で組み立てる）。社長の画面の三期比較 | これから |
| テスト・品質 | **Pest 5**、Larastan（PHPStan レベル7）、Pint | 実装済み。テスト34件 |
| 開発環境 | **Laravel Sail**：PHP 8.4・MySQL 8.4・Mailpit | 実装済み |
| 本番 | ABLENET VPS、**Ubuntu 26.04.1**・nginx 1.28・PHP-FPM 8.5.4・Node 24・Let's Encrypt | 稼働中 https://torudake-app.com |
| CI/CD | GitHub Actions：`tests.yml` → `deploy.yml` | 稼働中 |

リポジトリ：https://github.com/TannaiTomoya/torudake-app （公開、ブランチは main）

## 2. 全体の仕組み

```
スマホ（撮って送る）        PC（ライブ画面 → プロジェクターにミラーリング）
        │ HTTPS                       │ HTTPS
        └──────── nginx → PHP-FPM → Laravel（Livewire の画面と操作）
                                        ├─ MySQL（正本）
                                        ├─ storage/app/private/evidences（写真。30日で削除）
                                        ├─ キュー（database）→ AI のジョブ → Anthropic API
                                        └─ スケジューラ（毎日3時：写真の削除）
```

- 画面の操作（送る・直す・承認・締め）は Livewire のアクション。**操作のたびに `$this->authorize()` で確かめ直す。**
- 処理の本体は `app/Services/`。⚡ファイルは PHPStan が読まないので、業務のルールはクラスに置く。
- AI は送った直後にキューで動かし、画面は `wire:poll` で状態を読み直す（画面を止めない）。
- 写真は Policy を通るコントローラからだけ返す。ファイルの場所は URL に出さない。

## 3. フォルダの使い分け

| 場所 | 置くもの |
|---|---|
| `app/Enums/` | Role, SourceKind, SourceStatus, EntryStatus, EntryKind, Side, AccountType, BankAccountKind, AiTask, CorrectionReason。`label()` と `color()` を持つ |
| `app/Models/` | Company, Membership, Account, Category, BankAccount, Evidence, SourceRecord, JournalEntry, JournalLine, BalanceCheck, PeriodClosing, AuditLog, AiRun, AiCorrection, AiFeedback |
| `app/Policies/` | CompanyPolicy（土台）、SourceRecordPolicy、EvidencePolicy、JournalEntryPolicy、PeriodClosingPolicy |
| `app/Http/Middleware/` | `EnsureCompanyMember`（`company`）、`EnsureTwoFactorEnabled`（`two-factor`）、`AbsoluteSessionTimeout` |
| `app/Livewire/Forms/` | 入力と検証（`DraftEntryForm`・`CashCountForm` など） |
| `app/Services/` | SubmitEvidence（まとめて送るに対応）、ConfirmEntryService（経理の保存＝確定）、ReturnToBookkeeper（社長から戻す）、WorkbookService（Excel の1冊への追記・書き換え）、OwnerDashboard（社長の画面の数字）、ReportImportService（帳票の取り込み）、TrainingDataExporter、PurgeExpiredEvidence |
| `app/Services/Ai/` | `ClaudeClient`（本物）、`FakeClaudeClient`（開発・テスト）、`ReplayClaudeClient`（デモ再生）、`Tasks/`（ReadReceipt・Journalize・CheckEntry・**ReadReport**（帳票の表）・**OwnerBrief**（社長へのひとこと）・**Forecast**（来月の見込み）） |
| `app/Jobs/` | `ProcessEvidenceWithAi`（L1→L2→L3）、`ProcessReportWithAi`（帳票の表を読む）、`SendWeeklyOwnerDigest`（月曜の朝の通知） |
| `app/Support/` | `RemainingCount`（「あと n 件」＝まだ確定していない件数の式）、`ConcernRules`（気になる仕訳の条件） |
| `app/Console/Commands/` | `torudake:create-company`、`torudake:add-member` |
| `resources/prompts/` | AIへの指示文（タスクごと、版つき） |
| `resources/views/pages/` | 画面（03 §7） |
| `docs/` | 設計書・runbook・イメージ画像（`docs/mockups/`）・本番の準備スクリプト（`docs/server/`） |

## 4. コードの決まり

### 4.1 共通
- 画面の文言・エラー文は日本語。日付は `isoFormat('M月D日(ddd)')`、金額は `number_format()` で「5,200円」。時刻は Asia/Tokyo。
- 会社ごとの値（会社名・決算月・分類・口座）はコードに書かない。
- `.env` の値・APIキー・パスワードはコードにもコミットにも入れない（リポジトリは公開）。
- 1回の作業で扱うのは1機能まで。終えるたびに `/check`（§8.1）を通してコミットする。

### 4.2 サーバー側
- 会社の画面はすべて `/c/{company}/...`。子は会社から引く（`$company->sourceRecords()->findOrFail()`）。暗黙の結合は `scopeBindings()`。他社は404。
- 権限は Policy。一覧の絞り込みも必ず会社から引く。Policy だけに頼らない。
- `company_id`・`role`・各種 `status`・`created_by`・`approved_by` は一括代入させない。Livewire のプロパティの ID は `#[Locked]`。
- 状態を変える処理は Service の `DB::transaction` で、対象の行を `lockForUpdate()` してから確かめる。
- **確定（confirmed）した仕訳は、画面から直接書き換えない。** 直すときは「社長から戻し（returned）」にしてから経理が直して再確定する。Model の `updating` で、状態が confirmed のまま金額・科目を変えようとしたら例外。**業務テーブルにクエリビルダの一括 `update()` / `delete()` を使わない。**
- 実在の帳票の画像・数字は、リポジトリ（公開）・テストの fixture・デモ用データに入れない。テストとデモは架空の数字だけ。
- 外部サービス（Anthropic）を呼ぶのは `app/Services/Ai/` だけ。テストは `AI_DRIVER=fake`。
- テストのデータはファクトリの state で作る（`owner()`・`editor()`・`submitOnly()`・`approved()`・`purged()` など）。

### 4.3 画面（デザイントークン）
`resources/css/app.css` の `@theme` に定義済み。**画面では生の色（`#…`・`zinc-500` など）を書かず、この名前を使う。**

| トークン | 値 | 使う所 |
|---|---|---|
| `ink` / `ink-sub` | #1F2A33 / #3E4A52 | 本文・濃いボタン / 補足（白地で9.1） |
| `paper` / `panel` / `line` | #F4F1EA / #F5F3EE / #E2E5E1 | 背景 / 区画 / 罫線 |
| `brand` / `brand-ink` / `brand-soft` | #E8862E / #A8520F / #FFE3C4 | **強調はこの1色だけ** / オレンジの文字 / 変わった所を光らせる |
| `ok` / `ok-soft` | #1F6B40 / #E3F1E8 | 確定・送れました |
| `warn` / `warn-soft` | #9A4508 / #FDF0DC | 注意 |
| `ng` / `ng-soft` | #B42318 / #FBE4E1 | エラー |
| `muted` | #6B767C | アイコン・枠（白地で4.7） |
| `text-body` / `text-note` | 16px / 15px | 本文 / 補足の最小 |
| `text-live` / `text-live-hero` | 32px / 132px | ライブ画面の最小 / 「あと n 件」 |

- 文字のコントラストは4.5以上、アイコン・枠は3.0以上。
- 今動いている所だけをオレンジの枠で明るくし、ほかは不透明度60%。
- 選んでいるボタンには太いオレンジの枠（フォーカス）。

## 5. 認証とセッション

| 項目 | 決まり | 状態 |
|---|---|---|
| 方式 | Fortify のセッション認証 | 済み |
| 会員登録 | なし（`/register` は404。テスト T01） | 済み |
| メンバーの作成 | `torudake:add-member {company} {email} {role} [--submit-only]` → パスワード再設定のメール | 工程2 |
| 2段階認証 | TOTP＋復旧コード。**有効にしていない人は会社の画面に入れず、設定画面へ回す**（`two-factor`） | 工程2 |
| セッション | 操作がなければ2時間。ログインから24時間で必ず切れる（`AbsoluteSessionTimeout`） | 工程2 |
| パスワード | 12文字以上。漏えい済みは拒否 | 工程2 |

## 6. AI（Anthropic）の決まり

| 項目 | 決まり |
|---|---|
| モデル | `claude-opus-5-5`。effort は読み取り・検算を `low`、仕訳・帳票の読み取り・社長へのひとこと・見込みを `medium` |
| 呼び出し | Laravel の HTTP クライアントで Messages API。写真はその場で base64。**Files API・Batch は使わない**（保存されるため） |
| 出力 | 構造化出力（JSON スキーマ）。スキーマに実データを書かない。形が違えば保存せず、やり直しは1回まで |
| 会社の分離 | プロンプトに入れる直した例は、その会社の最近20件だけ |
| 社長へのひとこと | 渡すのは月ごとの合計・分類ごとの合計・上位の取引先の割合（**名前は渡さない**）。「何が起きたか・理由の見立て・確かめるとよい点」まで。「〜すべき」とは言わせない |
| 見込み | 過去の月の実績から、中央値と8割の範囲を返させる（デモ版は LLM。Python編で規則に置き換えて比べる） |
| 記録 | 呼ぶたびに `ai_runs` に入力（写真は入れない）・出力・トークン数・時間・成否を残す |
| 失敗 | 30秒で打ち切り。原資料は残り、人が手で下書きを作れる |
| 上限 | アプリで1社1日100回。超えたら手入力に切り替え、画面に表示。コンソールで月 $20 |
| 切り替え | `AI_DRIVER=claude`（本物）／`fake`（開発・自動テスト）／`replay`（デモ再生：同じ写真の練習時の出力を再生） |
| キー | `ANTHROPIC_API_KEY` は本番の `.env` だけ。リポジトリ・画面に出さない |

## 7. 写真とデータの安全

| 心配なこと | 対策 |
|---|---|
| 他社のデータを見られる | UUIDv7、会社から引く、`company` ミドルウェア、全画面のテスト |
| 写真の URL が漏れる | 非公開ディスク、Policy を通るルートだけ、ファイル名は UUIDv7、`Cache-Control: private, no-store` |
| 画像以外のファイル | `mimetypes`（JPEG・PNG・WebP・HEIC・PDF）と10MB |
| 二重送信 | SHA-256 と `UNIQUE(company_id, sha256)`、処理中はボタンを押せない |
| 写真が長く残る | 毎日3時に30日を過ぎた写真を削除。DB のバックアップに写真は入らない |
| AI に本番を壊される | AI は JSON を返すだけ。保存はコード、承認は人。本番の SSH・DB を AI のツールに渡さない |

## 8. 環境・テスト・リリース

### 8.1 検査（コミットの前に毎回、Sail で）
```bash
vendor/bin/sail composer test
```
中身：設定キャッシュのクリア → Pint → Larastan → Pest。ビューを触ったら `vendor/bin/sail npm run build` も。Claude Code では `/check`（`.claude/commands/check.md`）。

### 8.2 開発環境（Sail）
| 項目 | 値 |
|---|---|
| 起動 | `vendor/bin/sail up -d` |
| 画面 | http://localhost:8081 |
| MySQL | localhost:3307（DB `torudake`、テストは `testing`） |
| Mailpit | http://localhost:8026 |
| 初期データでのログイン | 2段階認証の鍵は16文字以上の固定の値にする（`secret` では短すぎて6桁のコードが作れない。10/24 に発覚）。開発用の鍵と復旧コードは README に書く |
| 注意 | フォルダ名が日本語なので `.env` の `COMPOSE_PROJECT_NAME=torudake` が必要。`node_modules` はコンテナで入れたので、**Mac で `npm run dev` は動かない**（Sail の中で動かす） |

### 8.3 本番（稼働中）
| 項目 | 値 |
|---|---|
| URL | https://torudake-app.com（Cloudflare DNS、A `@` → 138.248.65.29、**DNS only**） |
| 証明書 | Let's Encrypt、期限 2027-01-21、certbot が自動更新 |
| 置き場所 | `/var/www/torudake`（持ち主 `ubuntu`、グループ `www-data`） |
| DB | `torudake`（ユーザー `torudake`、パスワードは VPS の `/root/.torudake-db-password` と `.env` だけ） |
| 定期実行 | cron `* * * * * php artisan schedule:run`（ubuntu） |
| ジョブ | systemd `torudake-queue@1`・`@2`（2つ。1枚に十数秒かかるため） |
| メール | 当面 `log`（送らない）。送る設定は runbook §6 |
| 初回の準備 | `docs/server/setup-torudake.sh`（何度実行しても壊れない。Ubuntu 以外では止まる） |

### 8.4 CI/CD
| ワークフロー | いつ | 中身 |
|---|---|---|
| `tests.yml` | PR と main への push | MySQL 8.4 をサービスコンテナで、PHP 8.5、`composer setup` → `composer ci:check` |
| `deploy.yml` | `tests` が main で成功したあと（または手動） | VPS に SSH して `deploy.sh`：**DB のバックアップ** → `git pull` → `composer install --no-dev` → `npm ci && npm run build` → `migrate --force` → `optimize` → `queue:restart`。`DEPLOY_ENABLED=true` のときだけ動く（設定済み） |

secrets：`SSH_HOST`・`SSH_USER`（ubuntu）・`SSH_PRIVATE_KEY`（camp_actions）。登録済み。

### 8.5 本番に出す手順（毎回同じ）
1. 手元で `/check` が通る
2. main に push（またはPRをマージ）
3. `tests` → `deploy` が自動で走る
4. runbook §2 のチェックリストで確かめる
5. おかしければ runbook §3 で戻す

## 9. 外部の値（`.env`）

| キー | 開発 | 本番 |
|---|---|---|
| `APP_URL` | http://localhost:8081 | https://torudake-app.com |
| `APP_TIMEZONE` / `APP_LOCALE` | Asia/Tokyo / ja | 同じ |
| `DB_*` | Sail（mysql / torudake / sail） | 127.0.0.1 / torudake / torudake |
| `MAIL_MAILER` | smtp（Mailpit） | log |
| `EVIDENCE_DISK` / `EVIDENCE_RETENTION_DAYS` | local / 30 | 同じ |
| `AI_DRIVER` | fake | claude（デモの保険は replay） |
| `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` | 空でよい / claude-opus-5-5 | 本番のキー / claude-opus-5-5 |
| `AI_DAILY_LIMIT` | 100 | 100 |
| `CONCERN_AMOUNT_YEN` | 30000 | 30000（気になる仕訳の金額の目安。会社設定で上書き） |

**`.env` を変えたら、キューの常駐を立ち上げ直す**（`vendor/bin/sail artisan queue:restart`、本番は `php artisan queue:restart`）。常駐は起動したときの設定で動き続けるため、変えても反映されない（10/24 に実際に起きた）。
| `COMPOSE_PROJECT_NAME` ほか Sail のポート | torudake・8081・3307・8026 | 使わない |
