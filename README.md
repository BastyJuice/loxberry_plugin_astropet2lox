# AstroPet2Lox

**Dein AstroPet-Gerät mit Loxone verbinden – über LoxBerry und MQTT.**

AstroPet2Lox liest die Daten eines AstroPet-Geräts über das vorhandene AstroPet-Konto und veröffentlicht Zahlenwerte unter `astropet2lox/#` am in LoxBerry konfigurierten MQTT-Broker. Das LoxBerry-MQTT-Gateway übernimmt die Weiterleitung an den Loxone-Miniserver.

```text
AstroPet-/Tuya-Cloud → AstroPet2Lox auf LoxBerry → MQTT → LoxBerry-MQTT-Gateway → Loxone
```

Das Plugin nutzt die normale LoxBerry-Weboberfläche und das vorhandene PHP. Ein zusätzlicher Webserver oder ein eigenes Tuya-Cloudprojekt ist für die Einrichtung nicht erforderlich. Die Anbindung liest Daten; sie sendet keine Gerätebefehle.

**Version:** 1.0.0 · **Autor:** Bastian Schössow

## Funktionen

- Anmeldung mit der E-Mail-Adresse und dem Passwort des AstroPet-Kontos, bei Bedarf mit E-Mail-Bestätigungscode.
- Auswahl eines Geräts, wenn dem Konto mehrere Geräte zugeordnet sind.
- Automatischer Abruf im Hintergrund: standardmäßig alle 60 Sekunden, einstellbar auf 60 bis 3600 Sekunden.
- Numerische MQTT-Werte mit QoS 1, retained Speicherung, Verfügbarkeitsstatus und Lebenszeichen.
- Registrierung des Abos `astropet2lox/#` im LoxBerry-MQTT-Gateway V2.
- Übersicht aller Variablen, Einheiten, Gültigkeitsregeln und Loxone-Namen im Plugin.

## Voraussetzungen

| Voraussetzung | Beschreibung |
| --- | --- |
| LoxBerry | Version 4 |
| Architektur | 64 Bit: `aarch64`, `x86_64` oder `riscv64` |
| PHP | Vorhandenes PHP ab 7.4, ebenfalls 64 Bit |
| PHP-Erweiterungen | cURL, OpenSSL und zlib; für den Hintergrunddienst zusätzlich `pcntl` im CLI-PHP |
| AstroPet | Vorhandenes Konto mit einem eingerichteten Gerät und Internetzugang zur Cloud |
| MQTT/Loxone | In LoxBerry konfigurierter Broker, MQTT-Gateway V2 und Miniserver-Weiterleitung |

Das Plugin installiert keine zusätzliche PHP-Version und verändert die PHP-Konfiguration des LoxBerry nicht. Der Hintergrunddienst verwendet bevorzugt den vorhandenen Systembefehl `php`; bei Bedarf prüft der Installer andere bereits installierte PHP-Laufzeiten. Die Oberfläche läuft direkt als `index.php`.

## Installation und Update

1. Die bereitgestellte Plugin-ZIP `astropet2lox-1.0.0.zip` herunterladen.
2. In der **LoxBerry-Pluginverwaltung** die ZIP zur Installation auswählen.
3. Nach erfolgreicher Installation **AstroPet2Lox** öffnen.

Für ein Update die neue Plugin-ZIP über die vorhandene Installation installieren. **Vorher nicht deinstallieren:** Konfiguration und Gerätesitzung werden beim Update gesichert und wiederhergestellt.

Die ZIP gehört in die Pluginverwaltung. Für ein GitHub-Release kann die fertige Plugin-ZIP als Release-Datei bereitgestellt werden. Ein Neustart des LoxBerry ist normalerweise nicht erforderlich.

## AstroPet-Konto einrichten

1. Im Tab **Einstellungen** E-Mail-Adresse und Passwort des AstroPet-Kontos eingeben.
2. **Gerätewerte automatisch abfragen und per MQTT senden** aktivieren und das gewünschte Abfrageintervall einstellen.
3. **Speichern und verbinden** wählen.
4. Falls AstroPet eine Bestätigung verlangt: **E-Mail-Code anfordern**, anschließend den sechsstelligen Code eingeben und **Code bestätigen**. Führende Nullen gehören zum Code.
5. Bei mehreren gefundenen Geräten das gewünschte Gerät auswählen. Ein einzelnes Gerät wird automatisch ausgewählt.
6. Im Tab **MQTT-Variablen** den Status und die verfügbaren Werte prüfen.

Die Oberfläche muss für den laufenden Abruf nicht geöffnet bleiben. Bereits gespeicherte Zugangsdaten bleiben erhalten, wenn ihre Formularfelder leer gelassen werden.

## Loxone einrichten

1. Im LoxBerry-MQTT-Gateway den gewünschten Miniserver konfigurieren und die Weiterleitung aktivieren.
2. Im Plugin prüfen, ob das Abo `astropet2lox/#` registriert ist. Bei Bedarf **MQTT-Weiterleitung registrieren** wählen.
3. In **Loxone Config** virtuelle Eingänge mit den Namen aus der Variablenliste anlegen und die Konfiguration an den Miniserver übertragen.

Bei der HTTP-Weiterleitung des Gateways wird aus `/` im MQTT-Thema ein `_` im Eingangsnamen:

| MQTT-Thema | Virtueller Eingang in Loxone |
| --- | --- |
| `astropet2lox/weight_kg` | `astropet2lox_weight_kg` |
| `astropet2lox/visits_today` | `astropet2lox_visits_today` |
| `astropet2lox/last_visit_duration_seconds` | `astropet2lox_last_visit_duration_seconds` |
| `astropet2lox/fault_active` | `astropet2lox_fault_active` |
| `astropet2lox/availability` | `astropet2lox_availability` |
| `astropet2lox/data_valid` | `astropet2lox_data_valid` |
| `astropet2lox/heartbeat_counter` | `astropet2lox_heartbeat_counter` |

Das Plugin registriert das MQTT-Abo; die virtuellen Eingänge werden in Loxone Config angelegt. Bei der UDP-Weiterleitung gelten die UDP-Konfiguration und Erkennung des Gateways. Bestehende MQTT-Abos werden erhalten. Ohne eigene Miniserver-Auswahl für das Abo gilt der Standard-Miniserver des Gateways.

Die MQTT-Bestätigung bestätigt die Annahme durch den Broker; sie bestätigt keine Zustellung an den Miniserver.

## Gültigkeit der Daten

MQTT speichert die letzten Werte als **retained**. Ein vorhandener Wert kann deshalb auch nach einer Unterbrechung noch angezeigt werden.

Gerätewerte nur verwenden, wenn beide Bedingungen erfüllt sind:

```text
astropet2lox/availability = 1
astropet2lox/data_valid   = 1
```

`data_valid=1` setzt eine erfolgreiche Geräteabfrage, ein online gemeldetes Gerät und einen ausreichend neuen Abruf voraus. Das maximale Abrufalter beträgt `max(180, 3 × Abfrageintervall)` Sekunden. Das Alter einzelner Messungen kann davon abweichen.

Zusätzlich den `heartbeat_counter` in Loxone überwachen, beispielsweise mit einem **45-Sekunden-Watchdog**. Der Zähler läuft von 0 bis 65535 und ändert sich normalerweise alle zehn Sekunden. Bei einer MQTT-Verbindungsunterbrechung setzt das Last Will `availability=0`; bei einem Ausfall des gesamten Brokers erkennt der Watchdog das ausbleibende Lebenszeichen.

Für einzelne Werte gelten weitere Regeln:

- **Gewicht:** `weight_kg` und `weight_g` enthalten das zuletzt gemessene Katzengewicht. `weight_available`, `weight_reported_at` und `weight_age_seconds` beachten. Eine neue Cloud-Abfrage ist keine neue Gewichtsmessung.
- **Besuche:** `visits_today` nur bei `visits_today_available=1` und `visits_today_valid=1` verwenden. Die Meldung muss vom heutigen Kalendertag in der Gerätezeitzone stammen.
- **Besuchsdauer:** `last_visit_duration_seconds` enthält die zuletzt gemeldete Dauer. `duration_available` und das Alter der Meldung beachten.
- **Streuwert:** `litter_level_percent` nur bei `litter_level_available=1` und `litter_level_valid=1` verwenden. Die Meldung muss vom heutigen Gerätetag stammen und höchstens 180 Sekunden alt sein. Das Schema erlaubt Werte bis 250; daraus wird kein Abfallbehälter-Füllstand abgeleitet.
- **Fehler und Zustand:** Zustandsnummern und Bitfelder werden unverändert als Zahlen gemeldet. Die einzelnen Code- und Bitbedeutungen sind nicht verifiziert. `fault_active=0` setzt beide bekannten Meldungs-/Fehlerquellen voraus.

`*_reported_at` enthält Unix-Sekunden; `0` bedeutet unbekannt. Bei `*_age_seconds` bedeutet `-1` unbekannt. Fehlende Messwerte werden nicht durch Nullen ersetzt. Gültigkeitswechsel werden lokal nachgeführt, ohne zusätzliche Cloud-Abfragen auszulösen.

`*_available=1` zeigt ausschließlich an, dass der betreffende Wert vorhanden ist. Es bestätigt weder seine Frische noch die Erreichbarkeit des Geräts.

## MQTT-Variablen

Alle folgenden Namen tragen das Präfix `astropet2lox/`. Beispielsweise wird `weight_kg` als `astropet2lox/weight_kg` veröffentlicht. Messwerte erscheinen, sobald sie in einem gültig aufgebauten Gerätesnapshot vorhanden sind; ihre Verwendung richtet sich nach den Gültigkeitsregeln oben.

<details>
<summary>Hauptwerte, Status- und Zeitfelder anzeigen</summary>

| Name | Einheit | Bedeutung | Zusätzliche Gültigkeit |
| --- | --- | --- | --- |
| `availability` | 0/1 | Plugin-MQTT-Verbindung: 1 aktiv, 0 getrennt oder pausiert. Last Will setzt bei Verbindungsabbruch 0. | Statusfeld |
| `heartbeat` | Unix-Sekunden | Zeit der letzten Veröffentlichung. 0 bedeutet unbekannt. | Statusfeld |
| `heartbeat_counter` | Zähler | Lebenszeichen: Zähler 0 bis 65535, ändert sich normalerweise alle 10 Sekunden. Für den Loxone-Watchdog verwenden. | Statusfeld |
| `bridge_ok` | 0/1 | Ein zum ausgewählten Gerät passender Gerätesnapshot ist vorhanden. | Statusfeld |
| `data_valid` | 0/1 | Letzter Geräteabruf erfolgreich, Cache frisch und Gerät online. | availability=1 |
| `online` | -1/0/1 | Gemeldete Cloud-Verbindung: 1 online, 0 offline, -1 unbekannt. | Statusfeld |
| `last_poll_ok` | 0/1 | Ergebnis des letzten Geräteabrufs. | Statusfeld |
| `data_age_seconds` | s | Zeit seit dem letzten erfolgreichen Geräteabruf, -1 unbekannt. | Statusfeld |
| `weight_kg` | kg | Zuletzt gemessenes Katzengewicht; keine fortlaufende Live-Waage. | availability=1, data_valid=1, weight_available=1; Alter beachten |
| `weight_g` | g | Zuletzt gemessenes Katzengewicht in Gramm. | availability=1, data_valid=1, weight_available=1; Alter beachten |
| `visits_today` | Anzahl | Gemeldeter täglicher Benutzungszähler. | availability=1, data_valid=1, visits_today_valid=1, visits_today_available=1 |
| `visits_today_valid` | 0/1 | Zählermeldung stammt vom heutigen Kalendertag der Gerätezeitzone. | Statusfeld |
| `last_visit_duration_seconds` | s | Zuletzt gemeldete Dauer eines Toilettenbesuchs. | availability=1, data_valid=1, duration_available=1 |
| `litter_level_percent` | % | Zuletzt gemeldeter Streu-Status laut Produktschema. Bereich 0 bis 250; kein Abfallbehälter-Füllstand. | availability=1, data_valid=1, litter_level_valid=1, litter_level_available=1 |
| `litter_level_valid` | 0/1 | Streumeldung stammt von heute und ist höchstens 180 Sekunden alt. | Statusfeld |
| `work_state` | Code | Numerischer Arbeitszustand. Bedeutungen der Codes 0 bis 11 sind nicht verifiziert. | availability=1, data_valid=1, work_state_available=1 |
| `message_bitmap` | Bitfeld | Gemeldete Meldungsbits. Einzelne Bitbedeutungen sind nicht verifiziert. | availability=1, data_valid=1 |
| `fault_bitmap` | Bitfeld | Gemeldetes Fehler-Bitfeld BIT/E1. Einzelne Bitbedeutungen sind nicht verifiziert. | availability=1, data_valid=1 |
| `fault_active` | 0/1 | Mindestens ein gemeldetes Meldungs-/Fehlerbit gesetzt. 0 setzt beide bekannten Quellen voraus. | availability=1, data_valid=1, fault_available=1 |
| `auto_clean_enabled` | 0/1 | Gemeldete Einstellung für automatische Reinigung; dieses Plugin verändert sie nicht. | availability=1, data_valid=1, auto_clean_available=1 |
| `weight_available` | 0/1 | Der Wert weight_kg ist im gemeldeten Snapshot vorhanden. | Statusfeld |
| `visits_today_available` | 0/1 | Der Wert visits_today ist im gemeldeten Snapshot vorhanden. | Statusfeld |
| `duration_available` | 0/1 | Der Wert last_visit_duration_seconds ist im gemeldeten Snapshot vorhanden. | Statusfeld |
| `litter_level_available` | 0/1 | Der Wert litter_level_percent ist im gemeldeten Snapshot vorhanden. | Statusfeld |
| `work_state_available` | 0/1 | Der Wert work_state ist im gemeldeten Snapshot vorhanden. | Statusfeld |
| `fault_available` | 0/1 | Der Wert fault_active ist im gemeldeten Snapshot vorhanden. | Statusfeld |
| `auto_clean_available` | 0/1 | Der Wert auto_clean_enabled ist im gemeldeten Snapshot vorhanden. | Statusfeld |
| `weight_reported_at` | Unix-Sekunden | Zeitpunkt der Gewichtsmeldung. 0 bedeutet unbekannt. | Statusfeld |
| `weight_age_seconds` | s | Alter der Gewichtsmeldung. -1 bedeutet unbekannt; wird bei jeder Ausgabe neu berechnet. | Statusfeld |
| `visits_today_reported_at` | Unix-Sekunden | Zeitpunkt der Tageszählermeldung. 0 bedeutet unbekannt. | Statusfeld |
| `visits_today_age_seconds` | s | Alter der Tageszählermeldung. -1 bedeutet unbekannt; wird bei jeder Ausgabe neu berechnet. | Statusfeld |
| `last_visit_duration_reported_at` | Unix-Sekunden | Zeitpunkt der Dauermeldung. 0 bedeutet unbekannt. | Statusfeld |
| `last_visit_duration_age_seconds` | s | Alter der Dauermeldung. -1 bedeutet unbekannt; wird bei jeder Ausgabe neu berechnet. | Statusfeld |
| `litter_level_reported_at` | Unix-Sekunden | Zeitpunkt der Streumeldung. 0 bedeutet unbekannt. | Statusfeld |
| `litter_level_age_seconds` | s | Alter der Streumeldung. -1 bedeutet unbekannt; wird bei jeder Ausgabe neu berechnet. | Statusfeld |
| `message_reported_at` | Unix-Sekunden | Zeitpunkt der Meldungsbitmap. 0 bedeutet unbekannt. | Statusfeld |
| `message_age_seconds` | s | Alter der Meldungsbitmap. -1 bedeutet unbekannt; wird bei jeder Ausgabe neu berechnet. | Statusfeld |
| `fault_reported_at` | Unix-Sekunden | Zeitpunkt der Fehlerbitmap. 0 bedeutet unbekannt. | Statusfeld |
| `fault_age_seconds` | s | Alter der Fehlerbitmap. -1 bedeutet unbekannt; wird bei jeder Ausgabe neu berechnet. | Statusfeld |
| `fault_active_reported_at` | Unix-Sekunden | Zeitpunkt der zusammengefasster Fehlerzustand. 0 bedeutet unbekannt. | Statusfeld |
| `fault_active_age_seconds` | s | Alter der zusammengefasster Fehlerzustand. -1 bedeutet unbekannt; wird bei jeder Ausgabe neu berechnet. | Statusfeld |

</details>

<details>
<summary>Zusätzliche numerische Geräte-Datenpunkte anzeigen</summary>

Die `dp_`-Werte folgen dem tatsächlichen Geräteschema. `dp_<ID>` enthält den schema-geprüften Rohwert; `dp_<code>` enthält den Wert nach Schema-Skalierung. Die Einheit in der Tabelle gehört zum skalierten Wert. Auch diese Felder beschreiben ausschließlich gelesene Zustände und Einstellungen.

| Rohwert | Skalierter Wert | Einheit | Bedeutung |
| --- | --- | --- | --- |
| `dp_5` | `dp_delay_clean_time` | min | Wartezeit nach dem Verlassen bis zur automatischen Reinigung |
| `dp_6` | `dp_cat_weight` | g | Zuletzt gemessenes Katzengewicht |
| `dp_7` | `dp_excretion_times_day` | Anzahl | Gemeldete tägliche Benutzungsanzahl |
| `dp_8` | `dp_excretion_time_day` | s | Gemeldete Besuchsdauer |
| `dp_10` | `dp_sleep` | 0/1 | Gemeldete Schlafmodus-Einstellung |
| `dp_11` | `dp_sleep_start_time` |  | Schlafmodus-Startwert laut Geräteschema |
| `dp_12` | `dp_sleep_end_time` |  | Schlafmodus-Endwert laut Geräteschema |
| `dp_23` | `dp_factory_reset` | 0/1 | Gemeldeter Werksreset-Schalter; wird vom Plugin nicht betätigt |
| `dp_101` | `dp_work_state` | Code | Arbeitszustand als unverifizierter Zahlencode |
| `dp_102` | `dp_allowance` | % | Gemeldeter Streu-Status laut Schema |
| `dp_103` | `dp_message` | Bitfeld | Meldungsbitfeld ohne verifizierte Bitbedeutungen |
| `dp_107` | `dp_man_mode` | Code | Manueller Modus als Zahlencode |
| `dp_111` | `dp_sav` | 0/1 | Gemeldete Energiespareinstellung des Displays |
| `dp_113` | `dp_language` | Code | Gemeldete Display-Sprache als Zahlencode |
| `dp_114` | `dp_self_check` | 0/1 | Gemeldete Einstellung des täglichen Selbsttests |
| `dp_115` | `dp_self_check_time` |  | Gemeldeter Selbsttest-Zeitwert |
| `dp_117` | `dp_bit` | Bitfeld | Fehler-Bitfeld BIT/E1 ohne verifizierte Bitbedeutungen |
| `dp_119` | `dp_clear` | 0/1 | Gemeldete Geruchsreinigungs-Einstellung |
| `dp_122` | `dp_light2` | 0/1 | Gemeldete Einstellung der Innenbeleuchtung |
| `dp_124` | `dp_auto` | 0/1 | Gemeldete automatische Reinigung |
| `dp_126` | `dp_startr` |  | Gemeldeter Beginn-Zeitwert eines Besuchs; Format nicht verifiziert |
| `dp_127` | `dp_endr` |  | Gemeldeter Ende-Zeitwert eines Besuchs; Format nicht verifiziert |
| `dp_128` | `dp_automaticd` | 0/1 | Gemeldete automatische Geruchsreinigung |
| `dp_129` | `dp_unusual` | Code | Unverifizierter Zahlencode für die Geräte-Anomalieanzeige |

Nichtnumerische Datenpunkte, beispielsweise Tier-IDs und Raw-/Base64-Zeitplanpakete, werden nicht über MQTT veröffentlicht. Die Liste im Plugin verwendet das Schema des ausgewählten Geräts; sie kann bei einem anderen Gerätestand abweichen.

</details>

## Betrieb und Zugangsdaten

| Aktion | Wirkung |
| --- | --- |
| **Nur Einstellungen speichern** | Speichert die Konfiguration, ohne eine neue Kontoanmeldung auszulösen. |
| Automatische Abfrage deaktivieren | Pausiert den Abruf und setzt die Gültigkeitsfreigabe zurück. |
| **Werte jetzt abfragen** | Startet eine Geräteabfrage und stößt die MQTT-Ausgabe an. |
| **Geräteverbindung trennen** | Entfernt die gespeicherte Geräteverbindung und stößt eine ungültige MQTT-Ausgabe an. |
| **Browsersitzung löschen** | Entfernt die kurzlebige Browser-Anmeldesitzung. |

Bei einer abgelaufenen Geräteanmeldung im Plugin erneut verbinden. Der Hintergrunddienst fordert keine E-Mail-Codes an und führt keine neue Kontoanmeldung aus.

Zugangsdaten werden im privaten Konfigurationsordner des Plugins gespeichert; Sitzung und Cache liegen im privaten Datenordner. Dateien erhalten `0600`, Verzeichnisse `0700`. Die PHP-geschützten Laufzeitdateien werden als Text gelesen. Passwörter und Sitzungstokens werden weder per MQTT veröffentlicht noch in die Protokolle geschrieben oder in Formularfelder zurückgesetzt. Lokale Laufzeitdateien mit Zugangsdaten oder Sitzungen gehören nicht in dieses Repository.

## Fehlerbehebung

| Problem | Prüfen |
| --- | --- |
| Konto verbunden, aber keine Gerätewerte | Gewünschtes Gerät auswählen, Geräteverbindung und `data_valid` prüfen; eine reine Kontoanmeldung bestätigt noch keinen gültigen Geräteabruf. |
| Werte im Plugin, aber nicht in Loxone | Brokerverbindung, Abo `astropet2lox/#`, aktivierte Miniserver-Weiterleitung und Namen der virtuellen Eingänge prüfen. |
| `availability=0` | Automatische Abfrage, Brokerkonfiguration und Hintergrunddienst prüfen. |
| `data_valid=0` | Ergebnis und Alter der letzten Abfrage sowie den Online-Status des Geräts prüfen; bei abgelaufener Sitzung erneut verbinden. |
| Alter Gewichtswert oder ungültiger Tageszähler | Meldezeit und die Gültigkeitsfelder des jeweiligen Werts prüfen. |
| Oberfläche oder Dienst lädt nicht | Installationsprotokoll, vorhandenes PHP samt Erweiterungen und Schreibrechte der privaten Plugin-Ordner prüfen. |

Meldungen des Hintergrunddiensts stehen im Tab **Protokolle**. Bei einer Fehlermeldung helfen Plugin-Version, LoxBerry-Version und die betroffenen Protokollzeilen. Zugangsdaten und Sitzungstokens vor dem Veröffentlichen entfernen.

## Hinweise zur Anbindung

AstroPet2Lox ist eine **inoffizielle, lesende Anbindung** auf Grundlage der AstroPet-App 1.0.22. Herstelleränderungen am privaten Dienst können Anpassungen erfordern. Bisher wurde ein Gerätemodell live geprüft; die Unterstützung weiterer Modelle ist nicht bestätigt. Pro Plugin-Installation wird ein ausgewähltes Gerät unter dem festen Präfix `astropet2lox/` veröffentlicht.

Konto- und Tuya-Anmeldung sowie Geräteabfragen wurden live geprüft. Oberfläche, MQTT, Datenprojektion, Cache und Installationsabläufe wurden zusätzlich mit synthetischen Daten lokal geprüft. Die PHP-7.4-Kompatibilität wurde statisch geprüft; die lokalen Ausführungstests liefen mit einer neueren PHP-Version. Die konkrete Weiterleitung bis zum Miniserver wurde noch nicht durch einen vollständigen Ende-zu-Ende-Test bestätigt.

Automatische Plugin-Updates sind derzeit deaktiviert.

## Projektstruktur

| Pfad | Inhalt |
| --- | --- |
| `plugin.cfg` | Plugin-Metadaten und LoxBerry-Voraussetzungen |
| `webfrontend/htmlauth/` | Einstieg in die geschützte LoxBerry-Oberfläche |
| `bin/worker.php` | Hintergrunddienst für Geräteabruf und MQTT |
| `bin/lib/` | Laufzeit, MQTT, Variablenkatalog und Oberfläche |
| `bin/lib/AstroPet/` | AstroPet-/Tuya-Protokoll und Gerätedatenaufbereitung |
| `templates/` | Sprachdateien und Hilfe |
| `preroot.sh`, `preupgrade.sh`, `postinstall.sh`, … | Installation, Updatesicherung und Dienstverwaltung |

## Referenzen

- [Offizielle LoxBerry-Pluginvorlage V4](https://github.com/mschlenstedt/LoxBerry-Plugin-SamplePlugin-V4)
- [LoxBerry: Plugins entwickeln](https://wiki.loxberry.de/entwickler/plugin_fur_den_loxberry_entwickeln_ab_version_1x/start)
- [LoxBerry: MQTT-Verbindungsdaten](https://github.com/mschlenstedt/Loxberry/blob/master/libs/phplib/loxberry_io.php)
- [LoxBerry: MQTT-Gateway V2](https://github.com/mschlenstedt/Loxberry/blob/master/sbin/mqtt_gateway.py)

Hinweise zu verwendeten Referenzen und deren Lizenz stehen in [THIRD-PARTY.txt](THIRD-PARTY.txt).

## Donation

Wenn Ihnen mein Projekt hilft, können Sie mir gerne eine Tasse Kaffee spendieren :-)

[![Donate](https://img.shields.io/badge/Donate-PayPal-green.svg)](https://paypal.me/bastyjuice)
