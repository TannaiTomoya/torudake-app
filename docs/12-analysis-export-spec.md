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
| `manifest.json` | 版・期間・件数・ハッシュ・関連の説明 | — |

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

## 5. manifest.json
- `schema_version`（今は 2）、`metric_version`（項目の意味を変えたら上げる）、`sensitivity`、`company_key`、`month`
- `period`：数え方（`basis`）、`timezone`、`from_local`・`to_local`、`from_utc`・`to_utc`
- `time_format`：時刻（`*_at`）はすべて UTC。`entry_date` は日付だけ
- `files`：ファイルごとの `rows`（行数）と `sha256`
- `links`：ファイルをまたぐ結び方（`ai_corrections.ai_run_id → ai_runs.id` など）
- `links_outside_period`：相手が別の月にあり、この zip の中では結べない件数

## 6. 版1（〜2026-11-08）からの変更
版1は、AI実行の `input`/`output`/`error`、店名・摘要・科目名・口座名、修正前後の文字、kernel の文を、そのまま出していた。版2で、すべて上の項目に置き換えた。ID は仮名のキーになり、版1の ID とは結べない。
