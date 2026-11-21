#!/usr/bin/env python3
"""
Parser APRS : lit en continu le journal du service aprs-igate (Direwolf)
et enregistre chaque paquet reçu dans la base SQLite de l'interface web.

Réglages (variables d'environnement, fournies par aprs-parser.service) :
  APRS_DB_PATH         chemin de la base (défaut /var/www/aprs/packets.db)
  APRS_RETENTION_DAYS  jours de paquets conservés (défaut 30)
  APRS_FREQ_LABEL      fréquence affichée (défaut "144.39 MHz")
"""
import json
import os
import re
import sqlite3
import subprocess
from datetime import datetime

from aprs_decode import decode   # décodage lisible (position, Mic-E, météo, messages...)

DB_PATH = os.environ.get('APRS_DB_PATH', '/var/www/aprs/packets.db')
RETENTION_DAYS = int(os.environ.get('APRS_RETENTION_DAYS', '30'))
FREQ_LABEL = os.environ.get('APRS_FREQ_LABEL', '144.39 MHz')

# Ligne de paquet Direwolf : "[0] VE2ABC-9>APRS,..." ou "[0.3] VE2ABC-9>..."
PACKET_RE = re.compile(r'\[[\d.]+\]\s+([A-Z0-9-]+)>')
# Format décodé par Direwolf : N 45 30.1234, W 073 34.5678
COORD_DECODED_RE = re.compile(r'([NS])\s*(\d+)\s+(\d+\.\d+),\s*([EW])\s*(\d+)\s+(\d+\.\d+)')
# Format APRS brut : 4530.12N/07334.56W  (table de symboles / ou \)
COORD_APRS_RE = re.compile(r'(\d{2})(\d{2}\.\d+)([NS])[/\\](\d{3})(\d{2}\.\d+)([EW])')


def log(msg):
    print(f"[{datetime.now()}] {msg}", flush=True)


def cleanup_old_packets():
    """Supprime les paquets plus vieux que RETENTION_DAYS."""
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    cursor.execute("DELETE FROM packets WHERE timestamp < datetime('now', ?)",
                   (f'-{RETENTION_DAYS} days',))
    deleted = cursor.rowcount
    conn.commit()
    conn.close()
    if deleted > 0:
        log(f"Nettoyage: {deleted} paquets supprimés (> {RETENTION_DAYS} jours)")


def extract_callsign(line):
    """Extrait l'indicatif d'appel du paquet."""
    match = PACKET_RE.search(line)
    return match.group(1) if match else "UNKNOWN"


def _to_decimal(deg, minutes, direction, negative):
    value = float(deg) + float(minutes) / 60.0
    return -value if direction == negative else value


def parse_coordinates(text):
    """Retourne (lat, lon) en degrés décimaux, ou (None, None)."""
    match = COORD_DECODED_RE.search(text)
    if not match:
        match = COORD_APRS_RE.search(text)
        if match:
            lat_deg, lat_min, lat_dir, lon_deg, lon_min, lon_dir = match.groups()
        else:
            return None, None
    else:
        lat_dir, lat_deg, lat_min, lon_dir, lon_deg, lon_min = match.groups()

    lat = _to_decimal(lat_deg, lat_min, lat_dir, 'S')
    lon = _to_decimal(lon_deg, lon_min, lon_dir, 'W')
    if not (-90 <= lat <= 90 and -180 <= lon <= 180):
        return None, None
    return lat, lon


def decode_packet(raw_packet):
    """Retourne (JSON décodé, lat, lon). lat/lon = position de la station
    elle-même (pas celle d'un objet qu'elle annonce), pour la carte."""
    d = decode(raw_packet)
    lat, lon = d.get('lat'), d.get('lon')
    if d.get('type') == 'object' or d.get('third_party'):
        lat = lon = None
    elif lat is None and d.get('type') in ('unknown', 'other'):
        lat, lon = parse_coordinates(raw_packet)
    return json.dumps(d, ensure_ascii=False, separators=(',', ':')), lat, lon


def backfill_decoded():
    """Décode les paquets enregistrés avant l'ajout du décodeur."""
    conn = sqlite3.connect(DB_PATH, timeout=10)
    total = 0
    while True:
        rows = conn.execute("SELECT id, raw_packet FROM packets WHERE decoded_info IS NULL LIMIT 500").fetchall()
        if not rows:
            break
        for pid, raw in rows:
            info, lat, lon = decode_packet(raw or '')
            conn.execute("UPDATE packets SET decoded_info = ?, latitude = ?, longitude = ? WHERE id = ?",
                         (info, lat, lon, pid))
        conn.commit()
        total += len(rows)
    conn.close()
    if total:
        log(f"{total} anciens paquets décodés")


def insert_packet(callsign, raw_packet):
    """Insère un paquet dans la base, décodé et avec coordonnées si disponibles."""
    try:
        info, lat, lon = decode_packet(raw_packet)
        conn = sqlite3.connect(DB_PATH, timeout=10)
        conn.execute(
            "INSERT INTO packets (callsign, raw_packet, frequency, decoded_info, latitude, longitude) "
            "VALUES (?, ?, ?, ?, ?, ?)",
            (callsign, raw_packet, FREQ_LABEL, info, lat, lon)
        )
        conn.commit()
        conn.close()
        return True, lat, lon
    except Exception as e:
        log(f"Erreur insertion DB: {e}")
        return False, None, None


def follow_logs():
    """Suit le journal systemd du service aprs-igate en temps réel."""
    process = subprocess.Popen(
        ['journalctl', '-u', 'aprs-igate.service', '-f', '-n', '0', '-o', 'cat'],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        universal_newlines=True,
    )

    log(f"Démarrage du parser APRS (base: {DB_PATH}, rétention: {RETENTION_DAYS} jours)")
    cleanup_old_packets()
    try:
        backfill_decoded()
    except Exception as e:
        log(f"Décodage des anciens paquets impossible : {e}")

    last_cleanup = datetime.now()
    packet_count = 0

    for line in process.stdout:
        # Nettoyage périodique (toutes les heures)
        if (datetime.now() - last_cleanup).total_seconds() > 3600:
            cleanup_old_packets()
            last_cleanup = datetime.now()

        callsign = extract_callsign(line)
        if callsign == "UNKNOWN":
            continue
        try:
            success, lat, lon = insert_packet(callsign, line.strip())
            if not success:
                log(f"Échec insertion: {callsign}")
                continue
            packet_count += 1
            if lat is not None:
                log(f"Paquet #{packet_count} avec position: {callsign} ({lat:.4f}, {lon:.4f})")
            else:
                log(f"Paquet #{packet_count} inséré: {callsign}")
        except Exception as e:
            log(f"Erreur traitement: {e}")

    # journalctl s'est arrêté : systemd relancera le parser (Restart=always)
    raise SystemExit(f"journalctl s'est arrêté (code {process.wait()})")


if __name__ == "__main__":
    try:
        follow_logs()
    except KeyboardInterrupt:
        log("Arrêt du parser...")
