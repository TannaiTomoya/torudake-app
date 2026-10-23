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
| ジョブの常駐 | systemd の `torudake-queue` |

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
- [ ] `sudo systemctl status torudake-queue` が active

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

## 5. あとでやること
- メールを本当に送る（パスワード再設定に必要）：`.env` の `MAIL_*` を送信サービスの値にする。今は `log`（`storage/logs` に書くだけ）
- Cloudflare を Proxied（オレンジ）にする場合：SSL/TLS を Full (strict)、アプリに TrustProxies を入れてから（04 §5.2）
