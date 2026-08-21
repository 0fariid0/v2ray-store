# V2Ray Store 13.0.0

Telegram sales bot compatible with the current `0fariid0/3x-ui` client API.

## Fresh install from Git

```bash
sudo -i
git clone https://github.com/0fariid0/v2ray-store.git
cd v2ray-store
bash install.sh
```

One-line fresh install:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/0fariid0/v2ray-store/main/install.sh)
```

## Update an existing installation

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/0fariid0/v2ray-store/main/update.sh)
```

Update is intentionally separate from installation. It does not run `apt`,
reinstall Apache/PHP/MySQL/phpMyAdmin, recreate the database, replace
`baseInfo.php`, or issue a new TLS certificate. It validates the downloaded
source before atomically swapping directories, runs database migrations, then
repairs cron and the secured Telegram webhook.

Default paths:

- Bot: `/var/www/html/v2ray-store`
- Management panel: `/var/www/html/v2ray-store-panel`
- Installer configuration: `/root/confv2raystore/dbrootv2raystore.txt`
- Update backups: `/root/v2raystore_update_backups`

Run `v2ray-store` after installation to open the maintenance menu.
