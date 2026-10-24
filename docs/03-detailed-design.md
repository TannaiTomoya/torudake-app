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
| 3.5 | 手直し（開発用の鍵・スターターキットのリンク・`queue:restart`） | 済み（14:38） |
| 4 | 確定（経理の保存）・気になる印・対象外・あと n 件・金額なしの下書き・キューの常駐2つ | 済み |
| 4.5 | 忙しい月（会社設定） | 済み |
| 5 | Excel の1冊（仕訳帳への追記・書き換え・ダウンロード） | 済み。社長用・AIの記録のシートは工程7・8 |
| 6 | まとめて送る（ためる・×で外す・同じ batch_id・重複や失敗があってもほかは送れる）、ライブ画面（E 直す・S 保存、キー1・3・4・5） | 済み |
| 7b | 社長の画面（三期比較・前年同月の差・3期の平均・忙しい月の色づけと「忙しい月なのに前年より少ない」・今期の経費と残ったお金・気になる仕訳・売上の柱）、`torudake:seed-demo`（架空の三期比較・顧客別・今期の経費）。帳票の3テーブルを1つの移行ファイルで作った | 済み。ひとこと・見込みは 7c、戻す・見たは 7d |
| 7c | 社長へのひとこと（`owner_brief.v1`、7d で v2）・来月の見込み（`forecast.v1`）。入力のハッシュが同じなら AI を呼ばず、違えばジョブで作り直し、できるまで前回のものに「更新中」 | 済み |
| 7d | 社長が理由を付けて「経理に戻す」（社長の画面の気になる仕訳と、仕訳の画面から）・「見た」の印。気になる仕訳の数はサイドバーのバッジと社長の画面の欄に出す。ひとことの入力から気になる仕訳の数を外した（`owner_brief.v2`。「見た」で AI を呼び直さない）。経理への通知は 7e | 済み |
| 8・7a・7e・7f・9 | この順に作る（工程8 → 取り込み → 通知 → 社長用シート） | これから |

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
| companies | busy_months | json（初期値 `[6,7,10,11,12]`） | 忙しい月（社長の見立て）。1〜12の月の並び。会社設定で社長・管理者が変える |
| companies | approval_required_yen | bigint null | 社長の承認が必要な金額（将来 E09。null＝承認なし） |
| journal_entries | confirmed_by / confirmed_at | FK users null / datetime null | 確定した経理と時刻 |
| journal_entries | returned_by / returned_at | FK users null / datetime null | 社長が戻した人と時刻（理由は既存の `returned_reason`） |
| journal_entries | concern_flags | json null | 気になる印の一覧（例 `["amount","ai_duplicate","corrected"]`） |
| journal_entries | owner_seen_at | datetime null | 社長が見た時刻（通知の既読に使う） |
| journal_entries | workbook_row | int null | Excel の1冊の何行目に入れたか |
| journal_entries | bank_account_id | FK bank_accounts null | 支払い元（確定のときに貸方の勘定科目を決める。金額のない下書きでも持つ） |
| source_records | batch_id | uuid null | まとめて送った単位（同じ回の写真をまとめて表示） |

移行の中で、各社に口座「後日の請求書（未払金）」（kind `invoice`、勘定科目は未払金）を足し、`journal_entries.status = 'approved'` の行を `confirmed` に、`approved_by`→`confirmed_by`、`approved_at`→`confirmed_at` に写す。`approved_by`・`approved_at`・`companies.closed_through`・`allow_self_approval` は残すが使わない。

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
| rows | json null | AIが読んだ表（経理が確かめて直すまでここに置く） |
| created_by / saved_by / saved_at | | 撮った人・保存した人と時刻 | |

#### external_monthly_figures（外の月ごとの数字）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| year_month | char(7) | `2026-08` |
| metric | enum | sales（売上高）。将来：cost_of_sales・sga・operating_profit・cash |
| amount_yen | bigint | |
| source | enum `FigureSource` | zaimu（財務王）/ hanbai（販売王）/ manual / demo（デモ用の架空の数字） |
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
| report_import_id | FK null | もとの取り込み（デモの架空の数字は null） |

#### owner_insights（社長へのひとこと・来月の見込み）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id / kind | | kind は brief / forecast。UNIQUE(company_id, kind) |
| input_hash | char(64) null | output を作ったときの入力のハッシュ（プロンプトの版＋入力の JSON の sha256） |
| output / ai_run_id / generated_at | | AI の答えと、その実行・時刻 |
| pending_hash / pending_at | | 作り直しを頼んだ入力のハッシュと時刻（「更新中」） |
| error | text null | 作り直せなかった理由（前回の output は残す） |

#### notifications（Laravel 標準）
`php artisan make:notifications-table`。社長への通知（§4.8）。

### 2.4 Enum（版3.0）
| Enum | 値 → label() |
|---|---|
| EntryStatus | draft AIの下書き / **confirmed 確定** / **returned 社長から戻し**（`approved` は移行で confirmed に置き換え、Enum から外す） |
| SourceStatus | new 未処理 / drafted 下書きあり / **confirmed 確定** / ignored 対象外（`journaled` を confirmed に置き換え） |
| ConcernFlag | amount 金額が大きい / **amount_missing 金額の記載なし** / ai_duplicate 重複の疑い / ai_private 私的支出の疑い / ai_unbalanced 貸借が合わない / corrected 経理がAIを直した |
| BankAccountKind（足す） | invoice 後日の請求（未払金） |
| ReportKind | sales_three_period 売上高三期比較 / customer_sales 顧客別売上集計 |
| AiTask（足す） | read_report 帳票の読み取り（7a）/ owner_brief 社長へのひとこと / forecast 来月の見込み |
| InsightKind | brief 今月のひとこと / forecast 来月の見込み |

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
- L1（`read_receipt.v3`）は `amount_missing`（金額がそもそも書かれていない）と `payment_method`（cash / card / invoice_later / unknown）、宛名 `addressee` とその種類 `addressee_kind`（入力の会社名と比べる）も返す。
- L2（`journalize.v3`）は分類と支払い元（口座の名前）だけを返し、**借方・貸方の行はコードが組む**（`JournalLineWriter`）。支払い元は、①同じ店で人が直した支払い元（`PaymentMemory`、直した記録から。店名の空白・全角半角の違いは同じ店）②`invoice_later` なら「後日の請求」の口座 ③AI が選んだ口座 ④既定の口座、の順。①のときは画面に「前回、この店は『○○』に直されました」（`ai_check.remembered_payment`）。
- **金額の記載がない書類**（ガソリンカードの納品書など）は、行のない下書きを作り、`amount_missing` の印を付ける。検算（L3）は呼ばない。経理が金額を入れたら確定できる（記載のなかった金額を入れたのは「直した」に数えない）。
- 常駐は2つ（`torudake-queue@1`・`@2`）。`retry_after`（300秒）はジョブの時間の上限（240秒）より長くする。

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
confirmed → returned、`returned_by`・`returned_at`・`returned_reason`（必須、200文字まで）。社長・管理者だけ。社長の画面の気になる仕訳と、仕訳の画面から戻せる。監査 `entry.return`。Excel の行の状態を「社長から戻し」に書き換える。経理への通知。経理が直して保存すると、ふたたび confirmed（Excel の同じ行を上書き）。

### 4.5 ConcernRules（気になる仕訳の条件）
| 印 | 条件 |
|---|---|
| amount | 金額 ≥ `companies.concern_amount_yen`（仮に30,000円） |
| ai_duplicate / ai_private / ai_unbalanced | AIの検算の結果 |
| corrected | 経理がAIの分類・金額を直した |
| addressee_other_company / addressee_person | 宛名が別の会社（他社の領収証かも）／個人名（私的な支出かも）。`read_receipt.v3` が会社名と比べて own・other_company・person・none で答え、`app/Support/ReadingChecks` が「(株)」「株式会社」・敬称・空白の違いと上様・空欄を確かめ直す。own・none は印なしで宛名だけ出す |
| low_confidence | 読み取りの自信が低い（合計 0.8 未満、日付・店名 0.7 未満。金額の記載がない書類の合計は除く）。直して確定・ライブ画面では欄に「確かめて」と黄色の枠（%は出さない）。境目は `ReadingChecks::THRESHOLDS` だけ |

### 4.6 WorkbookService（F14・F15、Excel の1冊）
- 1冊を `phpoffice/phpspreadsheet` で開き、`version` を確かめてから書く（同時に書いたら後から来たほうがやり直す）。
- **仕訳帳シート**（既定の列）：管理番号（仕訳の ID の**末尾**8文字。ID は UUIDv7 で頭が時刻のため、続けて送ると頭が重なる）・日付・取引先・分類・借方科目・借方金額・貸方科目・貸方金額・摘要・**状態**（確定／社長から戻し）・確定した人・確定した日時・AIのまま／直した
- 確定 → 行を足す（`workbook_row` に行番号）。戻す・再確定 → 同じ行を書き換える（管理番号で探す）。
- 書くのは確定のトランザクションのあと。書けなくても確定は取り消さず、ダウンロードの前に行のない確定の仕訳を足す（`syncMissing`）。足す前に管理番号で探すので、二重にならない。ファイルがなくなったら作り直し、確定の仕訳をすべて入れ直す（DB が正。ファイルはバックアップしなくてよい）。
- ダウンロードのファイル名は `{会社名}_帳簿.xlsx`（日本語が使えない環境では `torudake.xlsx`）。
- **社長用シート**：三期比較の表（月×3期）、今期の経費と残ったお金。社長の画面と同じ数字（§4.7）。ダウンロードのたびに作り直す。
- **AIの記録シート**：AIの実行回数・直された回数・多い直し。
- 既存の Excel の取り込み（F15）：アップロードされたファイルの1行目を列の名前として読み、上の列と対応づける画面を出す。対応づけたら `layout` に保存し、以後その形で追記。**列の形が分かるまでは既定の形で作る。**

### 4.7 OwnerDashboard（F12、社長の画面の数字）
| 区画 | 数字 | 出どころ |
|---|---|---|
| ① ひとこと | 一番大きな変化を1つ・理由の見立て・確かめるとよい点 | `owner_brief`（AI）。渡すのは②⑤の合計と、月ごとの**忙しい月の印**・**3期の平均**・**忙しい月なのに前年同月より少ない**の印だけ |
| ② 三期比較 | 月×（今期・前期・前々期）の売上 | `external_monthly_figures`（sales） |
| ② 今期の経費・残ったお金 | 今期の月ごとの確定した経費（分類ごと）、売上 − 経費 | アプリの confirmed の仕訳＋②の売上 |
| ③ 気になる仕訳 | `concern_flags` があり `owner_seen_at` が null の確定した仕訳（`app/Support/OwnerConcerns` だけで数える）。「見た」で `owner_seen_at` を入れる。数はサイドバーのバッジにも出す | アプリ |
| ④ 来月の見込み | 来月の売上と経費の中央値・8割の範囲 | `forecast`（AI）。過去の月の実績と、月ごとの忙しい月の印・3期の平均を渡す |
| ⑤ 売上の柱 | 上位3社の割合（A社・B社・C社） | `external_customer_sales` |
- 比べは**金額の差を主に、%を添える**。前月だけでなく**前年同月**と並べる。
- 前年同月の差は、その月に今期の数字があれば今期−前期、まだなければ前期−前々期（画面に「前期と前々期」と添える）。「忙しい月なのに前年より少ない」も同じ差で決める。
- 経費は、アプリを使い始めた月からしかない（過去の経費は財務王にあり、取り込まない）。経費のない月は「—」と出す。
- ①④（`OwnerInsights`）：社長の画面を開くたびに入力（②③⑤の数字＝確定した仕訳を含む）のハッシュを出し、`owner_insights.input_hash` と同じなら AI を呼ばない。違えば `GenerateOwnerInsight` をキューに入れ、できるまで前回のものに「更新中」と出して2秒ごとに読み直す。頼んでから5分たっても終わらなければ頼み直す。AI が失敗したら前回のものを残し、5分は頼み直さない。デモの前に一度開いておけば、同じ数字のうちは待たずに出る。

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
TrainingDataExporter（JSONL）、PurgeExpiredEvidence（毎日3時）、Artisan コマンド（`torudake:create-company`・`torudake:add-member`・`torudake:seed-demo`）。`torudake:seed-demo` は、**架空の**三期比較（3期分の月ごとの売上。架空の基準額×季節の係数で作り、忙しい月の初期値と同じく**秋（10〜12月）が多く、6・7月もやや多い**形にする。デモの見せ場のため、忙しい月のうち1か月は前年同月より少なくする）・顧客別（上位2社で約8割）・今期の確定した経費・「あと1件」から始まるデータを入れる。人は作らない（§9）。

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
| 社長の画面 `⚡owner` | 上から3段：①ひとこと ②数字3つ ③三期比較のグラフ。表と「くわしく見る」はたたむ（§7.3） |
| 帳票の取り込み `reports/⚡index`・`⚡show` | 種類を選んで撮る → AIが読んだ表 → 直して保存 |
| 通知 `⚡notifications` | ベルを押すと一覧 |
| ライブ画面 `⚡live` | 1920×1080、サイドバーなし（`layouts::live`）、0.5秒ごとに読み直す。左に今の1件（写真・店名→日付→合計→分類→支払い元が0.4秒ずつ出る・覚えています・理由・仕訳）、右は「あと n 件」と流れ（直近3件）だけ（AIの処理時間は左の「AIが読み取りました（○秒）」で出す）。今の1件＝最新のまとめて送った回のまだ確定していない一番古い写真、その回が済んだらまだのものの一番新しい1件。**E 直す**はライブ画面の上で分類・合計・支払い元・理由を直す（画面を移らない）。**S 保存（確定）して次へ**は AI の値のまま確定（金額がないときは押せず「E で金額を入れると保存できます」）。読み取り中は秒数、15秒で「少し時間がかかっています」、30秒で「時間がかかっています。E で手で入れられます」（表示だけ。ジョブは続ける）。0件で全面に「今月の帳簿ができました」。キー 3 Excel・4 AIの記録・5 社長の画面・Esc ホーム（直している最中の Esc はやめるだけ）。左上の「撮って送るだけ帳簿」もホームへ。下の一文は「確定は人が保存します。写真は30日で消え、AIの学習には使われません。」。名前・メールは出さない |
| ホーム `⚡home` | 「今月 あと n 件」、各画面への入口。社長には「社長の画面」を一番上に |

### 7.1 送る画面の状態（6つ＋ためる）
待ち・**ためている（n枚）**・送信中・送れました・同じ写真です・送れませんでした。

### 7.2 デザイントークンと文字（版2.0 のまま）
本文16px以上、ライブ画面32px以上、強調はオレンジ1色、コントラスト4.5以上。

### 7.3 社長の画面（1画面、上から重要な順）
```
┌───────────────────────────────────┐
│ ① 今月のひとこと（AI）                                    │
│   headline（一番大きい文字）                                │
│   busy_alert（赤で1行）／確かめるとよい点（1行）／見立てを読む▸ │
│ ② 数字3つ                                                 │
│   今期の売上と前期との差 ｜ 来月の売上の見込みと8割の幅 ｜ 気になる仕訳 n件 │
│   （気になる仕訳があれば、その下に欄：見た／経理に戻す（理由））    │
│ ③ 三期比較（財務王のグラフと同じ形）  ■今期 ■前期 ■前々期 │
│   忙しい月は薄い帯だけ ／ 表で見る▸（月・今期・前期・差、万円）   │
│ くわしく見る▸ 経費と残ったお金・分類ごとの経費・来月の経費の見込み・売上の柱 │
│ 仕訳の一覧 ／ Excel をダウンロード                          │
└───────────────────────────────────┘
```
- 見せ方だけの決まり（数字の出し方は §4.7 のまま）。
  - 上の「今期n か月の売上」の行は出さない（②と重なるため）。
  - 気になる仕訳が0件なら②は「0件 ✓」だけで、欄は出さない。
  - 表は「表で見る」でたたむ。4列（月・今期・前期・差）、万円、`tabular-nums`・`whitespace-nowrap`。今期の数字がまだない月の差には「＊」を付け、「前期と前々期」の注記は表の下に1回だけ。忙しい月の行は色を塗らず「忙」の字だけ。
  - 「くわしく見る」の開いた状態はブラウザに覚えさせる（Alpine の `$persist`）。たたんでいても数字はページにある。
  - 赤は「忙しい月なのに少ない」だけ。忙しい月はグラフの薄い帯（`brand-soft`）だけ。
- グラフは Chart.js。色はデザイントークン（今期＝brand、前期＝ink、前々期＝muted）。凡例は下、幅600px未満では文字を小さくする。
- 経費のない月は「—」。数字は架空のデモ用と、本番の実データで同じ形。

## 8. 自動テスト（版3.0）

★はデモ版で必ず作る。AIはすべて偽物。**テストの数字は架空**（実在の帳票の数字を入れない）。

| # | 確かめること | 状態 |
|---|---|---|
| T01〜T11 | 認証・他社404・権限・送る・重複・AIのジョブ・会社の分離（版2.0） | 済み（一部は版3.0 の権限で直す） |
| T12★ | 経理の保存で confirmed、ai_corrections に前後・理由・kernel | これから |
| T13★ | 2回保存しても確定は1回。AIだけでは confirmed にならない | これから |
| T14★ | 確定すると Excel の1冊に1行入る。戻すと同じ行の状態が「社長から戻し」、再確定で上書き | 済み |
| T15★ | 「あと n 件」＝new＋draft＋returned。台本のデータで 1→3→0 | これから |
| T16★ | 気になる印：金額・AIの印・直したもの。会社の金額設定が効く | これから |
| T17★ | 社長だけが戻せる（理由必須）。editor は社長の画面に入れない | 済み |
| T18★ | まとめて送る：3枚のうち1枚が重複でも、2枚は送れる。同じ batch_id | 済み |
| T19★ | 帳票の取り込み：三期比較の表が月ごとの数字に、顧客別が A社・B社 に入る。**名前は保存されない** | 三期比較は済み（`ReportImportTest`：読むだけでは入らない・保存で入る・既存と違う月・自信が低い月の「確かめて」・他社は 404・owner/admin だけ）。顧客別は次の版 |
| T20★ | 社長の画面の数字（三期比較・前年同月の差・売上の柱）が、取り込んだ数字と一致 | 済み |
| T21★ | 週1回の通知が社長にだけ届く | これから |
| T25 | 忙しい月：初期値・保存・範囲外の拒否・経理は変えられない | 済み |
| T26 | 忙しい月：グラフの色づけ、owner_brief・forecast への入力（印・3期の平均）、「忙しいはずの月なのに前年同月より少ない」がひとことに出る（偽物の AI で確かめる）。同じ入力なら AI を呼ばない | 済み |
| T22★ | 30日を過ぎた写真が消える。監査記録 | 済み（`torudake:purge-evidence`、毎日3時。消した写真は「写真は保存期間が過ぎたため削除しました」） |
| T23 | 再生モード：デモ用の会社どうしで、同じ写真（`ai_runs.photo_sha256`）の練習の記録を同じ秒数で再生し AI を呼ばない。「再生」と出す。記録のない写真・実在の会社・claude/fake では本物（テストでは偽物）を呼ぶ | 済み |
| T28 | 宛名の印（別の会社・個人は印、上様・空欄・自分の会社〔「(株)」と「株式会社」の違いも〕は印なし）と、読み取りの自信（合計0.75で「確かめて」と low_confidence、0.85で付かない）。印は確定後も残り社長の気になる仕訳に出る | 済み |
| T27 | AIの記録：「AIが作った仕訳 n件のうち、そのまま確定 m件」・一番多い直し・間違いの型（表示は日本語、kernel はそのまま）。学習データは JSONL 3つ（ai_runs・ai_corrections・journal_entries）の zip、その会社のその月の分だけ、名前・メールなし、書き出しは監査記録。経理は 403・他社は 404 | 済み |
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
| 7 社長の画面 | 10/25 8:00–9:30 | 帳票の取り込み（三期比較・顧客別）、OwnerDashboard、owner_brief・forecast（忙しい月の印・3期の平均）、グラフ（忙しい月の色づけ）、戻す、通知 | T17・T19〜T21・T26 |
| 8 仕上げ | 9:30–10:30 | 写真の30日削除、AIの記録・JSONL、再生モード、`torudake:seed-demo`（秋が多い季節の形） | T22・T23 |
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
