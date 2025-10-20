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

Volvo bietet für bestimmte Modelle eine Kommunikation via Internet/Volvo-Cloud an: ([Volvo Cars API](https://developer.volvocars.com)), diese steht nur für bestimmte Modelle zur Vefügung ([siehe hier](https://developer.volvocars.com/apis/connected-vehicle/v2/overview/#availability)).

Realisiert ist sowohl der Abruf der Daten als auch die relevanten Befehle.

## 2. Voraussetzungen

- IP-Symcon ab Version 6.0

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff *Volvo* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL `https://github.com/demel42/Volvo.git` installiert werden.

### b. Einrichtung in IPS

## 4. Funktionsreferenz

`Volvo_StartClimatization(integer $InstanceID)`<br>
Startet die Klimatisierung

`Volvo_StopClimatization(integer $InstanceID)`<br>
Stoppt eine laufende Klimatisierung

`Volvo_LockDoors(integer $InstanceID)`<br>
Versperrt die Türen

`Volvo_UnlockDoors(integer $InstanceID)`<br>
Entsperrt die Türen

## 5. Konfiguration

### a. VolvoIO

#### Einstellungen

| Eigenschaft             | Typ     | Standardwert | Beschreibung |
| :---------------------- | :------ | :----------- | :----------- |
| Instanz deaktivieren    | boolean | false        | Instanz temporär deaktivieren |
|                         |         |              | |
| Verbindugstyp           | integer | 0            | Auswahl der Art der Verbindung (**OAuth** oder **Developer**) |
|                         |         |              | |
| - nur bei _Developer_ - |         |              | |
| Benutzername            | string  |              | Volvo-Konto: Benutzerkennung |
| Passwort                | string  |              | Volvo-Konto: Passwort |
| VCC API Key             | string  |              | Schlüssel der Volvo-API Application |

**Hinweis: die Anmeldung via IP-Symcon funktioniert zur Zeit noch nicht!**

#### Aktionen

| Bezeichnung   | Beschreibung |
| :------------ | :----------- |
| Anmeldung mit Code durchführen | _[1]_ |
| Bei Volvo anmelden | _[2]_ |
| Zugang prüfen | Prüft, ob die Angaben korrekt sind |

_[1]_: die Methode gilt für die Anmeldung bei dem [Volvo-Entwicklerkonto](https://developer.volvocars.com) mit dem *VCC API key* der eigenen *API application*. Hier wir nach Anforderung ein OTP (ein Code) an die im Konto hinterlegten Mailadresse geschickt, mitbdem die Anmeldung bestätigt wird.
_[2]_: die Methode ist für die Anmeldung via IP-Symcon

### b. VolvoConfig

keine Einstellungen

### c. VolvoVehicle

#### Einstellungen

| Eigenschaft               | Typ     | Standardwert | Beschreibung |
| :------------------------ | :------ | :----------- | :----------- |
| Instanz deaktivieren      | boolean | false        | Instanz temporär deaktivieren |
|                           |         |              | |
| Fahrgestellnummer         |         |              | wird vom Konfigurator gesetzt |
| Antriebsart               |         |              | wird vom Konfigurator gesetzt |
|                           |         |              | |
| Aktualisierungsintervall  | integer | 0            | Aktualisierungsintervall in Sekunden |

#### Aktionen

| Bezeichnung                | Beschreibung |
| :------------------------- | :----------- |
| Aktualisere Status         | Daten abrufen |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Boolean<br>
Volvo.Failure

* Integer<br>
Volvo.Altitude,
Volvo.CentralLockState,
Volvo.ChargingState,
Volvo.ConnectionState,
Volvo.DoorState,
Volvo.EngineState,
Volvo.Heading,
Volvo.Mileage,
Volvo.Minutes,
Volvo.TyreState,
Volvo.WindowState

* Float<br>
Volvo.BatteryCapacity,
Volvo.BatteryChargeLevel,
Volvo.Distance,
Volvo.EnergyConsumption,
Volvo.FuelAmount,
Volvo.FuelConsumption,
Volvo.Location,
Volvo.Range,
Volvo.Speed

## 6. Anhang

### GUIDs
- Modul: `{2563E223-0FDC-DE40-C61F-4EB9A2638993}`
- Instanzen:
  - VolvoIO: `{E730BFFA-6E1F-F615-D1B3-4D43A13B7285}`
  - VolvoConfig: `{4905DEEC-2A8D-74B6-138E-020C46999674}`
  - VolvoVehicle: `{6C6B7979-37AA-69B7-2E19-7E10D92A97E3}`
- Nachrichten:
    - `{76557D1D-4782-3FBA-81C8-78494D4B6908}`: an VolvoConfig, VolvoDevice
    - `{83DF672B-CA66-5372-A632-E9A5406332A7}`: an VolvoIO

### Quellen

## 7. Versions-Historie

- 1.3 @ 20.10.2025 19:58
  - Fix: Änderungen an der API zum 31.12.2025 nachgeführt ("/energy" nun als "v2" und "/extended-vehicle" in "/connected-vehicle" enthalten)

- 1.2 @ 10.08.2025 20:04
  - Fix: aktuelle API-Version legt Wert auf https (obwohl in den Daten nur http geliefert wird)

- 1.1.3 @ 02.01.2025 14:28
  - Fix: fehlende Übersetzung nachgeführt

- 1.1.2 @ 25.12.2024 11:10
  - Verbesserung: kosmetische Nacharbeiten
  - update submodule CommonStubs

- 1.1.1 @ 06.11.2024 10:02
  - Verbesserung: Anmeldung ist nun reboot-fest

- 1.1 @ 03.11.2024 15:05
  - Neu: Implementierung der Anmeldung am Entwicklerkonto mit OTP

- 1.0 @ 14.08.2024 12:48
  - Initiale Version
