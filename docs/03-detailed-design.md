# 03｜詳細設計書 — 撮って送るだけ帳簿（torudake）

版1.1・2026-10-23（**画面を Livewire に変更**し、1.5日で本番に出す範囲＝MVPを決めた）。機能番号（F・A）は [01 要件定義書](01-requirements.md)、技術の決まりは [02 技術仕様書](02-technical-spec.md)。

> **10/24 追記**：今回作るのは [04 デモ版（LLM編）](04-demo-llm-edition.md)。計算・判断は LLM、時間割は 04 §8 を使う。この文書のテーブル・権限・ルートは土台としてそのまま使う。

**MVP（10/24〜10/25午前に本番まで出す）**：ログイン（2段階認証）・会社と権限 → 撮って送る → 下書き → 承認・差し戻し・対象外 → 締めナビ・現金の残高・締める → 写真の30日削除 → 本番デプロイ。
**次の週**：明細CSVの取り込みと突き合わせ（F06）、取消（F13）、招待メール（F02）、サポート許可（F20）、S3互換ストレージ、テスト環境、AI（A01〜）。下の表と処理で「次の週」と書いたものは MVP では作らない。

---

## 1. 言葉の使い分け

| 言葉 | テーブル | 意味 |
|---|---|---|
| 会社 | `companies` | データを分ける単位。すべての業務データは会社に属する |
| メンバー | `memberships` | 人と会社のつながり。ロールと「送るだけ」の印を持つ |
| 証憑（写真） | `evidences` | 送られた写真・PDF 1枚。30日でファイルは消え、行（ハッシュ・テキスト）は残る |
| 原資料 | `source_records` | 仕訳のもとになる1件。写真1枚、または明細の1行。**未処理の数はここで数える** |
| 仕訳 | `journal_entries` ＋ `journal_lines` | 帳簿の1件と、その借方・貸方の行。下書き → 承認済み |
| 勘定科目 | `accounts` | 会計上の科目（現金・普通預金・車両費…）。会社ごとに持つ |
| 分類 | `categories` | 現場の言葉（燃料・部品・修理…）。勘定科目にIDでつながる。名前を変えても過去の仕訳は動かない |
| 口座 | `bank_accounts` | 現金・銀行・カード・立替。1つの勘定科目につながる |
| 残高の確認 | `balance_checks` | 明細の残高、または数えた現金の残高。帳簿の残高と比べる |
| 締め | `period_closings` ＋ `companies.closed_through` | 月を締めた記録と、締めた最後の日 |

## 2. データベース

**MVP で作るテーブル**：users（スターターキット）、companies、memberships、accounts、categories、bank_accounts、evidences、source_records、journal_entries、journal_lines、balance_checks、period_closings、audit_logs。**次の週**：invitations、support_access_grants、statement_imports、idempotency_keys（Livewire では不要。API を足すときに作る）、orphan_files、ai_suggestions、ai_corrections。

ID は UUIDv7（`CHAR(36)`）。金額は `BIGINT UNSIGNED`（円）。すべての業務テーブルに `company_id`（FK restrict）と `created_at` / `updated_at` を持つ。消すときの決まりは §2.4。

### 2.1 つながり

```
users 1─* memberships *─1 companies
companies 1─* accounts 1─* categories
          1─* bank_accounts *─1 accounts
          1─* evidences 1─1 source_records *─0..1 journal_entries 1─* journal_lines *─1 accounts
          1─* statement_imports 1─* source_records（明細の行）
          1─* balance_checks *─1 bank_accounts
          1─* period_closings
          1─* audit_logs, idempotency_keys, support_access_grants, invitations
          1─* ai_suggestions 1─* ai_corrections
```

### 2.2 テーブル

#### users
| 列 | 型 | 内容 |
|---|---|---|
| id | uuid7 | |
| name | string(50) | |
| email | string(255) unique | |
| password | string | |
| email_verified_at | datetime null | 招待を受けた時点で入れる |
| two_factor_secret / two_factor_recovery_codes | text null | Fortify（暗号化） |
| two_factor_confirmed_at | datetime null | **null の人は会社データのAPIを使えない** |
| is_operator | bool default false | 作者（運営者）。会社には所属しない。一括代入不可 |

#### companies
| 列 | 型 | 内容 |
|---|---|---|
| id | uuid7 | |
| name | string(100) | 例：丹内建機サービス |
| fiscal_year_end_month | tinyint 1〜12 | 決算月。丹内建機は 7 |
| bookkeeping_start_date | date null | 記帳開始日（Q1。未定なら null で、仕訳は作れるが締めはできない） |
| closed_through | date null | 締めた最後の日。これ以前の日付の仕訳は作れない・変えられない |
| allow_self_approval | bool default false | 自己承認 |
| match_window_days | tinyint default 3 | 写真と明細を突き合わせる日付の幅（±日） |
| tax_codes | json | 税区分の選択肢（Q4。最初は空 ＝「未設定」だけ） |
| ai_enabled_fields | json default `[]` | AIの候補を使う項目（`date` / `amount` / `vendor` / `category`）。最初は空 |

#### memberships
| 列 | 型 | 内容 |
|---|---|---|
| id | uuid7 | |
| company_id / user_id | FK | UNIQUE(company_id, user_id) |
| role | enum `Role` | owner / admin / approver / editor / viewer。一括代入不可 |
| submit_only | bool default false | 「送るだけ」。true なら送る画面だけを返す。**ロールは増やさない** |
| disabled_at | datetime null | 辞めた人。消さずに止める |

#### invitations
| 列 | 型 | 内容 |
|---|---|---|
| id | uuid7 | |
| company_id | FK | |
| email | string | |
| role / submit_only | | 参加したときのロール |
| token_hash | char(64) unique | トークンそのものは保存しない |
| invited_by | FK users | |
| expires_at | datetime | 72時間 |
| accepted_at | datetime null | |

#### support_access_grants
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| operator_user_id | FK users | 作者 |
| granted_by | FK users | owner |
| reason | string(200) | |
| expires_at | datetime | 最長7日 |
| revoked_at | datetime null | |

#### accounts（勘定科目）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| code | string(10) | 会社の中で一意 |
| name | string(50) | |
| type | enum `AccountType` | asset / liability / equity / revenue / expense |
| archived_at | datetime null | 使わなくなった科目。仕訳がある科目は消さない |

#### categories（分類）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| name | string(30) | 現場の言葉。自由に変えられる |
| direction | enum | expense（支出）/ income（収入） |
| account_id | FK accounts | つながる勘定科目 |
| default_tax_code | string null | |
| sort_order | smallint | |
| archived_at | datetime null | |

#### bank_accounts（口座）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| name | string(50) | 例：現金、〇〇銀行 本店 普通、事業用カード、社長の立替 |
| kind | enum `BankAccountKind` | cash / bank / card / reimbursement |
| account_id | FK accounts | 現金→現金、銀行→普通預金、カード→未払金、立替→役員借入金 など |
| csv_profile | json null | 明細CSVの読み方（文字コード・列の位置・日付の形）。§4.4 |
| is_default_for_receipts | bool | 写真の仕訳で最初に選ばれる支払い元（ふつうは現金） |
| archived_at | datetime null | |

#### evidences（証憑）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| storage_key | string null | `evidence` ディスク上のパス（`companies/{company_id}/{uuid7}.{ext}`）。消したら null |
| sha256 | char(64) | UNIQUE(company_id, sha256)。消したあとも残すので、同じ写真は二度と受け付けない |
| mime / size_bytes | | |
| uploaded_by | FK users | |
| extracted_text | text null | 読み取った全文のテキスト（AIまたは人が写す） |
| purge_after | datetime | 送った日時＋30日 |
| purged_at | datetime null | |

#### source_records（原資料）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| kind | enum `SourceKind` | receipt（写真）/ statement_line（明細の行） |
| status | enum `SourceStatus` | new（未処理）/ drafted（下書きあり）/ journaled（仕訳済み）/ ignored（対象外）。一括代入不可 |
| evidence_id | FK null | receipt のとき |
| statement_import_id / bank_account_id | FK null | statement_line のとき |
| line_hash | char(64) null | 明細の重複判定。UNIQUE(bank_account_id, line_hash) |
| occurred_on | date null | 写真は分かった時点で入れる。明細は取引日 |
| amount_yen | bigint null | |
| direction | enum null | out（出金）/ in（入金） |
| description | string(200) null | 明細の摘要、または送った人の一言メモ |
| balance_after_yen | bigint null | 明細のその行のあとの残高 |
| submitted_by | FK users null | |
| journal_entry_id | FK null | つながった仕訳（下書き含む）。1つの仕訳に写真と明細の2件がつながることがある |
| late | bool default false | 締めたあとに届いた |
| ignored_reason / ignored_by | null | 対象外の理由 |

索引：(company_id, status, created_at)、(company_id, kind, occurred_on, amount_yen)（突き合わせ）

#### statement_imports（明細の取り込み）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id / bank_account_id | | |
| file_sha256 | char(64) | 同じファイルの取り込みは 409 |
| rows_total / rows_imported / rows_skipped | int | 結果の件数を画面に出す |
| imported_by | FK users | |

CSVファイルそのものは保存しない（行を原資料にしたら捨てる）。

#### journal_entries（仕訳）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| entry_date | date | 締めた期間の日付は不可 |
| kind | enum | normal / opening（期首残高）/ reversal（取消） |
| status | enum `EntryStatus` | draft / approved。一括代入不可 |
| description | string(200) | 摘要（取引先など） |
| vendor | string(100) null | 取引先 |
| created_by | FK users | 自己承認の判定に使う |
| approved_by / approved_at | null | |
| returned_reason | string(200) null | 差し戻しの理由（直して保存したら消す） |
| reverses_entry_id | FK null | 取消の仕訳が、どの仕訳を取り消したか。UNIQUE（1つの仕訳は1回だけ取り消せる） |
| ai_suggestion_id | FK null | もとにしたAIの候補 |

**approved の行は変更・削除しない**（Model の `updating` / `deleting` で例外。§2.4）。

#### journal_lines（仕訳の行）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id / journal_entry_id | | 親が draft のときだけ入れ替えできる |
| line_no | tinyint | |
| side | enum `Side` | debit / credit |
| account_id | FK accounts | |
| amount_yen | bigint unsigned | 1円以上 |
| tax_code | string null | |
| memo | string(100) null | |

承認の条件：行が2行以上、借方合計＝貸方合計。

#### balance_checks（残高の確認）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id / bank_account_id | | |
| as_of | date | UNIQUE(bank_account_id, as_of) |
| balance_yen | bigint（符号あり） | |
| source | enum | statement（明細の最後の行から自動）/ count（現金を数えた） |
| recorded_by | FK users | |

#### period_closings（締め）
| 列 | 型 | 内容 |
|---|---|---|
| id / company_id | | |
| month | date（月初） | UNIQUE(company_id, month) |
| closed_by / closed_at | | |
| warnings_snapshot | json | 締めた時点の警告と、owner が書いた理由 |

締めたら `companies.closed_through` をその月末にする。締めは月の順番どおり（前の月が締まっていないと締められない）。**締めの取り消しは MVP では作らない**（必要なら作者が runbook の手順で行う）。

#### idempotency_keys
company_id, user_id, key, request_hash, response_status, response_body(json), expires_at（24時間）。UNIQUE(company_id, key)。

#### audit_logs
company_id null, user_id null, action（例 `entry.approve`）, subject_type, subject_id, before(json), after(json), ip, user_agent, created_at。更新・削除しない。

#### orphan_files
storage_key, reason, created_at。ストレージに置いたがDBの保存に失敗したファイル。ジョブが消す。

#### ai_suggestions / ai_corrections（段階9）
| テーブル | 主な列 |
|---|---|
| ai_suggestions | company_id, source_record_id, model, prompt_version, examples_count, result(json：日付・金額・店名・分類・各欄の自信度), extracted_text, latency_ms, status（ok / failed） |
| ai_corrections | company_id, ai_suggestion_id, journal_entry_id, field, before, after, reason（enum：misread / wrong_category / private / tax_rate / other）, corrected_by |

### 2.3 Enum
| Enum | 値 → label() |
|---|---|
| Role | owner 社長・責任者 / admin 管理者 / approver 承認者 / editor 経理担当 / viewer 閲覧のみ |
| SourceKind | receipt 写真 / statement_line 明細 |
| SourceStatus | new 未処理 / drafted 下書きあり / journaled 仕訳済み / ignored 対象外 |
| EntryStatus | draft 下書き / approved 承認済み |
| EntryKind | normal 通常 / opening 期首残高 / reversal 取消 |
| Side | debit 借方 / credit 貸方 |
| AccountType | asset 資産 / liability 負債 / equity 純資産 / revenue 収益 / expense 費用 |
| BankAccountKind | cash 現金 / bank 銀行 / card カード / reimbursement 立替 |
| CorrectionReason | misread 読み間違い / wrong_category 分類違い / private 私的支出 / tax_rate 税率違い / other その他 |

### 2.4 消す・変えるときの決まり
- 会社・人・勘定科目・口座・分類は消さない（restrict）。使わなくなったら `archived_at` / `disabled_at`。
- 原資料は消さない。要らないものは「対象外」にする。
- 下書きの仕訳は消せる（つながった原資料は new に戻る）。承認済みは消せない。
- 写真のファイルだけが30日で消える（行は残る）。

## 3. 状態の移り変わり

### 3.1 原資料
```
new ──下書きを作る──→ drafted ──承認──→ journaled
 │                      │  └─下書きを消す→ new
 └──対象外（理由）──→ ignored
```
- journaled のあと、つながった仕訳が取り消されたら、原資料は new に戻す（直した仕訳を作り直すため）。

### 3.2 仕訳
```
draft ──承認──→ approved ──取消──→（approved のまま。反対の仕訳 reversal が approved で増える）
  ↑ 差し戻し（理由）
```

## 4. 処理（Services）

どれもトランザクションの中で行う。状態を確かめる前に対象の行を `lockForUpdate()`。監査記録はトランザクションの中で書く。

### 4.1 SubmitEvidence（F04・F05）
入口：`⚡submit` の `send()`（`WithFileUploads` の一時ファイルを受け取る）。
1. 一時ファイルの SHA-256 を計算。同じ会社に同じハッシュがあれば「この写真はもう届いています」と出して終わる（保存しない）。
2. `evidence` ディスクへ保存（DBの外）。HEIC はそのまま保存し、表示はブラウザ任せ（JPEG への変換は次の週）。
3. DB：`evidences` と `source_records(kind=receipt, status=new)` を作り、監査 `evidence.submit`。`UNIQUE(company_id, sha256)` に当たったら（同時に2回押した）重複として扱う。
4. DB が失敗したら、保存したファイルをその場で消す。
5. 「送れました。今月 n 枚目」を出し、続けて撮れる状態に戻す。

### 4.2 ImportBankStatement（F06・次の週）
1. ファイルの SHA-256 が取り込み済みなら 409。
2. 口座の `csv_profile` で読む（文字コードは Shift_JIS か UTF-8、ヘッダの行数、列：日付・摘要・出金・入金・残高）。
3. 1行ごとに `line_hash = sha256(bank_account_id | 日付 | 出金 | 入金 | 摘要 | 残高)`。既にあればスキップして数える。
4. 原資料（kind=statement_line）を作る。締めた期間の行は `late=true`。
5. 最後の行の残高を `balance_checks(source=statement)` に入れる。
6. 応答：取り込んだ件数・スキップした件数・遅れて届いた件数。

`csv_profile` の例：
```json
{"encoding":"SJIS-win","header_rows":1,"columns":{"date":0,"description":2,"withdrawal":3,"deposit":4,"balance":5},"date_format":"Y/m/d"}
```
銀行ごとの形は Q3 で実物を見てから決める。テストでは `tests/Fixtures/statements/sample_bank.csv` を使う。

### 4.3 突き合わせの候補（F09・次の週）
写真の原資料に日付と金額が入ったら、同じ会社の明細の原資料のうち、
- `status = new`、`direction = out`
- 金額が同じ
- 日付が ±`match_window_days` 日以内

を、日付の近い順に最大5件返す。選ぶと、その明細も同じ仕訳につながり、支払い元はその明細の口座になる。逆向き（明細から写真を探す）も同じ条件。

### 4.4 CreateDraftEntry / UpdateDraftEntry（F09・F10）
入力：原資料（1件以上）、日付、取引先、分類、金額、支払い元の口座、税区分、メモ。または詳細入力（借方・貸方の行を直接）。

- 簡易入力から行を作る：
  - 支出：借方＝分類の勘定科目、貸方＝支払い元の口座の勘定科目
  - 収入：借方＝入金した口座の勘定科目、貸方＝分類の勘定科目
- `CloseGuard::assertOpen($company, $date)`：締めた期間なら 422 `period_closed`。
- 原資料が他の仕訳につながっていれば 409 `source_already_linked`。
- 原資料を drafted にし、`journal_entry_id` を入れる。
- 同じ日付・金額・取引先の承認済み仕訳があれば、応答に `warnings: ["possible_duplicate"]`（止めない）。
- AIの候補から直した欄があれば `ai_corrections` に前後と理由を入れる（理由は画面で選ぶ。未選択なら other）。

### 4.5 ApproveEntry（F11）
```php
DB::transaction(function () use ($company, $user, $entryId) {
    $entry = $company->journalEntries()->lockForUpdate()->findOrFail($entryId);
    if ($entry->status === EntryStatus::Approved) throw new AlreadyApproved;       // 409
    $this->closeGuard->assertOpen($company, $entry->entry_date);                    // 422
    if (! $company->allow_self_approval && $entry->created_by === $user->id)
        throw new SelfApprovalForbidden;                                            // 422
    $entry->assertBalanced();                                                       // 422 unbalanced_entry
    $entry->forceFill(['status' => EntryStatus::Approved,
        'approved_by' => $user->id, 'approved_at' => now()])->save();
    $entry->sources()->lockForUpdate()->get()
        ->each(fn ($s) => $s->forceFill(['status' => SourceStatus::Journaled])->save());  // 一括 update() は使わない（02 §4.2）
    AuditLog::record($user, 'entry.approve', $entry);
});
```
権限は Policy（owner・admin・approver）。同じキーの再送は前回の応答。

### 4.6 ReturnEntry（F12）
draft のまま `returned_reason` を入れる。作った人の一覧に「戻された」として出る。

### 4.7 ReverseEntry（F13・次の週）
- 対象が approved で、まだ取り消されていないこと（UNIQUE で守る）。
- 反対の行（借方と貸方を入れ替え）の仕訳を `kind=reversal`、`reverses_entry_id=元`、日付＝今日（締めた期間なら翌月の初日以降の開いている日）で作り、そのまま approved にする（承認できる人の操作なので）。
- 元の仕訳につながっていた原資料は new に戻し、`journal_entry_id` を外す。正しい仕訳は、ふつうの手順（下書き → 承認）で作り直す。

### 4.8 IgnoreSource（F14）
new か drafted の原資料を ignored にし、理由を残す。下書きがつながっていれば消す。

### 4.9 RecordCashCount（F07）
口座（kind=cash）と日付と残高を `balance_checks(source=count)` に入れる。同じ日なら上書き（監査に前後）。

### 4.10 CloseCheck（F15・読み取り専用）と ClosePeriod（F16）
**判定は1か所（`CloseCheck`）に書き、締めナビの表示と締めの両方が使う。**

| 区分 | 項目 | 数え方 |
|---|---|---|
| blocking（0でないと締められない） | draft_entries | その月の日付の下書き |
| | unbalanced_entries | その月の承認済みで貸借が合わないもの（本来0。壊れていないかの確認） |
| | previous_month_open | 前の月が締まっていない |
| warnings（理由を書けば締められる） | new_sources | 未処理の原資料（明細はその月の日付、写真は日付未入力も含む） |
| | statement_lines_without_receipt | その月の出金の明細で、写真がつながっていないもの |
| | bank_differences | 口座ごとに、月末の帳簿残高と、月末以前で最新の `balance_checks` の差 |
| | cash_not_counted | 現金の口座で、その月の数えた残高がない |
| | self_approval_required | 承認できる人が1人だけで、`allow_self_approval=false` |

帳簿残高＝その勘定科目の、月末までの承認済み仕訳の借方合計−貸方合計（資産の場合）。期首残高は `kind=opening` の仕訳で入れる。

ClosePeriod：`CloseCheck` の blocking がすべて0、warnings があれば理由が必須。`companies` の行を lock し、`closed_through` を月末に、`period_closings` を作り、監査を残す。owner だけ。

### 4.11 PurgeExpiredEvidence（F17）
毎日3時。`purge_after < now()` かつ `purged_at is null` を100件ずつ。ストレージから消す → `storage_key=null`、`purged_at=now()` → 監査 `evidence.purge`（件数）。ストレージで失敗したら次の日にやり直す。

### 4.12 ReadReceiptWithAi（A01・次の週以降）
`ReceiptReader::read(Evidence $e, Company $c): ReceiptReading`。会社の直した例（最近の20件）を渡し、結果を `ai_suggestions` に保存、`evidences.extracted_text` に全文を入れる。`ai_enabled_fields` に入っている欄だけ、下書きの画面の初期値にする。

### 4.13 メンバーの作成（F02）
**MVP**：`sail artisan torudake:add-member {company} {email} {role} [--submit-only]` で作り、パスワード再設定のメールを送る。初めてログインしたら2段階認証の設定画面へ回す。

**次の週（招待）**：owner がメール・ロール・送るだけを入れる → `invitations` を作り、メール（リンクに平文のトークン）。相手がリンクを開き、名前・パスワードを決める → `users` と `memberships` を作る → 2段階認証の設定画面へ。すでにアカウントがある人は、ログインして参加ボタンを押す。

## 5. 権限

### 5.1 表
| 操作 | owner | admin | approver | editor | viewer | 送るだけ |
|---|---|---|---|---|---|---|
| 送る（F04） | ○ | ○ | ○ | ○ | × | ○ |
| 原資料・写真を見る（F08） | ○ | ○ | ○ | ○ | ○ | 自分が送ったものだけ |
| 明細の取り込み・現金の残高（F06・F07） | ○ | ○ | ○ | ○ | × | × |
| 下書きを作る・直す・消す（F09） | ○ | ○ | ○ | ○ | × | × |
| 承認・差し戻し（F11・F12） | ○ | ○ | ○ | × | × | × |
| 取消（F13） | ○ | ○ | ○ | × | × | × |
| 対象外（F14） | ○ | ○ | ○ | × | × | × |
| 締めナビを見る（F15） | ○ | ○ | ○ | ○ | ○ | × |
| 締める（F16） | ○ | × | × | × | × | × |
| 会社設定・分類・口座（F19） | ○ | ○ | × | × | × | × |
| 招待・ロール変更（F02） | ○ | × | × | × | × | × |
| サポート許可（F20） | ○ | × | × | × | × | × |

- 「送るだけ」は `memberships.submit_only = true` のメンバー（ロールは editor）。
- 承認・取消は、`allow_self_approval=false` のとき自分が作った仕訳には使えない。
- 作者（`is_operator`）は、有効なサポート許可がある会社だけ「見る」ができる（書き込みは不可）。

### 5.2 実装
- `CompanyPolicy` の `member(User, Company)`：所属していて `disabled_at` が null か。すべての Policy の最初に呼ぶ。
- `SourceRecordPolicy`・`EvidencePolicy`・`JournalEntryPolicy`・`StatementImportPolicy`・`PeriodClosingPolicy`・`CompanySettingsPolicy`。
- 他社のものはルートの段階で `$company->...->findOrFail()` により404。ロールが足りないときは403。

## 6. ルーティング

### 6.1 考え方
- 会社の画面はすべて `/c/{company}/...` の下。`auth`・`verified`・`two-factor`（2段階認証が有効）・`company`（所属の確認）のミドルウェアをかける。他社の `{company}` は404。
- 子（原資料・仕訳など）は会社から引く：`$company->sourceRecords()->findOrFail($id)`。暗黙の結合は `scopeBindings()` で会社に限る。
- 書き込みはすべて Livewire のアクション。別のルートを持たない。アクションのたびに `$this->authorize()`。
- `/` と `/dashboard` は振り分けだけ：ゲスト→ログイン、所属が1社→その会社（送るだけ→送る、ほか→ホーム）。
- 月は `YYYY-MM`：`where('month', '[0-9]{4}-(0[1-9]|1[0-2])')`。

### 6.2 ルートの一覧
| メソッド | URL | ルート名 | 中身 | 権限 |
|---|---|---|---|---|
| GET | `/` | home | 振り分け | — |
| GET | `/dashboard` | dashboard | 振り分け | auth |
| GET | `/c/{company}` | company.home | `pages::company.home` | 送るだけ以外 |
| GET | `/c/{company}/submit` | company.submit | `pages::company.submit` | 送れる人 |
| GET | `/c/{company}/sources` | company.sources.index | `pages::company.sources.index`（`?status=&month=`） | 見られる人 |
| GET | `/c/{company}/sources/{source}` | company.sources.show | `pages::company.sources.show`（下書きを作る） | editor+ |
| GET | `/c/{company}/entries` | company.entries.index | `pages::company.entries.index`（`?status=&month=`） | member（送るだけ以外） |
| GET | `/c/{company}/entries/{entry}` | company.entries.show | `pages::company.entries.show`（承認・差し戻し） | member（送るだけ以外） |
| GET | `/c/{company}/close/{month}` | company.close | `pages::company.close` | member（送るだけ以外） |
| GET | `/c/{company}/settings` | company.settings | `pages::company.settings`（分類・口座・メンバーの一覧と自己承認） | admin+ |
| GET | `/c/{company}/evidences/{evidence}/image` | company.evidences.image | `EvidenceImageController`（`__invoke`） | 見られる人。消えていれば410 |
| — | `/settings/*`、`/login` ほか | スターターキット | プロフィール・パスワード・2段階認証 | — |

`routes/web.php` の形：
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
        Route::livewire('settings', 'pages::company.settings')->name('settings');
        Route::get('evidences/{evidence}/image', EvidenceImageController::class)->name('evidences.image');
    });
```

### 6.3 入力の検証（主なもの）
| 項目 | ルール |
|---|---|
| 写真 | `required|file|max:10240|mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif,application/pdf` |
| 一言メモ | `nullable|string|max:200` |
| 日付 | `required|date|before_or_equal:today`（締めた期間は業務ルールで止める） |
| 取引先 | `nullable|string|max:100` |
| 分類 | `required` かつ同じ会社の、使用中の分類 |
| 支払い元 | `required` かつ同じ会社の、使用中の口座 |
| 金額 | `required|integer|min:1|max:999999999999` |
| 理由（対象外・差し戻し・締めの警告） | `required|string|max:200` |
| 現金の残高 | `required|integer|min:0` |

## 7. 画面（Livewire）

「親」は URL を持つ画面、「区画」「モーダル」はその中の一部。スマホでは下のタブ（ホーム・送る・原資料・仕訳・締め）、PC では横のメニュー。送るだけの人にはタブを出さない。

| 親（ファイル） | 区画・モーダル | 機能 |
|---|---|---|
| ホーム `company/⚡home` | 大きく「今月 あと n 件」、未処理・下書き・戻された仕訳の数、各画面への入口 | F15 |
| 送る `company/⚡submit` | 大きな「撮る」（`<input type="file" accept="image/*,application/pdf" capture="environment">`）→ 小さな確認画像 →「送る」（処理中は押せない）／一言メモ（折りたたみ）／送れました「今月 n 枚目」「続けて撮る」／同じ写真です | F04・F05 |
| 原資料 `company/sources/⚡index` | 状態のタブ（未処理・下書きあり・仕訳済み・対象外）と件数、月の切り替え、写真の小さな画像・送った人・経過日数／モーダル：写真の拡大、対象外にする（理由） | F08・F14 |
| 下書きを作る `company/sources/⚡show` | 左（スマホは上）：写真　右：日付・取引先・分類・金額・支払い元・メモ（分類を選ぶと勘定科目を表示）／重複の警告／「保存して次へ」（次の未処理へ）／戻された理由 | F09・F10・F12 |
| 仕訳 `company/entries/⚡index` | 状態のタブ（下書き・戻された・承認済み）、月、作った人 | — |
| 仕訳の詳細 `company/entries/⚡show` | 借方・貸方の行、根拠の写真、作った人／「承認して次へ」・差し戻す（理由）・下書きを直す・消す（`can` で出し分け）／自己承認できないときはその理由を表示 | F11・F12 |
| 締めナビ `company/⚡close` | 大きく「あと n 件」、止める項目（blocking）と注意（warnings）を押すと該当の一覧へ／口座ごとの帳簿残高と確認した残高の差／モーダル：現金を数えて入れる／締める（警告の理由、owner だけ） | F07・F15・F16 |
| 会社設定 `company/⚡settings` | 会社名・決算月・記帳開始日・自己承認（owner）、分類と勘定科目の対応、口座、メンバーとロールの一覧（追加は MVP ではコマンド） | F19 |

画面の見た目は [docs/mockups/screens.png](mockups/screens.png)（出来上がりのイメージ）。

## 8. 自動テスト（Pest）

★は MVP。それ以外は次の週の機能と一緒に作る。

| # | 確かめること | 機能・基準 |
|---|---|---|
| T01★ | `/register` は404。ログイン後、送るだけ→送る、ほか→ホームへ振り分け | F01 |
| T02★ | 2段階認証を有効にしていない人は、会社の画面に入れず設定画面へ | F01・AC01 |
| T03★ | ログインから24時間を過ぎたらログアウトされる | F01 |
| T04★ | 会社Aの人が会社Bの URL（会社・原資料・仕訳・写真・締め）を開くとすべて404。Livewire のアクションで他社のIDを渡しても404 | F03・AC02 |
| T05★ | 権限の表（§5.1）をロールごとに回す。足りなければ403 | F03 |
| T06★ | 写真を送ると原資料（new）と証憑ができ、`evidence` ディスクに置かれる（`Storage::fake`） | F04 |
| T07★ | 画像・PDF以外、10MB超は弾かれ、行もファイルもできない | F04 |
| T08★ | 同じ写真を2回送ると1件だけ。2回目は「もう届いています」 | F05・AC04 |
| T11★ | 下書きを作ると原資料が drafted。別の仕訳につながった原資料には作れない。分類から勘定科目、支払い元から相手の科目が入る | F09 |
| T12★ | 締めた期間の日付では、下書きの保存も止まる | F16・AC08 |
| T13★ | 承認：自己承認は止まる、2回目は何も変わらない、貸借が合わないと止まる | F11・AC07 |
| T14★ | 承認前の仕訳は、締めナビの帳簿残高に入らない | AC05 |
| T15★ | 承認済みの仕訳は、画面からも Model の save/delete でも変えられない | AC06 |
| T17★ | 対象外は approver+ だけ、理由が必須。差し戻しで理由が残る | F12・F14 |
| T18★ | 締めナビの blocking の件数と、締めを止める条件が同じデータで一致する | F15・F16・AC08 |
| T19★ | 締めは owner だけ。前の月が開いていると止まる。warnings があるのに理由がないと止まる | F16 |
| T21★ | 30日を過ぎた写真が消え、`purged_at` と監査が残る。消えた写真の URL は410。同じ写真の再送は重複のまま | F17・AC09 |
| T22★ | 送る・下書き・承認・差し戻し・対象外・締め・写真の削除が監査記録に残り、パスワードなどが入っていない | F18 |
| T24★ | 送るだけの人は、送る画面以外が403、原資料は自分が送ったものだけ | F03 |
| T09・T10・T16・T20・T23・T25〜T27 | 明細・突き合わせ・取消・遅れて届いた明細・招待・サポート許可・AI | 次の週 |

## 9. 初期データ

| 環境 | 中身 |
|---|---|
| local・testing | 会社2つ（「丹内建機（デモ）」決算月7月、「デモ工務店」）。人：owner@example.com（父役）、editor@example.com（母役）、submitter@example.com（送るだけ）、other@example.com（他社の owner）。全員2段階認証済み、パスワードは `password`。勘定科目・分類・口座（現金・銀行・カード・立替）・期首残高。原資料と仕訳を、各状態を混ぜて先月と今月。写真はダミー画像 |
| production | `SEED_OWNER_EMAIL` / `SEED_OWNER_PASSWORD` の owner 1人だけ（空なら止める）。会社は `torudake:create-company "丹内建機サービス" --fiscal-year-end=7` で作り、勘定科目・分類・口座の初期値を入れる。母は `torudake:add-member` で editor として作る |

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

口座側の勘定科目：現金、普通預金、未払金（カード）、役員借入金（立替）。そのほか資本金・繰越利益剰余金・売掛金・買掛金・仕入高・外注費を持つ。

## 10. 作る順番（1.5日の計画）

### 10.1 見積もりの考え方（dlab の知見）
- 見積もりは甘くなる。約7割の案件が予算を超え、平均の超過幅は62%（[失敗を徹底的に潰す戦略的悲観術](https://daigovideolab.jp/play/D2SQ2WUQq4MZCYBFlMeo?t=3334)）。**使える約13時間のうち、作業として入れるのは約8〜9時間。残りは予備。**
- 1枠は1〜1.5時間。最初の枠の実績で、残りの見積もりを見直す（[計画錯誤を防ぐデッドライン設定術](https://daigovideolab.jp/play/jmv0vTgatOuKJfeKWT5I?t=1343)）。
- 失敗を先に計画する：削る順番を先に決める（§10.3）。
- 各枠の完了は「`/check` が通る」こと。目で見て終わりにしない。
- 本番での操作は `deploy.sh` だけ。AI に本番の SSH・DB を渡さない。

### 10.2 時間割
| 日 | 時間 | 枠 | 内容 | 完了の条件 |
|---|---|---|---|---|
| 10/24 | 9:00–10:00 | 0 土台 | Laravel 13＋Livewire スターターキット、Sail（MySQL・Mailpit）、日本語化、会員登録を切る、Pest・Larastan・Pint、`tests.yml` | CI が緑 |
| | 10:00–11:30 | 1 最初に本番へ | VPS にサブドメイン・DB・nginx・HTTPS、`deploy.sh`・`deploy.yml`。ログイン画面が本番で開く | スマホで本番のログイン画面が開く |
| | 11:30–13:00 | 2 会社と権限 | companies・memberships・Enum・`company`／`two-factor` ミドルウェア・CompanyPolicy・監査・24時間の期限・Artisan コマンド2つ | T01〜T05・T22（土台） |
| | 14:00–15:30 | 3 送る | evidences・source_records・送る画面・写真のルート・原資料の一覧 | T06〜T08・T24 |
| | 15:30–17:30 | 4 下書き | accounts・categories・bank_accounts・シーダー、journal_entries/lines、下書きを作る画面、CloseGuard | T11・T12 |
| | 17:30–19:00 | 5 承認 | 仕訳の一覧と詳細、承認・差し戻し・対象外、承認済みの保護 | T13〜T15・T17 |
| 10/25 | 9:00–10:00 | 6 締め | ホームと締めナビ（CloseCheck）、現金の残高、締める | T18・T19 |
| | 10:00–11:00 | 7 写真の削除 | PurgeExpiredEvidence とスケジューラ、`docs/runbook.md` | T21 |
| | 11:00–12:00 | 8 本番 | 本番に反映、会社・owner・母を作る、スマホで「送る → 下書き → 承認 → 締めナビ」を一通り | runbook のチェックリストがすべて済み |

### 10.3 削る順番（先に決めておく）
| いつ | こうなっていたら | こうする |
|---|---|---|
| 10/24 11:30 | 本番でログイン画面が開いていない | 枠1を続ける。締めナビは「件数の表示だけ」にし、「締める」と現金の残高は次の週 |
| 10/24 17:30 | 下書きが作れていない | 承認画面は「承認」ボタンだけにし、差し戻しと対象外は次の週 |
| 10/24 19:00 | 承認ができていない | 10/25 の枠6を承認の続きにし、締めナビは次の週 |
| どの時点でも | — | **写真の30日削除（枠7）と本番反映（枠8）は削らない** |

### 10.4 次の週
1. 明細CSVの取り込みと突き合わせ（F06・Q3 の実物を見てから）
2. 取消（F13）
3. 招待メール（F02）・サポート許可（F20）
4. テスト環境（ステージング）と S3互換ストレージ
5. AI の読み取りをテスト環境で測る（A01・A02）

## 11. 未決事項（実装は止めない）

| # | 決めること | 今の扱い |
|---|---|---|
| Q1〜Q8 | 01 §8.2 | 会社設定であとから入れる |
| X1 | 本番のサブドメイン名 | 10/24 の枠1で決める（例：`torudake.〇〇`） |
| X2 | 締めの取り消し | MVP では作らない。必要なら runbook で手順化 |
| X3 | 銀行ごとの明細CSVの形 | 次の週。`csv_profile` で吸収 |
| X4 | 試算表・決算書・税理士向けの出力 | 拡張 E01・E02 |
