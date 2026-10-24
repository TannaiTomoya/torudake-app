# 03｜詳細設計書 — 撮って送るだけ帳簿（torudake）

版2.0・2026-10-24。機能番号（F・AC）は [01 要件定義書](01-requirements.md)、技術の決まりは [02 技術仕様書](02-technical-spec.md)。画面のイメージは [docs/mockups/](mockups/)。

---

## 0. 今の状態（2026-10-24 9:40）

| 工程 | 内容 | 状態 |
|---|---|---|
| 0 | 土台：Laravel 13＋Livewire スターターキット（会員登録・パスキーを外した）、Sail、日本語化、Pest、デザイントークン、CI | **済み**（テスト34件） |
| 1 | 本番：torudake-app.com、VPS の準備、HTTPS、cron、ジョブの常駐、自動デプロイ | **済み**（稼働中） |
| 2〜8 | §10 の順に作る | これから |

---

## 1. 言葉の使い分け

| 言葉 | テーブル | 意味 |
|---|---|---|
| 会社 | `companies` | データを分ける単位。すべての業務データは会社に属する |
| メンバー | `memberships` | 人と会社のつながり。ロールと「送るだけ」の印を持つ |
| 証憑（写真） | `evidences` | 送られた写真・PDF 1枚。30日でファイルは消え、行（ハッシュ・テキスト）は残る |
| 原資料 | `source_records` | 仕訳のもとになる1件（今は写真だけ）。**未処理の数はここで数える** |
| 仕訳 | `journal_entries` ＋ `journal_lines` | 帳簿の1件と、借方・貸方の行。下書き → 承認済み |
| 勘定科目 | `accounts` | 会計上の科目（現金・普通預金・車両費…）。会社ごと |
| 分類 | `categories` | 現場の言葉（燃料・部品修理…）。勘定科目に ID でつながる |
| 口座 | `bank_accounts` | 現金・銀行・カード・立替。1つの勘定科目につながる |
| 残高の確認 | `balance_checks` | 数えた現金の残高 |
| 締め | `period_closings` ＋ `companies.closed_through` | 締めた記録と、締めた最後の日 |
| AIの実行 | `ai_runs` | Claude を1回呼んだ記録（入力・出力・時間・トークン） |
| 直した記録 | `ai_corrections` | 人が直した前後・理由・間違いの型（kernel） |

## 2. データベース

ID は UUIDv7（`CHAR(36)`、`HasUuids`）。**ただし `users` はスターターキットのまま bigint**（`memberships.user_id` などは bigint の外部キー）。金額は `BIGINT`（円）。業務テーブルはすべて `company_id`（restrict）と `created_at`・`updated_at` を持つ。

### 2.1 つながり
```
users 1─* memberships *─1 companies
companies 1─* accounts 1─* categories
          1─* bank_accounts *─1 accounts
          1─* evidences 1─1 source_records *─0..1 journal_entries 1─* journal_lines *─1 accounts
          1─* balance_checks, period_closings, audit_logs
          1─* ai_runs 1─* ai_corrections / ai_feedback
```

### 2.2 テーブル

#### users（スターターキットのまま）
name・email・password・email_verified_at・two_factor_secret・two_factor_recovery_codes・two_factor_confirmed_at。会員登録はない。

#### companies
| 列 | 型 | 内容 |
|---|---|---|
| id | uuid7 | |
| name | string(100) | 例：丹内建機サービス |
| fiscal_year_end_month | tinyint | 決算月（丹内建機は7） |
| bookkeeping_start_date | date null | 記帳開始日（Q1） |
| closed_through | date null | 締めた最後の日。これ以前の日付の仕訳は作れない・変えられない |
| allow_self_approval | bool default false | 自己承認 |
| is_demo | bool default false | デモ用の会社（画面に「デモ」と出す） |

#### memberships
| 列 | 型 | 内容 |
|---|---|---|
| id | uuid7 | |
| company_id / user_id | FK | UNIQUE(company_id, user_id) |
| role | enum `Role` | owner / admin / approver / editor / viewer。一括代入不可 |
| submit_only | bool default false | 「送るだけ」。ロールは増やさない |
| disabled_at | datetime null | 辞めた人。消さずに止める |

#### accounts（勘定科目）
id・company_id・code（会社の中で一意）・name・type（`AccountType`）・archived_at。

#### categories（分類）
id・company_id・name・direction（expense / income）・account_id・sort_order・archived_at。

#### bank_accounts（口座）
id・company_id・name・kind（`BankAccountKind`：cash / bank / card / reimbursement）・account_id・is_default_for_receipts・archived_at。

#### evidences（証憑）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| storage_key | string null | `companies/{company_id}/{uuid7}.{ext}`。消したら null |
| sha256 | char(64) | UNIQUE(company_id, sha256)。消したあとも残す |
| mime / size_bytes | | |
| uploaded_by | FK users | |
| extracted_text | text null | AI が読み取った全文 |
| purge_after | datetime | 送った日時＋30日 |
| purged_at | datetime null | |

#### source_records（原資料）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| kind | enum | receipt（今はこれだけ） |
| status | enum `SourceStatus` | new / drafted / journaled / ignored。一括代入不可 |
| ai_status | enum | waiting / reading / done / failed（ライブ画面の表示に使う） |
| ai_stage | string null | 受け取り → 読み取り中 → 店名 → 日付 → 合計 → 分類 → 仕訳（1項目ずつ出すため） |
| evidence_id | FK | |
| occurred_on | date null | AI が読んだ日付（直したら直した値） |
| amount_yen | bigint null | |
| vendor | string(100) null | |
| note | string(200) null | 送った人の一言メモ |
| submitted_by | FK users | |
| journal_entry_id | FK null | つながった仕訳 |
| ignored_reason / ignored_by | null | |

索引：(company_id, status, created_at)

#### journal_entries（仕訳）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| entry_date | date | 締めた期間の日付は不可 |
| kind | enum | normal / opening（期首残高） |
| status | enum `EntryStatus` | draft / approved。一括代入不可 |
| description / vendor | string | 摘要・取引先 |
| category_id | FK null | |
| created_by | FK users null | AI が作った下書きは null（作った人＝最初に保存した人） |
| approved_by / approved_at | null | |
| returned_reason | string(200) null | 差し戻しの理由 |
| ai_run_id | FK null | もとにした AI の実行（L2） |
| ai_check | json null | L3 の検算結果（借方合計・貸方合計・一致・気になる点） |

**approved の行は変更・削除しない**（Model の `updating` / `deleting` で例外）。

#### journal_lines（仕訳の行）
id・company_id・journal_entry_id・line_no・side（debit / credit）・account_id・amount_yen（1円以上）・tax_code null・memo null。

#### balance_checks / period_closings / audit_logs
| テーブル | 主な列 |
|---|---|
| balance_checks | company_id・bank_account_id・as_of（UNIQUE と口座）・balance_yen・recorded_by |
| period_closings | company_id・month（月初、UNIQUE）・closed_by・closed_at・warnings_snapshot（json） |
| audit_logs | company_id null・user_id null・action（例 `entry.approve`）・subject_type/id・before・after（json）・ip・created_at。更新・削除しない |

#### ai_runs / ai_corrections / ai_feedback（学習データ）
| テーブル | 主な列 |
|---|---|
| ai_runs | id・company_id・task（`AiTask`：read_receipt / journalize / check_entry / monthly_summary / close_check）・subject_type/id・model・prompt_version・driver（claude / fake / replay）・input（json、**写真は入れない**）・output（json）・valid（bool）・input_tokens・output_tokens・latency_ms・error・created_at |
| ai_corrections | id・company_id・ai_run_id・journal_entry_id・field（date / vendor / amount / category / debit_account / credit_account / line_amount）・before・after・reason（`CorrectionReason`）・**kernel**（間違いの型。例 `vendor:〇〇石油→category:燃料`、`amount:小計を合計と読んだ`）・corrected_by |
| ai_feedback | id・company_id・ai_run_id・rating（good / bad）・comment・user_id |

### 2.3 Enum
| Enum | 値 → label() |
|---|---|
| Role | owner 社長・責任者 / admin 管理者 / approver 承認者 / editor 経理担当 / viewer 閲覧のみ |
| SourceStatus | new 未処理 / drafted 下書きあり / journaled 仕訳済み / ignored 対象外 |
| EntryStatus | draft 下書き / approved 承認済み |
| Side | debit 借方 / credit 貸方 |
| AccountType | asset 資産 / liability 負債 / equity 純資産 / revenue 収益 / expense 費用 |
| BankAccountKind | cash 現金 / bank 銀行 / card カード / reimbursement 立替 |
| AiTask | read_receipt 読み取り / journalize 仕訳 / check_entry 検算 / monthly_summary 月次集計 / close_check 締めの確認 |
| CorrectionReason | misread 読み間違い / wrong_category 分類違い / private 私的支出 / tax_rate 税率違い / other その他 |

### 2.4 消す・変えるときの決まり
- 会社・人・勘定科目・口座・分類は消さない（`archived_at` / `disabled_at`）。
- 原資料は消さない。要らないものは「対象外」。
- 下書きの仕訳は消せる（原資料は new に戻る）。承認済みは消せない。
- 写真のファイルだけが30日で消える。

## 3. 状態の移り変わり

```
原資料：new ──AIが下書きを作る──→ drafted ──承認──→ journaled
         └──対象外（理由）──→ ignored        └─下書きを消す→ new
仕訳：  draft ──承認──→ approved
          ↑ 差し戻し（理由）
AI：    waiting → reading（ai_stage が進む）→ done / failed
```

**「あと n 件」＝その月の `source_records.status = new` の数 ＋ その月の `journal_entries.status = draft` の数**（`RemainingCount` の1か所だけで数える）。drafted の原資料はつながった下書きで数えるので、二重に数えない。

## 4. 処理（Services）

どれもトランザクションの中。状態を確かめる前に対象の行を `lockForUpdate()`。監査記録もトランザクションの中で書く。

### 4.1 SubmitEvidence（F04・F05）
1. 一時ファイルの SHA-256 を計算。同じ会社に同じハッシュがあれば「この写真はもう届いています」（保存しない）
2. `evidence` ディスクへ保存（DB の外）
3. DB：`evidences` と `source_records(status=new, ai_status=waiting)`、監査 `evidence.submit`。UNIQUE に当たったら重複として扱う
4. DB が失敗したら、保存したファイルを消す
5. `ProcessEvidenceWithAi` をキューに入れる
6. 「送れました。今月 n 枚目」

### 4.2 ProcessEvidenceWithAi（F06、キュー）
1. 1日の上限（`AI_DAILY_LIMIT`）を超えていれば `ai_status=failed`（理由：上限）で終わる
2. `ai_status=reading`、`ai_stage` を進めながら：
   - **L1 read_receipt**：写真（base64）→ 全文テキスト・日付・店名・合計・税率ごとの金額・登録番号・各欄の自信度
   - **L2 journalize**：L1 の結果＋会社の分類・勘定科目・口座の一覧＋**その会社の直した例（最近20件）**→ 分類・借方貸方の行・摘要・理由・自信度。直した例と同じ店なら `remembered=true`
3. 結果を `ai_runs` に残し、`evidences.extracted_text`・`source_records` の日付・金額・店名を入れる
4. **下書きの仕訳を作る**（`journal_entries.status=draft`、`ai_run_id`）。原資料は drafted
5. **L3 check_entry**：行の借方合計・貸方合計・一致・気になる点（重複の疑い・私的支出の疑い）を `journal_entries.ai_check` に入れる
6. `ai_status=done`。失敗（30秒・形が違う）は `failed`。人が手で下書きを作れる
7. 締めた期間の日付が返ってきたら、日付は締めた次の月の初日にして、「遅れて届いた」と表示する

### 4.3 DraftEntryService（F07）
- 直した欄ごとに、AI の値と人の値が違えば `ai_corrections` に前後・理由・kernel を入れる。理由は画面で選ぶ（未選択なら other）。kernel はサーバーで作る（例：分類が変われば `vendor:{店名}→category:{分類}`）
- 簡易入力から行を作り直す：支出なら借方＝分類の勘定科目、貸方＝支払い元の口座の勘定科目
- `CloseGuard::assertOpen()`：締めた期間なら保存しない
- 保存した人を `created_by` に入れる（自己承認の判定に使う）

### 4.4 ApprovalService（F09・F10）
```php
DB::transaction(function () use ($company, $user, $entryId) {
    $entry = $company->journalEntries()->lockForUpdate()->findOrFail($entryId);
    if ($entry->status === EntryStatus::Approved) return $entry;             // 2回目は何もしない
    $this->closeGuard->assertOpen($company, $entry->entry_date);
    if (! $company->allow_self_approval && $entry->created_by === $user->id) {
        throw new SelfApprovalForbidden;                                       // 「自分の下書きは承認できません」
    }
    $entry->forceFill(['status' => EntryStatus::Approved, 'approved_by' => $user->id, 'approved_at' => now()])->save();
    $entry->sources()->lockForUpdate()->get()
        ->each(fn ($s) => $s->forceFill(['status' => SourceStatus::Journaled])->save());   // 一括 update() は使わない
    AuditLog::record($user, 'entry.approve', $entry);
});
```
- **貸借の一致はデモ版ではコードで止めない**（L3 の結果を表示するだけ。01 §2）。不一致のときは画面に赤で出す
- 差し戻し：draft のまま `returned_reason`。対象外：原資料を ignored、下書きがあれば消す

### 4.5 CloseService（F11・F12）
- **L4 monthly_summary**：その月の承認済み仕訳（JSON）→ 勘定科目ごと・分類ごとの合計、口座の増減、前月との比較のコメント
- **L5 close_check**：未処理・下書き・現金の残高 → 「締めてよいか」の意見と理由
- 「あと n 件」は `RemainingCount`（コード）。**0件のときだけ締めるボタンを押せる**（owner）
- 締め：`companies` を lock、`closed_through` を月末に、`period_closings` を作る
- 現金の残高：`balance_checks` に入れる

### 4.6 ExcelExporter（F13）
`phpoffice/phpspreadsheet` で月ごとに作り、その場で返す（サーバーに残さない）。ファイル名 `{会社名}_帳簿_{YYYY-MM}.xlsx`。

| シート | 列・中身 | 数字の出どころ |
|---|---|---|
| 仕訳帳 | 日付・摘要・取引先・借方科目・借方金額・貸方科目・貸方金額・分類・作った人・承認した人・「AIのまま／人が直した」 | DB（承認済み） |
| 月次集計 | 勘定科目ごと・分類ごとの合計、口座の増減、AIのコメント。**分類ごとの円グラフ、口座の増減の棒グラフ** | **L4（LLM）**。「AIの計算」と注記 |
| 原資料 | 送った日・送った人・状態・読み取ったテキスト（写真は入れない） | DB |
| AIの記録 | その月の AI の実行回数・直された回数・直された欄と理由 | ai_runs・ai_corrections |

金額は `#,##0`。見出しは太字、列幅を整える。ダウンロードを監査に残す。

### 4.7 TrainingDataExporter（F14）
会社ごと・期間ごとに JSONL を zip で返す：`ai_runs.jsonl`・`ai_corrections.jsonl`・`journal_entries.jsonl`（承認済み＝正解）。写真・名前・メールは入れない（`user_id` はハッシュ）。

### 4.8 PurgeExpiredEvidence（F17）
毎日3時（スケジューラ）。`purge_after < now()` かつ未削除を100件ずつ、ファイルを消す → `storage_key=null`・`purged_at=now()` → 監査 `evidence.purge`。

### 4.9 Artisan コマンド（F02）
| コマンド | 中身 |
|---|---|
| `torudake:create-company "{名前}" --fiscal-year-end=7 [--demo]` | 会社と、勘定科目・分類・口座の初期値（§9.1） |
| `torudake:add-member {company} {email} {role} [--name=] [--submit-only]` | 人とメンバーを作り、パスワード再設定のメールを送る |
| `torudake:seed-demo {company}` | デモ用の1か月分の架空データ（承認済み多数＋下書き1件＝「あと1件」から始まる） |

## 5. 権限

| 操作 | owner | admin | approver | editor | viewer | 送るだけ |
|---|---|---|---|---|---|---|
| 送る | ○ | ○ | ○ | ○ | × | ○ |
| 原資料・写真を見る | ○ | ○ | ○ | ○ | ○ | 自分が送ったものだけ |
| 下書きを直す・消す | ○ | ○ | ○ | ○ | × | × |
| 承認・差し戻し・対象外 | ○ | ○ | ○ | × | × | × |
| 現金の残高 | ○ | ○ | ○ | ○ | × | × |
| 締めナビを見る・Excel | ○ | ○ | ○ | ○ | ○ | × |
| 締める | ○ | × | × | × | × | × |
| ライブ画面・AIの記録・学習データ | ○ | ○ | × | × | × | × |
| 会社設定 | ○ | ○ | × | × | × | × |

- `CompanyPolicy::member()`：所属していて `disabled_at` が null か。すべての Policy の最初に呼ぶ
- 承認は、`allow_self_approval=false` のとき自分が保存した下書きには使えない
- 他社のものはルートの段階で404、ロールが足りないときは403

## 6. ルーティング

```php
Route::middleware(['auth', 'verified', 'two-factor', 'company'])
    ->prefix('c/{company}')->name('company.')->scopeBindings()
    ->group(function () {
        Route::livewire('/', 'pages::company.home')->name('home');
        Route::livewire('submit', 'pages::company.submit')->name('submit');
        Route::livewire('sources', 'pages::company.sources.index')->name('sources.index');
        Route::livewire('sources/{source}', 'pages::company.sources.show')->name('sources.show');
        Route::livewire('entries', 'pages::company.entries.index')->name('entries.index');
        Route::livewire('entries/{entry}', 'pages::company.entries.show')->name('entries.show');
        Route::livewire('close/{month}', 'pages::company.close')
            ->where('month', '[0-9]{4}-(0[1-9]|1[0-2])')->name('close');
        Route::livewire('live', 'pages::company.live')->name('live');
        Route::livewire('ai', 'pages::company.ai')->name('ai');
        Route::livewire('settings', 'pages::company.settings')->name('settings');
        Route::get('evidences/{evidence}/image', EvidenceImageController::class)->name('evidences.image');
        Route::get('exports/{month}.xlsx', ExcelExportController::class)
            ->where('month', '[0-9]{4}-(0[1-9]|1[0-2])')->name('exports.excel');
        Route::get('exports/training-data.zip', TrainingDataController::class)->name('exports.training');
    });
```
- `/` と `/dashboard`：ゲスト→ログイン、所属が1社→その会社（送るだけ→送る、ほか→ホーム）
- 消えた写真の URL は410

### 6.1 入力の検証
| 項目 | ルール |
|---|---|
| 写真 | `required|file|max:10240|mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf` |
| 一言メモ | `nullable|string|max:200` |
| 日付 | `required|date|before_or_equal:today` |
| 取引先 | `nullable|string|max:100` |
| 分類・支払い元 | `required`、同じ会社の使用中のもの |
| 金額 | `required|integer|min:1|max:999999999999` |
| 理由（直した・差し戻し・対象外） | 選択式（`CorrectionReason`）／`required|string|max:200` |
| 現金の残高 | `required|integer|min:0` |

## 7. 画面（Livewire）

イメージ：[screens.png](mockups/screens.png)（スマホ4画面）、[send-states.png](mockups/send-states.png)（送る画面の6状態）、[live.png](mockups/live.png)（ライブ画面）、[storyboard.png](mockups/storyboard.png)（デモの流れ）。

| 親（ファイル） | 中身 | 機能 |
|---|---|---|
| ホーム `company/⚡home` | 「今月 あと n 件」、未処理・下書き・戻された仕訳の数、各画面への入口 | F11 |
| 送る `company/⚡submit` | **6つの状態**：待ち（大きな「撮る」、`<input type="file" accept="image/*,application/pdf" capture="environment">`）・確認（写真と「撮り直す」「送る」）・送信中（ボタンは押せない）・送れました（今月 n 枚目、「続けて撮る」）・同じ写真です・送れませんでした | F04・F05 |
| 原資料 `company/sources/⚡index` | 状態のタブと件数、送った人、経過日数、AIの状態 | F08 |
| 下書き `company/sources/⚡show` | 写真、AIの下書き（自信度の低い欄は `warn-soft`）、日付・取引先・分類・金額・支払い元、**直した理由**、「前回の修正を覚えています」、保存して次へ | F07 |
| 仕訳 `company/entries/⚡index`・`⚡show` | 借方・貸方の行、L3 の検算、根拠の写真、承認して次へ・差し戻す | F09・F10 |
| 締めナビ `company/⚡close` | 大きく「あと n 件」、L4 の集計、L5 の意見、現金の残高、締める（0件のときだけ）、Excel をダウンロード | F11〜F13 |
| **ライブ画面** `company/⚡live` | §7.1 | F15 |
| AIの記録 `company/⚡ai` | AI の実行回数・そのまま承認の割合・多い直し・学習データの書き出し | F14 |
| 会社設定 `company/⚡settings` | 会社名・決算月・自己承認、分類と勘定科目、口座、メンバーの一覧 | — |

スマホは下のタブ（ホーム・送る・原資料・仕訳・締め、文字14px以上）。送るだけの人にはタブを出さない。

### 7.1 ライブ画面（プロジェクター用、1920×1080）
- **左**：最新の1件。写真、AI が読み取った値（`ai_stage` に合わせて**1項目ずつ0.4秒の間をあけて**出す）、✓、処理時間、仕訳の行、「前回の修正を覚えています」、ボタン **E 直す**・**A 承認して次へ**（高さ76px）
- **右**：「今月の帳簿ができるまで あと n 件」（132px）、AIの処理時間、今日送った枚数、流れ（直近の数件）
- 今動いている所だけをオレンジの枠で明るく、ほかは不透明度60%。変わった値は1秒 `brand-soft`
- 0件になったら全面に「今月の帳簿ができました」
- `wire:poll.500ms` で読み直す。スマホから送っても PC で押しても同じ画面に出る
- キー：**1** ライブ／**2** 締めナビ／**3** Excel／**4** AIの記録／**E** 直す／**A** 承認
- 15秒を超えたら「少し時間がかかっています」、30秒で打ち切り（再生モードへ）
- 映さないもの：メールアドレス・本名（デモ用の名前「社長」「経理担当」）
- 下の帯：「写真は30日で自動削除・AIの学習には使われない・確定は人が承認」

## 8. 自動テスト（Pest）

AI はすべて `FakeClaudeClient`（決まった JSON を返す）。★はデモ版で必ず作る。

| # | 確かめること | 機能・基準 |
|---|---|---|
| T01★ | `/register` は404 | 済み |
| T02★ | 2段階認証が未設定の人は会社の画面に入れず設定画面へ | AC01 |
| T03★ | ログインから24時間でログアウト | F01 |
| T04★ | 会社Aの人が会社Bの URL（会社・原資料・仕訳・写真・締め・Excel・ライブ）を開くとすべて404。Livewire のアクションに他社の ID を渡しても404 | AC02 |
| T05★ | 権限の表（§5）をロールごとに回す | F03 |
| T06★ | 写真を送ると原資料と証憑ができ、ファイルが置かれ、ジョブが積まれる（`Storage::fake`・`Queue::fake`） | F04 |
| T07★ | 画像・PDF以外、10MB超は弾かれる | F04 |
| T08★ | 同じ写真を2回送ると1件だけ | AC04 |
| T09★ | ジョブ：偽物の AI で L1→L2→L3 が走り、下書きができ、`ai_runs` が3件、`ai_status=done` | F06 |
| T10★ | ジョブ：AI が失敗・形が違う・上限超えのとき、原資料は残り `failed` | F06 |
| T11★ | 会社Aの直した例が、会社Bの L2 の入力に入らない | 会社の分離 |
| T12★ | 下書きを直すと `ai_corrections` に前後・理由・kernel が入る | F07・AC09 |
| T13★ | 承認：自己承認は止まる、2回目は何も変わらない、人が押すまで approved にならない | AC05・AC06 |
| T14★ | 承認済みの仕訳は画面からも Model からも変えられない | — |
| T15★ | 「あと n 件」の式（未処理＋下書き）。台本のデータで 1→2→3→0 | AC07 |
| T16★ | 締めは owner だけ、0件のときだけ。締めた月の日付では下書きを保存できない | F12 |
| T17★ | Excel が4シートで、仕訳帳の行数が承認済みの数と一致、グラフがある | AC08 |
| T18★ | 学習データの JSONL に写真・名前・メールが入っていない | F14 |
| T19★ | 30日を過ぎた写真が消え、URL は410、同じ写真の再送は重複のまま | AC10 |
| T20★ | 監査記録に各操作が残る | F18 |
| T21★ | 送るだけの人は、送る画面以外が403 | F03 |
| T22 | 再生モード：同じ SHA-256 なら記録した出力を返す | F16 |

## 9. 初期データ

### 9.1 勘定科目と分類の初期値（丹内建機・案。Q2で直す）
| 分類 | 向き | 勘定科目 |
|---|---|---|
| 売上 | 収入 | 売上高 |
| 燃料 | 支出 | 車両費 |
| 部品・修理 | 支出 | 修繕費 |
| 消耗品 | 支出 | 消耗品費 |
| 通信 | 支出 | 通信費 |
| 交際 | 支出 | 接待交際費 |
| 手数料 | 支出 | 支払手数料 |
| 租税公課 | 支出 | 租税公課 |
| 雑費 | 支出 | 雑費 |

口座：現金（現金）・〇〇銀行（普通預金）・事業用カード（未払金）・社長の立替（役員借入金）。ほかに資本金・繰越利益剰余金・売掛金・買掛金・仕入高・外注費。

### 9.2 環境ごと
| 環境 | 中身 |
|---|---|
| local・testing | 会社2つ（「丹内建機（デモ）」決算月7月、「デモ工務店」）。owner@example.com（社長役）・editor@example.com（経理担当役）・submitter@example.com（送るだけ）・other@example.com（他社）。全員2段階認証済み・パスワード `password`。各状態の原資料と仕訳 |
| production | `torudake:create-company` と `torudake:add-member` で作る（runbook §1.2）。デモ用は `--demo` の会社と `torudake:seed-demo` |

## 10. 作る順番（残り）

1工程ずつ。終えるたびに `/check` → コミット → push（自動で本番へ）。**時刻は目安。間に合わなければ §10.2 の順に削る。**

| 工程 | 時間（目安） | 内容 | テスト |
|---|---|---|---|
| 2 会社と権限 | 10/24 10:00–11:30 | companies・memberships・Enum・`company`／`two-factor`／24時間のミドルウェア・CompanyPolicy・監査・コマンド2つ・勘定科目・分類・口座のシーダー・振り分け | T02〜T05・T20・T21 |
| 3 送る＋AI | 11:30–14:00 | evidences・source_records・送る画面（6状態）・写真のルート・原資料の一覧・ai_runs・`ClaudeClient`／偽物・ジョブ（L1→L2→L3） | T06〜T11 |
| 4 直す | 14:00–15:30 | 下書きの画面・DraftEntryService・ai_corrections・覚えた印 | T12 |
| 5 承認 | 15:30–17:00 | 仕訳の一覧と詳細・ApprovalService・差し戻し・対象外 | T13・T14 |
| 6 締めとライブ | 17:00–19:00 | RemainingCount・締めナビ（L4・L5）・現金・締める・**ライブ画面** | T15・T16 |
| 7 出力と削除 | 10/25 9:00–10:00 | ExcelExporter（4シート・グラフ）・AIの記録・JSONL・写真の30日削除 | T17〜T19 |
| 8 デモの仕上げ | 10:00–12:00 | 再生モード・`torudake:seed-demo`・本番に会社とメンバーを作る・架空の領収書3枚で台本（04 §7.3）を2回通して1回録画 | T22・AC11 |

### 10.1 完了の条件（どの工程も）
- `vendor/bin/sail composer test` が通る（ビューを触ったら `sail npm run build`）
- main に push → `tests` と `deploy` が緑
- 本番で、その工程の画面が開く

### 10.2 削る順番（先に決めておく）
| こうなっていたら | こうする |
|---|---|
| 工程3で本物の AI が動かない | L1 と L2 を1回の呼び出しにまとめる |
| 工程4で覚えた印ができない | 印は出さず、口頭で説明する（仕組みは同じ） |
| 工程5が遅れた | L5（締めの確認）をやめ、締めナビは件数と L4 だけ |
| 工程6が遅れた | ライブ画面を優先し、締めナビはライブ画面の「あと n 件」で代える |
| 工程7が遅れた | Excel は仕訳帳と月次集計の2シート、グラフ1つ |
| どの時点でも | **他社が見えないこと・人の承認・写真の30日削除・本番反映・再生モードは削らない** |

## 11. 未決事項（実装は止めない）
| # | 決めること | 今の扱い |
|---|---|---|
| Q1〜Q9 | 01 §9.2 | 会社設定であとから入れる |
| X1 | メールを本当に送る | 当面 log。runbook §6 |
| X2 | 開発の Sail を PHP 8.5 にそろえる | デモのあと |
| X3 | 締めの取り消し | 作らない（必要なら runbook で手順化） |
