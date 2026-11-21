#!/bin/bash
# Copie les archives de backup vers une clé USB.
# Le dossier de la clé se règle dans /admin (section Données et sauvegardes).

[ -r /etc/aprs-igate/install.env ] && . /etc/aprs-igate/install.env
IGATE_HOME="${IGATE_HOME:-/home/pi}"

USB_MOUNT=$(/usr/local/sbin/aprs-apply-config get usb_backup_mount 2>/dev/null || echo /media/pi/USB_BACKUP)
BACKUP_SOURCE="$IGATE_HOME/backups"

if ! mountpoint -q "$USB_MOUNT"; then
    echo "Erreur: Clé USB non montée à $USB_MOUNT"
    exit 1
fi

echo "Copie des backups vers USB ($USB_MOUNT)..."
mkdir -p "$USB_MOUNT/aprs-backups"
rsync -av "$BACKUP_SOURCE"/*.tar.gz "$USB_MOUNT/aprs-backups/" || exit 1
sync

echo "Backup USB terminé!"
