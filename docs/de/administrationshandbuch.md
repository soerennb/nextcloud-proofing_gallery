# Administrationshandbuch

Dieses Handbuch beschreibt Installation und Betrieb von Proofing Gallery 0.5.0.

## Voraussetzungen und Installation

- Nextcloud 31–34
- PHP 8.1–8.6 mit den von Nextcloud benötigten Erweiterungen; GD wird für
  Wasserzeichen-Vorschauen und Kontaktbögen benötigt
- SQLite, MariaDB/MySQL oder PostgreSQL
- Nextcloud-Cron sowie ein Mailtransport für Einladungen oder E-Mail-Hinweise

Prüfe `proofing_gallery.tar.gz` gegen `SHA256SUMS` und die GitHub-Artefakt-
Attestierung. Entpacke das Archiv als `custom_apps/proofing_gallery` und führe aus:

```bash
sudo -u www-data php occ app:enable proofing_gallery
sudo -u www-data php occ background:cron
```

Sichere vor Upgrades Datenbank, Datenverzeichnis, Konfiguration und Appdata.
Nextcloud führt App-Migrationen bei Aktivierung oder Upgrade aus. Überspringe
keine vorgesehenen Nextcloud-Upgrade-Schritte.

## Zugriff und Richtlinien

Administration → Zusätzliche Einstellungen → Proofing Gallery steuert Gruppen,
Funktionsfreigaben, Standardwerte, Branding, Mediendienste, Ressourcenlimits und
Aufbewahrung. Die Regeln werden serverseitig erzwungen und sperren kritische
Funktionen bei Unsicherheit. Native Nextcloud-Regeln für Freigaben, Passwörter,
Ablauf und Uploads bleiben maßgeblich und werden niemals gelockert.

Prüfe Freigabe-, Mail- und Gruppenrichtlinien vor der Einführung. Aktiviere
Gast-Downloads und -Uploads nur bei Bedarf. Richte Grenzen nach PHP, Proxy,
Speicher und Worker-Kapazität aus, nicht nach Browservalidierung.

## Hintergrundaufträge und Überwachung

Starte Nextcloud-Cron mindestens alle fünf Minuten. Überwache Nextcloud-Logs für
`proofing_gallery`, fehlgeschlagene Aufträge, Mailtransport und Vorschauen sowie
den Abschnitt **Systemstatus** der App. Er zeigt begrenzte Betriebskennzahlen zu
Bereinigung, Uploads, Video, Suche, Benachrichtigungen und Vorschauen, aber keine
Zugangsdaten oder Benutzerpfade.

Berücksichtige bei der Kapazitätsplanung Originale in Files, fortsetzbare
Uploadteile, Vorschauen, Videoderivate, Datenbankindizes und angenommene Uploads.
Bereinigung wirkt verzögert; halte Reserve für unterbrochene Aufträge vor. Für
Feedback und Upload-Eingang müssen Datenbank und Appdata konsistent gesichert sein.

## Videoverarbeitung

Installiere `ffmpeg` und `ffprobe` auf jedem Web- und Cron-Worker. Konfiguriere
einen vertrauenswürdigen absoluten Programmpfad. Die App arbeitet ohne Shell,
begrenzt Größe, Dauer, Ausgabehöhe, Parallelität und Laufzeit und schreibt
H.264/AAC-Derivate in privates Appdata. Originale bleiben schreibgeschützt.
Fehler werden begrenzt wiederholt und alte Derivate nach Richtlinie entfernt.

Ohne Transkodierung streamen browserfähige MP4-/WebM-Dateien weiterhin; andere
Formate bleiben bis zur sicheren Verarbeitung gesperrt.

## Metadaten, XMP und semantische Suche

Die Metadatenindexierung liest eine begrenzte EXIF-/IPTC-Auswahl und speichert
ETag-gebundene Datensätze. XMP-Schreiben benötigt beschreibbare Quellen, erzeugt
oder mischt `<Basisname>.xmp`, deaktiviert externe XML-Entitäten, begrenzt die
Größe und stoppt bei gleichzeitigen Änderungen. Deaktiviere XMP instanzweit,
wenn dieser Ablauf nicht benötigt wird.

Semantische Suche ist zunächst aus. Der lokale Anbieter nutzt Dateinamen und
wenige beschreibende Metadaten ohne Vorschauübertragung. Der HTTPS-Anbieter ist
ein gesondertes Opt-in und benötigt TLS sowie die ausdrückliche Erlaubnis für
begrenzte Vorschauen. Originale, GPS, Bewertungen, private Stichwörter und
Zugänge werden nie übertragen. Prüfe zuvor Aufbewahrung, Zugriff, Standort,
Verfügbarkeit und Datenschutzbedingungen des Dienstes.

## Live Push

Live Push ist zunächst deaktiviert. Ein Zugang gehört zu genau einer
Ordnergalerie und optional einem Unterordner und erlaubt nur Upload – niemals
Auflisten, Lesen oder Löschen. Clients senden den Dateiinhalt per HTTPS `PUT` an:

```text
/apps/proofing_gallery/live-push/upload?filename=<url-kodierter-name>
```

Die Anmeldung verwendet die erzeugten HTTP-Basic-Daten. Zugänge können getrennt
von öffentlichen Links rotiert oder widerrufen werden. FTP/FTPS ist nicht Teil
der App. Ein Protokoll-Gateway liegt außerhalb ihrer Sicherheitsgrenze und muss
TLS prüfen, Zugangsdaten schützen, Lesen verbieten und sichere Logs führen.

## Eigene Domains

Eigene Domains sind zunächst aus. Aktivierung verlangt den exakten DNS-TXT-
Nachweis, öffentliche DNS-Ziele, gültiges HTTPS und Adminfreigabe. Trage den Host
als vertrauenswürdige Nextcloud-Domain ein und leite ihn mit ursprünglichem
Host-Header an dasselbe Frontend. Nur `/` gehört zum Galerie-Domain-Einstieg;
`/s/`, `/apps/`, `/ocs/` und statische Nextcloud-Pfade bleiben unverändert.

Gelöschtes DNS ist kein Widerruf. Widerrufe Mapping oder nativen öffentlichen
Link in der App. Die regelmäßige Prüfung sperrt die Domain bei veraltetem
Besitznachweis, Adressziel, TLS oder Nextcloud-Endpunkt.

## Sammlungen, Speicher und Lebenszyklus

Sammlungen speichern geordnete Dateiverweise in der Datenbank und einen leeren
nativen Freigabeanker unter `.proofing-gallery/collections`. Lege dort keine
Benutzerdateien ab. Die Bereinigung entfernt nur alte, leere, unreferenzierte
Anker mit dem erzeugten Namensformat. Rekursive Galerien indexieren begrenzte
Datei- und Sortiermetadaten, niemals Bildinhalte.

Deaktivieren bewahrt Daten und beendet App-Zugriff. Exportiere vor Deinstallation
benötigte Auswahlen und kläre offene Uploads. Bei einem bekannt gewordenen Token
ist das Widerrufen des öffentlichen Links die schnellste Sofortmaßnahme.

## Prüfung nach Upgrade und Wiederherstellung

1. Prüfe `occ app:list` und fehlgeschlagene Hintergrundaufträge.
2. Erzeuge als berechtigter Benutzer eine private Testgalerie.
3. Prüfe Passwort, Ablauf, Widerruf, Vorschau und Downloadrichtlinie.
4. Teste Mail nur bei vorhandener Konfiguration und starte einen Cronlauf.
5. Kontrolliere den Proofing-Gallery-Systemstatus.

Fehlt ein Quellordner, stelle ihn regulär wieder her oder lasse einen geprüften
Ersatz auswählen. Für Reviewhistorie sind Datenbank und Appdata gemeinsam
wiederherzustellen. Sicherheitsfehler werden privat über GitHub gemeldet – mit
Version und Reproduktion, aber ohne echte Kundendaten oder Zugangsdaten.
