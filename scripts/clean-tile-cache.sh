#!/bin/bash
# Entretien du cache de tuiles (/var/www/aprs/tiles), lancé chaque nuit par cron.
#  1. Efface les tuiles plus vieilles que tile_max_age_days (0 = jamais),
#     pour qu'elles soient retéléchargées à jour au prochain affichage.
#  2. Si le cache dépasse tile_cache_max_mb, efface les plus anciennes.

GET=/usr/local/sbin/aprs-apply-config
TILE_DIR="/var/www/aprs/tiles"
MAX_MB=$($GET get tile_cache_max_mb 2>/dev/null || echo 1000)
MAX_AGE=$($GET get tile_max_age_days 2>/dev/null || echo 365)

[ -d "$TILE_DIR" ] || exit 0

if [ "$MAX_AGE" -gt 0 ]; then
    find "$TILE_DIR" -type f -name '*.png' -mtime +"$MAX_AGE" -delete
fi

USED_MB=$(du -sm "$TILE_DIR" | cut -f1)
if [ "$USED_MB" -gt "$MAX_MB" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Cache des tuiles: ${USED_MB} Mo > ${MAX_MB} Mo, nettoyage..."
    # Effacer les plus anciennes jusqu'à revenir à 90 % de la limite
    TARGET_KB=$((MAX_MB * 1024 * 9 / 10))
    find "$TILE_DIR" -type f -name '*.png' -printf '%T@ %k %p\n' | sort -n | \
        awk -v excess=$(( $(du -sk "$TILE_DIR" | cut -f1) - TARGET_KB )) \
            '{ if (freed >= excess) exit; freed += $2; sub(/^[^ ]+ [^ ]+ /, ""); print }' | \
        xargs -r -d '\n' rm -f
fi

# Retirer les dossiers devenus vides
find "$TILE_DIR" -mindepth 1 -type d -empty -delete
