🇫🇷 [Version française](README.md)

📘 **Illustrated installation and user guide**: [online](docs/GUIDE.en.md)

# APRS iGate with a Raspberry Pi Zero 2W

Deployment kit to build a **receive-only APRS iGate** with a
Raspberry Pi Zero 2W and an RTL-SDR dongle:

- packets received on 144.390 MHz are sent to **APRS-IS** (visible on [aprs.fi](https://aprs.fi));
- a **local web page** shows the packets and a map of the stations, even without Internet;
- **everything is configured in the browser** (`/admin` page, password-protected): no files to edit;
- a btop-style **system monitor** and a **Direwolf** page to tune reception live.

Total power draw: about 2 W (Pi + USB hub).

---

## Hardware

| Part | Notes |
|---|---|
| Raspberry Pi Zero 2W | Get the version with the header pre-soldered |
| microSD card | 16 GB minimum, 32 GB recommended |
| RTL-SDR dongle (RTL2832U) | The V3 is enough, no need for the V4 (see the note below) |
| VHF antenna | Tuned for 144.390 MHz (North America) or 144.800 MHz (Europe) |
| 5V / 2.5A USB power supply | |
| Micro-USB to USB cable | To connect the RTL-SDR |
| Geekworm C296 heatsink | Optional, ideal with the PiSugar 3 |
| PiSugar 3 | Optional: battery / UPS |
| Small USB hub with RJ45 | Optional: wired network (pick a low-power model) |

> **RTL-SDR Blog V4**: it uses an R828D tuner that needs a recent driver. If the
> `/admin` → Direwolf page shows a "V4" warning, install the RTL-SDR Blog driver
> (`rtlsdrblog/rtl-sdr-blog` repository on GitHub) instead of the `rtl-sdr` package.

---

## 1. Prepare the microSD card

1. Install **Raspberry Pi Imager** on your computer.
2. Device: **Raspberry Pi Zero 2 W**.
3. OS: **Raspberry Pi OS (other) → Raspberry Pi OS Lite (64-bit)**.
4. Customisation:
   - hostname: `aprs-igate`
   - time zone (Toronto for Québec)
   - user: `pi` (recommended) and a password
   - your Wi-Fi network
   - **enable SSH**
5. Write the card, insert it in the Pi, plug in the RTL-SDR and power up.

## 2. Install the kit

Log in over SSH (`ssh pi@aprs-igate.local`, or with PuTTY on Windows), then:

```bash
sudo apt update && sudo apt install -y git
git clone https://github.com/banshee1971/iGate-APRS-avec-un-Raspberry-Pi-Zero-2W.git
cd iGate-APRS-avec-un-Raspberry-Pi-Zero-2W
sudo ./install.sh
```

The script first asks for the **username and password** of the `/admin` page
(8 characters minimum), then runs on its own. Installation takes quite a while
on a Pi Zero (system upgrade and Direwolf build). At the end, the script
shows the address of the configuration page.

Forgot the password? Over SSH: `sudo aprs-admin-passwd`. You can also change it in
`/admin` → Account.

## 3. Configure in the browser

Open **`http://aprs-igate/admin`** (or `http://<pi-ip-address>/admin`) and log in.
The page has six tabs (plus a 🔋 PiSugar link if one is installed).

### ⚙️ Configuration

| Section | What you set there |
|---|---|
| 📍 Position and beacon | Latitude/longitude (click on the map), beacon on/off, interval, comment |
| 🖥️ Display | Page title and subtitle, default map time range |
| 💾 Data and backups | Packet retention period, backup frequency and retention, USB stick |
| 🔌 Hardware | USB hub powered by the Pi |
| 🗺️ Map and cache | Max tile cache size, refresh, pre-download area |

For a first install: enter your callsign in the **📡 aprs.fi Reporting** tab,
then your position here. Click **"Save and apply"**: Direwolf, the services and the scheduled
tasks are regenerated and restarted automatically. As long as the callsign is
`N0CALL`, the iGate does not connect to APRS-IS.

This tab also shows the system status (services, dongle model, today's packets,
cache) and buttons: restart services, back up, copy to USB stick,
pre-download or clear tiles, reboot the Pi.

### 📡 aprs.fi Reporting

Sending received packets to APRS-IS, the network that feeds [aprs.fi](https://aprs.fi):

- **callsign** and **SSID**: each SSID is shown with its icon and recommended
  use (🏠 -0 primary station, 🚗 -9 mobile, 🌐 -10 iGate, ⛅ -13 weather…);
- **passcode**: computed automatically as you type your callsign;
- **Enable / Disable** buttons: when disabled, the iGate keeps receiving and showing
  packets locally, but no longer sends anything to the Internet;
- **last successful report**: server connection, passcode accepted or rejected, last
  packet relayed, iGate beacon;
- **confirmation by aprs.fi** (optional): with your aprs.fi API key (free, in
  aprs.fi → My account), the page shows when aprs.fi last heard your station.

### 🐺 Direwolf

Everything that helps you **receive better**:

- **live status** (refreshed every 5 s): service, APRS-IS connection, passcode
  verified or not, version, actual tuner gain, restarts;
- **SDR dongle**: detected model (e.g. RTL-SDR Blog V3/V4, generic stick), chipset,
  tuner (R820T, R828D, E4000…), serial number, USB speed, with tips if
  something is wrong (no dongle, DVB driver active, dongle busy, unsupported SDR);
- **live needle VU meter**: the needle rises when a signal is present on the
  frequency (like a squelch), with audio level, clipping and the history of the
  last 2 minutes: ideal for checking the antenna and gain;
- **audio level** of the latest decoded packets on a gauge (ideal zone 50-70);
- **decoded packets**: 15 min, 1 h, 24 h, unique stations, hourly histogram;
- live, colour-coded **Direwolf log**;
- **settings**: frequency, gain, ppm, automatic gain, sample rate,
  DC filter, bias-tee (preamp), demodulator profile (A, A+, B, B+), FIX_BITS;
- **gain test**: one button tests 0, 20, 35, 40, 45 and 49 (about 6 minutes) and
  a "Use" button applies the best one.

### 🌐 Network

Saved **network profiles**, activated with one click:

- **Ethernet** (built-in or USB adapter): DHCP (default), static address or disabled;
- **Wi-Fi**: the network entered in Raspberry Pi Imager, another network (DHCP or static
  address), a **Wi-Fi hotspot** (the Pi creates its own network, address `http://10.42.0.1`),
  or disabled.

The **"Original"** profile puts everything back as it was at install time. Example for a club visit:
create a "Club" profile as a Wi-Fi hotspot, activate it before leaving, connect your
phone to the hotspot and open `http://10.42.0.1/admin`. Back home, reactivate
"Original".

**Safety net**: after each change, click "Keep this configuration"
from the new network within 5 minutes, otherwise the Pi reverts on its own to the previous
profile. A **fallback profile** (for example the Wi-Fi hotspot) can also be activated
automatically if the Pi has no network 3 minutes after boot.

> Requires NetworkManager (Raspberry Pi OS Bookworm or newer). As a Wi-Fi hotspot
> without an Ethernet cable, the iGate keeps receiving and showing packets locally,
> but cannot send them to aprs.fi (no Internet).

### 🔄 Updates

No more command line to keep the Pi up to date:

- **🔎 Check now**: lists the system updates (Raspberry Pi OS, `apt`) and the new
  commits in this GitHub repository;
- **Install system updates**: the same as `sudo apt update && sudo apt upgrade`
  (5 to 30 minutes on a Pi Zero 2W; the iGate keeps running);
- **Update the iGate**: the same as `git pull` followed by `sudo ./install.sh --skip-upgrade`;
  your settings, account and packets are kept.

The log of the last update is shown live, and a banner offers to reboot the Pi when a
kernel update requires it.

**📦 Export / import settings** (Configuration tab): "Export" downloads a compressed file
(`.json.gz`) with all settings and network profiles (and, if you tick the box, the
administrator account with its encrypted password). "Import" re-validates every setting,
applies it and restarts the services: handy to reinstall a Pi or set up a second one.
Imported network profiles are saved but not activated. The file contains the Wi-Fi
passwords: keep it somewhere safe.

### 📊 System

A **btop**-style monitor, refreshed every 2 seconds: CPU (graph, each core,
frequency, temperature), **power** (needle gauges with the ideal zone: processor core
voltage, 5 V input on models that measure it, PiSugar battery, and under-voltage alert),
memory, disks (used/free), network (throughput) and the most active processes.

> The Pi Zero 2W does not measure its 5 V input voltage: its firmware only reports a drop
> below 4.63 V. The 5 V needle gauge only appears on a Pi 5.

### 👤 Account

Change the username and password for `/admin`.

> ⚠️ The `/admin` page is password-protected, but the site runs over HTTP (not
> encrypted). Keep it on a trusted network (home, AREDN), never directly
> on the Internet.

Then check your iGate at `https://aprs.fi/#!call=a%2FYOUR-CALLSIGN-10`.

---

## The web interface

The interface is bilingual French / English: a **FR | EN** switch at the top of every page,
and a default language setting in Admin → Configuration ("Langue de l'interface" /
interface language; default is automatic, following the browser language).

- **📦 Packets**: all received packets, filterable by date and callsign, **decoded in
  plain language** with each station's APRS icon: position (with distance and bearing from
  the iGate, and a link to the map), speed, course, altitude, weather (°C, km/h, hPa, mm),
  messages and acks, objects (repeaters…), status, telemetry, radio or software
  type, and "direct" or "via" the digipeater that relayed it. Compressed and
  Mic-E formats (Kenwood, Yaesu radios…) are decoded. The raw packet is still
  one click away.
- **🗺️ Map**: OpenStreetMap map of the stations (last hour to 7 days), refreshed every
  30 seconds. Each station shows how old its position is (green: under 10 min, orange: under
  an hour, grey: older). **Tracks** link successive positions over the chosen duration
  (15 min to 24 h). **Favorite groups** (kept in your browser) show only some stations: check
  or uncheck to edit them, or type a callsign (`VE2ABC*` = all of VE2ABC's stations). The
  "Follow" option re-centers the map on the displayed stations at every refresh. The
  "Offline mode" map has the same features (same groups), using cached tiles only.
- **📴 Offline mode**: the same map, but using only the tiles already cached on the Pi.

### Map tile cache

The Pi keeps a copy of every map tile displayed (in `/var/www/aprs/tiles`):

1. when you browse the map, each tile is downloaded **only once** from
   OpenStreetMap and then served by the Pi, for you and for every other user (AREDN);
2. browsers also keep tiles for 30 days in their own cache;
3. without Internet, the map keeps showing everything already cached, and
   **Offline mode** shows exactly what is available (never-seen areas in grey).

The **"Pre-download tiles"** button in `/admin` fills the cache around the
iGate ahead of time. Every night, the cache is trimmed back below the chosen maximum size.

---

## Updating the kit

Your settings and data are kept:

```bash
cd ~/iGate-APRS-avec-un-Raspberry-Pi-Zero-2W
git pull
sudo ./install.sh --skip-upgrade
```

## Backup and restore

Automatic backups (frequency set in `/admin`) go to `~/backups/`.
They contain the settings, the database and all generated files.

```bash
ls -lh ~/backups/                                   # list backups
sudo ~/backup-aprs-config.sh                        # force a backup
sudo ~/restore-aprs-config.sh ~/backups/aprs-igate-backup-DATE.tar.gz
```

## Optimising the gain

```bash
~/test-direwolf-gain.sh            # tests 0, 20, 35, 40, 45, 49 (≈ 6 minutes)
~/test-direwolf-gain.sh 30 35 40   # or your own values
```

The easiest way: `/admin` → Direwolf → **"Test gains"**, then "Use" on the
best result. From the command line, then enter the best gain in `/admin` → Direwolf.

| Gain | Use |
|---|---|
| 20-30 | Urban area, many strong signals |
| 35-40 | General use, good compromise |
| 45-49 | Rural area, weak signals |
| 49.6 | Maximum: risk of overload |

In the Direwolf log, a good setting gives `audio level = 50-70`. Around 10-20,
the gain is too low; above 95, the signal is clipping.

## Useful commands

```bash
sudo systemctl status aprs-igate aprs-parser nginx   # service status
sudo journalctl -u aprs-igate -f                     # live packets
sudo journalctl -u aprs-parser -f                    # database inserts
cat /var/lib/aprs-igate/apply.log                    # /admin log
sudo aprs-admin-passwd                               # change the /admin account
sqlite3 /var/www/aprs/packets.db "SELECT callsign, COUNT(*) c FROM packets GROUP BY callsign ORDER BY c DESC LIMIT 10;"
```

## Publishing on AREDN

In your AREDN node's interface → **Local Services**:

1. add a **Host alias** (e.g. `yourcallsign-aprs-igate`) pointing to the Pi's IP;
2. add a **Generic URL** service: name `APRS-iGate-YOURCALLSIGN`, protocol `http`,
   the host created above, port `80`.

## PiSugar 3 (optional)

The PiSugar is installed by hand, because its installer asks questions:

```bash
sudo raspi-config nonint do_i2c 0     # enable I2C
wget https://cdn.pisugar.com/release/pisugar-power-manager.sh
bash pisugar-power-manager.sh -c release
```

Battery management: `http://<pi-ip>:8421`. To power it on: short press, then long press.

As soon as the PiSugar is installed and running, a **🔋 PiSugar** link (with the battery
level) appears in the `/admin` tabs. No PiSugar, no link.

---

## How it works (for the curious)

```
 RTL-SDR ─► rtl_fm ─► Direwolf ─► APRS-IS (aprs.fi)
                         │
                   systemd journal
                         │
                parse_direwolf.py ─► packets.db ─► nginx + PHP ─► browser
                                                        │
 browser ─► /admin ─► settings.json + request ─► aprs-admin.path (systemd)
                                                        │
                                       aprs-apply-config (root): validates,
                                       regenerates direwolf.conf, services, cron
```

| Item | Location on the Pi |
|---|---|
| Settings (written by `/admin`) | `/var/lib/aprs-igate/settings.json` |
| `/admin` account (bcrypt-hashed password) | `/var/lib/aprs-igate/admin-auth.json` |
| Settings description (form + validation) | `web/lib/settings.schema.json` |
| Applying settings | `/usr/local/sbin/aprs-apply-config` |
| Creating / changing the `/admin` account | `/usr/local/sbin/aprs-admin-passwd` |
| Templates for generated files | `/usr/local/share/aprs-igate/templates/` |
| Direwolf configuration (generated) | `~/direwolf.conf` |
| Database | `/var/www/aprs/packets.db` |
| Tile cache | `/var/www/aprs/tiles/` |
| Installation log | `/var/log/aprs-igate-install.log` |

The web server has no root privileges: `/admin` drops a request into
`/var/lib/aprs-igate/queue/`. Systemd detects it and runs `aprs-apply-config`,
which **revalidates every value** before touching any system file.

**Adding a setting**: describe it in `settings.schema.json` (label, help, type,
validation) and it shows up in `/admin` (Direwolf tab if its section has `"page": "direwolf"`). Then use it in a template in
`templates/` or in `aprs-apply-config`.

## Repository layout

```
install.sh                 install / update
backend/aprs-apply-config  applies the settings (root)
backend/aprs-admin-passwd  creates / changes the /admin account
parser/parse_direwolf.py   Direwolf log -> SQLite database
web/                       web interface (index, offline map, /admin with its tabs, lib/)
templates/                 templates: direwolf.conf, systemd services, nginx, cron, udev
scripts/                   watchdog, backup, restore, gain, tiles
```

## Credits

- APRS symbols: aprs.fi high-resolution set by Heikki Hannikainen, OH7LZB
  (<https://github.com/hessu/aprs-symbols>), downloaded by `install.sh`.
- Maps: © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors.
- [Dire Wolf](https://github.com/wb2osz/direwolf) by John Langner, WB2OSZ.

## License

This project is distributed under the **GNU GPL v3.0** license (see [LICENSE](LICENSE)).
You may use, modify and redistribute it freely, provided that any
modified versions you share also remain under GPL v3.0, with their source code.

73! — banshee1971
