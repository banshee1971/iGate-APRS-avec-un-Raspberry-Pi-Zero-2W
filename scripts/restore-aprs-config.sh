#!/bin/bash
# Script de restauration APRS iGate
#   sudo ~/restore-aprs-config.sh ~/backups/aprs-igate-backup-DATE.tar.gz
#
# Restaure les réglages /admin et la base de données, puis régénère tous les
# fichiers (direwolf.conf, services, cron) avec aprs-apply-config.

[ -r /etc/aprs-igate/install.env ] && . /etc/aprs-igate/install.env
IGATE_USER="${IGATE_USER:-pi}"
IGATE_HOME="${IGATE_HOME:-/home/$IGATE_USER}"

if [ "$(id -u)" -ne 0 ]; then
    exec sudo "$0" "$@"
fi

if [ -z "$1" ]; then
    echo "Usage: $0 <fichier-backup.tar.gz>"
    echo ""
    echo "Backups disponibles:"
    ls -lh "$IGATE_HOME"/backups/aprs-igate-backup-*.tar.gz 2>/dev/null || echo "Aucun backup trouvé"
    exit 1
fi

BACKUP_FILE="$1"
if [ ! -f "$BACKUP_FILE" ]; then
    echo "Erreur: Fichier $BACKUP_FILE introuvable"
    exit 1
fi

echo "=========================================="
echo "Restauration APRS iGate"
echo "Fichier: $BACKUP_FILE"
echo "=========================================="
echo ""
echo "⚠️  ATTENTION: Cette opération va écraser la configuration actuelle!"
read -r -p "Continuer? (oui/non): " confirm
if [ "$confirm" != "oui" ]; then
    echo "Restauration annulée."
    exit 0
fi

TEMP_DIR=$(mktemp -d)
trap 'rm -rf "$TEMP_DIR"' EXIT
echo "✓ Extraction du backup..."
tar -xzf "$BACKUP_FILE" -C "$TEMP_DIR"
BACKUP_DIR=$(ls -d "$TEMP_DIR"/aprs-igate-backup-* | head -n1)

echo "✓ Arrêt des services..."
systemctl stop aprs-parser.service aprs-igate.service

if [ -f "$BACKUP_DIR/settings.json" ]; then
    echo "✓ Restauration des réglages /admin..."
    install -o www-data -g www-data -m 664 "$BACKUP_DIR/settings.json" /var/lib/aprs-igate/settings.json
    if [ -f "$BACKUP_DIR/admin-auth.json" ]; then
        echo "✓ Restauration du compte /admin..."
        install -o www-data -g www-data -m 600 "$BACKUP_DIR/admin-auth.json" /var/lib/aprs-igate/admin-auth.json
    fi
    if [ -f "$BACKUP_DIR/network-profiles.json" ]; then
        echo "✓ Restauration des profils réseau (non activés : choisissez-en un dans /admin -> Réseau)..."
        install -o www-data -g www-data -m 600 "$BACKUP_DIR/network-profiles.json" /var/lib/aprs-igate/network-profiles.json
    fi
else
    # Ancien backup (fait avec le guide manuel) : on reprend direwolf.conf tel quel
    echo "⚠️  Backup sans settings.json (ancien format) : restauration de direwolf.conf seulement."
    echo "   Pensez à vérifier vos réglages dans http://<ip-du-pi>/admin"
    install -o "$IGATE_USER" -g "$IGATE_USER" -m 640 "$BACKUP_DIR/direwolf.conf" "$IGATE_HOME/direwolf.conf"
fi

echo "✓ Restauration base de données..."
install -o "$IGATE_USER" -g www-data -m 664 "$BACKUP_DIR/packets.db" /var/www/aprs/packets.db

if [ -f "$BACKUP_DIR/settings.json" ]; then
    echo "✓ Régénération de la configuration..."
    /usr/local/sbin/aprs-apply-config apply
else
    systemctl start aprs-igate.service aprs-parser.service
fi

echo "=========================================="
echo "Restauration terminée!"
echo "=========================================="
echo ""
echo "Vérification des services:"
systemctl status aprs-igate.service --no-pager -l | head -n 5
systemctl status aprs-parser.service --no-pager -l | head -n 5
