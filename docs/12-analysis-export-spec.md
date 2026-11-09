# 分析用の書き出し 仕様書（#31）

「AIの記録」画面の「学習データを書き出す」で出る zip の中身の決まり。Python の集計・L3（検算）の評価に使う。
**許可した項目だけを、型を確かめて出す。** 自由文はどの階層からも写さない（`app/Services/AnalysisExportSanitizer.php`）。

## 1. 扱い
- **仮名化した機密データ。匿名化ではない。** 金額・日付・取引の並びから、人が会社や取引先を推測できる場合がある。
- 公開リポジトリ・Issue・PR・Actions のログや成果物に上げない（`.gitignore` で `*.jsonl`・分析用の zip を除外済み）。実データを使う分析は、手元の非公開の場所だけで行う。
- テスト・fixture・Issue は架空データのみ。
- 権限は変えない：社長・管理者だけ（`exportTrainingData` ＝ `viewLive`）。他社は 404、そのほかのロールは 403、未認証はログインへ。応答は `no-store`。書き出しは監査記録（`training.export`、月・版・件数）に残す。

## 2. zip の中身
| ファイル | 1行の単位 | 期間の決め方 |
|---|---|---|
| `ai_runs.jsonl` | AI の実行1回 | `created_at` が期間内 |
| `ai_corrections.jsonl` | 経理が AI の下書きを直した1か所 | `created_at` が期間内 |
| `journal_entries.jsonl` | 確定した（または戻された）仕訳 | `confirmed_at` が期間内 |
| `first_confirmations.jsonl` | 仕訳の初回確定の記録（1仕訳に1行） | `first_confirmed_at`（初回に確定した時刻）が期間内 |
| `manifest.json` | 版・期間・件数・ハッシュ・関連・指標 | — |

期間は「その月の1日 0時以上、翌月1日 0時未満」（会社の時刻 `Asia/Tokyo`）。`manifest.json` に UTC でも書く。

## 3. 仮名のキー
`{種類}_{16桁の16進}`。会社ごとに値が違い、**別の会社どうしでは結べない**。同じ会社の中では、月をまたいでも同じ値になる。会社名を変えても変わらない。

| 頭文字 | 対象 | 元の値 |
|---|---|---|
| `r` | AI実行 | ai_runs.id |
| `x` | 直した記録 | ai_corrections.id |
| `s` | 原資料 | source_records.id |
| `e` | 仕訳 | journal_entries.id |
| `v` | 店 | 店名を表記ゆれ（全角半角・株式会社・空白）でそろえたもの |
| `c` | 分類 | categories.id |
| `b` | 支払い元 | bank_accounts.id |
| `u` | 人 | users.id |
| `k` | 会社 | companies.id |

アプリの鍵（`APP_KEY`）で HMAC をとる。鍵を替えるとキーが変わるので、`schema_version` を上げて知らせる。

## 4. 項目
値は、数・真偽・日付（`YYYY-MM-DD`）・UTC時刻（`YYYY-MM-DDTHH:MM:SSZ`）・列挙・仮名のキー・記号だけの短い語（モデル名・版）に限る。型が合わない値は `null`。

### ai_runs.jsonl
- 共通：`id`、`task`、`subject_kind`、`subject_key`（`SourceRecord` は `s`、`JournalEntry` は `e`）、`model`、`prompt_version`、`driver`、`valid`、`has_error`（例外メッセージは出さない）、`input_tokens`、`output_tokens`、`latency_ms`、`created_at`、`input_features`、`output_features`
- `read_receipt`：
  - input：`has_note`
  - output：`date`、`vendor_key`、`total_yen`、`amount_missing`、`addressee_kind`、`payment_method`、`tax_breakdown`（`rate`・`amount_yen`）、`has_registration_number`、`full_text_length`（全文は出さない）、`confidence`（`date`・`vendor`・`total`）
- `journalize`：
  - input：`corrections_in_prompt`（件数）
  - output：`category_key`、`payment_key`、`vendor_key`、`confidence`、`remembered`
- `check_entry`（L3 の評価用）：
  - input：`entry_date`、`lines`（`side`・`amount_yen`。科目名は出さない）、`recent_entries_count`
  - output：`debit_total`、`credit_total`、`balanced`、`duplicate_suspected`、`private_suspected`、`concerns_count`（文は出さない）
- 上の3つ以外（月次集計・社長へのひとこと・見込み・帳票の読み取り など）：`input_features`・`output_features` は `null`（自由文が中心のため中身は出さない）

### ai_corrections.jsonl
`id`、`ai_run_id`、`journal_entry_id`、`field`（`date`・`vendor`・`category`・`credit_account`・`amount`・`other`）、`before`、`after`、`reason`、`kernel_kind`、`vendor_key`、`corrected_by`、`created_at`
- `before`/`after` は欄ごとの型：日付、金額（整数）、店・分類・支払い元は仮名のキー。分類・支払い元の名前が後から変わって引けないときは `null`。
- `kernel_kind`：`vendor_to_category`・`vendor_to_payment`・`amount_change`・`date_change`・`vendor_change`・`other`。kernel の文（店名を含む）は出さない。

### journal_entries.jsonl
`id`、`entry_date`、`status`、`vendor_key`、`category_key`、`payment_key`、`amount_yen`、`lines`（`side`・`account_code`・`amount_yen`）、`ai_run_id`、`origin`（`manual`・`ai_unchanged`・`corrected`）、`concern_flags`、`confirmed_by`、`confirmed_at`
- 店名・摘要・科目名・口座名は出さない。`account_code` は英数字とハイフンの短い値だけ。
- `origin` の `corrected`：その仕訳に AI を直した記録が（期間に関係なく）1件でもある。

### first_confirmations.jsonl（#32）
`id`、`journal_entry_id`、`ai_run_id`、`kind`（`ai`・`manual`）、`evaluation_class`（`real`・`demo`）、`driver`、`prompt_version`、`required_complete`、`unchanged`、`changed_fields`、`filled_fields`、`description_changed`、`draft_origin`（`date`・`vendor`・`amount`＝`read`・`defaulted`・`missing`・`unknown`、`category`＝`ai`・`none`、`payment`＝`history`・`invoice_rule`・`ai`・`default`・`unknown`・`none`）、`reading_methods`（欄ごとに `confirmed`・`changed`・`entered`・`saved_as_is`）、`duplicate`（`shown`・`concerning`・`decided` の件数）、`recommendation`（履歴からの候補の扱い。`status`＝`confident`・`hold`・`none`、`reasons`（理由の型）、`usable_count`、`candidate_count`、`applied`、`expired`、`outcome`＝`adopted`・`rejected`・`held`・`no_candidate`。分類・口座・店名は出さない）、`reason`、`first_confirmed_by`、`first_confirmed_at`、`post_events`（`kind`＝`returned`・`reconfirmed`、`changed_fields`、`occurred_at`）
- 欄の名前は `date`・`vendor`・`category`・`credit_account`・`amount`（`post_events` の変更欄だけ `description` も）。摘要・理由の文は出さない。
- 戻し・再確定で行は増えない。後の出来事は `post_events` に載る（書き出した時点まで）。

## 5. manifest.json
- `schema_version`（今は 4）、`metric_version`（項目の意味を変えたら上げる）、`sensitivity`、`company_key`、`month`
- `period`：数え方（`basis`）、`timezone`、`from_local`・`to_local`、`from_utc`・`to_utc`
- `time_format`：時刻（`*_at`）はすべて UTC。`entry_date` は日付だけ
- `files`：ファイルごとの `rows`（行数）と `sha256`
- `links`：ファイルをまたぐ結び方（`ai_corrections.ai_run_id → ai_runs.id` など）
- `links_outside_period`：相手が別の月にあり、この zip の中では結べない件数
- `metrics`：画面（AIの記録）と同じ定義・同じ母数と分子（§7）

## 6. 版の履歴
- 版3（2026-11-09、#32）：`first_confirmations.jsonl` と manifest の `metrics` を追加。`metric_version` は `analysis-metrics.v2`。

### 版1（〜2026-11-08）からの版2の変更
版1は、AI実行の `input`/`output`/`error`、店名・摘要・科目名・口座名、修正前後の文字、kernel の文を、そのまま出していた。版2で、すべて上の項目に置き換えた。ID は仮名のキーになり、版1の ID とは結べない。

## 7. 「手を入れなかった率」などの定義（#32）
正本は `App\Support\FirstConfirmationMetrics`（版 `first-confirmation.v1`）。画面・manifest の `metrics`・`first_confirmations.jsonl` から数え直した値は、母数も分子も一致する。
- **母数**：その月（会社の時刻）に**初回確定**した、AI が下書きを作った仕訳（`kind=ai`）で、`evaluation_class` が同じもの。再確定・戻しでは増えず、月をまたいで二重に数えない。
- **手を入れず確定（`unchanged`）**：必須項目（日付が読めた・金額の記載がある）が揃い、日付・店名・分類・支払い元・金額のどれも AI の値から変えず（`changed_fields` が空）、記載のなかった欄も人が埋めていない（`filled_fields` が空）。**摘要は含めず**、別に `description_changed` として数える。
- **別に出す数**：金額の記載がなく人が入れた件数、摘要を直した件数、支払い元に履歴を使った件数と履歴なしの率、AI の下書きがなく人が最初から入力した件数（母数は AI 由来＋手入力）、確定のあとで戻した・再確定した件数、AI（読み取り・仕訳）の実行の失敗数。
- **実際のAIとデモを混ぜない**：`real`＝実際の AI（claude）が作り、デモ会社でないもの。fake・replay・デモ会社は `demo`。実際の会社は `real` だけ、デモ会社は `demo` だけを出し、もう一方は `other_class` に件数だけ出す。
- **記録のない過去の確定は評価不能**：この機能より前に確定した仕訳は初回確定の記録がない。`legacy` に件数だけ出し、率には入れない。後から正解のラベルを作らない。母数が0の率は `null`（0% にも 100% にもしない）。
- **正解率ではない**：測るのは、下書きに手を入れたかという行動。AI の答えが会計として正しかった割合ではない。会計上の正解率は、人が検証したラベルのある評価集合でだけ測る。
- 従来の指標（確定した月・直した記録の有無で数える `ai_corrections` ベース）は、画面に「従来の数え方（参考）」として残す。再確定や過去の記録を含むため、上の数字とは一致しないことがある。
