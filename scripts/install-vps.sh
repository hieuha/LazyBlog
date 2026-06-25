#!/usr/bin/env bash
#
# LazyBlog — bare-metal VPS installer (Debian / Ubuntu / Raspbian, x86_64 + ARM)
#
# Installs and configures everything LazyBlog needs to serve traffic
# WITHOUT Docker:
#   - PHP 8.2 (auto-adds packages.sury.org / ppa:ondrej/php if the
#     distro's default PHP is older — covers Debian 11+, Ubuntu 20.04+,
#     Raspbian Bullseye+, Pi OS Bookworm)
#   - PHP-FPM (private pool running as `lazyblog` user)
#   - Composer + app dependencies
#   - Caddy (HTTP-only by default; switch to HTTPS by editing the
#     Caddyfile and adding your domain + email)
#   - .env with bcrypt admin password hash
#   - Daily backup cron (local tarballs under /var/backups/lazyblog)
#
# Usage (as root):
#   curl -fsSL https://raw.githubusercontent.com/hieuha/LazyBlog/main/scripts/install-vps.sh | sudo bash
#   # …or download first and read it before running:
#   wget https://raw.githubusercontent.com/hieuha/LazyBlog/main/scripts/install-vps.sh
#   sudo bash install-vps.sh
#
# Re-running on an already-installed host updates the code (git pull),
# re-installs composer deps, and reloads services. Existing .env is
# preserved.

set -euo pipefail

# ---------------------------------------------------------------------------
# Config (override via env vars when invoking the script):
#   REPO_URL=... INSTALL_DIR=... bash install-vps.sh
# ---------------------------------------------------------------------------
REPO_URL="${REPO_URL:-https://github.com/hieuha/LazyBlog.git}"
INSTALL_DIR="${INSTALL_DIR:-/var/www/lazyblog}"
APP_USER="${APP_USER:-lazyblog}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/lazyblog}"
LISTEN_ADDR="${LISTEN_ADDR:-:80}"   # ":80" or "blog.example.com" for HTTPS

SRC_DIR="$INSTALL_DIR/src"
ENV_FILE="$SRC_DIR/.env"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
say()  { printf '\033[1;32m▶\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

require_root() {
    if [[ $EUID -ne 0 ]]; then
        die "Run as root (or via sudo)."
    fi
}

detect_distro() {
    if [[ ! -f /etc/os-release ]]; then
        die "Cannot read /etc/os-release — unsupported distro."
    fi
    # shellcheck disable=SC1091
    . /etc/os-release
    # ID values: debian, ubuntu, raspbian (legacy 32-bit Pi OS).
    case "$ID" in
        debian|ubuntu|raspbian) ;;
        *) die "Only Debian, Ubuntu, and Raspbian are supported (detected: $ID)." ;;
    esac
    DISTRO_ID="$ID"
    DISTRO_CODENAME="${VERSION_CODENAME:-}"
    say "Detected $PRETTY_NAME"
}

# ---------------------------------------------------------------------------
# Phase 1: system packages
# ---------------------------------------------------------------------------
install_packages() {
    say "Updating apt index…"
    apt-get update -qq

    say "Installing prerequisites…"
    apt-get install -y -qq \
        ca-certificates curl gnupg apt-transport-https \
        debian-keyring debian-archive-keyring \
        git unzip lsb-release cron

    # Caddy ships from cloudsmith repo on Debian/Ubuntu/Raspbian.
    if ! command -v caddy >/dev/null 2>&1; then
        say "Adding Caddy apt repo…"
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
            | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
        curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
            > /etc/apt/sources.list.d/caddy-stable.list
        apt-get update -qq
    fi

    ensure_php82
    apt-get install -y -qq caddy composer
}

# Make sure PHP 8.2 is installed. If the distro's default repo doesn't carry
# it (Debian 11, Ubuntu 20.04/22.04, Raspbian Bullseye), add Ondřej Surý's
# repo first (packages.sury.org for Debian/Raspbian, ppa:ondrej/php for
# Ubuntu) — that's the maintainer of both the official Debian PHP packages
# and the upstream Ubuntu PPA, so binaries are trustworthy.
ensure_php82() {
    if apt-cache show php8.2-cli >/dev/null 2>&1; then
        say "PHP 8.2 available in current repos — installing…"
    else
        case "$DISTRO_ID" in
            debian|raspbian)
                say "Adding packages.sury.org for PHP 8.2 (codename: ${DISTRO_CODENAME:-?})…"
                curl -fsSL https://packages.sury.org/php/apt.gpg \
                    | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg
                echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${DISTRO_CODENAME} main" \
                    > /etc/apt/sources.list.d/sury-php.list
                ;;
            ubuntu)
                say "Adding ppa:ondrej/php for PHP 8.2…"
                apt-get install -y -qq software-properties-common
                add-apt-repository -y ppa:ondrej/php
                ;;
        esac
        apt-get update -qq
    fi

    # php8.2-intl ships the Normalizer class that composer 2.x (via
    # symfony/string) needs to render its own console output.
    # php8.2-gd is required by the /admin/upload endpoint (image strip +
    # resize + WebP encode).
    # php8.2-imagick powers the series cover Atkinson dither pipeline. Without
    # it, /admin/series still saves title + description; cover upload disables.
    apt-get install -y -qq \
        php8.2-fpm php8.2-cli php8.2-mbstring \
        php8.2-zip php8.2-xml php8.2-curl php8.2-intl php8.2-gd \
        php8.2-imagick

    # Pin the unversioned `php` command to 8.2 so composer + scripts use it.
    if command -v update-alternatives >/dev/null 2>&1; then
        update-alternatives --set php /usr/bin/php8.2 >/dev/null 2>&1 || true
    fi
}

# We always install php8.2 explicitly — pin PHP_VER so all later paths
# (/etc/php/8.2/..., systemctl restart php8.2-fpm) are deterministic.
detect_php_version() {
    PHP_VER="8.2"
    say "Pinned PHP version: $PHP_VER"
}

# ---------------------------------------------------------------------------
# Phase 2: system user + directories
# ---------------------------------------------------------------------------
ensure_user() {
    if id "$APP_USER" >/dev/null 2>&1; then
        say "User $APP_USER already exists"
    else
        say "Creating system user $APP_USER…"
        useradd -r -m -d "$INSTALL_DIR" -s /usr/sbin/nologin "$APP_USER"
    fi
    mkdir -p "$INSTALL_DIR"
    chown "$APP_USER:$APP_USER" "$INSTALL_DIR"
    # Debian's useradd -m defaults to mode 0750 on the home dir, which
    # blocks the `caddy` user from traversing to public/ → 403. Caddy
    # needs the path to be world-traversable.
    chmod 755 "$INSTALL_DIR"
}

# ---------------------------------------------------------------------------
# Phase 3: source code + composer
# ---------------------------------------------------------------------------
deploy_code() {
    if [[ -d "$SRC_DIR/.git" ]]; then
        say "Updating existing checkout (git pull)…"
        sudo -u "$APP_USER" git -C "$SRC_DIR" pull --ff-only
    else
        say "Cloning $REPO_URL → $SRC_DIR"
        sudo -u "$APP_USER" git clone --depth 1 "$REPO_URL" "$SRC_DIR"
    fi

    say "Installing composer dependencies (--no-dev)…"
    # -H so composer's cache goes under the lazyblog HOME instead of root's.
    sudo -H -u "$APP_USER" \
        composer install --no-dev --optimize-autoloader \
        --working-dir="$SRC_DIR" --no-interaction --quiet

    # content/posts/ + content/uploads/ must be writeable by php-fpm
    # (admin save + image upload). Both run as $APP_USER. Caddy serves
    # /uploads/* from content/uploads/ directly so it needs read+x too.
    mkdir -p "$SRC_DIR/content/posts" "$SRC_DIR/content/uploads"
    chown -R "$APP_USER:$APP_USER" "$SRC_DIR/content"
    chmod 755 "$SRC_DIR/content"
    chmod 755 "$SRC_DIR/content/posts"
    chmod 755 "$SRC_DIR/content/uploads"

    # Make CLI helper scripts executable so cron + manual invocations work
    # without the explicit `bash ...` prefix.
    chmod +x "$SRC_DIR/scripts/"*.sh 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Phase 4: .env (admin password + secrets)
# ---------------------------------------------------------------------------
prompt_hidden() {
    local prompt="$1"
    local var
    # Read from /dev/tty so this works when the script itself is being piped
    # (e.g. `curl ... | sudo bash`) and stdin is not the terminal.
    read -r -s -p "$prompt" var </dev/tty
    echo >&2
    printf '%s' "$var"
}

setup_env() {
    if [[ -f "$ENV_FILE" ]]; then
        say ".env already exists at $ENV_FILE — leaving it alone."
        return
    fi

    say "Creating $ENV_FILE from .env.example"
    cp "$SRC_DIR/.env.example" "$ENV_FILE"

    # --- Admin password ---
    local pw pw2
    while :; do
        pw="$(prompt_hidden 'Admin password (input hidden): ')"
        if [[ -z "$pw" ]]; then
            warn "Password cannot be empty."
            continue
        fi
        pw2="$(prompt_hidden 'Confirm:                        ')"
        [[ "$pw" == "$pw2" ]] && break
        warn "Passwords do not match — try again."
    done
    local hash
    hash="$(sudo -u "$APP_USER" php "$SRC_DIR/scripts/hash-password.php" "$pw" | sed -n 's/^ADMIN_PASSWORD_HASH=//p')"
    # Strip surrounding quotes that hash-password.php emits.
    hash="${hash%\"}"
    hash="${hash#\"}"

    # --- Other prompts ---
    local default_host
    default_host="$(hostname -I 2>/dev/null | awk '{print $1}')"
    default_host="${default_host:-localhost}"

    # All reads from /dev/tty so `curl ... | sudo bash` keeps working.
    read -r -p "Site title       [LazyBlog]: " site_title </dev/tty
    site_title="${site_title:-LazyBlog}"
    read -r -p "Site URL         [http://$default_host]: " site_url </dev/tty
    site_url="${site_url:-http://$default_host}"
    read -r -p "Default author   [XV5HP]: " author </dev/tty
    author="${author:-XV5HP}"
    read -r -p "Callsign         [leave blank to skip]: " callsign </dev/tty
    read -r -p "Timezone         [Asia/Saigon]: " tz </dev/tty
    tz="${tz:-Asia/Saigon}"

    # Inline-replace keys via a tiny PHP helper (quoted heredoc → bash
    # won't try to expand bcrypt's $2y$12$… into positional params).
    local edit_php
    edit_php="$(mktemp --suffix=.php)"
    cat > "$edit_php" <<'PHPEOF'
<?php
[$_, $path, $hash, $site, $site_url, $author, $callsign, $tz] = $argv;
$lines = file($path);
foreach ($lines as &$line) {
    if (preg_match('/^ADMIN_PASSWORD_HASH=/', $line)) $line = 'ADMIN_PASSWORD_HASH="' . $hash . '"' . "\n";
    elseif (preg_match('/^SITE_TITLE=/', $line))      $line = 'SITE_TITLE="' . $site . '"' . "\n";
    elseif (preg_match('/^SITE_URL=/', $line))        $line = 'SITE_URL="' . $site_url . '"' . "\n";
    elseif (preg_match('/^DEFAULT_AUTHOR=/', $line))  $line = 'DEFAULT_AUTHOR="' . $author . '"' . "\n";
    elseif (preg_match('/^CALLSIGN=/', $line))        $line = 'CALLSIGN="' . $callsign . '"' . "\n";
    elseif (preg_match('/^TIMEZONE=/', $line))        $line = 'TIMEZONE="' . $tz . '"' . "\n";
    elseif (preg_match('/^SESSION_SECURE=/', $line))  $line = 'SESSION_SECURE="false"' . "\n";
}
file_put_contents($path, implode('', $lines));
PHPEOF
    php "$edit_php" "$ENV_FILE" "$hash" "$site_title" "$site_url" "$author" "$callsign" "$tz" || die "Failed to write .env"
    rm -f "$edit_php"

    # Caddy on Debian/Ubuntu runs as the `caddy` user. PHP-FPM pool runs
    # as `$APP_USER`. .env is read by PHP-FPM only, so owner = app user,
    # group = caddy (so Caddy could read it if ever needed; harmless when
    # not). Fall back to APP_USER:APP_USER if the caddy group is absent.
    chown "$APP_USER:caddy" "$ENV_FILE" 2>/dev/null || chown "$APP_USER:$APP_USER" "$ENV_FILE"
    chmod 640 "$ENV_FILE"
}

# ---------------------------------------------------------------------------
# Phase 5: PHP-FPM private pool
# ---------------------------------------------------------------------------
setup_fpm_pool() {
    local conf="/etc/php/$PHP_VER/fpm/pool.d/lazyblog.conf"
    say "Writing FPM pool config → $conf"
    cat > "$conf" <<EOF
; LazyBlog — private FPM pool. Auto-generated by install-vps.sh.
[lazyblog]
user = $APP_USER
group = $APP_USER
listen = /run/php/lazyblog.sock
listen.owner = caddy
listen.group = caddy
listen.mode = 0660

pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3

php_admin_value[expose_php]       = Off
php_admin_value[display_errors]   = Off
php_admin_value[log_errors]       = On
php_admin_value[session.use_strict_mode] = 1
php_admin_value[opcache.enable]   = 1
; Bump memory_limit for image upload (GD decodes truecolor RGBA into ~4
; bytes/pixel; a 15MP source needs ~60MB just for the buffer). 256M is
; ample headroom + still safe for small VPS.
php_admin_value[memory_limit]     = 256M
php_admin_value[upload_max_filesize] = 12M
php_admin_value[post_max_size]    = 12M
php_admin_value[opcache.validate_timestamps] = 0
EOF

    # Disable the distro's www pool so it doesn't fight us for the socket
    # name / resource budget (optional, but cleaner).
    local www="/etc/php/$PHP_VER/fpm/pool.d/www.conf"
    if [[ -f "$www" ]]; then
        mv "$www" "$www.disabled-by-lazyblog"
        say "Disabled default $www pool"
    fi

    systemctl restart "php${PHP_VER}-fpm"
    say "php${PHP_VER}-fpm restarted"
}

# ---------------------------------------------------------------------------
# Phase 6: Caddy
# ---------------------------------------------------------------------------
setup_caddy() {
    local conf="/etc/caddy/Caddyfile"
    if [[ -f "$conf" ]] && ! grep -q 'lazyblog' "$conf"; then
        cp "$conf" "${conf}.pre-lazyblog"
        say "Backed up existing Caddyfile → ${conf}.pre-lazyblog"
    fi

    say "Writing Caddyfile (listen $LISTEN_ADDR) → $conf"
    cat > "$conf" <<EOF
# LazyBlog — auto-generated by install-vps.sh.
# To enable HTTPS: replace "$LISTEN_ADDR" with your domain (e.g. blog.example.com),
# then \`systemctl reload caddy\`. Caddy will request a Let's Encrypt cert.

$LISTEN_ADDR {
    root * $SRC_DIR/public
    encode gzip

    # Block dotfiles (e.g. .env, .index.json) before anything else handles them.
    @dotfiles path /.*
    respond @dotfiles 404

    # Defense in depth: block PHP / PHTML / PHAR execution under any
    # content-derived URL prefix. The default config only maps /uploads/*
    # to content/uploads/ via handle_path below, but if the operator ever
    # exposes content/posts, content/plugins, or content/*, this rule
    # prevents the file from being executed or its source from being
    # served as static text. PHP-routed prefixes (/posts, /plugin-assets)
    # never need a .php file either, so blocking at the edge is cheaper.
    # path_regexp keeps the extension check AND-able with the prefix
    # check; two bare path lines would OR and match too much.
    @content_php_exec {
        path /uploads/* /posts/* /plugins/* /plugin-assets/* /content/*
        path_regexp content_php_ext \.(php[0-9]*|phtml|phar)\$
    }
    respond @content_php_exec 403

    # /uploads/* is the public surface for admin-UI image uploads.
    # UploadController only ever writes .webp, so the URL prefix should
    # only ever serve images. Reject anything else.
    @uploads_non_image {
        path /uploads/*
        not path_regexp uploads_image_ext \.(webp|png|jpg|jpeg|gif|svg|avif)\$
    }
    respond @uploads_non_image 403

    # User-uploaded images live outside the webroot under
    # \$SRC_DIR/content/uploads/ so the backup cron catches them.
    # Serve directly here so Caddy handles caching/range/etag, not PHP.
    handle_path /uploads/* {
        root * $SRC_DIR/content/uploads
        file_server
    }

    # php_fastcgi handles routing for us — its built-in try_files logic
    # serves real files when they exist and falls through to /index.php
    # otherwise, while keeping REQUEST_URI as the original (so the PHP
    # router sees the actual path, not /index.php).
    php_fastcgi unix//run/php/lazyblog.sock

    @static path *.css *.js *.svg *.png *.jpg *.jpeg *.gif *.webp *.ico *.woff *.woff2 *.ttf
    header @static Cache-Control "public, max-age=2592000, immutable"

    header {
        X-Content-Type-Options "nosniff"
        X-Frame-Options        "SAMEORIGIN"
        Referrer-Policy        "strict-origin-when-cross-origin"
        Permissions-Policy     "geolocation=(), camera=(), microphone=(), payment=()"
        -Server
    }

    # Per-site access log. Caddy emits runtime/error events to the systemd
    # journal by default — tail with \`journalctl -u caddy -f\`. Rotation
    # is handled in-process by the roll_* options so we don't need
    # logrotate.
    log {
        output file /var/log/caddy/lazyblog-access.log {
            roll_size 10mb
            roll_keep 5
            roll_keep_for 720h
        }
        format console
        level INFO
    }

    file_server
}
EOF

    # The caddy apt package creates /var/log/caddy with caddy:caddy
    # ownership. Belt-and-braces: ensure it exists and is writable.
    mkdir -p /var/log/caddy
    chown -R caddy:caddy /var/log/caddy 2>/dev/null || true

    systemctl reload caddy
    say "Caddy reloaded (access log → /var/log/caddy/lazyblog-access.log; errors → journalctl -u caddy)"
}

# ---------------------------------------------------------------------------
# Phase 7: backup cron
# ---------------------------------------------------------------------------
setup_backup_cron() {
    mkdir -p "$BACKUP_DIR"
    chown "$APP_USER:$APP_USER" "$BACKUP_DIR"

    local log="/var/log/lazyblog-backup.log"
    touch "$log"
    chown "$APP_USER:adm" "$log" 2>/dev/null || chown "$APP_USER:$APP_USER" "$log"

    local cron="/etc/cron.d/lazyblog-backup"
    cat > "$cron" <<EOF
# LazyBlog daily content backup — local tarballs.
# Auto-generated by install-vps.sh. Old archives are NOT auto-pruned;
# remove them manually when $BACKUP_DIR gets too large.
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
0 3 * * * $APP_USER $SRC_DIR/scripts/backup-content.sh --src $SRC_DIR/content --out $BACKUP_DIR >> $log 2>&1
EOF
    chmod 644 "$cron"

    # Make sure cron daemon is running so /etc/cron.d/* gets picked up.
    systemctl enable --now cron 2>/dev/null || true

    say "Backup cron registered:"
    printf '    file       %s\n'   "$cron"
    printf '    schedule   %s\n'   "Daily at 03:00 (server local time)"
    printf '    runs as    %s\n'   "$APP_USER"
    printf '    source     %s\n'   "$SRC_DIR/content"
    printf '    output     %s/content-YYYYMMDD-HHMMSS.tar.gz\n' "$BACKUP_DIR"
    printf '    log        %s\n'   "$log"
    printf '    verify     %s\n'   "sudo -u $APP_USER $SRC_DIR/scripts/backup-content.sh --src $SRC_DIR/content --out $BACKUP_DIR"
    printf '    pruning    NONE (manual rm; see %s)\n' "$BACKUP_DIR"
}

# ---------------------------------------------------------------------------
# Phase 8: final smoke test + summary
# ---------------------------------------------------------------------------
final_summary() {
    say "Smoke-testing local HTTP…"
    local smoke_status
    if curl -fsS -o /dev/null "http://127.0.0.1/"; then
        smoke_status="HTTP 200 on http://127.0.0.1/  ✓"
        say "$smoke_status"
    else
        smoke_status="WARN: local curl did not return 200. Check journalctl -u caddy and journalctl -u php${PHP_VER}-fpm."
        warn "$smoke_status"
    fi

    local host_ip
    host_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    local summary_file="$INSTALL_DIR/setup-successfully.txt"

    # Build the summary once, write to a file, also print to stdout.
    local summary
    summary=$(cat <<EOF
LazyBlog installed — $(date -u +'%Y-%m-%dT%H:%M:%SZ')
────────────────────────────────────────────────────────────────────

  URL          http://${host_ip:-<host>}/
  Admin login  http://${host_ip:-<host>}/admin/login   (use the password you set)
  Smoke test   $smoke_status

  Code         $SRC_DIR
  Content      $SRC_DIR/content/posts/
  .env         $ENV_FILE   (mode 640, $APP_USER:caddy)
  FPM pool     /etc/php/$PHP_VER/fpm/pool.d/lazyblog.conf
  Caddy site   /etc/caddy/Caddyfile

  Backup job
    cron file  /etc/cron.d/lazyblog-backup
    schedule   Daily at 03:00 (server local time)
    runs as    $APP_USER
    output     $BACKUP_DIR/content-YYYYMMDD-HHMMSS.tar.gz
    log        /var/log/lazyblog-backup.log
    pruning    NONE — old archives must be removed manually

  Next steps
  • Point DNS at this host, then edit /etc/caddy/Caddyfile and replace
    "$LISTEN_ADDR" with your domain to enable HTTPS (Caddy will auto-issue
    a TLS certificate from LetsEncrypt).
  • After HTTPS works, set SESSION_SECURE=true in $ENV_FILE
    and \`systemctl restart php${PHP_VER}-fpm\`.
  • Run the backup once manually to verify:
      sudo -u $APP_USER $SRC_DIR/scripts/backup-content.sh \\
          --src $SRC_DIR/content --out $BACKUP_DIR

  This summary is saved to $summary_file for later reference.
────────────────────────────────────────────────────────────────────
EOF
)

    printf '\n%s\n' "$summary"
    printf '%s\n' "$summary" > "$summary_file"
    chmod 644 "$summary_file"
    chown root:root "$summary_file" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
main() {
    require_root
    detect_distro
    install_packages
    detect_php_version
    ensure_user
    deploy_code
    setup_env
    setup_fpm_pool
    setup_caddy
    setup_backup_cron
    final_summary
}

main "$@"
