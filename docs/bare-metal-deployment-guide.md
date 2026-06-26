# LazyBlog — Deployment Guide

Target stack: Ubuntu 24.04 LTS (or Debian 12) VPS with Caddy + php-fpm.
Adapt the paths if you're on a different distro.

## Fast path: `install-vps.sh`

If you just want a working install in one shot, skip directly to
phases 2–7 by running:

```bash
# As root on a fresh Debian/Ubuntu/Raspbian host (x86_64 or ARM)
curl -fsSL https://raw.githubusercontent.com/hieuha/LazyBlog/main/scripts/install-vps.sh -o install-vps.sh
sudo bash install-vps.sh
```

That single script does everything phases 2–7 below describe:
PHP 8.2 (auto-adds Sury / Ondrej repos when needed), Caddy listening
on `:80`, dedicated `lazyblog` system user, private FPM pool,
interactive prompts for admin password + site title + URL + author +
callsign + timezone, daily backup cron, smoke-test, and a summary
saved to `/var/www/lazyblog/setup-successfully.txt`.

Phase 1 (VPS provisioning) and phase 5 (DNS + TLS) are still manual —
the script can't book a VPS or point your domain.

The phases below remain useful when you need to customize a step, audit
what the installer did, or recover after an incident.

## Phases

1. [Provision the VPS](#1-provision-the-vps)
2. [Install runtime](#2-install-runtime)
3. [Deploy the app](#3-deploy-the-app)
4. [Configure Caddy](#4-configure-caddy)
5. [DNS + TLS](#5-dns--tls)
6. [Set admin password](#6-set-admin-password)
7. [Backups](#7-backups)
8. [Update flow](#8-update-flow)
9. [Logs](#9-logs)
10. [Troubleshooting](#10-troubleshooting)

---

## 1. Provision the VPS

Anything with 1 vCPU + 1 GB RAM + 20 GB disk is plenty for a personal blog.

Tested providers: Hetzner CX22 (€4/mo), DigitalOcean Basic ($4/mo), Vultr Cloud Compute.

```bash
# Initial root-level hardening (run as root)
adduser harry
usermod -aG sudo harry
ssh-copy-id harry@vps.example.com         # from your laptop
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable

# Disable password auth (key auth only)
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart sshd
```

## 2. Install runtime

```bash
sudo apt update
sudo apt install -y \
    php8.2-fpm php8.2-cli \
    php8.2-mbstring php8.2-xml php8.2-zip php8.2-opcache \
    composer \
    git rsync curl

# Caddy 2 (official repo)
curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key \
    | sudo gpg --dearmor -o /usr/share/keyrings/caddy.gpg
curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt \
    | sudo tee /etc/apt/sources.list.d/caddy.list
sudo apt update && sudo apt install -y caddy

systemctl enable --now php8.2-fpm caddy
```

## 3. Deploy the app

```bash
# Use a dedicated unprivileged user for the app
sudo useradd -r -s /usr/sbin/nologin -d /var/www/lazyblog lazyblog
sudo mkdir -p /var/www/lazyblog
sudo chown -R lazyblog:www-data /var/www/lazyblog
sudo -u lazyblog git clone git@github.com:hieuha/LazyBlog.git /var/www/lazyblog

cd /var/www/lazyblog
sudo -u lazyblog composer install --no-dev --optimize-autoloader

# Configure env
sudo -u lazyblog cp .env.example .env
sudo -u lazyblog editor .env       # set SITE_TITLE, SITE_URL, TIMEZONE, etc.
sudo chmod 640 .env
sudo chown lazyblog:www-data .env

# Make content/ writable by php-fpm (runs as www-data on Debian/Ubuntu)
sudo -u lazyblog mkdir -p content/posts
sudo chgrp -R www-data content
sudo chmod -R g+rwX content
```

Set `SESSION_SECURE="true"` in `.env` once you've confirmed TLS is up
(step 5). Until then, sessions can be sniffed over http.

## 4. Configure Caddy

```bash
# Copy + edit the example
sudo cp /var/www/lazyblog/Caddyfile.example /etc/caddy/Caddyfile
sudo editor /etc/caddy/Caddyfile
# Replace blog.example.com with your domain.
# Confirm php-fpm socket path: ls /run/php/*.sock

# Optional: keep php-fpm pool config close to the app
sudo editor /etc/php/8.2/fpm/pool.d/www.conf
#   listen.owner = www-data
#   listen.group = www-data
#   listen.mode  = 0660

sudo systemctl reload php8.2-fpm caddy
sudo caddy validate --config /etc/caddy/Caddyfile
```

## 5. DNS + TLS

Point an `A` record at the VPS IP (and AAAA if IPv6).

```
blog.example.com   A   203.0.113.42
```

Caddy fetches a Let's Encrypt cert on the first request that matches your
hostname. No extra commands needed.

Verify:

```bash
curl -I https://blog.example.com/
# Expected: HTTP/2 200, valid TLS cert, security headers present
```

## 6. Set admin password

```bash
# Interactive (recommended — password never hits shell history or `ps`)
sudo -u lazyblog php /var/www/lazyblog/scripts/hash-password.php

# Or non-interactive (visible in shell history / ps — use only in trusted CI)
sudo -u lazyblog php /var/www/lazyblog/scripts/hash-password.php "your-real-strong-password"

# The script prints a ready-to-paste line — copy it into .env:
#   ADMIN_PASSWORD_HASH="$2y$12$..."

sudo systemctl restart php8.2-fpm    # re-read .env
```

Now log in at `https://blog.example.com/admin/login`. Empty hash = login
disabled, so the admin is dormant until you set this.

## 7. Backups

Daily backup of `content/`. The script defaults to a local timestamped
tarball; pass `--remote` for off-host rsync.

```bash
sudo crontab -u lazyblog -e

# Option A — local tarball under /var/backups/lazyblog/ (simplest)
0 3 * * * /var/www/lazyblog/scripts/backup-content.sh \
    --src /var/www/lazyblog/content \
    --out /var/backups/lazyblog \
    >> /var/log/lazyblog-backup.log 2>&1

# Option B — rsync to a separate host (idempotent — only changed files)
0 3 * * * /var/www/lazyblog/scripts/backup-content.sh \
    --src /var/www/lazyblog/content \
    --remote user@backup-host:/backups/lazyblog/ \
    >> /var/log/lazyblog-backup.log 2>&1
```

The script never auto-deletes old archives — prune them manually when the
backup directory grows too large.

Optionally: `git init` inside `content/` for per-post version history that
survives even if rsync fails:

```bash
cd /var/www/lazyblog/content
sudo -u lazyblog git init
sudo -u lazyblog git add posts/ && sudo -u lazyblog git commit -m "init"
# Then push to a private remote on a schedule.
```

## 8. Update flow

Zero-downtime app update:

```bash
cd /var/www/lazyblog
sudo -u lazyblog git pull
sudo -u lazyblog composer install --no-dev --optimize-autoloader

# php-fpm uses OPcache with validate_timestamps=0 in prod, so reload it
# so changed .php files are picked up immediately.
sudo systemctl reload php8.2-fpm
```

No DB migrations, no asset compile, no container rebuild. Tip: do
`git pull --ff-only` so you fail fast if local changes exist.

## 9. Logs

`install-vps.sh` configures these log destinations:

| What | Where | How to tail |
|------|-------|-------------|
| Caddy request access | `/var/log/caddy/lazyblog-access.log` | `sudo tail -f /var/log/caddy/lazyblog-access.log` |
| Caddy runtime / errors / TLS / startup | systemd journal | `sudo journalctl -u caddy -f` |
| PHP-FPM errors (PHP fatal/warnings, `error_log()`) | systemd journal | `sudo journalctl -u php8.2-fpm -f` |
| Backup cron output | `/var/log/lazyblog-backup.log` | `sudo tail -f /var/log/lazyblog-backup.log` |

Caddy's access log auto-rotates in-process: 10 MB roll size, keep last 5
files (~720 hours). No logrotate config needed. PHP errors land in the
journal because the FPM pool sets `log_errors = On` with no explicit
`error_log` path → goes to stderr → systemd captures it.

Useful one-liners:

```bash
# Top 20 client IPs in the last hour
sudo journalctl -u caddy --since "1 hour ago" | grep -oE 'remote_ip":"[^"]+' | sort | uniq -c | sort -rn | head -20

# Recent 5xx responses
sudo grep -E '"status":5[0-9]{2}' /var/log/caddy/lazyblog-access.log | tail

# PHP fatal errors today
sudo journalctl -u php8.2-fpm --since today | grep -iE "fatal|error"
```

## 10. Troubleshooting

| Symptom | Likely cause + fix |
|---------|-------------------|
| `curl -I /` returns 502 Bad Gateway | php-fpm socket path mismatch. `ls /run/php/*.sock` and update `php_fastcgi` in `Caddyfile`. |
| Admin save fails with "Directory not writable" | `content/` permissions. `sudo chgrp -R www-data content && sudo chmod -R g+rwX content`. |
| Cert never issues, Caddy log shows `acme:` errors | DNS hasn't propagated yet, or `:80` isn't reachable from the internet. Verify with `dig +short blog.example.com` and `curl http://blog.example.com/`. |
| Posts visible but `/admin/login` POST returns 419/403 | Old form session expired. Reload login page (gives a new CSRF token). |
| `Session name cannot be changed` warnings in logs | An output buffering misconfiguration ate the session_start. Make sure no `echo` happens before `index.php` enters. |
| RSS reader shows only excerpt, not full post | Intentional since v1.12.0 — `feed.xml` ships `<description>` only (280-char excerpt), no `<content:encoded>` body. Readers should follow `<link>` to the post page. Keeps the feed small. |
| Editor toolbar icons are empty boxes | CSP is blocking Font Awesome. Confirm `font-src` in CSP includes `https://cdn.jsdelivr.net`. |
| Updates land but pages don't change | OPcache hasn't refreshed. `sudo systemctl reload php8.2-fpm` |

---

## Production-hardening checklist

- [ ] `SESSION_SECURE="true"` in `.env`
- [ ] `display_errors = Off`, `log_errors = On`, `expose_php = Off` in php.ini
- [ ] `opcache.enable = 1`, `opcache.validate_timestamps = 0`
- [ ] `.env` mode 640, owner `lazyblog:www-data`
- [ ] HSTS uncommented in `Caddyfile` after TLS is stable for at least a week
- [ ] Daily backup cron + at least weekly restore test
- [ ] PHP version supported (8.2 EOL is Dec 2026; plan upgrade path now)
- [ ] Fail2ban or Caddy rate-limit plugin on `/admin/login` if exposed publicly
- [ ] DNS CAA record for Let's Encrypt only
