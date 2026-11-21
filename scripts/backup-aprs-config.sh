#!/bin/bash
# Script de backup automatique APRS iGate
# Sauvegarde les réglages (/admin), les fichiers générés et la base de données.
# Lancé par cron (root) selon la fréquence choisie dans /admin, ou à la main :
#   sudo ~/backup-aprs-config.sh

[ -r /etc/aprs-igate/install.env ] && . /etc/aprs-igate/install.env
IGATE_USER="${IGATE_USER:-pi}"
IGATE_HOME="${IGATE_HOME:-/home/$IGATE_USER}"
SUDO=""; [ "$(id -u)" -ne 0 ] && SUDO="sudo"

# Configuration
BACKUP_DIR="$IGATE_HOME/backups"
DATE=$(date +%Y-%m-%d_%H-%M-%S)
BACKUP_NAME="aprs-igate-backup-$DATE"
BACKUP_PATH="$BACKUP_DIR/$BACKUP_NAME"
RETENTION_DAYS=$(/usr/local/sbin/aprs-apply-config get backup_retention_days 2>/dev/null || echo 90)

mkdir -p "$BACKUP_PATH"

echo "=========================================="
echo "Backup APRS iGate - $DATE"
echo "=========================================="

# 1. Réglages de l'interface /admin (la source de tout le reste)
echo "✓ Sauvegarde des réglages /admin..."
cp /var/lib/aprs-igate/settings.json "$BACKUP_PATH/" 2>/dev/null || echo "  (aucun réglage enregistré)"

# 2. Configuration Direwolf (générée)
echo "✓ Sauvegarde configuration Direwolf..."
cp "$IGATE_HOME/direwolf.conf" "$BACKUP_PATH/" 2>/dev/null

# 3. Base de données APRS
echo "✓ Sauvegarde base de données..."
sqlite3 /var/www/aprs/packets.db ".backup '$BACKUP_PATH/packets.db'"

# 4. Configuration Nginx
echo "✓ Sauvegarde configuration Nginx..."
mkdir -p "$BACKUP_PATH/nginx"
$SUDO cp /etc/nginx/sites-available/aprs-igate "$BACKUP_PATH/nginx/"

# 5. Services systemd, udev et cron
echo "✓ Sauvegarde services systemd, udev et cron..."
mkdir -p "$BACKUP_PATH/systemd"
$SUDO cp /etc/systemd/system/aprs-igate.service /etc/systemd/system/aprs-parser.service "$BACKUP_PATH/systemd/"
$SUDO cp /etc/udev/rules.d/99-rtlsdr-restart.rules "$BACKUP_PATH/" 2>/dev/null
$SUDO cp /etc/cron.d/aprs-igate "$BACKUP_PATH/cron.d-aprs-igate" 2>/dev/null
crontab -l -u "$IGATE_USER" > "$BACKUP_PATH/crontab-backup.txt" 2>/dev/null || echo "  (pas de crontab personnelle)"

# 6. Configuration réseau (hostname, hosts)
echo "✓ Sauvegarde configuration réseau..."
mkdir -p "$BACKUP_PATH/network"
cp /etc/hostname /etc/hosts "$BACKUP_PATH/network/"

# 7. Liste des paquets installés
echo "✓ Sauvegarde liste des paquets..."
dpkg --get-selections > "$BACKUP_PATH/installed-packages.txt"

# 8. Informations système
echo "✓ Sauvegarde informations système..."
cat > "$BACKUP_PATH/system-info.txt" << EOF
Backup créé le: $DATE
Station: $(/usr/local/sbin/aprs-apply-config get station 2>/dev/null)
Hostname: $(hostname)
OS: $(grep PRETTY_NAME /etc/os-release | cut -d'"' -f2)
Kernel: $(uname -r)
Python: $(python3 --version)
PHP: $(php -v | head -n1)
Direwolf: $(direwolf -v 2>&1 | head -n1)

Services status:
- aprs-igate: $(systemctl is-active aprs-igate.service)
- aprs-parser: $(systemctl is-active aprs-parser.service)
- nginx: $(systemctl is-active nginx)

Statistiques DB:
- Nombre de paquets: $(sqlite3 /var/www/aprs/packets.db "SELECT COUNT(*) FROM packets;")
- Indicatifs uniques: $(sqlite3 /var/www/aprs/packets.db "SELECT COUNT(DISTINCT callsign) FROM packets;")
EOF

# 9. Archive compressée
echo "✓ Compression du backup..."
cd "$BACKUP_DIR" || exit 1
tar -czf "$BACKUP_NAME.tar.gz" "$BACKUP_NAME"
rm -rf "$BACKUP_NAME"
BACKUP_SIZE=$(du -h "$BACKUP_NAME.tar.gz" | cut -f1)
echo "✓ Backup créé: $BACKUP_NAME.tar.gz ($BACKUP_SIZE)"

# 10. Nettoyage des vieux backups
echo "✓ Nettoyage des backups > $RETENTION_DAYS jours..."
find "$BACKUP_DIR" -name "aprs-igate-backup-*.tar.gz" -type f -mtime +"$RETENTION_DAYS" -delete
BACKUP_COUNT=$(ls -1 "$BACKUP_DIR"/aprs-igate-backup-*.tar.gz 2>/dev/null | wc -l)

# Lancé en root (cron) : redonner les fichiers à l'utilisateur
[ "$(id -u)" -eq 0 ] && chown -R "$IGATE_USER:$IGATE_USER" "$BACKUP_DIR"

echo "=========================================="
echo "Backup terminé avec succès!"
echo "Emplacement: $BACKUP_DIR/$BACKUP_NAME.tar.gz"
echo "Taille: $BACKUP_SIZE"
echo "Backups conservés: $BACKUP_COUNT"
echo "=========================================="

logger -t aprs-backup "Backup APRS iGate créé: $BACKUP_NAME.tar.gz ($BACKUP_SIZE)"
