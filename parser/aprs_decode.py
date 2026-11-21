"""
aprs_decode — décodage des paquets APRS en informations lisibles.

    from aprs_decode import decode
    d = decode('[0.4] VE2ABC-9>APRS,WIDE1-1,WIDE2-1:!4530.12N/07334.56W>Mobile')

Retourne un dictionnaire (sérialisable en JSON) : type, position, symbole,
vitesse, altitude, météo, message, objet, appareil, chemin, etc.
Formats pris en charge : position (normale, compressée, Mic-E), avec ou sans
heure, objets, items, statut, messages, météo, télémétrie, NMEA ($GPRMC,
$GPGGA), capacités, paquets tiers.
"""
import math
import re

KNOT_KMH = 1.852
MPH_KMH = 1.609344
FT_M = 0.3048

# ------------------------------------------------------------------ symboles
# (table, code) -> (emoji, nom). Table '/' = primaire, '\\' = alternative.
SYMBOLS = {
    '/!': ('🚓', 'Poste de police'), '/#': ('📡', 'Digipeater'), '/$': ('☎️', 'Téléphone'),
    '/%': ('📡', 'DX cluster'), '/&': ('🌐', 'Passerelle HF'), "/'": ('🛩️', 'Petit avion'),
    '/(': ('🛰️', 'Station mobile satellite'), '/)': ('♿', 'Fauteuil roulant'), '/*': ('🛷', 'Motoneige'),
    '/+': ('➕', 'Croix-Rouge'), '/,': ('🏕️', 'Scouts'), '/-': ('🏠', 'Maison'), '/.': ('❌', 'X'),
    '//': ('🔴', 'Point rouge'), '/:': ('🔥', 'Feu'), '/;': ('⛺', 'Camping'), '/<': ('🏍️', 'Moto'),
    '/=': ('🚆', 'Train'), '/>': ('🚗', 'Voiture'), '/?': ('🖥️', 'Serveur'), '/@': ('🌀', 'Ouragan'),
    '/A': ('🩺', 'Poste de secours'), '/B': ('📠', 'BBS'), '/C': ('🛶', 'Canot'), '/E': ('👁️', 'Événement'),
    '/F': ('🚜', 'Tracteur'), '/G': ('🔲', 'Grille'), '/H': ('🏨', 'Hôtel'), '/I': ('🌐', 'TCP/IP'),
    '/K': ('🏫', 'École'), '/L': ('💻', 'Ordinateur'), '/M': ('🍎', 'MacAPRS'), '/N': ('📡', 'Station NTS'),
    '/O': ('🎈', 'Ballon'), '/P': ('🚓', 'Police'), '/R': ('🚙', 'VR'), '/S': ('🚀', 'Navette'),
    '/T': ('📺', 'SSTV'), '/U': ('🚌', 'Autobus'), '/V': ('📺', 'ATV'), '/W': ('🌦️', 'Service météo'),
    '/X': ('🚁', 'Hélicoptère'), '/Y': ('⛵', 'Voilier'), '/Z': ('🪟', 'Windows'), '/[': ('🚶', 'Piéton'),
    '/\\': ('🔺', 'Triangle (DF)'), '/]': ('📮', 'Bureau de poste'), '/^': ('✈️', 'Avion'),
    '/_': ('⛅', 'Station météo'), '/`': ('📡', 'Antenne parabolique'), '/a': ('🚑', 'Ambulance'),
    '/b': ('🚲', 'Vélo'), '/c': ('🏢', 'Poste de commandement'), '/d': ('🚒', 'Caserne de pompiers'),
    '/e': ('🐎', 'Cheval'), '/f': ('🚒', 'Camion de pompiers'), '/g': ('🪂', 'Planeur'),
    '/h': ('🏥', 'Hôpital'), '/i': ('🏝️', 'IOTA'), '/j': ('🚙', 'Jeep'), '/k': ('🛻', 'Camionnette'),
    '/l': ('💻', 'Portable'), '/m': ('📻', 'Mic-E répéteur'), '/n': ('📍', 'Nœud'), '/o': ('🏛️', 'Centre d\'urgence'),
    '/p': ('🐕', 'Chien'), '/q': ('🔲', 'Grille'), '/r': ('📻', 'Répéteur'), '/s': ('🚢', 'Bateau'),
    '/t': ('⛽', 'Relais routier'), '/u': ('🚚', 'Camion lourd'), '/v': ('🚐', 'Fourgonnette'),
    '/w': ('💧', 'Station hydrologique'), '/x': ('🪟', 'X-APRS'), '/y': ('📡', 'Yagi'), '/z': ('🏚️', 'Abri'),
    '\\#': ('📡', 'Digipeater'), '\\&': ('🌐', 'iGate / passerelle'), '\\-': ('🏠', 'Maison (HF)'),
    '\\>': ('🚗', 'Voiture'), '\\_': ('⛅', 'Station météo'), '\\a': ('🆘', 'ARES / urgence'),
    '\\k': ('🚙', 'VUS'), '\\n': ('🔺', 'Triangle'), '\\r': ('📶', 'Tour d\'antenne'), '\\s': ('🚤', 'Bateau'),
    '\\u': ('🚚', 'Camion'), '\\v': ('🚐', 'Fourgonnette'), '\\y': ('🌀', 'Tornade'), '\\[': ('⛈️', 'Orage'),
    '\\0': ('⭕', 'Cercle'), '\\!': ('🚨', 'Urgence'), '\\%': ('⚡', 'Centrale électrique'),
    '\\\'': ('💥', 'Écrasement'), '\\O': ('🎈', 'Ballon'), '\\^': ('✈️', 'Avion'), '\\j': ('🚧', 'Travaux'),
    '\\m': ('🪧', 'Borne kilométrique'), '\\h': ('🏪', 'Magasin'), '\\w': ('🌊', 'Inondation'),
    '\\x': ('🧪', 'Laboratoire'), '\\z': ('🏠', 'Abri'), '\\;': ('🏞️', 'Parc'), '\\<': ('🚩', 'Drapeau'),
    '\\(': ('☁️', 'Nuageux'), '\\*': ('❄️', 'Neige'), '\\:': ('🧊', 'Grêle'), '\\D': ('🌧️', 'Bruine'),
    '\\E': ('🌫️', 'Fumée'), '\\F': ('🌨️', 'Pluie verglaçante'), '\\I': ('🌧️', 'Averses'),
    '\\J': ('⚡', 'Éclairs'), '\\U': ('☀️', 'Ensoleillé'), '\\`': ('🌧️', 'Pluie'), '\\e': ('🌨️', 'Grésil'),
    '\\g': ('🚩', 'Fanions'), '\\p': ('⛅', 'Partiellement nuageux'), '\\{': ('🌫️', 'Brouillard'),
}

# Préfixes du champ destination -> appareil ou logiciel
TOCALLS = [
    ('APDW', 'Dire Wolf'), ('APDR', 'APRSdroid'), ('APMI', 'Microsat WX3in1'), ('APN3', 'Kantronics KPC-3'),
    ('APN9', 'Kantronics KPC-9612'), ('APNU', 'UIdigi'), ('APNX', 'TNC-X'), ('APOT', 'OpenTracker'),
    ('APTT', 'TinyTrak'), ('APTW', 'WXtrak'), ('APK003', 'Kenwood TH-D72'), ('APK004', 'Kenwood TH-D74'),
    ('APK005', 'Kenwood TH-D75'), ('APK0', 'Kenwood TH-D7'), ('APK1', 'Kenwood TM-D700/D710'),
    ('APWW', 'APRSIS32'), ('APWM', 'APRSISCE'), ('APX', 'Xastir'), ('APAGW', 'AGWtracker'),
    ('APY01D', 'Yaesu FT1D'), ('APY02D', 'Yaesu FT2D'), ('APY03D', 'Yaesu FT3D'), ('APY05D', 'Yaesu FT5D'),
    ('APY100', 'Yaesu FTM-100D'), ('APY200', 'Yaesu FTM-200D'), ('APY300', 'Yaesu FTM-300D'),
    ('APY400', 'Yaesu FTM-400'), ('APY500', 'Yaesu FTM-500D'), ('APRX', 'aprx'), ('APLRG', 'LoRa iGate'),
    ('APLRT', 'LoRa tracker'), ('APLG', 'LoRa (OE5BPA)'), ('APDG', 'Passerelle D-Star / DMR'),
    ('APBM', 'BrandMeister DMR'), ('APGRWO', 'Graywolf'), ('APAT', 'AnyTone'), ('APAVT', 'AVRT tracker'),
    ('APOSB', 'OpenSPOT'), ('APOSW', 'OpenSPOT'), ('APMG', 'PiGate'), ('APWL', 'Winlink'),
    ('APCHP', 'WPSD / Pi-Star'), ('APDST', 'dsTracker'), ('APJ', 'JavAPRS'), ('APU2', 'UI-View'),
    ('APZMDR', 'HaMDR'), ('APSTM', 'STM32 tracker'), ('APTCHE', 'PU2TDE tracker'), ('APE', 'PIC-E'),
    ('APIN', 'PinPoint'), ('APOA', 'OpenAPRS'), ('APMT', 'Micro-Trak'), ('APNK', 'Kenwood TM-D710 (TNC)'),
    ('APRS', 'générique'),
]

MICE_STATUS = ['Hors service (Off Duty)', 'En route', 'En service', 'De retour', 'Engagé',
               'Spécial', 'Priorité', '🚨 URGENCE']


def symbol_info(table, code):
    key = (table if table in '/\\' else '\\') + code
    emoji, name = SYMBOLS.get(key, ('📍', 'Symbole ' + table + code))
    if table not in '/\\':
        name += ' (surimpression ' + table + ')'
    return emoji, name


def device_from_tocall(dest):
    call = dest.split('-')[0].upper()
    for prefix, name in TOCALLS:
        if call.startswith(prefix):
            return name
    return None


def base91(s):
    v = 0
    for c in s:
        v = v * 91 + (ord(c) - 33)
    return v


# ----------------------------------------------------------------- en-tête
HEADER_RE = re.compile(r'^(?:\[[^\]]*\]\s*)?([A-Za-z0-9-]{1,9})>([A-Za-z0-9-]{1,9})((?:,[^:,]+)*):(.*)$', re.S)


def unescape(raw):
    """Direwolf affiche les octets non imprimables sous la forme <0x1c>."""
    return re.sub(r'<0x([0-9a-fA-F]{2})>', lambda m: chr(int(m.group(1), 16)), raw)


def path_info(path):
    hops = [h for h in path.split(',') if h]
    # TCPIP* et qAx viennent d'Internet, pas d'un digipeater radio
    used = [h.rstrip('*') for h in hops if h.endswith('*') and not re.match(r'^(TCPIP|TCPXX|qA)', h)]
    # Le dernier digipeater identifié (pas un alias WIDEn-N / RELAY / TRACE)
    named = [h for h in used if not re.match(r'^(WIDE|RELAY|TRACE|TCPIP|qA)', h, re.I)]
    if not used:
        return {'direct': True, 'via': None, 'hops': hops}
    return {'direct': False, 'via': named[-1] if named else 'digipeater non identifié', 'hops': hops}


# --------------------------------------------------------------- positions
UNCOMP_RE = re.compile(r'^(\d{2})([\d ]{2})\.([\d ]{2})([NnSs])(.)(\d{3})([\d ]{2})\.([\d ]{2})([EeWw])(.)')
COMP_RE = re.compile(r'^([/\\A-Za-j])([!-{]{4})([!-{]{4})(.)(.)(.)(.)')


def parse_uncompressed(s, out):
    m = UNCOMP_RE.match(s)
    if not m:
        return None
    la_d, la_m, la_h, ns, table, lo_d, lo_m, lo_h, ew, code = m.groups()
    amb = (la_m + la_h).count(' ')
    lat = int(la_d) + float((la_m + '.' + la_h).replace(' ', '0')) / 60
    lon = int(lo_d) + float((lo_m + '.' + lo_h).replace(' ', '0')) / 60
    if ns in 'Ss':
        lat = -lat
    if ew in 'Ww':
        lon = -lon
    if not (-90 <= lat <= 90 and -180 <= lon <= 180):
        return None
    out.update(lat=round(lat, 5), lon=round(lon, 5), table=table, code=code)
    if amb:
        out['ambiguity'] = amb
    return s[19:]


def parse_compressed(s, out):
    m = COMP_RE.match(s)
    if not m:
        return None
    table, y, x, code, c, sp, t = m.groups()
    if table in 'abcdefghij':
        table = str('abcdefghij'.index(table))
    lat = 90 - base91(y) / 380926.0
    lon = -180 + base91(x) / 190463.0
    if not (-90 <= lat <= 90 and -180 <= lon <= 180):
        return None
    out.update(lat=round(lat, 5), lon=round(lon, 5), table=table, code=code, compressed=True)
    if c != ' ':
        tb = ord(t) - 33
        if c == '{':
            out['range_km'] = round(2 * 1.08 ** (ord(sp) - 33) * MPH_KMH, 1)
        elif (tb & 0x18) == 0x10:
            alt_ft = 1.002 ** ((ord(c) - 33) * 91 + (ord(sp) - 33))
            out['altitude_m'] = round(alt_ft * FT_M)
        elif '!' <= c <= 'z':
            course = (ord(c) - 33) * 4
            speed = 1.08 ** (ord(sp) - 33) - 1
            if course:
                out['course'] = course
            out['speed_kmh'] = round(speed * KNOT_KMH, 1)
    return s[13:]


def parse_mic_e(dest, info, out):
    d = dest.split('-')[0].upper()
    if len(d) < 6 or len(info) < 9:
        return None
    digits, msg_bits, custom = [], [], False
    for ch in d[:6]:
        if ch.isdigit():
            digits.append(ch); msg_bits.append(0)
        elif 'A' <= ch <= 'J':
            digits.append(str(ord(ch) - 65)); msg_bits.append(1); custom = True
        elif 'P' <= ch <= 'Y':
            digits.append(str(ord(ch) - 80)); msg_bits.append(1)
        elif ch == 'K':
            digits.append('0'); msg_bits.append(1); custom = True
        elif ch == 'L':
            digits.append('0'); msg_bits.append(0)
        elif ch == 'Z':
            digits.append('0'); msg_bits.append(1)
        else:
            return None
    lat = int(digits[0] + digits[1]) + float(digits[2] + digits[3] + '.' + digits[4] + digits[5]) / 60
    if not ('P' <= d[3] <= 'Z'):
        lat = -lat
    lon_off = 'P' <= d[4] <= 'Z'
    west = 'P' <= d[5] <= 'Z'
    deg = ord(info[1]) - 28
    if lon_off:
        deg += 100
    if 180 <= deg <= 189:
        deg -= 80
    elif 190 <= deg <= 199:
        deg -= 190
    mins = ord(info[2]) - 28
    if mins >= 60:
        mins -= 60
    hun = ord(info[3]) - 28
    lon = deg + (mins + hun / 100.0) / 60
    if west:
        lon = -lon
    if not (-90 <= lat <= 90 and -180 <= lon <= 180):
        return None
    sp, dc, se = ord(info[4]) - 28, ord(info[5]) - 28, ord(info[6]) - 28
    speed = sp * 10 + dc // 10
    course = (dc % 10) * 100 + se
    if speed >= 800:
        speed -= 800
    if course >= 400:
        course -= 400
    out.update(lat=round(lat, 5), lon=round(lon, 5), code=info[7], table=info[8], mic_e=True)
    if speed:
        out['speed_kmh'] = round(speed * KNOT_KMH, 1)
    if course:
        out['course'] = course
    idx = msg_bits[0] * 4 + msg_bits[1] * 2 + msg_bits[2]
    out['status'] = ('Personnalisé ' + str(7 - idx)) if custom and idx else MICE_STATUS[7 - idx]
    rest = info[9:]
    if rest[:1] in ('>', ']', '`', "'"):
        rest = rest[1:]
    m = re.match(r'^(.{3})\}', rest)
    if m and all(33 <= ord(ch) <= 123 for ch in m.group(1)):
        out['altitude_m'] = base91(m.group(1)) - 10000
        rest = rest[4:]
    # Signature d'appareil Kenwood / Yaesu en fin de commentaire
    rest = re.sub(r'(_[ "#$%&()*0-9]|[=^])$', '', rest)
    return rest


def parse_extension(rest, out):
    """Extensions après la position : cap/vitesse, PHG, RNG, altitude."""
    if out.get('code') == '_':                       # météo : géré à part
        return rest
    m = re.match(r'^(\d{3})/(\d{3})', rest)
    if m:
        c, s = int(m.group(1)), int(m.group(2))
        if 0 < c <= 360:
            out['course'] = c
        if s:
            out['speed_kmh'] = round(s * KNOT_KMH, 1)
        rest = rest[7:]
        b = re.match(r'^/(\d{3})/(\d{3})', rest)    # extension DF (cap/qualité) ignorée
        if b:
            rest = rest[8:]
    m = re.match(r'^PHG(\d)(\d)(\d)(\d)', rest)
    if m:
        p, h, g, d = (int(v) for v in m.groups())
        out['phg'] = {'power_w': p * p, 'height_m': round(10 * 2 ** h * FT_M), 'gain_db': g,
                      'direction': 'omnidirectionnelle' if d == 0 else str(d * 45) + '°'}
        rest = rest[7:]
        if rest[:1] == '/':
            rest = rest[1:]
    m = re.match(r'^RNG(\d{4})', rest)
    if m:
        out['range_km'] = round(int(m.group(1)) * MPH_KMH)
        rest = rest[7:]
    return rest


def parse_altitude(rest, out):
    m = re.search(r'/A=(-?\d{5,6})', rest)
    if m:
        out['altitude_m'] = round(int(m.group(1)) * FT_M)
        rest = rest[:m.start()] + rest[m.end():]
    rest = re.sub(r'!W..!', '', rest)               # précision DAO
    return rest


def parse_weather(rest, out):
    """Données météo (champs à lettre : c s g t r p P h b L ...)."""
    w = {}
    m = re.match(r'^(\d{3}|\.{3}| {3})/(\d{3}|\.{3}| {3})', rest)
    if m:
        if m.group(1).isdigit():
            w['wind_dir'] = int(m.group(1))
        if m.group(2).isdigit():
            w['wind_kmh'] = round(int(m.group(2)) * MPH_KMH)
        rest = rest[7:]
    widths = {'c': 3, 's': 3, 'g': 3, 't': 3, 'r': 3, 'p': 3, 'P': 3, 'h': 2, 'b': 5, 'L': 3, 'l': 3, '#': 3}
    while rest and rest[0] in widths:
        k, n = rest[0], widths[rest[0]]
        val = rest[1:1 + n]
        if not re.match(r'^(-?\d+|\.+| +)$', val):
            break
        rest = rest[1 + n:]
        if not re.match(r'^-?\d+$', val):
            continue
        v = int(val)
        if k == 'c':
            w['wind_dir'] = v
        elif k == 's':
            w['wind_kmh'] = round(v * MPH_KMH)
        elif k == 'g':
            w['gust_kmh'] = round(v * MPH_KMH)
        elif k == 't':
            w['temp_c'] = round((v - 32) * 5 / 9, 1)
        elif k == 'r':
            w['rain_1h_mm'] = round(v * 0.254, 1)
        elif k == 'p':
            w['rain_24h_mm'] = round(v * 0.254, 1)
        elif k == 'P':
            w['rain_today_mm'] = round(v * 0.254, 1)
        elif k == 'h':
            w['humidity'] = 100 if v == 0 else v
        elif k == 'b':
            w['pressure_hpa'] = round(v / 10.0, 1)
        elif k in 'Ll':
            w['luminosity'] = v + (1000 if k == 'l' else 0)
    if w:
        out['weather'] = w
    return rest


def parse_timestamp(s):
    m = re.match(r'^(\d{6})([zh/])', s)
    if not m:
        return None, s
    v, kind = m.groups()
    if kind == 'h':
        txt = f'{v[0:2]}:{v[2:4]}:{v[4:6]} UTC'
    elif kind == 'z':
        txt = f'le {v[0:2]} à {v[2:4]}:{v[4:6]} UTC'
    else:
        txt = f'le {v[0:2]} à {v[2:4]}:{v[4:6]} (heure locale)'
    return txt, s[7:]


def parse_position_body(s, out):
    """Position normale ou compressée + extensions. Retourne le commentaire."""
    rest = parse_uncompressed(s, out)
    if rest is None:
        rest = parse_compressed(s, out)
        if rest is None:
            return s
    else:
        rest = parse_extension(rest, out)
    if out.get('code') == '_':
        rest = parse_weather(rest, out)
    return parse_altitude(rest, out)


def nmea_coord(v, hemi):
    if not v or '.' not in v:
        return None
    dot = v.index('.')
    deg = float(v[:dot - 2]) if dot > 2 else 0.0
    val = deg + float(v[dot - 2:]) / 60
    return -val if hemi in ('S', 'W') else val


# --------------------------------------------------------------- principal
def decode(raw):
    """Décode une ligne de paquet (format Direwolf ou TNC2). Ne lève jamais d'exception."""
    try:
        return _decode(unescape(raw.strip()))
    except Exception as e:  # un paquet mal formé ne doit jamais bloquer le parser
        return {'type': 'unknown', 'label': 'Paquet non décodé', 'error': str(e)[:80]}


def _decode(raw, depth=0):
    m = HEADER_RE.match(raw)
    if not m:
        return {'type': 'unknown', 'label': 'Paquet non reconnu'}
    src, dest, path, info = m.group(1), m.group(2), m.group(3).lstrip(','), m.group(4)
    out = {'src': src.upper(), 'dest': dest.upper()}
    out.update(path_info(path))
    dev = device_from_tocall(dest)
    if dev:
        out['device'] = dev
    if not info:
        out.update(type='empty', label='Paquet vide')
        return out
    t, body = info[0], info[1:]
    comment = ''

    if t in '!=':
        out.update(type='position', label='Position', messaging=(t == '='))
        if body[:1] == '!':                           # ancien format « !! » (Ultimeter)
            comment = body
        else:
            comment = parse_position_body(body, out)
    elif t in '/@':
        ts, body = parse_timestamp(body)
        out.update(type='position', label='Position', messaging=(t == '@'))
        if ts:
            out['time'] = ts
        comment = parse_position_body(body, out)
    elif t in ('`', "'", '\x1c', '\x1d'):
        out.update(type='position', label='Position (Mic-E)')
        rest = parse_mic_e(dest, info, out)
        comment = rest if rest is not None else body
        if rest is None:
            out['label'] = 'Mic-E non décodé'
    elif t == ';':
        name, live, body = body[:9].strip(), body[9:10], body[10:]
        ts, body = parse_timestamp(body)
        out.update(type='object', label='Objet', object={'name': name, 'live': live != '_'})
        if ts:
            out['time'] = ts
        comment = parse_position_body(body, out)
    elif t == ')':
        m2 = re.match(r'^([^!_]{3,9})([!_])(.*)$', body, re.S)
        if m2:
            out.update(type='object', label='Item', object={'name': m2.group(1).strip(), 'live': m2.group(2) == '!'})
            comment = parse_position_body(m2.group(3), out)
        else:
            comment = body
    elif t == '>':
        ts, body = parse_timestamp(body)
        out.update(type='status', label='Statut')
        if ts:
            out['time'] = ts
        comment = body
    elif t == ':' and len(body) >= 10 and body[9] == ':':
        to, text = body[:9].strip(), body[10:]
        kind, msg_id = 'message', None
        if re.match(r'^(ack|rej)[A-Za-z0-9}]{1,6}$', text):
            kind = 'ack' if text.startswith('ack') else 'rej'
        elif re.match(r'^(PARM|UNIT|EQNS|BITS)\.', text):
            kind = 'telemetry-def'
        else:
            m2 = re.search(r'\{([A-Za-z0-9]{1,5})(\}.*)?$', text)
            if m2:
                msg_id, text = m2.group(1), text[:m2.start()]
        if to.startswith('BLN'):
            kind = 'bulletin'
        labels = {'message': 'Message', 'ack': 'Accusé de réception', 'rej': 'Message refusé',
                  'telemetry-def': 'Définition de télémétrie', 'bulletin': 'Bulletin'}
        out.update(type='message', label=labels[kind], message={'to': to, 'text': text, 'id': msg_id, 'kind': kind})
    elif t == '_' and re.match(r'^\d{8}', body):
        out.update(type='weather', label='Météo', code='_', table='/', time=f'{body[0:2]}/{body[2:4]} {body[4:6]}:{body[6:8]}')
        comment = parse_weather(body[8:], out)
        if comment.startswith('c...') or comment.startswith('s'):
            pass
    elif t == 'T' and body.startswith('#'):
        parts = body[1:].split(',')
        out.update(type='telemetry', label='Télémétrie',
                   telemetry={'seq': parts[0], 'values': parts[1:6], 'bits': parts[6] if len(parts) > 6 else ''})
    elif t == '$':
        out.update(type='nmea', label='Position GPS (NMEA)')
        f = info.split(',')
        if f[0].endswith('RMC') and len(f) > 8 and f[2] == 'A':
            lat, lon = nmea_coord(f[3], f[4]), nmea_coord(f[5], f[6])
            if lat is not None and lon is not None:
                out.update(lat=round(lat, 5), lon=round(lon, 5), table='/', code='>')
            if f[7]:
                out['speed_kmh'] = round(float(f[7]) * KNOT_KMH, 1)
            if f[8]:
                out['course'] = int(float(f[8]))
        elif f[0].endswith('GGA') and len(f) > 9 and f[6] not in ('', '0'):
            lat, lon = nmea_coord(f[2], f[3]), nmea_coord(f[4], f[5])
            if lat is not None and lon is not None:
                out.update(lat=round(lat, 5), lon=round(lon, 5), table='/', code='>')
            if f[9]:
                out['altitude_m'] = round(float(f[9]))
    elif t == '<':
        out.update(type='capabilities', label='Capacités de la station')
        comment = body
    elif t == '}' and depth < 2:
        inner = _decode(body, depth + 1)
        inner['third_party'] = {'via': src.upper(), 'path': path}
        return inner
    else:
        out.update(type='other', label='Autre format')
        comment = info

    if 'code' in out and 'table' in out:
        out['emoji'], out['symbol_name'] = symbol_info(out['table'], out['code'])
        if out['code'] == '_' and out.get('type') == 'position':
            out['label'] = 'Station météo'
    comment = comment.strip()
    if comment:
        out['comment'] = comment[:200]
    return out


if __name__ == '__main__':
    import json
    import sys
    for line in sys.stdin:
        print(json.dumps(decode(line), ensure_ascii=False))
