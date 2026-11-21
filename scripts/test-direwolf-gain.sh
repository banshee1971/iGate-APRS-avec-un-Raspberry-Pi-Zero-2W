#!/bin/bash
# Test de décodage Direwolf avec différents gains (environ 1 minute par gain).
# Lancé depuis /admin -> Direwolf (bouton « Tester les gains ») ou à la main :
#     ~/test-direwolf-gain.sh            # gains 0 20 35 40 45 49
#     ~/test-direwolf-gain.sh 30 35 40   # vos propres valeurs
# Le service aprs-igate est arrêté pendant le test (le dongle ne peut servir
# qu'à un programme à la fois), puis redémarré. Le test n'envoie rien à APRS-IS.

[ -r /etc/aprs-igate/install.env ] && . /etc/aprs-igate/install.env
IGATE_HOME="${IGATE_HOME:-/home/pi}"
GET=/usr/local/sbin/aprs-apply-config

FREQ=$($GET get freq 2>/dev/null || echo 144.39M)
PPM=$($GET get ppm 2>/dev/null || echo 0)
SAMPLE_RATE=$($GET get rtl_sample_rate 2>/dev/null || echo 48000)
RTL_EXTRA=""
[ "$($GET get rtl_dc_block 2>/dev/null)" = yes ] && RTL_EXTRA="$RTL_EXTRA -E dc"
[ "$($GET get rtl_bias_tee 2>/dev/null)" = yes ] && RTL_EXTRA="$RTL_EXTRA -T"
TEST_DURATION="${TEST_DURATION:-60}"  # secondes par gain
GAINS="${*:-0 20 35 40 45 49}"
RESULTS="${GAIN_RESULTS:-$IGATE_HOME/direwolf-gain-results.txt}"
LOCK=/run/aprs-igate-gain-test.lock
SUDO=""; [ "$(id -u)" -ne 0 ] && SUDO="sudo"

echo "=========================================="
echo "Test Direwolf - Comptage de paquets décodés ($FREQ)"
echo "=========================================="

# Le watchdog ne doit pas relancer l'iGate pendant le test
$SUDO touch "$LOCK"

# Configuration de test : démodulateur seulement, sans connexion APRS-IS ni journaux
TEST_CONF=$(mktemp)
grep -Ev '^[[:space:]]*(IGSERVER|IGLOGIN|IGTXVIA|IGFILTER|PBEACON|LOGDIR)' \
    "$IGATE_HOME/direwolf.conf" > "$TEST_CONF" 2>/dev/null || printf 'ADEVICE stdin null\nMODEM 1200\n' > "$TEST_CONF"

WAS_ACTIVE=no
if systemctl is-active --quiet aprs-igate.service; then
    WAS_ACTIVE=yes
    echo "Arrêt temporaire du service aprs-igate..."
    $SUDO systemctl stop aprs-parser.service aprs-igate.service
    sleep 2
fi

restore() {
    rm -f "$TEST_CONF"
    $SUDO rm -f "$LOCK"
    if [ "$WAS_ACTIVE" = yes ]; then
        echo "Redémarrage du service aprs-igate..."
        $SUDO systemctl start aprs-igate.service aprs-parser.service
    fi
}
trap restore EXIT

: > "$RESULTS"
chmod 644 "$RESULTS" 2>/dev/null
echo "# Test du $(date '+%Y-%m-%d %H:%M') - $FREQ - ${TEST_DURATION}s par gain" >> "$RESULTS"

for gain in $GAINS; do
    echo ""
    echo "Test avec gain: $gain (décodage pendant $TEST_DURATION secondes)..."
    # shellcheck disable=SC2086
    OUT=$(timeout "$TEST_DURATION" bash -c "rtl_fm -f $FREQ -s $SAMPLE_RATE -g $gain -p $PPM $RTL_EXTRA - 2>/dev/null | \
        direwolf -c $TEST_CONF -r $SAMPLE_RATE -D 1 -t 0 - 2>&1")
    packets=$(printf '%s\n' "$OUT" | grep -c '^\[0')
    levels=$(printf '%s\n' "$OUT" | grep -o 'audio level = [0-9]*' | grep -o '[0-9]*$')
    avg=$(printf '%s\n' "$levels" | awk 'NF {s+=$1; n++} END {if (n) printf "%d", s/n; else print "-"}')
    echo "gain=$gain paquets=$packets niveau_audio=$avg" | tee -a "$RESULTS"
done

echo ""
echo "=========================================="
echo "Résultats:"
echo "=========================================="
cat "$RESULTS"
echo ""
echo "Utilisez le gain qui a décodé le plus de paquets"
echo "(page /admin -> Direwolf, bouton « Utiliser »)."
