#!/bin/bash
# Pré-télécharge les tuiles OpenStreetMap autour de l'iGate, dans le même
# cache que celui rempli en naviguant sur la carte (/var/www/aprs/tiles).
# Position, zooms et rayon : réglés dans /admin.
#
# Respectez la politique d'OSM : pas de gros téléchargements massifs.
# https://operations.osmfoundation.org/policies/tiles/

GET=/usr/local/sbin/aprs-apply-config
TILE_DIR="/var/www/aprs/tiles"
export TILE_DIR
export CENTER_LAT=$($GET get lat)
export CENTER_LON=$($GET get lon)
export ZOOM_LEVELS=$($GET get tile_zooms)
export RADIUS=$($GET get tile_radius)

echo "=========================================="
echo "Téléchargement des tuiles OpenStreetMap"
echo "=========================================="
echo "Centre: $CENTER_LAT, $CENTER_LON"
echo "Zooms: $ZOOM_LEVELS"
echo "Rayon: $RADIUS tuiles"
echo ""

mkdir -p "$TILE_DIR"

python3 - << 'PYTHON_SCRIPT'
import math
import os
import sys
import time
import urllib.request

CENTER_LAT = float(os.environ['CENTER_LAT'])
CENTER_LON = float(os.environ['CENTER_LON'])
TILE_DIR = os.environ['TILE_DIR']
ZOOM_LEVELS = [int(z) for z in os.environ['ZOOM_LEVELS'].split()]
RADIUS = int(os.environ['RADIUS'])
UA = 'aprs-igate-kit/1.0 (+https://github.com/banshee1971/iGate-APRS-avec-un-Raspberry-Pi-Zero-2W)'


def deg2num(lat_deg, lon_deg, zoom):
    """Convertir lat/lon en numéro de tuile"""
    lat_rad = math.radians(lat_deg)
    n = 2.0 ** zoom
    xtile = int((lon_deg + 180.0) / 360.0 * n)
    ytile = int((1.0 - math.asinh(math.tan(lat_rad)) / math.pi) / 2.0 * n)
    return xtile, ytile


total_tiles = len(ZOOM_LEVELS) * ((RADIUS * 2) + 1) ** 2
downloaded = skipped = errors = 0
print(f"Total à télécharger: ~{total_tiles} tuiles\n")


def progress():
    sys.stdout.write(f"\r  Progression: {downloaded + skipped + errors}/{total_tiles} "
                     f"(skip: {skipped}, dl: {downloaded}, err: {errors})")
    sys.stdout.flush()


for zoom in ZOOM_LEVELS:
    x_center, y_center = deg2num(CENTER_LAT, CENTER_LON, zoom)
    max_tile = 2 ** zoom - 1
    print(f"Zoom {zoom}: Centre tuile ({x_center}, {y_center})")
    for x in range(max(0, x_center - RADIUS), min(max_tile, x_center + RADIUS) + 1):
        for y in range(max(0, y_center - RADIUS), min(max_tile, y_center + RADIUS) + 1):
            tile_dir = f"{TILE_DIR}/{zoom}/{x}"
            tile_path = f"{tile_dir}/{y}.png"
            if os.path.exists(tile_path):
                skipped += 1
                progress()
                continue
            os.makedirs(tile_dir, exist_ok=True)
            url = f"https://tile.openstreetmap.org/{zoom}/{x}/{y}.png"
            try:
                req = urllib.request.Request(url, headers={'User-Agent': UA})
                with urllib.request.urlopen(req, timeout=15) as response:
                    data = response.read()
                with open(tile_path + '.tmp', 'wb') as out_file:
                    out_file.write(data)
                os.replace(tile_path + '.tmp', tile_path)
                downloaded += 1
                time.sleep(0.1)  # Pause pour respecter les limites OSM
            except Exception:
                errors += 1
            progress()
    print("")

print("")
print("=" * 50)
print("Téléchargement terminé!")
print(f"  Tuiles téléchargées: {downloaded}")
print(f"  Tuiles déjà présentes: {skipped}")
print(f"  Erreurs: {errors}")
print("=" * 50)
sys.exit(1 if errors and not downloaded and not skipped else 0)
PYTHON_SCRIPT
STATUS=$?

# nginx (www-data) doit pouvoir ajouter des tuiles au même cache
chown -R www-data:www-data "$TILE_DIR"
chmod -R u=rwX,g=rwX,o=rX "$TILE_DIR"

echo ""
echo "Espace disque utilisé: $(du -sh "$TILE_DIR" | cut -f1)"
exit $STATUS
