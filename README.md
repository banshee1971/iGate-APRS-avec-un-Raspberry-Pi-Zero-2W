🇬🇧 [English version](README.en.md)

📘 **Guide illustré d'installation et d'utilisation** : [en ligne](docs/GUIDE.fr.md))

# iGate APRS avec un Raspberry Pi Zero 2W

Kit de déploiement pour monter un **iGate APRS en réception seulement** avec un
Raspberry Pi Zero 2W et un dongle RTL-SDR :

- les paquets reçus sur 144.390 MHz sont envoyés à **APRS-IS** (visibles sur [aprs.fi](https://aprs.fi)) ;
- une **page web locale** affiche les paquets et une carte des stations, même sans Internet ;
- **tout se configure dans le navigateur** (page `/admin`, protégée par mot de passe) : aucun fichier à éditer ;
- un **moniteur système** façon btop et une page **Direwolf** pour régler la réception en direct.

Consommation totale : environ 2 W (Pi + hub USB).

---

## Matériel

| Pièce | Notes |
|---|---|
| Raspberry Pi Zero 2W | Prenez la version avec connecteur (header) soudé |
| Carte microSD | 16 Go minimum, 32 Go recommandé |
| Dongle RTL-SDR (RTL2832U) | Le V3 suffit, pas besoin du V4 (voir la note plus bas) |
| Antenne VHF | Accordée pour 144.390 MHz (Amérique du Nord) ou 144.800 MHz (Europe) |
| Alimentation USB 5V / 2.5A | |
| Câble micro-USB vers USB | Pour brancher le RTL-SDR |
| Dissipateur Geekworm C296 | Optionnel, idéal avec le PiSugar 3 |
| PiSugar 3 | Optionnel : batterie / UPS |
| Petit hub USB avec RJ45 | Optionnel : réseau filaire (choisir un modèle qui consomme peu) |

> **RTL-SDR Blog V4** : il utilise un tuner R828D qui demande un pilote récent. Si la page
> `/admin` → Direwolf affiche un avertissement « V4 », installez le pilote de RTL-SDR Blog
> (dépôt `rtlsdrblog/rtl-sdr-blog` sur GitHub) à la place du paquet `rtl-sdr`.

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

Le script commence par demander le **nom d'utilisateur et le mot de passe** de la page
`/admin` (8 caractères minimum), puis travaille seul. L'installation prend un bon moment
sur un Pi Zero (mise à jour du système et compilation de Direwolf). À la fin, le script
affiche l'adresse de la page de configuration.

Mot de passe oublié ? En SSH : `sudo aprs-admin-passwd`. On peut aussi le changer dans
`/admin` → Compte.

## 3. Configurer dans le navigateur

Ouvrez **`http://aprs-igate/admin`** (ou `http://<adresse-ip-du-pi>/admin`) et connectez-vous.
La page a six onglets (plus un lien 🔋 PiSugar si celui-ci est installé).

### ⚙️ Configuration

| Section | Ce qu'on y règle |
|---|---|
| 📍 Position et balise | Latitude/longitude (cliquez sur la carte), balise oui/non, intervalle, commentaire |
| 🖥️ Affichage | Titre et sous-titre de la page, période par défaut de la carte |
| 💾 Données et sauvegardes | Durée de conservation des paquets, fréquence et rétention des sauvegardes, clé USB |
| 🔌 Matériel | Hub USB alimenté par le Pi |
| 🗺️ Carte et cache | Taille max du cache de tuiles, rafraîchissement, zone de pré-téléchargement |

Pour une première installation : entrez votre indicatif dans l'onglet **📡 Reporting aprs.fi**,
puis votre position ici. Cliquez sur **« Enregistrer et appliquer »** : Direwolf, les services et les tâches
planifiées sont régénérés et redémarrés automatiquement. Tant que l'indicatif vaut
`N0CALL`, l'iGate ne se connecte pas à APRS-IS.

Cet onglet affiche aussi l'état du système (services, modèle du dongle, paquets du jour,
cache) et des boutons : redémarrer les services, sauvegarder, copier sur clé USB,
pré-télécharger ou vider les tuiles, redémarrer le Pi.

### 📡 Reporting aprs.fi

L'envoi des paquets reçus vers APRS-IS, le réseau qui alimente [aprs.fi](https://aprs.fi) :

- **indicatif** et **SSID** : chaque SSID est présenté avec son icône et son usage
  recommandé (🏠 -0 station principale, 🚗 -9 mobile, 🌐 -10 iGate, ⛅ -13 météo…) ;
- **passcode** : calculé automatiquement pendant que vous tapez votre indicatif ;
- boutons **Enable / Disable** : désactivé, l'iGate continue de recevoir et d'afficher
  les paquets localement, mais n'envoie plus rien sur Internet ;
- **dernier reporting réussi** : connexion au serveur, passcode accepté ou refusé, dernier
  paquet relayé, balise de l'iGate ;
- **confirmation par aprs.fi** (optionnelle) : avec votre clé API aprs.fi (gratuite, dans
  aprs.fi → Mon compte), la page affiche quand aprs.fi a reçu votre station pour la dernière fois.

### 🐺 Direwolf

Tout ce qui sert à **mieux capter** :

- **état en direct** (rafraîchi toutes les 5 s) : service, connexion APRS-IS, passcode
  vérifié ou non, version, gain réel du tuner, redémarrages ;
- **dongle SDR** : modèle détecté (ex. RTL-SDR Blog V3/V4, clé générique), chipset,
  tuner (R820T, R828D, E4000…), numéro de série, vitesse USB, avec des conseils si
  quelque chose cloche (aucun dongle, pilote DVB actif, dongle occupé, SDR non compatible) ;
- **vu-mètre à aiguille en direct** : l'aiguille monte quand un signal est présent sur la
  fréquence (comme un squelch), avec le niveau audio, la saturation et l'historique des
  2 dernières minutes : idéal pour vérifier l'antenne et le gain ;
- **niveau audio** des derniers paquets décodés sur une jauge (zone idéale 50-70) ;
- **paquets décodés** : 15 min, 1 h, 24 h, stations uniques, histogramme par heure ;
- **journal de Direwolf** en direct, en couleurs ;
- **réglages** : fréquence, gain, ppm, gain automatique, taux d'échantillonnage,
  filtre DC, bias-tee (préampli), profil du démodulateur (A, A+, B, B+), FIX_BITS ;
- **test des gains** : un bouton teste 0, 20, 35, 40, 45 et 49 (environ 6 minutes) et
  un bouton « Utiliser » applique le meilleur.

### 🌐 Réseau

Des **profils réseau** enregistrés, à activer d'un clic :

- **Ethernet** (carte intégrée ou adaptateur USB) : DHCP (par défaut), adresse fixe ou désactivé ;
- **Wi-Fi** : le réseau entré dans Raspberry Pi Imager, un autre réseau (DHCP ou adresse
  fixe), une **borne Wi-Fi** (le Pi crée son propre réseau, adresse `http://10.42.0.1`),
  ou désactivé.

Le profil **« Origine »** remet tout comme à l'installation. Exemple pour aller au club :
créez un profil « Club » en borne Wi-Fi, activez-le avant de partir, connectez votre
téléphone à la borne et ouvrez `http://10.42.0.1/admin`. De retour à la maison, réactivez
« Origine ».

**Filet de sécurité** : après chaque changement, cliquez sur « Garder cette configuration »
depuis le nouveau réseau dans les 5 minutes, sinon le Pi revient tout seul au profil
précédent. Un **profil de secours** (par exemple la borne Wi-Fi) peut aussi s'activer
automatiquement si le Pi n'a aucun réseau 3 minutes après le démarrage.

> Nécessite NetworkManager (Raspberry Pi OS Bookworm ou plus récent). En borne Wi-Fi
> sans câble Ethernet, l'iGate continue de recevoir et d'afficher les paquets localement,
> mais ne peut pas les envoyer à aprs.fi (pas d'Internet).

### 📊 Système

Un moniteur façon **btop**, rafraîchi toutes les 2 secondes : CPU (graphique, chaque cœur,
fréquence, température), alimentation (sous-tension détectée), mémoire, disques
(utilisé/libre), réseau (débits) et processus les plus actifs.

### 👤 Compte

Changer le nom d'utilisateur et le mot de passe de `/admin`.

> ⚠️ La page `/admin` est protégée par mot de passe, mais le site est en HTTP (non
> chiffré). Gardez-le sur un réseau de confiance (maison, AREDN), jamais directement
> sur Internet.

Vérifiez ensuite votre iGate sur `https://aprs.fi/#!call=a%2FVOTRE-INDICATIF-10`.

---

## L'interface web

L'interface est bilingue français / anglais : sélecteur **FR | EN** en haut de chaque page,
langue par défaut dans Admin → Configuration (« Langue de l'interface » ; par défaut
automatique, selon la langue du navigateur).

- **📦 Paquets** : tous les paquets reçus, filtrables par date et indicatif, **décodés en
  clair** avec l'icône APRS de chaque station : position (avec distance et direction depuis
  l'iGate, et lien vers la carte), vitesse, cap, altitude, météo (°C, km/h, hPa, mm),
  messages et accusés de réception, objets (répéteurs…), statuts, télémétrie, type de radio
  ou de logiciel, et « direct » ou « via » le digipeater qui l'a relayé. Les formats
  compressés et Mic-E (radios Kenwood, Yaesu…) sont décodés. Le paquet brut reste
  disponible d'un clic.
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

Le plus simple : `/admin` → Direwolf → **« Tester les gains »**, puis « Utiliser » sur le
meilleur résultat. En ligne de commande, entrez ensuite le meilleur gain dans `/admin` → Direwolf.

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
sudo aprs-admin-passwd                               # changer le compte de /admin
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

Dès que le PiSugar est installé et actif, un lien **🔋 PiSugar** (avec le niveau de la
batterie) apparaît dans les onglets de `/admin`. Sans PiSugar, pas de lien.

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
| Compte `/admin` (mot de passe chiffré bcrypt) | `/var/lib/aprs-igate/admin-auth.json` |
| Description des réglages (formulaire + validation) | `web/lib/settings.schema.json` |
| Application des réglages | `/usr/local/sbin/aprs-apply-config` |
| Création / changement du compte `/admin` | `/usr/local/sbin/aprs-admin-passwd` |
| Modèles des fichiers générés | `/usr/local/share/aprs-igate/templates/` |
| Configuration Direwolf (générée) | `~/direwolf.conf` |
| Base de données | `/var/www/aprs/packets.db` |
| Cache des tuiles | `/var/www/aprs/tiles/` |
| Journal d'installation | `/var/log/aprs-igate-install.log` |

Le serveur web n'a aucun droit root : `/admin` dépose une demande dans
`/var/lib/aprs-igate/queue/`. Systemd la détecte et lance `aprs-apply-config`,
qui **revalide chaque valeur** avant de toucher aux fichiers du système.

**Ajouter un réglage** : décrivez-le dans `settings.schema.json` (libellé, aide, type,
validation) et il apparaît dans `/admin` (onglet Direwolf si sa section a `"page": "direwolf"`). Utilisez-le ensuite dans un modèle de
`templates/` ou dans `aprs-apply-config`.

## Structure du dépôt

```
install.sh                 installation / mise à jour
backend/aprs-apply-config  applique les réglages (root)
backend/aprs-admin-passwd  crée / change le compte de /admin
parser/parse_direwolf.py   journal Direwolf -> base SQLite
web/                       interface web (index, carte offline, /admin avec ses onglets, lib/)
templates/                 modèles : direwolf.conf, services systemd, nginx, cron, udev
scripts/                   watchdog, backup, restauration, gain, tuiles
```

## Crédits

- Symboles APRS : jeu haute résolution d'aprs.fi par Heikki Hannikainen, OH7LZB
  (<https://github.com/hessu/aprs-symbols>), téléchargé par `install.sh`.
- Cartes : © les contributeurs d'[OpenStreetMap](https://www.openstreetmap.org/copyright).
- [Dire Wolf](https://github.com/wb2osz/direwolf) par John Langner, WB2OSZ.

## Licence

Ce projet est distribué sous la licence **GNU GPL v3.0** (voir [LICENSE](LICENSE)).
Vous pouvez l'utiliser, le modifier et le redistribuer librement, à condition que
les versions modifiées que vous partagez restent elles aussi sous GPL v3.0, avec leur code source.

73 ! — banshee1971
