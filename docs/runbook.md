# 本番の手順書（runbook）— 撮って送るだけ帳簿

本番：https://torudake-app.com（ABLENET VPS `138.248.65.29`、Ubuntu）
決まり：**VPS の操作は本人が行う。AI は本番に SSH しない。** AI はスクリプトとコマンドを用意するだけ。

| 項目 | 値 |
|---|---|
| ドメイン | `torudake-app.com`（Cloudflare で登録、期限 2027-10-24、自動更新 ON） |
| DNS | Cloudflare：`A @ 138.248.65.29`、**DNS only（灰色の雲）** |
| アプリの場所 | `/var/www/torudake` |
| DB | `torudake`（ユーザー `torudake`、パスワードは VPS の `/root/.torudake-db-password` と `.env` にだけある） |
| バックアップ | `~/backups/torudake/`（デプロイのたびに DB を保存、30日分） |
| 定期実行 | cron の `schedule:run`（毎分） |
| ジョブの常駐 | systemd の `torudake-queue@1`・`torudake-queue@2`（2つ） |

---

## 1. 初回の準備（1回だけ）

### 1.1 サーバーを準備する（VPS で）
```bash
curl -fsSL https://raw.githubusercontent.com/TannaiTomoya/torudake-app/main/docs/server/setup-torudake.sh -o setup-torudake.sh
sudo ADMIN_EMAIL=あなたのメール bash setup-torudake.sh
```
最後に `https://torudake-app.com/login → 200` と出れば完了。途中で止まったら、表示された原因を直してもう一度実行する（何度実行しても壊れない）。

### 1.2 最初の owner を作る（VPS で）
工程2でコマンドを足したあとに行う。
```bash
cd /var/www/torudake && php artisan torudake:create-company "丹内建機サービス" --fiscal-year-end=7
```

### 1.3 自動デプロイを有効にする（Mac で）
GitHub Actions が VPS に SSH するための鍵を、このリポジトリに登録する。鍵は ticket-app で使っていた camp_actions のもの（VPS 側の公開鍵は残してある）。
```bash
gh secret set SSH_HOST --body "138.248.65.29"
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

---

## 2. いつもの更新
1. 手元で `/check` が通る
2. main に push（またはPRをマージ）
3. GitHub Actions：`tests` が通る → `deploy` が VPS で `deploy.sh` を実行（DB のバックアップ → 取得 → 依存 → 組み立て → migrate）
4. 反映後の確認（下のチェックリスト）

手で更新するとき（VPS で）：
```bash
bash /var/www/torudake/deploy.sh
```

### 反映後のチェックリスト
- [ ] https://torudake-app.com/login が開き、鍵マークが付いている
- [ ] ログインできる（2段階認証のコードを含めて）
- [ ] 送る → 原資料の一覧に出る（工程3以降）
- [ ] 下書き → 承認 → 締めナビの件数が減る（工程4以降）
- [ ] `sudo systemctl status 'torudake-queue@*'` で2つとも active

### `.env` を変えたとき
キューの常駐は起動したときの設定のまま動くので、`.env` を変えたら設定を読み直して常駐を立ち上げ直す（`AI_DRIVER`・`ANTHROPIC_API_KEY` など）。
```bash
cd /var/www/torudake && php artisan config:cache && php artisan queue:restart
```
手元（Sail）では `vendor/bin/sail artisan queue:restart` のあと、止まった `queue:work` を起動し直す（2つ動かすときは、ターミナル2つで `vendor/bin/sail artisan queue:work`）。

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
5. パスワードを決める。メールは今 `log` なので、再設定のリンクはログに出る。**メールアドレスごとに一番下のリンク**を使う（前のリンクは使えなくなる。有効なのは60分）。
```bash
grep -ho 'https://torudake-app.com/reset-password/[^" ]*' storage/logs/*.log | tail -8
```
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

---

## 4. ticket-app の片付け（10/31 以降）
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

## 5. サーバーの版（10/24 確認）
- OS：Ubuntu 26.04.1 LTS。PHP は OS 標準の **8.5**、Node は **22**、MySQL は **8.4**（外部の PPA は使わない）
- CI（`tests.yml`）も本番に合わせて PHP 8.5 で回す。開発の Sail は 8.4（あとで 8.5 にそろえる）

### PHP が古いとき
`setup-torudake.sh` が「PHP x.x は古い」と止まったら、OS が古い。Laravel 13 は PHP 8.3 以上が必要。OS を上げるか、作者に相談する。

## 6. あとでやること
- メールを本当に送る（パスワード再設定に必要）：`.env` の `MAIL_*` を送信サービスの値にする。今は `log`（`storage/logs` に書くだけ）
- Cloudflare を Proxied（オレンジ）にする場合：SSL/TLS を Full (strict)、アプリに TrustProxies を入れてから（04 §5.2）
