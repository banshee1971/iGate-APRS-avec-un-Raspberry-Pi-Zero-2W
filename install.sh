#!/bin/bash
# =====================================================================
#  Kit de déploiement - iGate APRS sur Raspberry Pi Zero 2W + RTL-SDR
# =====================================================================
#  Installation (sur le Pi, en SSH) :
#     git clone https://github.com/banshee1971/iGate-APRS-avec-un-Raspberry-Pi-Zero-2W.git
#     cd iGate-APRS-avec-un-Raspberry-Pi-Zero-2W
#     sudo ./install.sh
#  Le script demande d'abord le nom d'utilisateur et le mot de passe de la
#  page /admin, puis ouvrez  http://<adresse-du-pi>/admin  pour tout configurer.
#
#  Mise à jour du kit (vos réglages sont conservés) :
#     git pull && sudo ./install.sh --skip-upgrade
#
#  Options :
#     --skip-upgrade       ne pas faire « apt upgrade » (plus rapide)
#     --rebuild-direwolf   recompiler Direwolf même s'il est déjà installé
#     --reset-admin        redemander le compte de la page /admin
#     --help               afficher cette aide
#  Sans clavier : sudo ADMIN_USER=admin ADMIN_PASSWORD=... ./install.sh
#  (sinon un mot de passe aléatoire est écrit dans /root/aprs-admin-password.txt)
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
        -h|--help) sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Option inconnue : $arg (voir --help)"; exit 1 ;;
    esac
done

if [ "$(id -u)" -ne 0 ]; then
    echo "Lancez ce script avec sudo :  sudo ./install.sh"
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
        echo "=== Compte de la page de configuration /admin ==="
        while true; do
            printf "Nom d'utilisateur [admin] : " >&3
            read -r -u 3 ADMIN_USER
            ADMIN_USER="${ADMIN_USER:-admin}"
            [[ "$ADMIN_USER" =~ ^[A-Za-z0-9._-]{1,32}$ ]] && break
            echo "  Lettres, chiffres, point, tiret ou souligné seulement." >&3
        done
        while true; do
            printf "Mot de passe (8 caractères minimum) : " >&3
            read -r -s -u 3 ADMIN_PASSWORD; echo >&3
            if [ "${#ADMIN_PASSWORD}" -lt 8 ]; then echo "  Trop court." >&3; continue; fi
            printf "Confirmez le mot de passe : " >&3
            read -r -s -u 3 ADMIN_CONFIRM; echo >&3
            [ "$ADMIN_PASSWORD" = "$ADMIN_CONFIRM" ] && break
            echo "  Les deux mots de passe ne correspondent pas." >&3
        done
        unset ADMIN_CONFIRM
        exec 3>&-
        echo "Merci ! L'installation peut maintenant se faire sans vous."
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
    gettext-base curl rsync
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

install -m 644 "$KIT_DIR/templates/aprs-admin.path" "$KIT_DIR/templates/aprs-admin.service" /etc/systemd/system/
install -m 644 "$KIT_DIR/templates/99-rtlsdr-restart.rules" /etc/udev/rules.d/
udevadm control --reload-rules
systemctl daemon-reload
systemctl enable --now aprs-admin.path
ok "aprs-apply-config installé, aprs-admin.path actif"

# ---------------------------------------------------------------------
step "5/9 Interface web et base de données"
# ---------------------------------------------------------------------
install -d -o "$IGATE_USER" -g www-data -m 755 /var/www/aprs
cp -r "$KIT_DIR"/web/. /var/www/aprs/
chown -R "$IGATE_USER:www-data" /var/www/aprs/index.php /var/www/aprs/map-offline.php \
    /var/www/aprs/admin /var/www/aprs/lib
find /var/www/aprs/admin /var/www/aprs/lib -type d -exec chmod 755 {} +
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
chown -R "$IGATE_USER:www-data" /var/www/aprs/vendor 2>/dev/null || true

# ---------------------------------------------------------------------
step "6/9 Parser et scripts utilitaires"
# ---------------------------------------------------------------------
install -o "$IGATE_USER" -g "$IGATE_USER" -m 755 "$KIT_DIR/parser/parse_direwolf.py" "$IGATE_HOME/"
for script in "$KIT_DIR"/scripts/*.sh; do
    install -o "$IGATE_USER" -g "$IGATE_USER" -m 755 "$script" "$IGATE_HOME/"
done
install -d -o "$IGATE_USER" -g "$IGATE_USER" -m 755 "$IGATE_HOME/backups" "$IGATE_HOME/direwolf_logs"
ok "Scripts copiés dans $IGATE_HOME"

# ---------------------------------------------------------------------
step "7/9 Nginx"
# ---------------------------------------------------------------------
PHP_FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -n1 || true)"
if [ -z "$PHP_FPM_SOCK" ]; then
    PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    PHP_FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
fi
DNS_RESOLVERS="$(awk '/^nameserver/ && $2 !~ /:/ {print $2}' /etc/resolv.conf | head -n2 | tr '\n' ' ')1.1.1.1"
export PHP_FPM_SOCK DNS_RESOLVERS
envsubst '${PHP_FPM_SOCK} ${DNS_RESOLVERS}' \
    < "$KIT_DIR/templates/nginx-aprs-igate.conf.tmpl" > /etc/nginx/sites-available/aprs-igate
ln -sf /etc/nginx/sites-available/aprs-igate /etc/nginx/sites-enabled/aprs-igate
rm -f /etc/nginx/sites-enabled/default
nginx -t
PHP_FPM_SERVICE="$(basename "$PHP_FPM_SOCK" .sock)"
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
echo " ✅ Installation terminée !"
echo
echo " Ouvrez la page de configuration dans votre navigateur :"
for ip in $IPS; do
    echo "     http://$ip/admin"
done
echo "     http://$(hostname)/admin"
echo
if [ "$ADMIN_MODE" = random ]; then
    echo " Identifiants : sudo cat $RANDOM_PW_FILE"
    echo " (changez-les dans /admin -> Compte, ou avec : sudo aprs-admin-passwd)"
else
    echo " Connectez-vous avec le compte choisi au début de l'installation"
    echo " (oublié ? sudo aprs-admin-passwd)."
fi
echo
echo " Entrez votre indicatif et votre position, puis cliquez sur"
echo " « Enregistrer et appliquer » : l'iGate démarrera tout seul."
if [ "$REBOOT_NEEDED" = yes ]; then
    echo
    echo " ⚠️  Redémarrez le Pi d'abord :  sudo reboot"
fi
echo "======================================================"
