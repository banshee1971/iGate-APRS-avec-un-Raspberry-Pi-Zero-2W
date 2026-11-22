#!/bin/bash
# =====================================================================
#  Kit de déploiement - iGate APRS sur Raspberry Pi Zero 2W + RTL-SDR
#  Deployment kit - APRS iGate on Raspberry Pi Zero 2W + RTL-SDR
# =====================================================================
#  Installation (sur le Pi, en SSH / on the Pi, over SSH) :
#     git clone https://github.com/banshee1971/iGate-APRS-avec-un-Raspberry-Pi-Zero-2W.git
#     cd iGate-APRS-avec-un-Raspberry-Pi-Zero-2W
#     sudo ./install.sh
#  Le script demande d'abord le nom d'utilisateur et le mot de passe de la
#  page /admin, puis ouvrez  http://<adresse-du-pi>/admin  pour tout configurer.
#  The script first asks for the /admin page username and password, then
#  open  http://<pi-address>/admin  to configure everything.
#
#  Mise à jour du kit (vos réglages sont conservés) :
#  Updating the kit (your settings are kept):
#     git pull && sudo ./install.sh --skip-upgrade
#
#  Options :
#     --skip-upgrade       ne pas faire « apt upgrade » (plus rapide)
#                          skip "apt upgrade" (faster)
#     --rebuild-direwolf   recompiler Direwolf même s'il est déjà installé
#                          rebuild Direwolf even if already installed
#     --reset-admin        redemander le compte de la page /admin
#                          ask again for the /admin page account
#     --help               afficher cette aide / show this help
#  Sans clavier / Unattended : sudo ADMIN_USER=admin ADMIN_PASSWORD=... ./install.sh
#  (sinon un mot de passe aléatoire est écrit dans /root/aprs-admin-password.txt)
#  (otherwise a random password is written to /root/aprs-admin-password.txt)
# =====================================================================
set -euo pipefail

KIT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE=/var/log/aprs-igate-install.log
SKIP_UPGRADE=no
REBUILD_DIREWOLF=no
REBOOT_NEEDED=no
RESET_ADMIN=no
AUTH_FILE=/var/lib/aprs-igate/admin-auth.json
RANDOM_PW_FILE=/root/aprs-admin-password.txt

for arg in "$@"; do
    case "$arg" in
        --skip-upgrade) SKIP_UPGRADE=yes ;;
        --rebuild-direwolf) REBUILD_DIREWOLF=yes ;;
        --reset-admin) RESET_ADMIN=yes ;;
        -h|--help) sed -n '2,29p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Option inconnue / Unknown option : $arg (voir / see --help)"; exit 1 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Lancez ce script avec sudo / Run this script with sudo :  sudo ./install.sh"
    exit 1
fi

# Utilisateur qui fait tourner l'iGate (celui qui a lancé sudo, « pi » dans le guide)
IGATE_USER="${IGATE_USER:-${SUDO_USER:-pi}}"
if [ "$IGATE_USER" = "root" ] || ! id "$IGATE_USER" >/dev/null 2>&1; then
    echo "Utilisateur « $IGATE_USER » invalide. Lancez avec sudo depuis votre compte (ex.: pi),"
    echo "ou précisez-le :  sudo IGATE_USER=pi ./install.sh"
    exit 1
fi
IGATE_HOME="$(getent passwd "$IGATE_USER" | cut -d: -f6)"

# ---------------------------------------------------------------------
#  Compte de la page /admin : demandé tout de suite, pour ne pas attendre
#  la fin de l'installation (le mot de passe n'est jamais écrit au journal)
# ---------------------------------------------------------------------
ADMIN_USER="${ADMIN_USER:-}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
ADMIN_MODE=keep
if [ ! -s "$AUTH_FILE" ] || [ "$RESET_ADMIN" = yes ]; then
    if [ -n "$ADMIN_PASSWORD" ]; then
        ADMIN_MODE=env
    elif { exec 3<>/dev/tty; } 2>/dev/null; then
        ADMIN_MODE=env
        echo
        echo "=== Compte de la page de configuration /admin / Configuration page account ==="
        while true; do
            printf "Nom d'utilisateur admin / Admin username [admin] : " >&3
            read -r -u 3 ADMIN_USER
            ADMIN_USER="${ADMIN_USER:-admin}"
            [[ "$ADMIN_USER" =~ ^[A-Za-z0-9._-]{1,32}$ ]] && break
            echo "  Lettres, chiffres, point, tiret ou souligné seulement." >&3
            echo "  Letters, digits, dot, hyphen or underscore only." >&3
        done
        while true; do
            printf "Mot de passe / Password (8 caractères minimum / 8 characters minimum) : " >&3
            read -r -s -u 3 ADMIN_PASSWORD; echo >&3
            if [ "${#ADMIN_PASSWORD}" -lt 8 ]; then echo "  Trop court / Too short." >&3; continue; fi
            printf "Confirmez le mot de passe / Confirm password : " >&3
            read -r -s -u 3 ADMIN_CONFIRM; echo >&3
            [ "$ADMIN_PASSWORD" = "$ADMIN_CONFIRM" ] && break
            echo "  Les deux mots de passe ne correspondent pas / Passwords do not match." >&3
        done
        unset ADMIN_CONFIRM
        exec 3>&-
        echo "Merci ! L'installation peut maintenant se faire sans vous."
        echo "Thanks! The installation can now continue without you."
    else
        ADMIN_MODE=random
    fi
fi

exec > >(tee -a "$LOG_FILE") 2>&1

step() { echo; echo "==> $*"; }
ok()   { echo "    ✓ $*"; }
warn() { echo "    ⚠️  $*"; }

echo "======================================================"
echo " Installation iGate APRS - $(date '+%Y-%m-%d %H:%M')"
echo " Utilisateur : $IGATE_USER ($IGATE_HOME)"
echo " Journal     : $LOG_FILE"
echo "======================================================"

# ---------------------------------------------------------------------
step "1/9 Paquets système (cela peut être long sur un Pi Zero)"
# ---------------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
apt-get update
if [ "$SKIP_UPGRADE" = no ]; then
    apt-get -y upgrade
fi
apt-get install -y \
    git cmake build-essential libasound2-dev libudev-dev libhamlib-dev libgps-dev \
    rtl-sdr sox alsa-utils usbutils \
    nginx php-fpm php-cli php-sqlite3 sqlite3 python3 \
    gettext-base curl rsync dnsmasq-base
ok "Paquets installés"

# ---------------------------------------------------------------------
step "2/9 RTL-SDR : désactivation du pilote DVB"
# ---------------------------------------------------------------------
cat > /etc/modprobe.d/blacklist-rtl.conf <<'EOF'
# Le pilote TV (DVB) bloque l'accès du RTL-SDR à rtl_fm
blacklist dvb_usb_rtl28xxu
blacklist rtl2832
blacklist rtl2830
EOF
if lsmod | grep -q '^dvb_usb_rtl28xxu'; then
    if rmmod dvb_usb_rtl28xxu 2>/dev/null; then
        ok "Pilote DVB déchargé"
    else
        REBOOT_NEEDED=yes
        warn "Pilote DVB encore chargé : un redémarrage sera nécessaire"
    fi
fi
usermod -a -G plugdev,dialout,systemd-journal "$IGATE_USER"
ok "$IGATE_USER ajouté aux groupes plugdev, dialout, systemd-journal"
# La page /admin -> Direwolf lit le journal de l'iGate
usermod -a -G systemd-journal www-data

# ---------------------------------------------------------------------
step "3/9 Direwolf"
# ---------------------------------------------------------------------
if [ -x /usr/local/bin/direwolf ] && [ "$REBUILD_DIREWOLF" = no ]; then
    ok "Déjà installé ($(/usr/local/bin/direwolf -v 2>&1 | head -n1 || true)) — --rebuild-direwolf pour recompiler"
else
    SRC=/usr/local/src/direwolf
    if [ -d "$SRC/.git" ]; then
        git -C "$SRC" fetch --tags --quiet
        git -C "$SRC" pull --ff-only --quiet || true
    else
        git clone https://github.com/wb2osz/direwolf.git "$SRC"
    fi
    if [ -n "${DIREWOLF_REF:-}" ]; then
        git -C "$SRC" checkout --quiet "$DIREWOLF_REF"
    fi
    mkdir -p "$SRC/build"
    cd "$SRC/build"
    cmake ..
    # -j2 : le Pi Zero 2W n'a que 512 Mo de RAM
    make -j2
    make install
    cd "$KIT_DIR"
    ok "Direwolf compilé et installé"
fi

# ---------------------------------------------------------------------
step "4/9 Outil de configuration (/admin -> aprs-apply-config)"
# ---------------------------------------------------------------------
install -d -m 755 /etc/aprs-igate /usr/local/share/aprs-igate/templates
cat > /etc/aprs-igate/install.env <<EOF
# Écrit par install.sh — utilisateur qui fait tourner l'iGate
IGATE_USER=$IGATE_USER
IGATE_HOME=$IGATE_HOME
EOF
install -m 644 "$KIT_DIR"/templates/*.tmpl "$KIT_DIR"/templates/99-rtlsdr-restart.rules \
    /usr/local/share/aprs-igate/templates/
install -m 644 "$KIT_DIR/web/lib/settings.schema.json" /usr/local/share/aprs-igate/
install -m 755 "$KIT_DIR/backend/aprs-apply-config" /usr/local/sbin/aprs-apply-config
install -m 755 "$KIT_DIR/backend/aprs-admin-passwd" /usr/local/sbin/aprs-admin-passwd
install -m 755 "$KIT_DIR/backend/aprs-audio-tap" /usr/local/bin/aprs-audio-tap
install -m 755 "$KIT_DIR/backend/aprs-network" /usr/local/sbin/aprs-network

# État partagé avec l'interface web (www-data y écrit les réglages et demandes)
install -d -o root -g www-data -m 2775 /var/lib/aprs-igate
install -d -o www-data -g www-data -m 2775 /var/lib/aprs-igate/queue
touch /var/lib/aprs-igate/apply.log
chmod 644 /var/lib/aprs-igate/apply.log

case "$ADMIN_MODE" in
    env)
        ADMIN_USER="${ADMIN_USER:-admin}" ADMIN_PASSWORD="$ADMIN_PASSWORD"             /usr/local/sbin/aprs-admin-passwd
        ;;
    random)
        /usr/local/sbin/aprs-admin-passwd --random "$RANDOM_PW_FILE"
        ;;
    keep)
        ok "Compte /admin existant conservé (--reset-admin pour le changer)"
        ;;
esac
unset ADMIN_PASSWORD

install -m 644 "$KIT_DIR/templates/aprs-admin.path" "$KIT_DIR/templates/aprs-admin.service" \
    "$KIT_DIR/templates/aprs-net-fallback.service" "$KIT_DIR/templates/aprs-net-fallback.timer" /etc/systemd/system/
install -m 644 "$KIT_DIR/templates/99-rtlsdr-restart.rules" /etc/udev/rules.d/
udevadm control --reload-rules
systemctl daemon-reload
systemctl enable --now aprs-admin.path
# Profil réseau de secours (page /admin -> Réseau), vérifié 3 min après le démarrage
systemctl enable aprs-net-fallback.timer
ok "aprs-apply-config installé, aprs-admin.path actif"

# ---------------------------------------------------------------------
step "5/9 Interface web et base de données"
# ---------------------------------------------------------------------
install -d -o "$IGATE_USER" -g www-data -m 755 /var/www/aprs
cp -r "$KIT_DIR"/web/. /var/www/aprs/
chown -R "$IGATE_USER:www-data" /var/www/aprs/index.php /var/www/aprs/map-offline.php \
    /var/www/aprs/admin /var/www/aprs/lib /var/www/aprs/lang
find /var/www/aprs/admin /var/www/aprs/lib /var/www/aprs/lang -type d -exec chmod 755 {} +
find /var/www/aprs -maxdepth 3 -name '*.php' -exec chmod 644 {} +
chmod 644 /var/www/aprs/lib/settings.schema.json

# Cache des tuiles : nginx (www-data) y enregistre les tuiles consultées
install -d -o www-data -g www-data -m 2775 /var/www/aprs/tiles

sudo -u "$IGATE_USER" sqlite3 /var/www/aprs/packets.db <<'EOF'
CREATE TABLE IF NOT EXISTS packets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    callsign TEXT NOT NULL,
    raw_packet TEXT NOT NULL,
    frequency TEXT,
    decoded_info TEXT,
    latitude REAL,
    longitude REAL
);
CREATE INDEX IF NOT EXISTS idx_timestamp ON packets(timestamp);
CREATE INDEX IF NOT EXISTS idx_callsign ON packets(callsign);
CREATE INDEX IF NOT EXISTS idx_coordinates ON packets(latitude, longitude);
EOF
chown "$IGATE_USER:www-data" /var/www/aprs/packets.db
chmod 664 /var/www/aprs/packets.db
ok "Base de données prête (/var/www/aprs/packets.db)"

# Leaflet en local : la carte fonctionne même sans Internet (AREDN)
LEAFLET_URL="https://unpkg.com/leaflet@1.9.4/dist"
LEAFLET_DIR=/var/www/aprs/vendor/leaflet
if [ ! -f "$LEAFLET_DIR/leaflet.js" ]; then
    mkdir -p "$LEAFLET_DIR/images"
    if curl -fsSL -o "$LEAFLET_DIR/leaflet.js" "$LEAFLET_URL/leaflet.js" \
       && curl -fsSL -o "$LEAFLET_DIR/leaflet.css" "$LEAFLET_URL/leaflet.css"; then
        for img in layers.png layers-2x.png marker-icon.png marker-icon-2x.png marker-shadow.png; do
            curl -fsSL -o "$LEAFLET_DIR/images/$img" "$LEAFLET_URL/images/$img" || true
        done
        ok "Leaflet installé localement"
    else
        rm -f "$LEAFLET_DIR/leaflet.js" "$LEAFLET_DIR/leaflet.css"
        warn "Téléchargement de Leaflet impossible : la carte utilisera Internet (CDN)"
    fi
fi
# Symboles APRS d'aprs.fi (github.com/hessu/aprs-symbols, libres pour les
# applications APRS avec mention de la source) : mêmes icônes que sur aprs.fi
SYM_URL="https://raw.githubusercontent.com/hessu/aprs-symbols/master/png"
SYM_DIR=/var/www/aprs/vendor/aprs-symbols
if [ ! -f "$SYM_DIR/aprs-symbols-24-2@2x.png" ]; then
    mkdir -p "$SYM_DIR"
    for t in 0 1 2; do
        curl -fsSL -o "$SYM_DIR/aprs-symbols-24-$t@2x.png" "$SYM_URL/aprs-symbols-24-$t@2x.png" || rm -f "$SYM_DIR/aprs-symbols-24-$t@2x.png"
    done
    if [ -f "$SYM_DIR/aprs-symbols-24-2@2x.png" ]; then ok "Symboles APRS installés"; else warn "Symboles APRS non téléchargés : des emojis seront utilisés"; fi
fi
chown -R "$IGATE_USER:www-data" /var/www/aprs/vendor 2>/dev/null || true

# ---------------------------------------------------------------------
step "6/9 Parser et scripts utilitaires"
# ---------------------------------------------------------------------
install -o "$IGATE_USER" -g "$IGATE_USER" -m 755 "$KIT_DIR/parser/parse_direwolf.py" "$IGATE_HOME/"
install -o "$IGATE_USER" -g "$IGATE_USER" -m 644 "$KIT_DIR/parser/aprs_decode.py" "$IGATE_HOME/"
for script in "$KIT_DIR"/scripts/*.sh; do
    install -o "$IGATE_USER" -g "$IGATE_USER" -m 755 "$script" "$IGATE_HOME/"
done
install -d -o "$IGATE_USER" -g "$IGATE_USER" -m 755 "$IGATE_HOME/backups" "$IGATE_HOME/direwolf_logs"
ok "Scripts copiés dans $IGATE_HOME"
# Mise à jour : relancer le parser s'il tourne déjà, pour charger le nouveau code
systemctl try-restart aprs-parser.service 2>/dev/null || true

# ---------------------------------------------------------------------
step "7/9 Nginx"
# ---------------------------------------------------------------------
# Service et socket PHP-FPM d'après la version installée (php8.2-fpm sur
# Bookworm, php8.4-fpm sur Trixie). Attention : /run/php/php-fpm.sock est un
# simple lien générique, il n'y a pas de service « php-fpm ».
PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_FPM_SERVICE="php${PHP_VER}-fpm"
if ! systemctl list-unit-files "$PHP_FPM_SERVICE.service" | grep -q "$PHP_FPM_SERVICE"; then
    PHP_FPM_SERVICE="$(systemctl list-unit-files 'php*-fpm.service' --no-legend | awk '{print $1}' \
        | grep -E '^php[0-9.]+-fpm\.service$' | sort -V | tail -n1 | sed 's/\.service$//')"
fi
[ -n "$PHP_FPM_SERVICE" ] || { echo "Service PHP-FPM introuvable (paquet php-fpm installé ?)"; exit 1; }
PHP_FPM_SOCK="/run/php/${PHP_FPM_SERVICE}.sock"
DNS_RESOLVERS="$(awk '/^nameserver/ && $2 !~ /:/ {print $2}' /etc/resolv.conf | head -n2 | tr '\n' ' ')1.1.1.1"
export PHP_FPM_SOCK DNS_RESOLVERS
envsubst '${PHP_FPM_SOCK} ${DNS_RESOLVERS}' \
    < "$KIT_DIR/templates/nginx-aprs-igate.conf.tmpl" > /etc/nginx/sites-available/aprs-igate
ln -sf /etc/nginx/sites-available/aprs-igate /etc/nginx/sites-enabled/aprs-igate
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now "$PHP_FPM_SERVICE" nginx
# Redémarrer PHP pour qu'il profite du groupe systemd-journal (page Direwolf)
systemctl restart "$PHP_FPM_SERVICE" nginx
ok "Nginx configuré (PHP : $PHP_FPM_SOCK, DNS : $DNS_RESOLVERS)"

# ---------------------------------------------------------------------
step "8/9 Application des réglages"
# ---------------------------------------------------------------------
/usr/local/sbin/aprs-apply-config apply || warn "Voir /var/lib/aprs-igate/apply.log"

# ---------------------------------------------------------------------
step "9/9 Vérifications"
# ---------------------------------------------------------------------
RTL_IDS='0bda:283[28]|0413:6680|0413:6f0f|0458:707f|0ccd:00(a9|b3|d3|e0)|185b:06[25]0|1b80:d39[345d]|1d19:110[123]|1f4d:[bc]803'
SDR_LINE="$(lsusb | grep -E "ID ($RTL_IDS)" | head -n1 || true)"
if [ -n "$SDR_LINE" ]; then
    ok "Dongle RTL-SDR détecté : ${SDR_LINE#*ID }"
else
    warn "Dongle RTL-SDR non détecté (branché ? câble de données ?)"
fi
for unit in nginx aprs-admin.path; do
    if systemctl is-active --quiet "$unit"; then ok "$unit actif"; else warn "$unit inactif"; fi
done

IPS="$(hostname -I 2>/dev/null | tr ' ' '\n' | grep -v ':' | grep -v '^$' || true)"
echo
echo "======================================================"
echo " ✅ Installation terminée ! / Installation complete!"
echo
echo " Ouvrez la page de configuration dans votre navigateur :"
echo " Open the configuration page in your browser:"
for ip in $IPS; do
    echo "     http://$ip/admin"
done
echo "     http://$(hostname)/admin"
echo
if [ "$ADMIN_MODE" = random ]; then
    echo " Identifiants / Credentials : sudo cat $RANDOM_PW_FILE"
    echo " (changez-les dans /admin -> Compte, ou avec : sudo aprs-admin-passwd)"
    echo " (change them in /admin -> Account, or with: sudo aprs-admin-passwd)"
else
    echo " Connectez-vous avec le compte choisi au début de l'installation"
    echo " (oublié ? sudo aprs-admin-passwd)."
    echo " Log in with the account chosen at the start of the installation"
    echo " (forgotten? sudo aprs-admin-passwd)."
fi
echo
echo " Entrez votre indicatif et votre position, puis cliquez sur"
echo " « Enregistrer et appliquer » : l'iGate démarrera tout seul."
echo " Enter your callsign and position, then click"
echo " \"Save and apply\": the iGate will start on its own."
if [ "$REBOOT_NEEDED" = yes ]; then
    echo
    echo " ⚠️  Redémarrez le Pi d'abord / Reboot the Pi first :  sudo reboot"
fi
echo "======================================================"
