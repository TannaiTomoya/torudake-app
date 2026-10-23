# 02｜技術仕様書 — 撮って送るだけ帳簿（torudake）

版1.1・2026-10-23（**画面を Livewire に変更**。1.5日で本番に出すための最終整理）。要件は [01 要件定義書](01-requirements.md)、テーブル・画面・テストは [03 詳細設計書](03-detailed-design.md)。

---

## 0. 版1.0からの変更

| 項目 | 版1.0 | 版1.1（この版） | 理由 |
|---|---|---|---|
| 画面 | Next.js＋Laravel API（Sanctum） | **Laravel の Livewire 4（単一ファイルコンポーネント）＋ Flux UI 無料版** | ticket-app の構成（2段階認証つきログイン・CI・`deploy.sh`・VPS）をそのまま使え、作る量が約半分になる。家計版と画面を共有する理由（07 K1）はなくなった |
| API | `/api/v1` の JSON API | **作らない**。画面の操作は Livewire のアクション、写真だけコントローラで返す | 利用者は1社3〜4人。外部連携が要るときは、Services を呼ぶ API をあとから足す |
| 写真の保存先 | S3互換ストレージ | **MVP はサーバー内の非公開ディスク**（`storage/app/private`）。S3互換は次の週 | 30日で消すので容量は小さい。ディスクの切り替えは設定だけで済むように `Storage::disk('evidence')` を通す |
| 二重送信の防止 | Idempotency-Key | **ボタンを処理中は押せなくする＋行ロック＋写真の SHA-256** | Livewire は同じ画面から1回ずつ送るため、キーの保存までは要らない |
| 招待 | メールで招待 | **MVP は Artisan コマンドでメンバーを作る**。招待メールは次の週 | 使う人は家族3人 |

デメリットと対処（2026-10-23に検討）：操作ごとにサーバーと通信するので、電波が弱いと反応が遅れる（「送る」は1回の送信なので影響は小さい）。スマホ側で写真を縮める処理は Alpine.js で書く（次の週）。

## 1. 技術の構成

| 分類 | 採用 | メモ |
|---|---|---|
| 言語・フレームワーク | **PHP 8.4 ＋ Laravel 13** | ticket-app と同じ。12 はバグ修正が2026年8月で終了、13 はセキュリティ修正が2028年第1四半期まで（[公式](https://laravel.com/docs/13.x/releases)） |
| 画面 | **Livewire 4** の単一ファイルコンポーネント（`resources/views/pages/**/⚡*.blade.php`） | Volt は使わない。写真の送信は `WithFileUploads` |
| UI | **Flux UI 無料版 ＋ Tailwind CSS** | 足りない部品は Tailwind で組む。スマホ優先 |
| 認証 | **Laravel Fortify（Livewire スターターキット）** | ログイン・パスワード再設定・メール確認・2段階認証（TOTP＋復旧コード）。会員登録は切る |
| 権限 | Policy / Gate ＋ `company` ミドルウェア | 会社の所属とロールを先に確かめる |
| DB | **MySQL 8.4** | 金額は `BIGINT` の整数円。ID は UUIDv7（`HasUuids`） |
| 写真 | `evidence` ディスク（MVP は `local` の `storage/app/private/evidences`） | 非公開。権限を確かめるルートからだけ返す |
| ジョブ | Laravel のスケジューラ（cron）＋ キュー（`database` ドライバ） | 写真の30日削除。Redis は使わない（MVP） |
| AI | Anthropic Messages API（`claude-opus-5-5`） | 次の週以降。`app/Services/Ai/` のクラスだけが呼ぶ |
| テスト・品質 | **Pest**、Larastan（PHPStan）、Pint | コミット前に `/check`（整形・静的解析・全テスト・ビルド） |
| 開発環境 | **Laravel Sail（Docker）**：PHP・MySQL・Mailpit | コマンドは必ず Sail から（`sail artisan`、`sail composer`、`sail npm`） |
| 本番 | **ticket-app と同じ VPS**（Ubuntu・nginx・PHP-FPM・MySQL・Let's Encrypt）。サブドメインで別のサイトとして置く | DB・ユーザー・`.env`・ディレクトリは ticket-app と分ける |
| CI/CD | GitHub Actions：`tests.yml`（PR と main）、`deploy.yml`（main で test → VPS へ SSH で `deploy.sh`） | ticket-app のものをパスを変えて流用 |

## 2. 全体の仕組み

```
スマホ・PC のブラウザ
   │ HTTPS（サブドメイン）
 nginx → PHP-FPM → Laravel（Livewire の画面と操作）
                     ├─ MySQL（正本）
                     ├─ storage/app/private/evidences（写真。30日で削除）
                     └─ スケジューラ（毎日3時：写真の削除）
```

- 画面の操作（送る・下書き・承認・締め）は Livewire のアクション。**操作のたびに `$this->authorize()` で権限を確かめ直す**（`mount` だけでなく、アクションごと）。
- 処理の本体は `app/Services/`。⚡ファイルは PHPStan が見ないため、業務のルールはクラスに置く。
- 写真は `GET /c/{company}/evidences/{evidence}/image` を通し、Policy で確かめてからファイルを返す。ファイルの場所は URL に出さない。

## 3. フォルダの使い分け

| 場所 | 置くもの |
|---|---|
| `app/Enums/` | Role, SourceKind, SourceStatus, EntryStatus, EntryKind, Side, AccountType, BankAccountKind。表示用に `label()` と `color()` |
| `app/Models/` | Company, Membership, Account, Category, BankAccount, Evidence, SourceRecord, JournalEntry, JournalLine, BalanceCheck, PeriodClosing, AuditLog |
| `app/Policies/` | CompanyPolicy（土台）、SourceRecordPolicy、EvidencePolicy、JournalEntryPolicy、PeriodClosingPolicy |
| `app/Http/Middleware/` | `EnsureCompanyMember`（`company` ミドルウェア）、`EnsureTwoFactorEnabled`、`AbsoluteSessionTimeout` |
| `app/Livewire/Forms/` | 入力と検証のフォームオブジェクト（`DraftEntryForm`、`CashCountForm` など） |
| `app/Services/` | SubmitEvidence、DraftEntryService、ApprovalService、CloseCheck、ClosePeriod、PurgeExpiredEvidence |
| `app/Support/` | CloseGuard、Money |
| `app/Console/Commands/` | `torudake:create-company`、`torudake:add-member` |
| `app/Http/Controllers/` | Livewire にしないもの（写真を返す） |
| `resources/views/pages/` | 画面（03 §7） |
| `docs/` | 設計書・runbook |

## 4. コードの決まり（ticket-app から引き継ぐ）

- 画面の文言・エラー文は日本語。日付は `isoFormat('M月D日(ddd)')`、金額は `number_format()` で「5,200円」。時刻は Asia/Tokyo。
- 権限の判定は `app/Policies/` に書き、画面からは `$this->authorize()`。**一覧の絞り込みでも、必ず会社から引く**（`$this->company->sourceRecords()`）。Policy だけに頼らない。
- `company_id`・`role`・各種 `status`・`created_by`・`approved_by` は一括代入させない（`$fillable` に入れない）。Livewire のプロパティの ID は `#[Locked]`。
- 状態を変える処理は Service の `DB::transaction` の中で、対象の行を `lockForUpdate()` してから状態を確かめる。
- 承認済みの仕訳は Model の `updating` / `deleting` で例外にする。**業務テーブルにクエリビルダの一括 `update()` / `delete()` を使わない**（Observer が動かないため）。
- 状態は Enum で持つ。エラー文の項目名はフォームオブジェクトの `validationAttributes()` で画面の見出しにそろえる。
- テストのデータはファクトリの state で作る（`owner()`、`editor()`、`submitOnly()`、`approved()`、`purged()` など）。
- 機能を足したら `tests/Feature/` に Pest のテストも足し、`/check` を通す。ビューを触ったら `sail npm run build`。
- `.env` の値・APIキー・パスワードはコードにもコミットにも入れない。
- 会社ごとの値（会社名・決算月・分類・口座）はコードに書かない。
- 1回の依頼で扱うのは1機能まで。終えるたびに `/check` を通してコミットする。

## 5. 認証とセッション

| 項目 | 決まり |
|---|---|
| 方式 | Fortify（スターターキット）のセッション認証 |
| 会員登録 | なし（`Features::registration()` を外し、`/register` は404） |
| メンバーの作成 | MVP は `sail artisan torudake:add-member {company} {email} {role} [--submit-only]`。仮のパスワードは表示せず、パスワード再設定のメールを送る（メールの持ち主の確認を兼ね、`email_verified_at` を入れる） |
| 2段階認証 | TOTP＋復旧コード。**有効にしていない人は、会社の画面に入れず設定画面へ回す**（`EnsureTwoFactorEnabled`） |
| セッション | 操作がなければ2時間で切れる。ログインから24時間で必ず切れる（`AbsoluteSessionTimeout`） |
| パスワード | 12文字以上。漏えい済みは拒否（`Password::uncompromised()`） |
| レート制限 | ログイン5回/分（Fortify 既定）。写真の送信は1人30枚/時 |
| 復旧コード | 紙に印刷して父が保管 |

## 6. 写真とデータの安全

| 心配なこと | 対策 |
|---|---|
| 他社のデータを見られる（IDの付け替え） | ID は UUIDv7。ルートは `/c/{company}/...` で、子は会社から `findOrFail`（他社は404）。`company` ミドルウェアで所属を確かめる。テストで全画面を回す |
| 写真の URL が漏れる | 非公開ディスクに置き、Policy を通るルートからだけ返す。ファイル名は UUIDv7、元のファイル名は使わない。応答に `Cache-Control: private, no-store` |
| 画像以外のファイル | `mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf` と `max:10240`。拡張子は中身から決める |
| 同じ写真の二重送信 | SHA-256 と `UNIQUE(company_id, sha256)`。ボタンは処理中 `wire:loading.attr="disabled"` |
| 写真が長く残る | 毎日3時に30日を過ぎた写真を削除。バックアップに写真は含めない（DB だけ） |
| Livewire のプロパティの書き換え | ID は `#[Locked]`。アクションごとに Policy で確かめ直す |
| 承認済みの数字が変わる | Model で拒否、取消は反対仕訳（次の週） |
| AI に本番を壊される | 本番の SSH・DB の資格情報は AI のツールに渡さない。本番での操作は `deploy.sh` だけを人が実行（または CI が実行） |

## 7. 環境とリリース

### 7.1 環境
| 環境 | データ | 目的 |
|---|---|---|
| 開発（自分のPC、Sail） | シーダーの架空データ | 作る・自動テスト |
| 本番（ticket-app と同じ VPS、サブドメイン） | 実データ | 丹内建機で使う |

テスト環境（ステージング）は次の週に作る。それまでは「本番に出す前のバックアップ」と「戻す手順」を必ず踏む。本番の実データは開発に持ち込まない。

### 7.2 本番の構成（ticket-app に足すもの）
| 項目 | 値 |
|---|---|
| 置き場所 | `/var/www/torudake`（ticket-app と別のディレクトリ） |
| DB | `torudake` データベースと専用ユーザー（ticket-app の DB には触れない） |
| nginx | サブドメインのサーバーブロック、`client_max_body_size 13m;` |
| PHP | `upload_max_filesize=12M`、`post_max_size=13M` |
| HTTPS | Let's Encrypt（certbot） |
| cron | `* * * * * php /var/www/torudake/artisan schedule:run` |
| キュー | MVP は使わない（30日削除はスケジューラから直接） |
| バックアップ | 毎日 `mysqldump` を30日分。デプロイの前にも1回 |

### 7.3 CI/CD
| ワークフロー | いつ | 中身 |
|---|---|---|
| `tests.yml` | PR と main への push | MySQL 8.4 をサービスコンテナで起動し、Pint・Larastan・Pest・`npm run build` |
| `deploy.yml` | main への push（tests が通ったら） | VPS へ SSH して `deploy.sh`（バックアップ → `git pull` → `composer install --no-dev` → `migrate --force` → `npm ci && npm run build` → キャッシュ → PHP-FPM 再読み込み） |

secrets は `gh secret set` で登録する（ticket-app で詰まった点）。

### 7.4 本番に出す手順（毎回同じ）
1. 開発で `/check` が通る
2. PR → main へマージ（CI が通っていること）
3. `deploy.yml` が DB をバックアップしてから反映する
4. 反映後の確認：ログイン・送る・下書き・承認・締めナビ（`docs/runbook.md` のチェックリスト）
5. おかしければ、runbook の「戻す」手順（前のコミットに戻して `deploy.sh`、必要なら DB を復元）

## 8. 外部の値（`.env`）

| キー | 中身 |
|---|---|
| `APP_URL` | 本番のサブドメイン |
| `APP_TIMEZONE` / `APP_LOCALE` | `Asia/Tokyo` / `ja` |
| `DB_*` | `torudake` 専用 |
| `MAIL_*` | パスワード再設定のメール（ticket-app と同じ送信元の設定を流用） |
| `EVIDENCE_DISK` / `EVIDENCE_RETENTION_DAYS` | `local` / `30` |
| `SEED_OWNER_EMAIL` / `SEED_OWNER_PASSWORD` | 本番の最初の owner。空なら止める |
| `ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL` | 次の週以降。MVP では空 |
