# 分析用 CLI（#33）

「AIの記録」画面の書き出し（[docs/12-analysis-export-spec.md](../../docs/12-analysis-export-spec.md)）を手元で読み、**修正の傾向**・**修正なしの初回確定率**・**L3（検算）の誤差**を集計する。
本番の Laravel は PHP のまま。ここは、ダウンロード済みのファイルを読むだけ（DB・ネットワーク・AI には触れず、入力を書き換えない）。

## 使い方
```bash
python3 tools/analysis/analyze.py 書き出しのフォルダか.zip [別の月 ...] [--format json] [--top 10] [--strict]
```
- Python 3.10 以上、標準ライブラリだけ。複数月は並べる（会社が違うものは混ぜられない）。同じ ID は1回だけ数える。
- 終了コード：0＝集計できた／1＝`--strict` で入力に問題があった／2＝入力の形が違う（manifest なし・未対応の版・会社が違う・ファイル欠け）。
- 同じ入力なら、何回実行しても同じ結果（並びは「多い順、同数はキー順」）。

## 公開しない
- 書き出しも、集計の結果も、**仮名化しただけの機密データ**。リポジトリ・Issue・PR・Actions のログや成果物に上げない。
- 結果をファイルにするなら `tools/analysis/out/` か `reports/`（`.gitignore` 済み）。CI は分析レポートも実データもアップロードしない。
- エラーは「ファイル名・行番号・種別・項目名」だけ。行の値（金額・仮名キー）は出さない。
- テストの fixture は架空。再生成：`cd tools/analysis && python3 -m tests.fixture_data`

## 何を数えるか
| 区分 | 内容 |
|---|---|
| 修正の傾向 | 修正項目数（直した箇所）と仕訳数（直した仕訳）を分ける。1仕訳に3か所直したら項目3・仕訳1。型・欄・店（仮名）ごとの多い順、月別 |
| 初回確定 | `first_confirmations` から、月×区分（real／demo）。手を入れず確定・金額の補完・摘要を直した・事後修正・人が最初から入力・履歴の候補（採用・別を選んだ・保留）。定義と丸めは PHP の `FirstConfirmationMetrics` と同じ（四捨五入・.5 は切り上げ、母数0は `—`） |
| L3（検算） | `ai_runs` の `check_entry` で、**同じ実行の `input_features.lines` を整数で検算し直し**、同じ実行の `output_features` と比べる |

### L3 の決まり
- 比べるのは**保存済みの入力と出力だけ**。AI に再質問しない。後から人が直した今の仕訳（`journal_entries`）と、昔の L3 の出力は比べない（そのファイルは読まない）。
- 対象：`task=check_entry`・`driver=claude`・`valid`・エラーなし・入力と出力が揃うもの。**driver・model・prompt_version ごとに分ける**。
- 除外は理由ごとに件数を出す：実AI以外（fake・replay など）・失敗・入力欠損・出力欠損・再試行で置き換え。同じ対象に複数の実行があれば、`created_at` が最後の1件を採る。
- 出すもの：規則で貸借一致の率、規則×AI の一致/不一致の4通り、AI の誤った一致判定・誤った不一致判定、合計（借方・貸方）の食い違い、トークンと時間の平均。
- **貸借の算術一致率は、仕訳全体の正解率ではない。** 科目・税率・取引内容の正しさは保証しない。`valid` はスキーマを通ったという意味で、正解ではない。
- 版5から、書き出しの `balanced` は AI の答えがないと `null` になり、「出力欠損」として除外する。版4までは「`true` でなければ false」なので、答えのない実行が「AI は不一致と判定」に混ざりうる。AI の合計が範囲外（上限超え）のときは、上限で打ち切った値と比べない。

### 検算の規則（PHP と共通）
`models.py` の `check_balance` は `App\Support\JournalBalanceChecker` と同じ規則（整数のみ・空／片側だけ／不正な借貸／1円未満／範囲外は一致にしない・最初の問題を返す）。
共通の架空ベクトル `tests/fixtures/balance_vectors.json` を、PHP（`JournalBalanceCheckerTest`）と Python（`tests/test_models.py`）の両方が読む。**どちらかの規則を変えたら、ベクトルを直して両方を通す。**

## テスト
```bash
cd tools/analysis && python3 -m unittest discover -s tests -t .
```
CI（`.github/workflows/tests.yml` の `python` ジョブ）も同じコマンド。ネットワーク・実AI・本番の資格情報は使わない。

## これから規則を作るとき（予告）
- Python で候補規則を作るときは、**時間で評価用データを分ける**（古い月で作り、新しい月で試す）。同じ取引を、規則を作ることと評価することの両方に使わない。
- Laravel から呼ぶ必要が出たら、Queue → 固定した Python コマンド（仮想環境・JSON の入出力・タイムアウト・失敗時の保留）の非同期バッチにする。先に別の API サーバーは作らない。
- 宿題リポジトリの `a.py`〜`e.py` は提出物として別に保つ。
