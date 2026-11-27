#!/usr/bin/env bash
# Moodle 5.3 LTS -asennus: Ubuntu 24.04 / Linux Mint 22 / Debian 13
# Apache + PHP + MariaDB, paikallinen kehityskäyttö.
# Käyttö:  sudo bash install-moodle.sh
# Asetuksia voi muuttaa ympäristömuuttujilla, esim.
#   sudo SITE_URL=http://192.168.1.50 bash install-moodle.sh
set -euo pipefail

MOODLE_BRANCH="${MOODLE_BRANCH:-MOODLE_503_STABLE}"
SITE_URL="${SITE_URL:-http://localhost}"
MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
DATA_DIR="${DATA_DIR:-/var/moodledata}"
DB_NAME="${DB_NAME:-moodle}"
DB_USER="${DB_USER:-moodleuser}"
DB_PASS="${DB_PASS:-$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 20)}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-Adm1n-$(openssl rand -base64 12 | tr -dc 'A-Za-z0-9' | head -c 10)!}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
SITE_NAME="${SITE_NAME:-Oma Moodle}"
SITE_SHORT="${SITE_SHORT:-moodle}"

[[ $EUID -eq 0 ]] || { echo "Aja rootina: sudo bash $0"; exit 1; }

# www-data ei pääse käyttäjän kotikansioon -> ajetaan neutraalista kansiosta
cd /tmp

echo "==> Asennetaan paketit"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y curl ca-certificates

# Moodle 5.3 vaatii MariaDB 11.4+, jakelun oma paketti on 10.11 -> MariaDB:n virallinen repo
# Linux Mint ym. johdannaiset: kerrotaan repo-skriptille pohjana oleva Ubuntu/Debian
. /etc/os-release
REPO_OS_ARGS=()
if [[ -n "${UBUNTU_CODENAME:-}" && "${ID}" != "ubuntu" ]]; then
  REPO_OS_ARGS=(--os-type=ubuntu --os-version="$UBUNTU_CODENAME")
elif [[ -n "${DEBIAN_CODENAME:-}" && "${ID}" != "debian" ]]; then
  REPO_OS_ARGS=(--os-type=debian --os-version="$DEBIAN_CODENAME")
fi
curl -LsS -o /tmp/mariadb_repo_setup https://r.mariadb.com/downloads/mariadb_repo_setup
bash /tmp/mariadb_repo_setup --mariadb-server-version="mariadb-11.4" "${REPO_OS_ARGS[@]}"
apt-get update
apt-get install -y -o Dpkg::Options::="--force-confold" mariadb-server mariadb-client
systemctl enable --now mariadb
mariadb-upgrade >/dev/null 2>&1 || true
mariadb --version

apt-get install -y apache2 libapache2-mod-php git unzip openssl \
  php php-cli php-mysql php-xml php-mbstring php-curl php-zip php-gd \
  php-intl php-soap php-bcmath ghostscript graphviz aspell

PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "==> PHP $PHPV"
if [[ "$(printf '%s\n' 8.3 "$PHPV" | sort -V | head -1)" != "8.3" ]]; then
  echo "Moodle 5.3 vaatii PHP 8.3+. Tällä jakelulla on $PHPV."
  echo "Kokeile MOODLE_BRANCH=MOODLE_501_STABLE tai uudempaa jakelua."
  exit 1
fi

echo "==> PHP-asetukset"
for sapi in apache2 cli; do
  cat > "/etc/php/$PHPV/$sapi/conf.d/99-moodle.ini" <<EOF
max_input_vars = 5000
memory_limit = 512M
upload_max_filesize = 256M
post_max_size = 256M
max_execution_time = 300
EOF
done

echo "==> Tietokanta"
mariadb <<EOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

echo "==> Moodlen koodi ($MOODLE_BRANCH)"
if [[ ! -d "$MOODLE_DIR/.git" ]]; then
  git clone --depth 1 -b "$MOODLE_BRANCH" https://github.com/moodle/moodle.git "$MOODLE_DIR"
fi
mkdir -p "$DATA_DIR"
chown -R www-data:www-data "$MOODLE_DIR" "$DATA_DIR"
chmod 0770 "$DATA_DIR"

# Moodle 5.1+: web-juuri on public/-alikansiossa
if [[ -d "$MOODLE_DIR/public" ]]; then WEBROOT="$MOODLE_DIR/public"; else WEBROOT="$MOODLE_DIR"; fi
CLI="$WEBROOT/admin/cli"
[[ -f "$CLI/install.php" ]] || CLI="$MOODLE_DIR/admin/cli"

echo "==> Apache"
cat > /etc/apache2/sites-available/moodle.conf <<EOF
<VirtualHost *:80>
    ServerName ${SITE_URL#*://}
    DocumentRoot $WEBROOT
    <Directory $WEBROOT>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php index.html
        FallbackResource /r.php
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/moodle_error.log
    CustomLog \${APACHE_LOG_DIR}/moodle_access.log combined
</VirtualHost>
EOF
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite moodle >/dev/null
a2enmod rewrite >/dev/null
systemctl restart apache2

echo "==> Moodlen asennus (kestää muutaman minuutin)"
if [[ ! -f "$MOODLE_DIR/config.php" ]]; then
  sudo -u www-data php "$CLI/install.php" \
    --non-interactive --agree-license --lang=en \
    --wwwroot="$SITE_URL" --dataroot="$DATA_DIR" \
    --dbtype=mariadb --dbhost=localhost --dbname="$DB_NAME" \
    --dbuser="$DB_USER" --dbpass="$DB_PASS" \
    --fullname="$SITE_NAME" --shortname="$SITE_SHORT" \
    --adminuser="$ADMIN_USER" --adminpass="$ADMIN_PASS" \
    --adminemail="$ADMIN_EMAIL" --supportemail="$ADMIN_EMAIL"
else
  echo "config.php on jo olemassa, ohitetaan asennus."
fi

echo "==> Cron"
echo "* * * * * www-data /usr/bin/php $CLI/cron.php >/dev/null 2>&1" > /etc/cron.d/moodle
chmod 0644 /etc/cron.d/moodle

cat <<EOF

=========================================
 Moodle asennettu: $SITE_URL
 Admin:    $ADMIN_USER / $ADMIN_PASS
 DB:       $DB_NAME / $DB_USER / $DB_PASS
 Koodi:    $MOODLE_DIR
 Data:     $DATA_DIR
 Ota tunnukset talteen!
=========================================
EOF
