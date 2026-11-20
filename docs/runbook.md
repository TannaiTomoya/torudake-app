# 本番の手順書（runbook）— 撮って送るだけ帳簿

本番：https://torudake-app.com（Xserver の VPS `153.115.51.65`、Ubuntu 26.04、8GB・100GB。**ほかのアプリと同居**）
決まり：**VPS の操作は本人が行う。AI は本番に SSH しない。** AI はスクリプトとコマンドを用意するだけ。
経緯：前の ABLENET VPS（`138.248.65.29`）は 10/25 以降つながらなくなり、11/20 に今のサーバーへ移した（#57）。前のサーバーの DB と写真は取り出せなかったので、空から始めた。同じことを繰り返さないための、サーバーの外へのバックアップは §1.4、戻し方は §3.4。

| 項目 | 値 |
|---|---|
| ドメイン | `torudake-app.com`（Cloudflare で登録、期限 2027-10-24、自動更新 ON） |
| DNS | Cloudflare：`A @ 153.115.51.65`、**DNS only（灰色の雲）** |
| アプリの場所 | `/var/www/torudake` |
| DB | `torudake`（ユーザー `torudake`、パスワードは VPS の `/root/.torudake-db-password` と `.env` にだけある） |
| バックアップ | `~/backups/torudake/`（デプロイのたびに DB を保存、30日分。**同じサーバーの中**） |
| 外へのバックアップ | Cloudflare R2（毎日1回、暗号化、30日分。§1.4 で本人が設定する。設定するまでは無い） |
| 定期実行 | cron の `schedule:run`（毎分） |
| ジョブの常駐 | systemd の `torudake-queue@1`・`torudake-queue@2`（2つ） |

---

## 1. 初回の準備（1回だけ）

### 1.1 サーバーを準備する（VPS で）
**ほかのアプリと同居するとき**は、先に今の状態を見る（何も変えない）。
```bash
whoami; ls -l /etc/nginx/sites-enabled/; node -v; which php mysql certbot; sudo ss -tlnp | grep -E ":(80|443|3306) "
```
- 最後の行（80・443・3306 を使っているもの）：80・443 が nginx 以外（Docker・Caddy など）なら、このスクリプトでは置けないので止めて相談する
- `whoami`：ふだん SSH するユーザー（`ubuntu`）であること。このユーザーがアプリの持ち主・常駐の実行ユーザー・GitHub Actions の接続先になる
- Node が 22 未満：スクリプトは入れ替えずに止まる。同居のアプリが Node を使っていないと分かっているときだけ `ALLOW_NODE_REPLACE=1` を付ける
- PHP をすでに同居のアプリが使っている：スクリプトの `99-torudake.ini`（アップロード13MB・メモリ256MB）が PHP-FPM 全体に効き、PHP-FPM が数秒再起動する
- スクリプトが同居のアプリのために**しないこと**：nginx の default のサイトを消す（消すときだけ `REMOVE_DEFAULT_SITE=1`）、入っている nginx・certbot の更新、ほかのサービスの自動再起動。nginx の設定は `nginx -t` が通ったときだけ読み直す

次に、Cloudflare の DNS を今のサーバーに向ける（証明書を取るのに要る）。Mac で次が今のサーバーの IP を返してから進む。
```bash
dig +short A torudake-app.com
```
準備のスクリプトを実行する。
```bash
curl -fsSL https://raw.githubusercontent.com/TannaiTomoya/torudake-app/main/docs/server/setup-torudake.sh -o setup-torudake.sh
sudo ADMIN_EMAIL=あなたのメール bash setup-torudake.sh
```
最後に `https://torudake-app.com/login → 200` と出れば完了。途中で止まったら、表示された原因を直してもう一度実行する（何度実行しても壊れない）。同居のアプリが今までどおり開くことも確かめる。

そのあと `.env` に本番の値を足し、読み直す（値は `.env` にだけ書く。画面や issue に貼らない）。
- `ANTHROPIC_API_KEY`（AI のキー）、`AI_DRIVER=claude`
- `CACHE_STORE=database`（`/health` の判定に要る。下の「稼働確認」）
```bash
nano /var/www/torudake/.env
```
```bash
cd /var/www/torudake && php artisan config:cache && php artisan queue:restart
```

### 1.2 最初の owner を作る（VPS で）
工程2でコマンドを足したあとに行う。
```bash
cd /var/www/torudake && php artisan torudake:create-company "丹内建機サービス" --fiscal-year-end=7
```
自分を owner（社長・責任者）として入れる。
```bash
php artisan torudake:add-member "丹内建機サービス" 自分のメール owner --name=表示名
```
パスワードを決めるリンクを作る（下の「パスワードを決めるリンク」）。開いてパスワードを決め、ログインして2段階認証を設定する。

### パスワードを決めるリンク（メールを送らない間）
メールは当面 `log` で、本番のログは「警告以上だけ」（`LOG_LEVEL=warning`）なので、**再設定のリンクはログに残らない**（11/20 に発覚。ログから grep する前の手順は働かない）。リンクはコマンドで作る。前のリンクは使えなくなり、有効なのは60分。リンクはパスワードと同じ扱いにして、チャットや issue に貼らない。
```bash
cd /var/www/torudake && php artisan tinker --execute='$u = App\Models\User::where("email", "その人のメール")->firstOrFail(); echo route("password.reset", ["token" => Illuminate\Support\Facades\Password::broker()->createToken($u), "email" => $u->email]), PHP_EOL;'
```

### 1.3 自動デプロイを有効にする（Mac で）
GitHub Actions が VPS に SSH するための鍵を、このリポジトリに登録する。鍵は camp_actions のもの。**サーバーを移したら、公開鍵を新しいサーバーに足し、`SSH_HOST`・`SSH_USER` を入れ直す**（秘密鍵は同じなら入れ直さなくてよい）。

公開鍵を VPS に足す（Mac で。`tsunagu-vps` は `~/.ssh/config` の今のサーバーの名前）。
```bash
ssh tsunagu-vps 'mkdir -p ~/.ssh && chmod 700 ~/.ssh && cat >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys' < ~/.ssh/camp_actions.pub
```
```bash
gh secret set SSH_HOST --body "153.115.51.65"
```
```bash
gh secret set SSH_USER --body "VPSのユーザー名"
```
```bash
gh secret set SSH_PRIVATE_KEY < ~/.ssh/camp_actions
```
```bash
gh variable set DEPLOY_ENABLED --body true
```
GitHub の Settings → Environments に `production` ができる。デプロイの前に承認を挟みたいときは、そこで「Required reviewers」に自分を入れる。

### 1.4 サーバーの外へのバックアップを設定する（1回だけ。#35）
デプロイのときのバックアップは同じサーバーの中にあるので、サーバーごと失うと一緒になくなる（10/25 に実際に起きた）。毎日1回、DB・写真・`.env` を1つに束ねて暗号化し、サーバーの外（Cloudflare R2）へ送る。

| 決めること | 値（11/21 に本人が決めた） |
|---|---|
| 保管先 | Cloudflare R2 |
| 頻度 | 毎日1回、**日本時間の 3:30**（サーバーの時計が UTC でも、日本時間で動く）。失うのは最大で前の日の 3:30 から後に入れた分 |
| 残す期間 | 30日（R2 の自動削除）。アプリが30日で消した写真も、バックアップの中には最長でさらに30日残る |
| 大きさ | R2 の無料の範囲は 10GB。写真が多いと超えうるので、最初の数日で大きさを見る（下の 7） |
| 失敗に気づく方法 | 26時間たっても成功の印がないと `/health` が 503 になり、15分おきの見張り（monitor.yml）が Issue を立てる |
| 戻すまでの目安 | 半日（新しいサーバーの準備 §1.1 → 下の §3.4） |

入っているもの：DB の全部、写真（`storage/app/private/evidences`）、`.env`（アプリの鍵 `APP_KEY` が入っている。これがないと2段階認証を読めない）、控え（件数と貸借の合計だけ）。Excel は入れない（DB から作り直せる。§3.5 の練習で確かめた）。

1. **開ける鍵を作る（Mac で）**。秘密鍵はサーバーに置かない。サーバーごと失っても、乗っ取られても、鍵は手元に残る。
```bash
brew install age && age-keygen -o ~/torudake-backup-key.txt && chmod 600 ~/torudake-backup-key.txt
```
画面に出る `Public key: age1...` が公開鍵（サーバーに置くのはこれだけ）。`~/torudake-backup-key.txt` が秘密鍵。**秘密鍵をなくすと、バックアップは誰にも開けない**。Mac のほかにもう1か所（パスワード管理アプリなど）に同じ中身を置く。秘密鍵はチャット・issue・リポジトリに貼らない。

2. **R2 に置き場を作る（Cloudflare の画面で）**
- R2 → バケットを作る（名前は例：`torudake-backup`）
- そのバケットの Settings → Object lifecycle rules → 「30日たったら消す」を足す
- R2 → API トークン → 作る：権限は「Object Read & Write」、対象は**このバケットだけ**。出てきた Access Key ID・Secret Access Key・エンドポイント（`https://（アカウントID）.r2.cloudflarestorage.com`）を控える（この画面は一度しか出ない。貼らない）

3. **サーバーに道具と設定を置く（VPS で）**
```bash
sudo apt install -y age rclone
```
```bash
mkdir -p ~/.config && nano ~/.config/torudake-backup.env
```
中身（`...` を自分の値にする）：
```bash
BACKUP_REMOTE=r2:torudake-backup/torudake
BACKUP_AGE_RECIPIENT=age1...
RCLONE_CONFIG_R2_TYPE=s3
RCLONE_CONFIG_R2_PROVIDER=Cloudflare
RCLONE_CONFIG_R2_ACCESS_KEY_ID=...
RCLONE_CONFIG_R2_SECRET_ACCESS_KEY=...
RCLONE_CONFIG_R2_ENDPOINT=https://（アカウントID）.r2.cloudflarestorage.com
RCLONE_CONFIG_R2_NO_CHECK_BUCKET=true
```
```bash
chmod 600 ~/.config/torudake-backup.env
```
`NO_CHECK_BUCKET` は、バケット1つだけに絞ったトークンで要る（バケットを作る権限がないので、作る確認を省く）。

4. **1回、手で取る（VPS で）**。最後に「OK 保管先の確認」「バックアップ完了」と出れば成功。Cloudflare の画面でもファイルが1つ見える。
```bash
bash /var/www/torudake/docs/server/backup-torudake.sh
```

5. **毎日、日本時間の 3:30 に取るようにする（VPS で）**。cron は毎時30分に呼ぶが、スクリプトが「日本時間の3時台」のときだけ動く（`--jst-hour 03`）。サーバーの時計が UTC でも日本時間でも、同じ1行でよい。
```bash
( crontab -l 2>/dev/null | grep -v backup-torudake.sh; echo '30 * * * * bash /var/www/torudake/docs/server/backup-torudake.sh --jst-hour 03 >> $HOME/backups/torudake/offsite.log 2>&1' ) | crontab -
```
次の朝、`offsite.log` に「バックアップ完了」が1回だけ増えていることを見る。
```bash
grep -c 'バックアップ完了' ~/backups/torudake/offsite.log; tail -4 ~/backups/torudake/offsite.log
```

6. **失敗に気づけるようにする（VPS で）**。4 が成功したあとで、`.env` に `BACKUP_MAX_AGE_HOURS=26` を足して読み直す（先に足すと、成功の印がまだないので `/health` が 503 になる）。
```bash
cd /var/www/torudake && php artisan config:cache && php artisan queue:restart
```

7. **最初の3日間、大きさを見る（VPS で）**。束には毎回「その時点の写真の全部」が入る（写真は縮めずに保存していて、1枚は最大 10MB）。写真はアプリが30日で消すので、束は「30日分の写真」で頭打ちになり、保管先にはそれが30日分並ぶ。つまり、合計はおよそ「1か月分の写真の大きさ × 30」。10GB に収まるのは、1か月分の写真が 340MB くらいまで（1枚 3MB なら月に110枚ほど）。
```bash
grep '大きさ' ~/backups/torudake/offsite.log | tail -5
```
2日分たまると「1日に約 n MB ずつ増加／このままだと 30 日分で約 m MB（目安 10240 MB）」と出る。見込みが目安の8割を超えると「注意」の行が出る（バックアップは止めない）。見込みは、写真を送る量が今のままという仮定の粗い数字。

8. **復元を1回試す（Mac で）**。取れているだけでは足りないので、本物の束を、Mac に置いた本物の秘密鍵で開けて、開発の Sail の練習用の DB に戻して確かめる。本番にも、ふだんの開発の DB にも触らない。件数や金額は表示しない。終わると、戻した中身・作り直した Excel・コンテナに写した束と鍵は消える。
   - 道具と練習用の DB を用意する（初めてのときだけ）
```bash
vendor/bin/sail up -d && docker compose exec -T -u root laravel.test bash -c "apt-get update -qq && apt-get install -y -qq age rclone"
```
```bash
docker compose exec -T mysql bash -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE DATABASE IF NOT EXISTS torudake_drill_src; CREATE DATABASE IF NOT EXISTS torudake_drill_dst; GRANT ALL ON \`torudake\_drill\_%\`.* TO \"$MYSQL_USER\"@\"%\";"'
```
   - Cloudflare の画面から、いちばん新しい束を Mac にダウンロードし、束と秘密鍵をコンテナの `/tmp` に写す（作業フォルダには置かない。リポジトリは公開なので）
```bash
docker compose cp ~/Downloads/torudake-（日時）.tar.gz.age laravel.test:/tmp/trial.tar.gz.age
```
```bash
docker compose cp ~/torudake-backup-key.txt laravel.test:/tmp/trial-key.txt
```
   - 試す
```bash
vendor/bin/sail bash docs/server/restore-trial.sh /tmp/trial.tar.gz.age /tmp/trial-key.txt
```
   - 「すべて通りました」と出れば、束が開く・DB が戻る・件数と貸借の合計が控えと同じ・アプリの鍵が合う・Excel を作り直せる、まで確かめられた。#35 には**日付と「通った」だけ**を書く（件数や金額は書かない）。`~/Downloads` の束は消す
   - 通らなかったら、表示された「NG」の行を見る。「束を開けられません」は鍵が違う（公開鍵と秘密鍵の組が合っていない）。そのままにせず、鍵を作り直して 1 からやり直す
   - この試しで確かめないこと：写真を戻すこと（束に入っている数だけ表示する）、画面を開いての操作、新しいサーバーを一から用意する時間（§3.4）

知っておくこと：
- 保管先の確認だけ：`bash /var/www/torudake/docs/server/backup-torudake.sh --check`（最新が26時間以内か・32日を過ぎたものが残っていないか・大きさ）
- **大きさが目安を超えそうなとき**（どれかを選ぶ）：(a) 残す期間を短くする（R2 の自動削除と、設定の `BACKUP_RETENTION_DAYS` を同じ日数にする。例：14）。(b) 超えた分を払う（10GB を超えた分は 1GB あたり月 0.015 ドルほど。料金は Cloudflare の画面で確かめる）。(c) 写真を束から分けて、増えた分だけ送るように作り替える（まだ作っていない。要るなら issue を立てる）
- 復元の試し（8）は、鍵を作り直したとき・サーバーを移したとき・半年に1回、もう一度行う
- 成功の印はキャッシュにある。`php artisan cache:clear` を実行すると印が消えて `/health` が 503 になるので、バックアップを1回取り直す
- R2 のトークンは「書ける」と「消せる」が分かれていない。サーバーを乗っ取られると、保管先のバックアップも消されうる（中身は読まれない。開ける鍵がサーバーにないため）
- Cloudflare のアカウントが止まると、ドメインとバックアップが同時に使えなくなる（どちらも Cloudflare）。気になるなら、月に1回、束を1つ Mac に落としておく

---

## 2. いつもの更新
1. 手元で `/check` が通る
2. main に push（またはPRをマージ）
3. GitHub Actions：`tests` が通る → `deploy` が VPS で `deploy.sh` を実行（DB のバックアップ → 取得 → 依存 → 組み立て → migrate）。最後に写真保存先の共有グループ・非公開権限を検査する
4. 反映後の確認（下のチェックリスト）

手で更新するとき（VPS で）：
```bash
bash /var/www/torudake/deploy.sh
```

### 反映後のチェックリスト
- [ ] https://torudake-app.com/login が開き、鍵マークが付いている
- [ ] ログインできる（2段階認証のコードを含めて）
- [ ] 送る → 原資料の一覧に出る（工程3以降）
- [ ] 経理が下書きを直して保存すると「あと n 件」が減り、Excel の仕訳帳に入る
- [ ] `sudo systemctl status 'torudake-queue@*'` で2つとも active
- [ ] https://torudake-app.com/health が `{"app":"torudake","ok":true}`（反映の直後は1〜2分かかることがある）

### 稼働確認（公開サイトが見えているか）
- **反映のあと（deploy.yml の最後の手順）**：`docs/server/check-public-site.sh` が外から `/login` と `/health` を確認する。見えなければ反映を失敗にし、GitHub の失敗通知が届く
- **15分おき（monitor.yml）**：同じ確認を回す。異常なら実行が失敗し、Issue `[稼働監視]`（ラベル `monitor-down`）を1件だけ立てる。直ると自動で「復旧」と書いて閉じる
- 手で確認：`bash docs/server/check-public-site.sh`（別の URL は引数、または `SITE_URL`）。終了コード 0 が正常
- 結果の読み方：
  - 「接続できません」「時間切れ」「証明書」→ VPS・DNS・nginx・証明書（サーバー側の問題）
  - 「サーバーエラー」→ アプリの `storage/logs` を見る
  - 「別のサイトへ移動」「ログイン画面ではありません」「アプリの印がない」→ DNS や nginx の向き先が違う
  - 「DB・定期処理・キューのどれかが止まっているか、外へのバックアップが古くなっています」（`/health` が 503）→ `storage/logs` の「稼働確認：問題あり」の行に `database` / `scheduler` / `queue` / `backup` のどれかが出る。`schedule:run` の cron、`torudake-queue@*`、MySQL を順に確認。`backup` なら `~/backups/torudake/offsite.log` の最後を見て、§1.4 の 4 を手で実行する
- `/health` の判定：DB につながる・定期処理の印が5分以内・キューの印が10分以内（`config/torudake.php` の `health`）。`.env` に `BACKUP_MAX_AGE_HOURS` があるときは、外へのバックアップの成功がその時間以内（§1.4）。印は毎分の `torudake:heartbeat` が書く（キューは空でも、この仕事を流して生きているか見る）。印の置き場はキャッシュなので、`.env` の `CACHE_STORE` は `database`（cron・PHP-FPM・キューで共有できる値）にしておく。`file` や `array` だと常に 503 になる
- 通知まで含めた擬似障害の試験（本番は止めない）：Actions の `monitor` を手動実行し、確認先に `http://127.0.0.1:9` のようなつながらない URL を入れる → 実行が失敗し、Issue `[稼働監視]` が1件立つ（もう一度失敗させても増えない）→ 確認先を空にして手動実行 → 「復旧」と書いて閉じる。試験したら、実行の URL を #38 に貼って証拠にする。失敗の通知メールは GitHub の通知設定（Watching・Actions）が有効なときだけ届く。無効なら Issue と Actions の赤い印だけが残る
- 擬似障害（判定だけ）の確かめ方：`vendor/bin/sail artisan test tests/Feature/PublicSiteCheckTest.php`（偽のサイトで 500・別サイトへの移動・時間切れ・接続不可などを検出できるか）。本番で止めて試さない
- できないこと：これは「見張り」であって稼働の保証ではない。GitHub の定期実行は数分〜十数分遅れることがあり、60日間リポジトリに動きがないと止まる（Actions の画面で有効にし直す）。メールや電話では知らせない（GitHub の通知だけ）。`SITE_URL`（リポジトリの変数）で確認先を変えられる

### 本番の最小確認（本人が、反映のたびに）
本番に架空の帳簿は作らない。自分の会社の画面で次だけ見る。
- [ ] ログイン（2段階認証まで）でき、役割ごとの最初の画面に入る
- [ ] レシートを1枚送ると、経理の一覧に出る
- [ ] 経理が保存すると「あと n 件」が減る
- [ ] Excel をダウンロードして、保存した1件が入っている
- 役割ごとの出入り（送るだけ・社長・他社404）は `tests/Feature/AcceptanceTest.php` で毎回確かめている

### `.env` を変えたとき
キューの常駐は起動したときの設定のまま動くので、`.env` を変えたら設定を読み直して常駐を立ち上げ直す（`AI_DRIVER`・`ANTHROPIC_API_KEY` など）。
```bash
cd /var/www/torudake && php artisan config:cache && php artisan queue:restart
```
手元（Sail）では `vendor/bin/sail artisan queue:restart` のあと、止まった `queue:work` を起動し直す（2つ動かすときは、ターミナル2つで `vendor/bin/sail artisan queue:work`）。

### レシートのAI処理が止まったとき

- ジョブの最終失敗時は原資料を「AIが読めませんでした」に戻し、経理が写真を見て手入力・保存できる。保存後は次の未処理資料へ進む。確定した仕訳やAI完了済みの資料は変更しない。
- ワーカー停止で失敗通知が届かなくても、`waiting`・`reading` のまま10分以上更新されない原資料を、5分ごとの `torudake:fail-stale-evidence-ai` が復旧する。停滞開始からおおむね10〜15分が目安。原資料・写真・下書きは削除せず、失敗への変更を `evidence.ai_failed` に記録する。
- 復旧が動かない場合は、本人がVPSで次を確認する（AIはSSHしない）。定期復旧には毎分の `schedule:run` が必要で、キューの常駐が停止中でも動く。通常のAI読み取りには常駐2つを使う。手動の復旧コマンドも同じ条件で判定し、AIは呼ばない。

```bash
cd /var/www/torudake
php artisan schedule:list
crontab -l
sudo systemctl status 'torudake-queue@*'
php artisan torudake:fail-stale-evidence-ai
```

### 写真保存先の権限復旧

`Unable to read file from location` が出て、Web で写真が見えるのにAIが失敗する場合は、Web（`www-data`）とキュー（`User=ubuntu`、`Group=www-data`）の実行ユーザー差を確認する。保存フォルダが `0700` だとキューが読めない。

レシート・帳票は共通の `EvidenceFileStorage` を通し、非公開ファイル `0660`・ディレクトリ `2770` で保存する。保存中だけ `umask=0007` にし、例外時も元へ戻す。最上位の保存先は **初期セットアップで `www-data` グループ・`2770` にする必要がある**。setgid によって新しい子ディレクトリ・ファイルへ共有グループを継承する。設定値だけで最上位への setgid 付与や既存ファイルの復旧は行わない。

既存環境の復旧は本人がVPSで一度実行する（10/25 の環境では実施済み）。写真保存先の外には適用しない。

```bash
cd /var/www/torudake
sudo chgrp -R www-data storage/app/private/evidences
sudo find storage/app/private/evidences -type d -exec chmod 2770 {} +
sudo find storage/app/private/evidences -type f -exec chmod 660 {} +
bash docs/server/check-evidence-permissions.sh
```

デプロイの `Verify private evidence storage permissions` は、最上位が通常のディレクトリ・`2770`・`www-data` であることを読み取り専用で検査する。異常なら Actions を失敗させる。既存の子ファイルすべての検査や権限変更、`sudo` は行わない。アプリの画像取得は引き続き認証・会社スコープ・`EvidencePolicy` を通す。公開ディスクへ移したり、その他のOSユーザーに権限を付けたりしない。

修正後はキューを再起動する（通常のデプロイに含む）。失敗済みの原資料は自動再実行しないため、手入力して保存するか、新しく撮った写真で読み取りを確認する。同じ画像は重複として扱う。

### キューの常駐を2つにする（10/24、1回だけ）
1枚に十数秒かかるので、まとめて送った写真を並べて読むために常駐を2つにする。今の `torudake-queue` を止めて、`torudake-queue@1`・`@2` に置き換える。まず今の常駐の `User=` を確かめる（下の `User=ubuntu` と違えば、そこを書き換える）。
```bash
systemctl cat torudake-queue | grep '^User='
```
```bash
sudo systemctl disable --now torudake-queue && sudo rm /etc/systemd/system/torudake-queue.service
sudo tee /etc/systemd/system/torudake-queue@.service >/dev/null <<'UNIT'
[Unit]
Description=torudake queue worker %i
After=network.target mysql.service

[Service]
User=ubuntu
Group=www-data
WorkingDirectory=/var/www/torudake
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=1 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl daemon-reload && sudo systemctl enable --now torudake-queue@1 torudake-queue@2
```
```bash
sudo systemctl status 'torudake-queue@*' --no-pager | grep -E 'torudake-queue@|Active'
```
`deploy.sh` の `queue:restart` は2つとも立ち上げ直すので、デプロイの手順は変わらない。

### デモ用の会社を2つ作る（1回だけ、VPS で）
練習用（本物の Claude に読ませて記録を作る）と本番用（当日に送る）。再生はデモ用の会社（`--demo`）どうしでだけ働く。
- **会社名は2つとも、デモの領収証の宛名と同じ名前にする**。違うと、自分の会社あての領収証にも「宛名が別の会社」の印が付く（「(株)」と「株式会社」・空白の違いは同じとみなす。「（練習）」などを足すと別の名前になる）。
- 同じ名前の会社が並ぶので、このあとは名前ではなく **ID** で指定する。

1. 本番用、練習用の順に作り、表示された ID を控える（決算月は `--fiscal-year-end`、省略すると7月）。
```bash
cd /var/www/torudake && php artisan torudake:create-company "宛名と同じ会社名" --demo
```
```bash
php artisan torudake:create-company "宛名と同じ会社名" --demo
```
2. 2つともデモ用になっているか確かめる。
```bash
php artisan tinker --execute='App\Models\Company::where("is_demo", true)->get()->each(fn ($c) => print("{$c->id} {$c->name}\n"));'
```
3. 本番用に、社長の画面の架空の数字（三期比較・顧客別・今期の経費）を入れる。練習用には入れない。
```bash
php artisan torudake:seed-demo 本番用のID
```
4. 人を入れる。**本番用を先に**入れる（ログインすると、先に入った会社が開く）。PC はライブ画面で直して保存する「経理担当」（admin。ライブ画面・社長の画面も見られる）、スマホは送るだけの「現場」。名前はプロジェクターに映るので、本名にしない。

   admin のホームとメニューは「直して保存」を優先する。社長の画面は補助リンクからも開ける。owner は社長向けホーム、editor は経理向けホームになる。表示名を「経理担当」に変えてもロールは変わらない。

```bash
php artisan torudake:add-member 本番用のID PCのメール admin --name=経理担当
```
```bash
php artisan torudake:add-member 本番用のID スマホのメール editor --name=現場 --submit-only
```
```bash
php artisan torudake:add-member 練習用のID PCのメール admin --name=経理担当
```
```bash
php artisan torudake:add-member 練習用のID スマホのメール editor --name=現場 --submit-only
```
5. パスワードを決める。メールアドレスごとに、§1.2 の「パスワードを決めるリンク」のコマンドでリンクを作る（ログには残らない。有効なのは60分）。
6. スマホと PC でそれぞれリンクを開いてパスワードを決め、ログインすると2段階認証の設定画面に移るので、認証アプリで設定する。復旧コードは紙に控える。
7. ブックマークする（練習用は ID を入れた URL を直接開く）。
   - PC：`https://torudake-app.com/c/本番用のID/live`・`/c/練習用のID/live`
   - スマホ：`https://torudake-app.com/c/本番用のID/submit`・`/c/練習用のID/submit`

### デモの再生モード（AI が遅い・止まったときの保険）
1. デモ用の会社を2つ作る（上の「デモ用の会社を2つ作る」）。
2. `AI_DRIVER=claude` のまま、練習用の会社で**デモで使う写真**を送り、本物の Claude に読ませる（これが記録になる）。**練習の記録は、プロンプトの版を上げる変更（「読み取りの自信と宛名の印」など）を push したあとで作る**（版が違う記録は再生しない）。
3. デモの前に `.env` を `AI_DRIVER=replay` にし、上の「`.env` を変えたとき」の手順で読み直す。
4. デモでは本番用の会社に、**練習で撮った同じ写真**を送る（その場で撮り直すと SHA-256 が変わり、再生されない）。記録のある写真は練習と同じ秒数で再生され、ライブ画面に小さく「再生」と出る。記録のない写真は本物の Claude を呼ぶ。
5. 再生するのはデモ用の会社どうしだけ。実在の会社では `replay` にしても本物を呼ぶ。プロンプトの版を上げたら、練習をやり直す。
6. デモが終わったら `.env` を `AI_DRIVER=claude` に戻し、同じ手順で読み直す。

---

## 3. 戻し方

### 3.1 コードだけ戻す（DB はそのまま）
```bash
cd /var/www/torudake && git reset --hard "$(cat ~/backups/torudake/previous-commit)" && composer install --no-dev --optimize-autoloader && npm ci && npm run build && php artisan optimize && php artisan queue:restart
```
戻したあと、GitHub の main にも同じ修正（revert）を入れる。入れないと、次の push で元に戻る。

### 3.2 DB も戻す（migrate で壊れたとき）
直前のバックアップの名前を確かめてから戻す。**戻すと、そのあとに入ったデータは消える。**
```bash
ls -t ~/backups/torudake/db-*.sql.gz | head -3
```
```bash
gunzip -c ~/backups/torudake/db-（日時）.sql.gz | mysql -u torudake -p torudake
```

### 3.3 練習
テスト環境ができるまでは、デモの前に一度「更新 → 3.1 で戻す → もう一度更新」を通しておく（04 §8・AC10）。

### 3.4 サーバーごと失ったとき（外のバックアップから戻す。#35）
**本人が実行する。** 戻すと、最後のバックアップのあとに入ったデータは戻らない。
1. 新しいサーバーを準備する（§1.1。空のアプリと空の DB ができる）。自動デプロイも付け直す（§1.3）
2. Cloudflare の画面から、いちばん新しい束（`torudake-（日時）.tar.gz.age`）を Mac に落とし、束と秘密鍵をサーバーへ送る（Mac で）
```bash
scp ~/Downloads/torudake-（日時）.tar.gz.age ~/torudake-backup-key.txt tsunagu-vps:~/
```
3. 止める（VPS で）
```bash
cd /var/www/torudake && php artisan down && sudo systemctl stop 'torudake-queue@*'
```
4. 戻す（VPS で）。`RESTORE_CONFIRM` なしで実行すると、何を上書きするかを表示して止まる
```bash
RESTORE_CONFIRM=torudake RESTORE_OVERWRITE=1 RESTORE_EVIDENCES=1 RESTORE_TO_PRODUCTION=1 bash /var/www/torudake/docs/server/restore-torudake.sh ~/torudake-（日時）.tar.gz.age ~/torudake-backup-key.txt
```
5. 「APP_KEY が違います」と出たら（新しいサーバーでは必ず出る）：表示された `~/torudake-restored-env-（日時）` から `APP_KEY`・`ANTHROPIC_API_KEY` などの行を `.env` に写し、読み直して、4 をもう一度実行する
```bash
cd /var/www/torudake && php artisan config:cache
```
6. 「復元の確認：すべて合っています」（件数と貸借の合計が控えと同じ・アプリの鍵・Excel の作り直し）と出たら、動かす
```bash
cd /var/www/torudake && sudo systemctl start 'torudake-queue@*' && php artisan up
```
7. 片付ける：サーバーに置いた秘密鍵・束・`~/torudake-restored-env-*` を消す。ログインして「本番の最小確認」（§2）を通す。§1.4 の 3〜8（外へのバックアップ）を新しいサーバーにも設定する
```bash
rm ~/torudake-backup-key.txt ~/torudake-*.tar.gz.age ~/torudake-restored-env-*
```

### 3.5 復元の練習（架空のデータで。#35）
本番を触らずに、取る → 暗号化 → 送る → 開ける → 戻す → 確かめる、を開発の Sail で通す。保管先は一時フォルダ、鍵は練習用、データは架空（`RestoreDrillSeeder`）、AI は偽物、メールは送らない。前準備（道具と練習用の DB）は `docs/server/restore-drill.sh` の先頭にある。
```bash
vendor/bin/sail bash docs/server/restore-drill.sh
```
**11/21 の記録（すべて通った）**
- 取る・暗号化・送る：成功。束の中に DB の文字は見えず、違う鍵では開かない
- 戻す：件数と貸借の合計が控えと同じ（人4・会社2・役割 owner 2／editor 2・仕訳8〔確定6・下書き1・戻し1〕・確定の借方と貸方がどちらも 396,560円）、アプリの鍵で2段階認証の秘密を読める、Excel を DB から作り直すと2社とも確定の件数と同じ行数
- 写真：2枚とも同じ中身で戻った
- 戻した環境で通す（処理を直接呼ぶ）：ログイン（パスワードと2段階認証のコード）、権限（自社だけ・送るだけの人は一覧を見られない）、送る → AI の下書き → 経理の保存で確定 → Excel に1行
- 気づけること：戻したあとに1件変わると件数と合計の違いとして出る、壊れた Excel、違う鍵、期限切れ（40日前）が残っている、最新が古い（2日前）、確認の指定なし・上書きの指定なし、ほかの人が読める設定、秘密鍵の置き間違い
- 上書き：中身が変わった DB に上書きで戻すと、控えと同じに戻る（控えにない表も消える）
- 日本時間の指定：時計が UTC のコンテナで、日本時間の今の時は動き、ほかの時の指定では何もしない
- 大きさの見込み：3日で 1MB から 4MB に増えた保管先で「1日に約 1MB、30日分で約 930MB」（手計算どおり）。目安の8割を超えると注意が出る
- 手元での試しの道具（`restore-trial.sh`、§1.4 の 8）：アプリの鍵が手元と違う束は、ふつうの復元では確認が失敗し、この道具では束の中の鍵で通る。金額やメールは表示されず、終わると、戻した中身・束の `.env`・写した束と鍵は残らない

**練習で確かめていないこと**（本人が §1.4 で確かめる）：本物の R2 への送受信と自動削除（練習は一時フォルダ）、本番のサーバーでの cron（5 の次の朝）、本物の束を Mac の本物の秘密鍵で開けて戻すこと（8）、画面を開いての操作（練習は処理を直接呼ぶ）。

---

## 4. ticket-app の片付け（前のサーバーの話。今のサーバーでは対象外）
前の ABLENET VPS にあったもの。本番は今のサーバーへ移したので、下のコマンドは**今のサーバーでは実行しない**（ticket-app は載っていない）。残っているのは、GitHub の ticket-app の Deploy key を画面から消すことだけ。以下は記録として残す。

10/24 に止めた（nginx のリンクを外した）。1週間問題がなければ消す。**消す前にバックアップがあることを確かめる。**
```bash
ls -l ~/backups/ticket-app/
```
```bash
sudo rm -rf /var/www/ticket-app && sudo rm -f /etc/nginx/sites-available/ticket-app && sudo nginx -t && sudo systemctl reload nginx
```
```bash
sudo mysql -e "DROP DATABASE ticket_app;"
```
MySQL のユーザー名は ticket-app の `.env`（`~/backups/ticket-app/env-2026-10-24`）の `DB_USERNAME` を見て消す。GitHub の ticket-app の Deploy key（ID 166730200）は GitHub の画面から消す。

---

## 5. サーバーの版（10/24 に前のサーバーで確認。今のサーバーも Ubuntu 26.04）
- OS：Ubuntu 26.04.1 LTS。PHP は OS 標準の **8.5**、Node は **22**、MySQL は **8.4**（外部の PPA は使わない）
- npm は **10 以上**。OS 標準の npm 9.2 は `package.json` の `overrides`（`npm:` の別名）を読めず、`npm ci` が「package-lock.json が無い」という的外れな文面で失敗する（11/20 に発覚）。`setup-torudake.sh` が npm だけ新しくする（`/usr/local` に入る。Node はそのまま）
- CI（`tests.yml`）も本番に合わせて PHP 8.5 で回す。開発の Sail は 8.4（あとで 8.5 にそろえる）

### PHP が古いとき
`setup-torudake.sh` が「PHP x.x は古い」と止まったら、OS が古い。Laravel 13 は PHP 8.3 以上が必要。OS を上げるか、作者に相談する。

## 6. あとでやること
- メールを本当に送る（パスワード再設定に必要）：`.env` の `MAIL_*` を送信サービスの値にする。今は `log`（`storage/logs` に書くだけ）
- Cloudflare を Proxied（オレンジ）にする場合：SSL/TLS を Full (strict)、アプリに TrustProxies を入れてから（04 §5.2）
