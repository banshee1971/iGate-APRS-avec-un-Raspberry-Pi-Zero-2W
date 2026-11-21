#!/bin/bash
# Watchdog iGate APRS : lancé toutes les 5 minutes par /etc/cron.d/aprs-igate (root).
# Redémarre les services s'ils sont arrêtés et note si le RTL-SDR a disparu.

[ -r /etc/aprs-igate/install.env ] && . /etc/aprs-igate/install.env
IGATE_HOME="${IGATE_HOME:-/home/pi}"
LOG="$IGATE_HOME/aprs-watchdog.log"

# Test de gain en cours (lancé depuis /admin) : il libère le dongle exprès
[ -e /run/aprs-igate-gain-test.lock ] && exit 0

# Vérifier que le RTL-SDR est présent
# Identifiants USB des clés RTL2832U reconnues par rtl_fm
RTL_IDS='0bda:283[28]|0413:6680|0413:6f0f|0458:707f|0ccd:00(a9|b3|d3|e0)|185b:06[25]0|1b80:d39[345d]|1d19:110[123]|1f4d:[bc]803'
if ! lsusb | grep -Eq "ID ($RTL_IDS)"; then
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
