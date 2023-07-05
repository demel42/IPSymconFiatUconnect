[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

Anbindung and das Uconnect-Portal von _Fiat Chrysler Automobiles_ (FCA) zum Auslesen von Daten der Fiat-Modelle. 
Das Modul beschränkt sich auf das Auslesen der Daten, Kommandos zur Steuerung werden nicht unterstützt; bisher nur mit einem Fiat 500E getestet

## 2. Voraussetzungen

- IP-Symcon ab Version 6.0<br>
- Fiat-Modell mit eingerichtetem Zugang zum Uconnect-Portal

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff *FiatUconnect* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL `https://github.com/demel42/FiatUconnect.git` installiert werden.

### b. Einrichtung in IPS

In IP-Symcon die Funktion _Instanz hinzufügen_ auswählen und als Hersteller _Fiat_ angeben.
Benutzerkennung und Passwort des Uconnect-Portal sowie die Fahrgestellnummer angeben.

## 4. Funktionsreferenz

`Fiat_OverwriteUpdateInterval(integer $InstanceID, int $Minutes)`<br>
ändert das Aktualisierumgsintervall; eine Angabe von **null** setzt auf den in der Konfiguration vorgegebene Wert zurück.
Es gibt hierzu auch zwei Aktionen (Setzen und Zurücksetzen).

## 5. Konfiguration

### Fiat uconnect

#### Properties

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Instanz deaktivieren      | boolean  | false        | Instanz temporär deaktivieren |
|                           |          |              | |
| Benutzer                  | string   |              | Benutzerkennung (EMail) des Uconnect-Portals |
| Passwort                  | string   |              | Passwort des Uconnect-Portals |
|                           |          |              | |
| VIN                       | string   |              | Fahrgestellnummer |
|                           |          |              | |
| Aktualisierungsintervall  | integer  | 5            | Intervall in Minuten |

#### Aktionen

| Bezeichnung                | Beschreibung |
| :------------------------- | :----------- |
| Aktualisiere Status        | Daten abrufen |
| erneut anmelden            | Login erzwingen |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Integer<br>
Fiat.Mileage,
Fiat.PlugInStatus,

* Float<br>
Fiat.Altitude,
Fiat.BatteryCapacity,
Fiat.Location,
Fiat.StateOfCharge,
Fiat.Voltage,

* String<br>
Fiat.ChargingStatus,

Wichtiger Hinweis: das Profil _Fiat.ChargingStatus_ ist nicht vollständig und muss gemäß ergänzt werden. Bitte über zusätzliche Einträge den Autor informieren, damit es nachgepflegt werden kann.

## 6. Anhang

### GUIDs
- Modul: `{88C1B197-2E69-D887-D794-AA6B0037F2E3}`
- Instanzen:
  - FiatUconnect: `{A0D064E8-44E3-206A-9B5E-87F12B666EA7}`
- Nachrichten:

### Quellen
- [ioBroker.Fiat](https://github.com/TA2k/ioBroker.fiat.git)
- [FiatChamp](https://github.com/wubbl0rz/FiatChamp.git)

## 7. Versions-Historie

- 1.4 @ 05.07.2023 11:56
  - Vorbereitung auf IPS 7 / PHP 8.2
  - update submodule CommonStubs
    - Absicherung bei Zugriff auf Objekte und Inhalte

- 1.3 @ 20.01.2023 17:08
  - Fix: Zeitstempel sind in der API in Millisekunden

- 1.2 @ 18.01.2023 14:02
  - Neu: automatisches Relogin, wenn HTTP-Error 403(unauthorized)

- 1.1 @ 16.12.2022 09:43
  - Neu: Führen einer Statistik der API-Calls im IO-Modul, Anzeige als Popup im Experten-Bereich
  - update submodule CommonStubs

- 1.0 @ 01.11.2022 09:28
  - Initiale Version
