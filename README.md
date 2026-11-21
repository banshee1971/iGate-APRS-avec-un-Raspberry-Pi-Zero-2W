# iGate APRS avec un Raspberry Pi Zero 2W

Kit de déploiement pour monter un **iGate APRS en réception seulement** avec un
Raspberry Pi Zero 2W et un dongle RTL-SDR :

- les paquets reçus sur 144.390 MHz sont envoyés à **APRS-IS** (visibles sur [aprs.fi](https://aprs.fi)) ;
- une **page web locale** affiche les paquets et une carte des stations, même sans Internet ;
- **tout se configure dans le navigateur** (page `/admin`) : aucun fichier à éditer.

Consommation totale : environ 2 W (Pi + hub USB).

---

## Matériel

| Pièce | Notes |
|---|---|
| Raspberry Pi Zero 2W | Prenez la version avec connecteur (header) soudé |
| Carte microSD | 16 Go minimum, 32 Go recommandé |
| Dongle RTL-SDR (RTL2832U) | Le V3 suffit, pas besoin du V4 |
| Antenne VHF | Accordée pour 144.390 MHz (Amérique du Nord) ou 144.800 MHz (Europe) |
| Alimentation USB 5V / 2.5A | |
| Câble micro-USB vers USB | Pour brancher le RTL-SDR |
| Dissipateur Geekworm C296 | Optionnel, idéal avec le PiSugar 3 |
| PiSugar 3 | Optionnel : batterie / UPS |
| Petit hub USB avec RJ45 | Optionnel : réseau filaire (choisir un modèle qui consomme peu) |

---

## 1. Préparer la carte microSD

1. Installez **Raspberry Pi Imager** sur votre ordinateur.
2. Appareil : **Raspberry Pi Zero 2 W**.
3. Système : **Raspberry Pi OS (other) → Raspberry Pi OS Lite (64-bit)**.
4. Personnalisation :
   - nom d'hôte : `aprs-igate`
   - fuseau horaire (Toronto pour le Québec)
   - utilisateur : `pi` (recommandé) et un mot de passe
   - votre réseau WiFi
   - **activez SSH**
5. Écrivez la carte, insérez-la dans le Pi, branchez le RTL-SDR et démarrez.

## 2. Installer le kit

Connectez-vous en SSH (`ssh pi@aprs-igate.local`, ou avec PuTTY sous Windows), puis :

```bash
sudo apt update && sudo apt install -y git
git clone https://github.com/banshee1971/iGate-APRS-avec-un-Raspberry-Pi-Zero-2W.git
cd iGate-APRS-avec-un-Raspberry-Pi-Zero-2W
sudo ./install.sh
```

L'installation prend un bon moment sur un Pi Zero (mise à jour du système et
compilation de Direwolf). À la fin, le script affiche l'adresse de la page de configuration.

## 3. Configurer dans le navigateur

Ouvrez **`http://aprs-igate/admin`** (ou `http://<adresse-ip-du-pi>/admin`).

| Section | Ce qu'on y règle |
|---|---|
| 📡 Station | Indicatif, SSID, passcode APRS-IS (calculé automatiquement) |
| 📍 Position et balise | Latitude/longitude (cliquez sur la carte), balise oui/non, intervalle, commentaire |
| 📻 Radio | Fréquence, gain du RTL-SDR, correction ppm |
| 🌐 Serveur APRS-IS | Serveur régional, filtre |
| 🖥️ Affichage | Titre et sous-titre de la page, période par défaut de la carte |
| 💾 Données et sauvegardes | Durée de conservation des paquets, fréquence et rétention des sauvegardes, clé USB |
| 🔌 Matériel | Hub USB alimenté par le Pi |
| 🗺️ Carte et cache | Taille max du cache de tuiles, rafraîchissement, zone de pré-téléchargement |

Cliquez sur **« Enregistrer et appliquer »** : Direwolf, les services et les tâches
planifiées sont régénérés et redémarrés automatiquement. Tant que l'indicatif vaut
`N0CALL`, l'iGate ne se connecte pas à APRS-IS.

La page `/admin` affiche aussi l'état du système (services, dongle, paquets du jour,
cache) et des boutons : redémarrer les services, sauvegarder, copier sur clé USB,
pré-télécharger ou vider les tuiles, redémarrer le Pi.

> ⚠️ **Pas de mot de passe pour l'instant.** Toute personne qui atteint le Pi
> sur le réseau peut modifier la configuration. Ne l'exposez que sur un réseau
> de confiance (maison, AREDN), jamais directement sur Internet.

Vérifiez ensuite votre iGate sur `https://aprs.fi/#!call=a%2FVOTRE-INDICATIF-10`.

---

## L'interface web

- **📦 Paquets** : tous les paquets reçus, filtrables par date et indicatif.
  Les paquets avec position ont un badge « 📍 Position ».
- **🗺️ Carte** : carte OpenStreetMap des stations (dernière heure à 7 jours).
- **📴 Mode Offline** : la même carte, mais uniquement avec les tuiles déjà en cache sur le Pi.

### Cache des tuiles de carte

Le Pi garde une copie de chaque tuile de carte affichée (dans `/var/www/aprs/tiles`) :

1. quand on navigue sur la carte, chaque tuile est téléchargée **une seule fois** sur
   OpenStreetMap puis servie par le Pi, pour vous et pour tous les autres utilisateurs (AREDN) ;
2. les navigateurs gardent aussi les tuiles 30 jours dans leur propre cache ;
3. sans Internet, la carte continue d'afficher tout ce qui est déjà en cache, et le
   **Mode Offline** montre exactement ce qui est disponible (zones jamais vues en gris).

Le bouton **« Pré-télécharger les tuiles »** de `/admin` remplit le cache autour de
l'iGate d'avance. Chaque nuit, le cache est ramené sous la taille maximale choisie.

---

## Mise à jour du kit

Vos réglages et vos données sont conservés :

```bash
cd ~/iGate-APRS-avec-un-Raspberry-Pi-Zero-2W
git pull
sudo ./install.sh --skip-upgrade
```

## Sauvegarde et restauration

Les sauvegardes automatiques (fréquence réglée dans `/admin`) vont dans `~/backups/`.
Elles contiennent les réglages, la base de données et tous les fichiers générés.

```bash
ls -lh ~/backups/                                   # lister les backups
sudo ~/backup-aprs-config.sh                        # forcer un backup
sudo ~/restore-aprs-config.sh ~/backups/aprs-igate-backup-DATE.tar.gz
```

## Optimiser le gain

```bash
~/test-direwolf-gain.sh            # teste 0, 20, 35, 40, 45, 49 (≈ 6 minutes)
~/test-direwolf-gain.sh 30 35 40   # ou vos propres valeurs
```

Entrez ensuite le meilleur gain dans `/admin` → Radio.

| Gain | Usage |
|---|---|
| 20-30 | Zone urbaine, beaucoup de signaux forts |
| 35-40 | Usage général, bon compromis |
| 45-49 | Zone rurale, signaux faibles |
| 49.6 | Maximum : risque de saturation |

Dans le journal Direwolf, un bon réglage donne `audio level = 50-70`. Autour de 10-20,
le gain est trop faible ; au-dessus de 95, le signal sature.

## Commandes utiles

```bash
sudo systemctl status aprs-igate aprs-parser nginx   # état des services
sudo journalctl -u aprs-igate -f                     # paquets en temps réel
sudo journalctl -u aprs-parser -f                    # insertion dans la base
cat /var/lib/aprs-igate/apply.log                    # journal de /admin
sqlite3 /var/www/aprs/packets.db "SELECT callsign, COUNT(*) c FROM packets GROUP BY callsign ORDER BY c DESC LIMIT 10;"
```

## Exposition sur AREDN

Dans l'interface de votre nœud AREDN → **Local Services** :

1. ajoutez un **Host alias** (ex.: `votreindicatif-aprs-igate`) pointant vers l'IP du Pi ;
2. ajoutez un service **Generic URL** : nom `APRS-iGate-VOTREINDICATIF`, protocole `http`,
   l'hôte créé ci-dessus, port `80`.

## PiSugar 3 (optionnel)

Le PiSugar s'installe à la main, car son installateur pose des questions :

```bash
sudo raspi-config nonint do_i2c 0     # activer l'I2C
wget https://cdn.pisugar.com/release/pisugar-power-manager.sh
bash pisugar-power-manager.sh -c release
```

Gestion de la batterie : `http://<ip-du-pi>:8421`. Pour l'allumer : appui court, puis appui long.

---

## Comment ça marche (pour les curieux)

```
 RTL-SDR ─► rtl_fm ─► Direwolf ─► APRS-IS (aprs.fi)
                         │
                   journal systemd
                         │
                parse_direwolf.py ─► packets.db ─► nginx + PHP ─► navigateur
                                                        │
 navigateur ─► /admin ─► settings.json + demande ─► aprs-admin.path (systemd)
                                                        │
                                       aprs-apply-config (root) : valide,
                                       régénère direwolf.conf, services, cron
```

| Élément | Emplacement sur le Pi |
|---|---|
| Réglages (écrits par `/admin`) | `/var/lib/aprs-igate/settings.json` |
| Description des réglages (formulaire + validation) | `web/lib/settings.schema.json` |
| Application des réglages | `/usr/local/sbin/aprs-apply-config` |
| Modèles des fichiers générés | `/usr/local/share/aprs-igate/templates/` |
| Configuration Direwolf (générée) | `~/direwolf.conf` |
| Base de données | `/var/www/aprs/packets.db` |
| Cache des tuiles | `/var/www/aprs/tiles/` |
| Journal d'installation | `/var/log/aprs-igate-install.log` |

Le serveur web n'a aucun droit root : `/admin` dépose une demande dans
`/var/lib/aprs-igate/queue/`. Systemd la détecte et lance `aprs-apply-config`,
qui **revalide chaque valeur** avant de toucher aux fichiers du système.

**Ajouter un réglage** : décrivez-le dans `settings.schema.json` (libellé, aide, type,
validation) et il apparaît dans `/admin`. Utilisez-le ensuite dans un modèle de
`templates/` ou dans `aprs-apply-config`.

## Structure du dépôt

```
install.sh                 installation / mise à jour
backend/aprs-apply-config  applique les réglages (root)
parser/parse_direwolf.py   journal Direwolf -> base SQLite
web/                       interface web (index, carte offline, /admin, lib/)
templates/                 modèles : direwolf.conf, services systemd, nginx, cron, udev
scripts/                   watchdog, backup, restauration, gain, tuiles
```
