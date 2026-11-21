#!/bin/bash
# Watchdog iGate APRS : lancé toutes les 5 minutes par /etc/cron.d/aprs-igate (root).
# Redémarre les services s'ils sont arrêtés et note si le RTL-SDR a disparu.

[ -r /etc/aprs-igate/install.env ] && . /etc/aprs-igate/install.env
IGATE_HOME="${IGATE_HOME:-/home/pi}"
LOG="$IGATE_HOME/aprs-watchdog.log"

# Vérifier que le RTL-SDR est présent
if ! lsusb | grep -q "0bda:2838"; then
    echo "[$(date)] ⚠️ RTL-SDR déconnecté!" >> "$LOG"
fi

# Vérifier les services (seulement s'ils sont activés : tant que l'iGate
# n'est pas configuré dans /admin, ils sont volontairement arrêtés)
for SERVICE in aprs-igate.service aprs-parser.service; do
    if systemctl is-enabled --quiet "$SERVICE" && ! systemctl is-active --quiet "$SERVICE"; then
        echo "[$(date)] ⚠️ $SERVICE arrêté, redémarrage..." >> "$LOG"
        systemctl start "$SERVICE"
    fi
done
