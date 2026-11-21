#!/bin/bash
# Test de décodage Direwolf avec différents gains (environ 6 minutes).
# Le service aprs-igate est arrêté pendant le test (le dongle ne peut servir
# qu'à un programme à la fois), puis redémarré.
# Reportez ensuite le meilleur gain dans http://<ip-du-pi>/admin

[ -r /etc/aprs-igate/install.env ] && . /etc/aprs-igate/install.env
IGATE_HOME="${IGATE_HOME:-/home/pi}"

FREQ=$(/usr/local/sbin/aprs-apply-config get freq 2>/dev/null || echo 144.39M)
SAMPLE_RATE="48000"
TEST_DURATION=60  # secondes
GAINS="${*:-0 20 35 40 45 49}"
RESULTS="$IGATE_HOME/direwolf-gain-results.txt"

echo "=========================================="
echo "Test Direwolf - Comptage de paquets décodés ($FREQ)"
echo "=========================================="

WAS_ACTIVE=no
if systemctl is-active --quiet aprs-igate.service; then
    WAS_ACTIVE=yes
    echo "Arrêt temporaire du service aprs-igate..."
    sudo systemctl stop aprs-parser.service aprs-igate.service
fi

restore() {
    if [ "$WAS_ACTIVE" = yes ]; then
        echo "Redémarrage du service aprs-igate..."
        sudo systemctl start aprs-igate.service aprs-parser.service
    fi
}
trap restore EXIT

test_with_direwolf() {
    local gain=$1
    echo ""
    echo "Test avec gain: $gain (décodage pendant $TEST_DURATION secondes)..."
    packet_count=$(timeout "$TEST_DURATION" bash -c "rtl_fm -f $FREQ -s $SAMPLE_RATE -g $gain -p 0 - 2>/dev/null | \
        direwolf -c $IGATE_HOME/direwolf.conf -r $SAMPLE_RATE -D 1 -t 0 - 2>&1" | \
        grep -c "^\[0")
    echo "Gain $gain: $packet_count paquets décodés en ${TEST_DURATION}s" | tee -a "$RESULTS"
}

: > "$RESULTS"
for gain in $GAINS; do
    test_with_direwolf "$gain"
done

echo ""
echo "=========================================="
echo "Résultats:"
echo "=========================================="
cat "$RESULTS"
echo ""
echo "Utilisez le gain qui a décodé le plus de paquets"
echo "et entrez-le dans la page /admin (section Radio)."
