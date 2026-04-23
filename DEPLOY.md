# Deploy VPS (balance.stevedavila.com)

## Variables de entorno

Bot (Python):
- TELEGRAM_BOT_TOKEN
- OPENAI_API_KEY
- OPENAI_EXTRACT_MODEL (opcional)
- OPENAI_TRANSCRIBE_MODEL (opcional)
- TZ (recomendado: America/Lima)
- DATABASE_URL (ej: postgresql://USER:PASS@127.0.0.1:5432/balancesteve)

Web (PHP):
- WEB_DB_DSN (ej: pgsql:host=127.0.0.1;port=5432;dbname=balancesteve)
- WEB_DB_USER
- WEB_DB_PASS

## Base de datos

1) Crear BD y usuario (ejemplo):

```sql
CREATE DATABASE balancesteve;
```

2) Restaurar tu dump actual (si lo usas) y luego aplicar migración:

```bash
psql -d balancesteve -f bot_smart_data.sql
psql -d balancesteve -f db_migrations/001_companies_multiempresa.sql
```

## Código en /var/www/balancesteve

```bash
sudo mkdir -p /var/www/balancesteve
sudo chown -R $USER:$USER /var/www/balancesteve
cd /var/www/balancesteve
git clone <TU_REPO_GITHUB> .
```

## Bot (systemd)

```bash
cd /var/www/balancesteve
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
```

Crear archivo `/etc/systemd/system/balancesteve-bot.service`:

```ini
[Unit]
Description=BalanceSteve Telegram Bot
After=network.target

[Service]
WorkingDirectory=/var/www/balancesteve
EnvironmentFile=/var/www/balancesteve/.env
ExecStart=/var/www/balancesteve/.venv/bin/python /var/www/balancesteve/bot.py
Restart=always
User=www-data

[Install]
WantedBy=multi-user.target
```

Activar:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now balancesteve-bot
sudo systemctl status balancesteve-bot
```

## Web (Nginx)

Raíz web: `/var/www/balancesteve/finweb/public`

Server block de ejemplo:

```nginx
server {
  server_name balance.stevedavila.com;
  root /var/www/balancesteve/finweb/public;
  index index.php;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }

  location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
  }
}
```

Certificado (LetsEncrypt):

```bash
sudo certbot --nginx -d balance.stevedavila.com
```

