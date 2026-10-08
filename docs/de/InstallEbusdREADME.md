# Installationskurzanleitung ebusd

## Inhaltsverzeichnis
1. [Überblick](#1-überblick)
2. [ebusd installieren](#2-ebusd-installieren)
3. [ebusd einrichten](#3-ebusd-einrichten)
4. [Überprüfen](#4-überprüfen)
5. [Mit ebusctl Daten lesen und schreiben](#5-mit-ebusctl-daten-lesen-und-schreiben)
6. [Über HTTP ebusd-Daten abfragen](#6-über-http-ebusd-daten-abfragen)

> [!TIP]
> Die ausführliche Beschreibung von ebusd steht im [ebusd Wiki](https://github.com/john30/ebusd/wiki), alle Startoptionen im Kapitel [2. Run](https://github.com/john30/ebusd/wiki/2.-Run).

## 1. Überblick

Die Kette hat drei Glieder:

```
eBUS ── Adapter ── ebusd ──┬── HTTP ──► Symcon (Konfiguration, Knöpfe)
                           └── MQTT ──► MQTT Server in Symcon (laufende Werte)
```

Der Adapter hängt am eBUS der Heizung. ebusd läuft auf einem Rechner im Netz (Raspberry Pi, Container, VM), spricht mit dem Adapter und stellt die Daten zweimal bereit: per HTTP für die Konfiguration und per MQTT für die laufenden Werte. Beide Wege müssen funktionieren, siehe [So kommen die Daten herein](../../README.md#so-kommen-die-daten-herein).

Der Rechner mit ebusd braucht eine feste IP-Adresse (im Router zuweisen oder statisch einrichten), denn sie steht später als Host in der Symcon-Instanz.

## 2. ebusd installieren

Es braucht ein Paket **mit MQTT-Unterstützung**. Bei den Debian-Paketen erkennt man es an `_mqtt1` im Dateinamen.

Das frühere apt-Repository von ebusd gibt es nicht mehr. Wer es noch eingetragen hat, entfernt den Eintrag, sonst scheitert jedes `apt update`:
```bash
sudo rm /etc/apt/sources.list.d/ebusd.list
```

### a) Debian-Paket (Raspberry Pi, Debian, VM)

Auf der Seite [ebusd releases](https://github.com/john30/ebusd/releases) das passende Paket wählen. Der Name enthält Architektur und Debian-Version, z. B. `ebusd-26.1_amd64-trixie_mqtt1.deb`. Beides zeigen
```bash
dpkg --print-architecture
cat /etc/os-release
```
Dann herunterladen und installieren:
```bash
wget https://github.com/john30/ebusd/releases/download/26.1/ebusd-26.1_amd64-trixie_mqtt1.deb
sudo apt install ./ebusd-26.1_amd64-trixie_mqtt1.deb
sudo systemctl enable ebusd
```
`apt` zieht fehlende Abhängigkeiten wie `libmosquitto1` selbst nach. Ein Update geht genauso mit dem neueren Paket; die Konfiguration bleibt dabei erhalten.

### b) Container auf Proxmox

Die [Proxmox VE Community Scripts](https://community-scripts.org/scripts/ebusd) legen einen fertigen LXC-Container mit Debian und ebusd an. In der Shell des Proxmox-Hosts:
```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/community-scripts/ProxmoxVE/main/ct/ebusd.sh)"
```
Die Standardeinstellungen (1 CPU, 512 MB RAM, 2 GB Platte) reichen. Der Container bekommt seine IP per DHCP; diese im Router fest zuweisen. Das Skript installiert das Paket mit MQTT und richtet den Dienst ein. Weiter geht es mit [3. ebusd einrichten](#3-ebusd-einrichten) im Container.

### c) Docker

Das offizielle Image heißt `john30/ebusd` ([Docker Hub](https://hub.docker.com/r/john30/ebusd)). Die Optionen aus Abschnitt 3 gelten genauso. Den Container am besten mit Bridge und eigener IP erstellen, sonst gibt es leicht Portkonflikte (8080).

## 3. ebusd einrichten

Die Startoptionen stehen in `/etc/default/ebusd` in der Zeile `EBUSD_OPTS`. Für Symcon reicht diese Zeile:
```
EBUSD_OPTS="--scanconfig -d <Adapter> --accesslevel=* --pollinterval=5 --httpport=8080 --mqtthost=<IP> --mqttport=<Port> --mqttuser=<Benutzer> --mqttpass=<Passwort> --mqttjson"
```
Nach jeder Änderung den Dienst neu starten:
```bash
sudo systemctl restart ebusd
```

<a id="6-symcon-relevante-konfigurationsparameter"></a>
**Die Optionen im Einzelnen**

- `-d <Adapter>` sagt ebusd, wo der Adapter ist:
  - Netzwerkadapter (eBUS Adapter v5, Adapter Shield C6 u. a. per WLAN oder Ethernet): `ens:<IP des Adapters>:9999`. Die Weboberfläche des Adapters zeigt diesen Eintrag unter „ebusd device string“ fertig an; am einfachsten von dort kopieren.
  - Adapter am USB: `ens:/dev/ttyACM0` (Nummer je nach System)
  - aufgesteckt auf den Raspberry Pi: `ens:/dev/ttyAMA0 --latency=50`
  - LAN-Gateway von Esera: `<IP>:<Port>` (IP, Port und Betriebsart „TCP-Server“ im configtool setzen)
- `--scanconfig` sucht beim Start die Geräte am Bus und lädt die passenden Konfigurationsdateien aus dem Netz. Ein `--configpath` ist dafür nicht nötig.
- `--accesslevel=*` gibt alle Meldungen frei, auch die schreibbaren.
- `--httpport=8080` schaltet die HTTP-Schnittstelle ein. Ohne diese Option hat ebusd keine, und die Symcon-Instanz meldet „nicht erreichbar“. Der Port gehört in die Instanz.
- `--mqtthost` ist die IP-Adresse des Symcon-Systems, `--mqttport` der Port im Server Socket der MQTT Server Instanz, `--mqttuser` und `--mqttpass` Benutzer und Passwort aus der MQTT Server Instanz. Stimmt eines davon nicht, kommen keine laufenden Werte an.
- `--mqttjson` schickt die Werte als JSON, so wie das Modul sie erwartet.

## 4. Überprüfen

### Verbindung zum Adapter

Bei Netzwerkadaptern zeigt die Weboberfläche des Adapters zwei Zeilen: „eBUS signal: acquired“ heißt, der Adapter hört den Bus. „ebusd connected: yes“ heißt, ebusd hat sich mit ihm verbunden. Steht dort „no“, stimmt meist die Adresse hinter `-d` nicht, oder ebusd wurde nach der Änderung nicht neu gestartet.

### Log

ebusd schreibt nach `/var/log/ebusd.log`. Ein guter Start sieht so aus:
```text
2022-11-11 17:08:44.010 [main notice] ebusd 22.4.v22.4 started with auto scan on device /dev/ttyebus
2022-11-11 17:08:44.029 [bus notice] bus started with own address 31/36
2022-11-11 17:08:44.033 [mqtt notice] connection established
2022-11-11 17:08:44.040 [bus notice] signal acquired
2022-11-11 17:08:46.003 [bus notice] new master 71, master count 2
```
Fehlt `[mqtt notice] connection established`, erreicht ebusd den MQTT Server in Symcon nicht. Prüfen lässt sich das mit
```bash
grep mqtt /var/log/ebusd.log | tail
```

In den ersten Minuten läuft der Scan der Geräte. Zeilen wie
```text
[main notice] scan completed 3 time(s), check again
[update notice] received unknown MS cmd: 1008b5110101 / 094a3f600eff6f0000ff
```
sind dabei normal. „unknown“ heißt nur, dass ebusd für dieses Telegramm keine Definition hat; die Daten anderer Geräte am Bus laufen ständig mit.

### Gefundene Geräte

Ist der Scan durch, zeigt `ebusctl i`, was ebusd gefunden hat (Auszug, Wärmepumpe mit Regler):
```text
signal: acquired
scan: finished
address 08: slave #11, scanned "MF=Vaillant;ID=HMUX0;SW=0406;HW=0504"
address 15: slave #2, scanned "MF=Vaillant;ID=CTLV3;SW=0808;HW=8004", loaded "vaillant/15.ctlv3.csv"
address 76: slave #9, scanned "MF=Vaillant;ID=VWZIO;SW=0500;HW=0504", loaded "vaillant/76.vwzio.csv"
```
Wichtig ist das Wort **loaded**: Nur für diese Geräte hat ebusd eine Konfiguration, und nur sie liefern Werte. Der Name der geladenen Datei ergibt den Schaltkreis für die Symcon-Instanz (`vaillant/15.ctlv3.csv` → `ctlv3`). Für jeden Schaltkreis wird eine eigene Instanz angelegt.

Steht bei einem Gerät nur **scanned**, kennt ebusd es nicht. Im Log steht dann z. B.
```text
unable to load scan config 08: no file from vaillant with prefix 08 matches ID "hmux0", SW0406, HW0504
```
Das betrifft vor allem neue Gerätegenerationen, etwa Wärmepumpen mit der Kennung `HMUX0`. Abhilfe kommt dann aus dem Projekt [ebusd-configuration](https://github.com/john30/ebusd-configuration/issues); im Modul lässt sich daran nichts ändern.

Über den scan-Befehl (z. B. `ebusctl scan 15`) lässt sich zusätzlich die Produkt-ID des Gerätes anzeigen.

## 5. Mit ebusctl Daten lesen und schreiben
An dieser Stelle lohnt ein Blick in die Konfigurationsdatei. Sie findet sich unter https://github.com/john30/ebusd-configuration/tree/master/ebusd-2.1.x/de/vaillant

Darin steht z. B. der Eintrag zur Heizkurve des ersten Heizkreises:
```text
r;w,,Hc1HeatCurve,HeatCurve Heizkreis 1,,,,0F00,,,EXP,,,heating curve of Hc1
```
Die wichtigsten Informationen daraus: r = lesbar, w = schreibbar, Hc1HeatCurve = Name des Parameters.

Damit lassen sich die Daten über ebusctl lesen und schreiben:
```text
pi@raspberrypi:~ $ ebusctl
localhost: r -c 700 Hc1HeatCurve
0.58

localhost: w -c 700 Hc1HeatCurve 0.5
done

localhost: r -c 700 Hc1HeatCurve
0.5
```
Die Beschreibung aller Befehle steht im Kapitel [3.1 TCP client commands](https://github.com/john30/ebusd/wiki/3.1.-TCP-client-commands).

## 6. Über HTTP ebusd-Daten abfragen

Zum Schluss im Browser prüfen, ob die HTTP-Schnittstelle antwortet (Port aus `--httpport`):

```http
http://<ebusd-Rechner>:8080/data
```

Es sollten die Daten der erkannten Geräte kommen:

```text
{
 "700": {
  "messages": {   "AdaptHeatCurve": {
    "name": "AdaptHeatCurve",
    "passive": false,
    "write": false,
    "lastup": 1646136520,
    "zz": 21,
    "fields": {
     "yesno": {"value": "nein"}
    }
   },
   "BankHolidayEndPeriod": {
    "name": "BankHolidayEndPeriod",
    "passive": false,
    "write": false,
    "lastup": 1643318788,
    "zz": 21,
    "fields": {
     "hto": {"value": "01.01.2015"}
    }
   }, ...
```

Damit ist ebusd fertig eingerichtet; weiter geht es in Symcon mit der [Konfiguration der Instanz](../../README.md#4-konfiguration).
