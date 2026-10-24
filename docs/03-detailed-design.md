# 03｜詳細設計書 — 撮って送るだけ帳簿（torudake）

版3.0・2026-10-24 14:30。機能番号（F・AC）は [01 要件定義書](01-requirements.md)、技術の決まりは [02 技術仕様書](02-technical-spec.md)。画面のイメージは [docs/mockups/](mockups/)（社長の画面は本書 §7.3 が新しい）。

---

## 0. 今の状態と、版3.0 で変わること

| 工程 | 内容 | 状態 |
|---|---|---|
| 0 | 土台（Laravel 13＋Livewire、Sail、Pest、デザイントークン、CI） | 済み |
| 1 | 本番 torudake-app.com、自動デプロイ | 済み |
| 2 | 会社と権限・監査・初期データ・コマンド | 済み（10:21） |
| 3 | 送る（1枚）・原資料の一覧・写真のルート・AIのジョブ（読み取り→仕訳→検算） | 済み（10:40）。テスト55件 |
| 3.5〜9 | §10 の順に作る | これから |

**版3.0 で変わること（実装済みの部分への影響）**
| 変更 | 実装済みへの影響 | やり方 |
|---|---|---|
| 確定は経理の保存（社長の承認をなくす） | `EntryStatus` の `approved` → `confirmed`、`returned` を足す | **新しい移行ファイル**で列を足し、既存の値を置き換える（本番に出ている移行ファイルは書き換えない） |
| 締めをなくす | `companies.closed_through`・`allow_self_approval` は使わない | 列は残す（消さない）。コードから参照しない |
| まとめて送る | 送る画面と `SubmitEvidence` | 1枚ずつの処理を、まとめた枚数ぶん呼ぶ |
| 社長の画面・通知・Excel・帳票の取り込み | なし（新規） | 新しいテーブルと画面 |

---

## 1. 言葉の使い分け

| 言葉 | テーブル | 意味 |
|---|---|---|
| 会社 | `companies` | データを分ける単位 |
| メンバー | `memberships` | 人と会社のつながり。ロールと「送るだけ」 |
| 証憑（写真） | `evidences` | 送った写真・PDF。30日でファイルは消え、行は残る |
| 原資料 | `source_records` | 仕訳のもとになる1件（今はレシート） |
| 仕訳 | `journal_entries`＋`journal_lines` | AIの下書き → 経理の保存で**確定** →（社長が戻せば）**社長から戻し** |
| 気になる仕訳 | `journal_entries.concern_flags` | 社長に見てもらう印（AIの検算・金額・経理の直し） |
| Excel の1冊 | `workbooks`＋ファイル | アプリが持ち続ける Excel。確定のたびに行が増える |
| 帳票の取り込み | `report_imports` | 財務王・販売王の帳票の写真。AIが表を読み、経理が確かめて保存 |
| 外の数字 | `external_monthly_figures`・`external_customer_sales` | 取り込んだ月ごとの売上・取引先ごとの年間売上（**名前なし**） |
| 通知 | `notifications`（Laravel 標準） | 社長へのお知らせ（ベル） |

## 2. データベース

ID は UUIDv7（`HasUuids`）。`users` は bigint（スターターキットのまま）。金額は `BIGINT`（円）。業務テーブルは `company_id`（restrict）と `created_at`・`updated_at` を持つ。

### 2.1 実装済みのテーブル（版2.0 のまま）
`users`・`companies`・`memberships`・`accounts`・`categories`・`bank_accounts`・`audit_logs`・`evidences`・`source_records`・`journal_entries`・`journal_lines`・`ai_runs`・`ai_corrections`（列は 2026_10_24_100000・110000 の移行ファイルのとおり）。

### 2.2 版3.0 で足す列（新しい移行ファイル `2026_10_24_140000_confirm_by_bookkeeper`）
| テーブル | 列 | 型 | 内容 |
|---|---|---|---|
| companies | concern_amount_yen | bigint default 30000 | 気になる仕訳の金額の目安（Q1） |
| companies | approval_required_yen | bigint null | 社長の承認が必要な金額（将来 E09。null＝承認なし） |
| journal_entries | confirmed_by / confirmed_at | FK users null / datetime null | 確定した経理と時刻 |
| journal_entries | returned_by / returned_at | FK users null / datetime null | 社長が戻した人と時刻（理由は既存の `returned_reason`） |
| journal_entries | concern_flags | json null | 気になる印の一覧（例 `["amount","ai_duplicate","corrected"]`） |
| journal_entries | owner_seen_at | datetime null | 社長が見た時刻（通知の既読に使う） |
| journal_entries | workbook_row | int null | Excel の1冊の何行目に入れたか |
| source_records | batch_id | uuid null | まとめて送った単位（同じ回の写真をまとめて表示） |

移行の中で、`journal_entries.status = 'approved'` の行を `confirmed` に、`approved_by`→`confirmed_by`、`approved_at`→`confirmed_at` に写す。`approved_by`・`approved_at`・`companies.closed_through`・`allow_self_approval` は残すが使わない。

### 2.3 版3.0 で足すテーブル
#### workbooks（Excel の1冊）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | 会社に1冊（UNIQUE company_id） |
| storage_key | string | `workbooks/{company_id}.xlsx`（非公開ディスク） |
| layout | json | 列の並び。既定は §4.6。既存の Excel を取り込んだら、その列の並び（F15） |
| next_row | int | 次に書く行 |
| version | int | 書き換えるたびに +1（同時に2人が書いても壊さない） |

#### report_imports（帳票の取り込み）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| kind | enum `ReportKind` | sales_three_period（売上高三期比較）/ customer_sales（顧客別売上集計） |
| evidence_id | FK | 撮った写真（30日で消える） |
| period_from / period_to | date | 帳票の期間（AIが読み、経理が確かめる） |
| ai_run_id | FK null | 読み取り（`read_report`） |
| status | enum | reading / review（経理が確かめる）/ saved / failed |
| saved_by / saved_at | | |

#### external_monthly_figures（外の月ごとの数字）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| year_month | char(7) | `2026-08` |
| metric | enum | sales（売上高）。将来：cost_of_sales・sga・operating_profit・cash |
| amount_yen | bigint | |
| source | enum | zaimu（財務王）/ hanbai（販売王）/ manual |
| report_import_id | FK null | もとの取り込み |
UNIQUE(company_id, year_month, metric)。同じ月を取り込み直したら上書き（監査に前後）。

#### external_customer_sales（取引先ごとの売上。名前なし）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| period_from / period_to | date | |
| rank | smallint | 金額の大きい順の順位 |
| label | string(10) | 「A社」「B社」…（**取引先の名前は保存しない**） |
| amount_yen | bigint | 売上金額（返品・値引き後） |
| report_import_id | FK | |

#### notifications（Laravel 標準）
`php artisan make:notifications-table`。社長への通知（§4.8）。

### 2.4 Enum（版3.0）
| Enum | 値 → label() |
|---|---|
| EntryStatus | draft AIの下書き / **confirmed 確定** / **returned 社長から戻し**（`approved` は移行で confirmed に置き換え、Enum から外す） |
| SourceStatus | new 未処理 / drafted 下書きあり / **confirmed 確定** / ignored 対象外（`journaled` を confirmed に置き換え） |
| ConcernFlag | amount 金額が大きい / ai_duplicate 重複の疑い / ai_private 私的支出の疑い / ai_unbalanced 貸借が合わない / corrected 経理がAIを直した |
| ReportKind | sales_three_period 売上高三期比較 / customer_sales 顧客別売上集計 |
| AiTask（足す） | read_report 帳票の読み取り / owner_brief 社長へのひとこと / forecast 来月の見込み |

## 3. 状態の移り変わり

```
原資料：new ─AIが下書き─→ drafted ─経理が保存─→ confirmed
         └─対象外（理由）─→ ignored
仕訳：  draft ─経理が保存＝確定─→ confirmed ─社長が戻す（理由）─→ returned ─経理が直して保存─→ confirmed
AI：    waiting → reading（ai_stage）→ done / failed
帳票：  reading → review（経理が確かめる）→ saved / failed
```

**「あと n 件」＝その月の `source_records.status = new` ＋ `journal_entries.status IN (draft, returned)`**（`RemainingCount` だけで数える）。0件で「今月の帳簿ができました」。

## 4. 処理（Services）

どれもトランザクションの中で、対象の行を `lockForUpdate()` してから状態を確かめる。監査記録もトランザクションの中。

### 4.1 SubmitEvidence（F04・F05、まとめて送る）
- 画面は、撮るたびに一時ファイル（Livewire の `WithFileUploads`、`photos[]`）をためる。「まとめて送る（n枚）」で、同じ `batch_id` を付けて1枚ずつ `submit()` を呼ぶ（既存の処理をそのまま使う）。
- 結果を1枚ずつ返す：送れた／もう届いています。1枚でも送れたら「送れました（n枚）」。

### 4.2 ProcessEvidenceWithAi（F06、実装済み）
読み取り → 仕訳の下書き → 検算。版3.0 で足すこと：検算の結果から `concern_flags` の AI の印（ai_duplicate・ai_private・ai_unbalanced）を入れる。

### 4.3 ConfirmEntryService（F07、経理の保存＝確定）
```php
DB::transaction(function () use ($company, $user, $entryId, $input) {
    $entry = $company->journalEntries()->lockForUpdate()->findOrFail($entryId);
    if ($entry->status === EntryStatus::Confirmed) return $entry;              // 2回押しても1回だけ
    // 直した欄ごとに ai_corrections（前後・理由・kernel）を入れる
    // 行を作り直す（支出：借方＝分類の勘定科目、貸方＝支払い元の口座の勘定科目）
    $flags = $this->concernRules->for($entry, $company, corrected: $wasCorrected);  // §4.5
    $entry->forceFill([
        'status' => EntryStatus::Confirmed, 'confirmed_by' => $user->id, 'confirmed_at' => now(),
        'concern_flags' => $flags, 'returned_reason' => null,
    ])->save();
    $entry->sources()->lockForUpdate()->get()
        ->each(fn ($s) => $s->forceFill(['status' => SourceStatus::Confirmed])->save());
    AuditLog::record($user, 'entry.confirm', $entry);
});
// トランザクションのあとで：WorkbookService::upsertRow($entry)（§4.6）、気になる印があれば社長へ通知（§4.8）
```
- 保存の前に、AIの検算（借方合計・貸方合計・気になる点）を必ず画面に出す（守り §01-6.2）。
- 貸借の一致は、デモ版ではコードで止めず、赤で表示する。

### 4.4 ReturnToBookkeeper（F13、社長から戻す）
confirmed → returned、`returned_by`・`returned_at`・`returned_reason`（必須）。Excel の行の状態を「社長から戻し」に書き換える。経理への通知。経理が直して保存すると、ふたたび confirmed（Excel の同じ行を上書き）。

### 4.5 ConcernRules（気になる仕訳の条件）
| 印 | 条件 |
|---|---|
| amount | 金額 ≥ `companies.concern_amount_yen`（仮に30,000円） |
| ai_duplicate / ai_private / ai_unbalanced | AIの検算の結果 |
| corrected | 経理がAIの分類・金額を直した |

### 4.6 WorkbookService（F14・F15、Excel の1冊）
- 1冊を `phpoffice/phpspreadsheet` で開き、`version` を確かめてから書く（同時に書いたら後から来たほうがやり直す）。
- **仕訳帳シート**（既定の列）：管理番号（仕訳の ID の頭8文字）・日付・取引先・分類・借方科目・借方金額・貸方科目・貸方金額・摘要・**状態**（確定／社長から戻し）・確定した人・確定した日時・AIのまま／直した
- 確定 → 行を足す（`workbook_row` に行番号）。戻す・再確定 → 同じ行を書き換える（管理番号で探す）。
- **社長用シート**：三期比較の表（月×3期）、今期の経費と残ったお金。社長の画面と同じ数字（§4.7）。ダウンロードのたびに作り直す。
- **AIの記録シート**：AIの実行回数・直された回数・多い直し。
- 既存の Excel の取り込み（F15）：アップロードされたファイルの1行目を列の名前として読み、上の列と対応づける画面を出す。対応づけたら `layout` に保存し、以後その形で追記。**列の形が分かるまでは既定の形で作る。**

### 4.7 OwnerDashboard（F12、社長の画面の数字）
| 区画 | 数字 | 出どころ |
|---|---|---|
| ① ひとこと | 一番大きな変化を1つ・理由の見立て・確かめるとよい点 | `owner_brief`（AI）。渡すのは②⑤の合計だけ |
| ② 三期比較 | 月×（今期・前期・前々期）の売上 | `external_monthly_figures`（sales） |
| ② 今期の経費・残ったお金 | 今期の月ごとの確定した経費（分類ごと）、売上 − 経費 | アプリの confirmed の仕訳＋②の売上 |
| ③ 気になる仕訳 | `concern_flags` があり `owner_seen_at` が null のもの | アプリ |
| ④ 来月の見込み | 来月の売上と経費の中央値・8割の範囲 | `forecast`（AI）。過去の月の実績を渡す |
| ⑤ 売上の柱 | 上位3社の割合（A社・B社・C社） | `external_customer_sales` |
- 比べは**金額の差を主に、%を添える**。前月だけでなく**前年同月**と並べる。
- 経費は、アプリを使い始めた月からしかない（過去の経費は財務王にあり、取り込まない）。経費のない月は「—」と出す。

### 4.8 通知（F11）
| 通知 | いつ | 中身 |
|---|---|---|
| WeeklyOwnerDigest | 毎週月曜 7:00（スケジューラ） | 「先週 n 件確定・気になる仕訳 n 件・今期の売上は前期の n%」 |
| ConcernRaised | 気になる印の付いた仕訳が確定したとき（1日にまとめて1回） | 「気になる仕訳が n 件あります」 |
| ReturnedToBookkeeper | 社長が戻したとき（経理へ） | 「社長から戻されました：理由」 |

### 4.9 ReportImportService（F16、帳票の取り込み）
1. 経理が帳票を撮って送る（種類を選ぶ：三期比較／顧客別）。写真は `evidences` に入れる（30日で消える）
2. `ProcessReportWithAi`：`read_report` で表を読む。三期比較なら「期・月・売上金額」の表、顧客別なら「期間・売上金額・返品・値引き」の列（**取引先の名前の列は読まない・保存しない**）
3. 経理が、読んだ表を画面で確かめて直す（数字の読み違いを直せる）→ 保存
4. 三期比較 → `external_monthly_figures`（月ごと）。顧客別 → 金額の大きい順に `external_customer_sales`（A社・B社…）
5. 監査 `report.import`

### 4.10 そのほか（版2.0 のまま）
TrainingDataExporter（JSONL）、PurgeExpiredEvidence（毎日3時）、Artisan コマンド（`torudake:create-company`・`torudake:add-member`・`torudake:seed-demo`）。`torudake:seed-demo` は、**架空の**三期比較（3期分の月ごとの売上）・顧客別（上位2社で約8割）・今期の確定した経費・「あと1件」から始まるデータを入れる。人は作らない（§9）。

## 5. 権限（版3.0）

| 操作 | owner | admin | approver | editor | viewer | 送るだけ |
|---|---|---|---|---|---|---|
| 送る（まとめて） | ○ | ○ | ○ | ○ | × | ○ |
| 原資料・写真を見る | ○ | ○ | ○ | ○ | ○ | 自分の分だけ |
| 下書きを直して確定・対象外 | ○ | ○ | ○ | ○ | × | × |
| 帳票の取り込み | ○ | ○ | ○ | ○ | × | × |
| 社長の画面・通知・経理に戻す | ○ | ○ | × | × | × | × |
| Excel のダウンロード | ○ | ○ | ○ | ○ | ○ | × |
| ライブ画面・AIの記録・学習データ | ○ | ○ | × | × | × | × |
| 会社設定（気になる金額など） | ○ | ○ | × | × | × | × |

他社は404、ロール不足は403（版2.0 のまま）。

## 6. ルーティング（足すもの）
```php
// /c/{company} のグループ（版2.0）に足す
Route::livewire('owner', 'pages::company.owner')->name('owner');                 // 社長の画面
Route::livewire('reports', 'pages::company.reports.index')->name('reports.index'); // 帳票の取り込み
Route::livewire('reports/{report}', 'pages::company.reports.show')->name('reports.show'); // 読んだ表を確かめる
Route::get('workbook.xlsx', WorkbookDownloadController::class)->name('workbook');  // Excel の1冊
Route::livewire('notifications', 'pages::company.notifications')->name('notifications');
```
`close/{month}` と `exports/{month}.xlsx` はなくす（`workbook.xlsx` に置き換え）。

## 7. 画面（版3.0）

| 画面 | 中身 |
|---|---|
| 送る `⚡submit` | 待ち（「撮る」）→ 撮るたびに下に小さな写真がたまる（×で外せる）→「**まとめて送る（n枚）**」→ 送信中 → 送れました（n枚）／うち同じ写真 n 枚 → 続けて撮る |
| 原資料 `sources/⚡index` | 状態のタブ（未処理・AIの下書き・確定・社長から戻し・対象外） |
| 直して確定 `sources/⚡show` | 写真、AIの下書き、AIの検算、直した理由、「前回の修正を覚えています」、**保存（確定）して次へ** |
| 社長の画面 `⚡owner` | §7.3 |
| 帳票の取り込み `reports/⚡index`・`⚡show` | 種類を選んで撮る → AIが読んだ表 → 直して保存 |
| 通知 `⚡notifications` | ベルを押すと一覧 |
| ライブ画面 `⚡live` | 版2.0 のまま。ボタンは **E 直す・S 保存（確定）**（A 承認はなくす）。キー5で社長の画面 |
| ホーム `⚡home` | 「今月 あと n 件」、各画面への入口。社長には「社長の画面」を一番上に |

### 7.1 送る画面の状態（6つ＋ためる）
待ち・**ためている（n枚）**・送信中・送れました・同じ写真です・送れませんでした。

### 7.2 デザイントークンと文字（版2.0 のまま）
本文16px以上、ライブ画面32px以上、強調はオレンジ1色、コントラスト4.5以上。

### 7.3 社長の画面（1画面、上から重要な順）
```
┌ 🔔 先週 12件確定・気になる仕訳 2件 ─────────────────┐
│ ① 今月のひとこと（AI）                                    │
│   「今期8〜9月の売上は前期より10%多い。売上の8割は1社。    │
│     その会社の今後の工事の予定を確かめるとよい」            │
│ ② 三期比較（財務王のグラフと同じ形）  ■今期 ■前期 ■前々期 │
│   8月 9月 10月 … 7月 の棒グラフ ＋ 表（金額・前年同月との差） │
│   今期の経費（分類ごと）と、売上 − 経費                     │
│ ③ 気になる仕訳（2件） → 見た／経理に戻す（理由）           │
│ ④ 来月の見込み：売上 だいたい○○万円（8割で○〜○万円）      │
│ ⑤ 売上の柱：A社 80% / B社 20%                             │
│ くわしく：仕訳の一覧 ／ Excel をダウンロード                │
└───────────────────────────────────┘
```
- グラフは Chart.js。色はデザイントークン（今期＝brand、前期＝ink、前々期＝muted）。
- 経費のない月は「—」。数字は架空のデモ用と、本番の実データで同じ形。

## 8. 自動テスト（版3.0）

★はデモ版で必ず作る。AIはすべて偽物。**テストの数字は架空**（実在の帳票の数字を入れない）。

| # | 確かめること | 状態 |
|---|---|---|
| T01〜T11 | 認証・他社404・権限・送る・重複・AIのジョブ・会社の分離（版2.0） | 済み（一部は版3.0 の権限で直す） |
| T12★ | 経理の保存で confirmed、ai_corrections に前後・理由・kernel | これから |
| T13★ | 2回保存しても確定は1回。AIだけでは confirmed にならない | これから |
| T14★ | 確定すると Excel の1冊に1行入る。戻すと同じ行の状態が「社長から戻し」、再確定で上書き | これから |
| T15★ | 「あと n 件」＝new＋draft＋returned。台本のデータで 1→3→0 | これから |
| T16★ | 気になる印：金額・AIの印・直したもの。会社の金額設定が効く | これから |
| T17★ | 社長だけが戻せる（理由必須）。editor は社長の画面に入れない | これから |
| T18★ | まとめて送る：3枚のうち1枚が重複でも、2枚は送れる。同じ batch_id | これから |
| T19★ | 帳票の取り込み：三期比較の表が月ごとの数字に、顧客別が A社・B社 に入る。**名前は保存されない** | これから |
| T20★ | 社長の画面の数字（三期比較・前年同月の差・売上の柱）が、取り込んだ数字と一致 | これから |
| T21★ | 週1回の通知が社長にだけ届く | これから |
| T22★ | 30日を過ぎた写真が消える。監査記録 | これから |
| T23 | 再生モード | これから |
| T24★ | 移行：approved の仕訳が confirmed になる | これから |

## 9. 初期データ
- 開発：版2.0 のまま。**2段階認証の鍵は16文字以上の固定値に直す**（ブラウザでログインできるように）。社長・経理・送るだけ・他社の4人
- デモ：`torudake:seed-demo`（§4.10、すべて架空）。**人は作らない**（会社と架空の数字だけ）。本番のデモ用の人は `torudake:add-member` で作り、パスワードと2段階認証は本人が設定する
- 開発用のパスワード・2段階認証の鍵（`UserFactory::DEV_*`）は、本番（`app()->isProduction()`）で使われたら例外にする
- 本番：`torudake:create-company`・`torudake:add-member`。実データの帳票は、本番で経理が取り込む（社長の同意メモのあと）

## 10. 作る順番（10/24 14:30 から）

見積もりは、超過62%を見込んで組む（[戦略的悲観術](https://daigovideolab.jp/play/D2SQ2WUQq4MZCYBFlMeo?t=3334)）。

| 工程 | 時間（目安） | 内容 | テスト |
|---|---|---|---|
| 3.5 手直し | 14:30–15:15 | 初期データの2段階認証の鍵、スターターキットの不要なリンクを消す、`.env` 変更後の `queue:restart` を runbook と AGENTS.md に | — |
| 4 確定 | 15:15–17:00 | 移行（§2.2）、EntryStatus の置き換え、直して確定の画面、ConfirmEntryService、ConcernRules、対象外、RemainingCount | T12・T13・T15・T16・T24 |
| 5 Excel | 17:00–18:00 | WorkbookService（仕訳帳への追記・書き換え）、ダウンロード | T14 |
| 6 まとめて送る・ライブ | 18:00–19:30 | 送る画面のためる・まとめて送る、ライブ画面（E・S、キー5） | T18 |
| 7 社長の画面 | 10/25 8:00–9:30 | 帳票の取り込み（三期比較・顧客別）、OwnerDashboard、owner_brief・forecast、グラフ、戻す、通知 | T17・T19〜T21 |
| 8 仕上げ | 9:30–10:30 | 写真の30日削除、AIの記録・JSONL、再生モード、`torudake:seed-demo` | T22・T23 |
| 9 本番とデモ | 10:30–12:00 | 本番に会社と3人を作る、`AI_DRIVER=claude`＋キー＋`queue:restart`、**実際のスマホで台本を2回**、1回録画 | AC12 |

### 10.1 削る順番（先に決めておく）
| こうなっていたら | こうする |
|---|---|
| 19:30 に工程6が終わっていない | ライブ画面は版2.0 の形のまま（キー5だけ足す）。まとめて送るは「1枚ずつ続けて送る」で代える |
| 10/25 9:30 に社長の画面ができていない | ④見込みと通知をやめ、①ひとこと・②三期比較・③気になる仕訳だけ |
| 既存の Excel の列が分からない | F15 はやめ、既定の列で作る |
| 帳票の取り込みが間に合わない | デモ用の架空の数字を `seed-demo` で直接入れる（取り込みの画面は口頭で説明） |
| どの時点でも | **他社が見えないこと・確定は人・写真の30日削除・本番反映・再生モード・実データをデモとリポジトリに入れないこと**は削らない |

## 11. 未決事項
01 §10.2 の Q1〜Q7。どれも会社設定・既定値で進め、実装は止めない。
