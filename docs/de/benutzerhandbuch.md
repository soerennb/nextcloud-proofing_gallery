# Benutzerhandbuch

Dieses Handbuch beschreibt Proofing Gallery für Eigentümer und
Galerie-Manager. Welche Funktionen verfügbar sind, kann die
Nextcloud-Administration einschränken.

## Galerie erstellen

1. Öffne **Proofing Gallery** und wähle **Neues Projekt**.
2. Wähle die Aufgabe: Dateien ausliefern, eine Geschichte präsentieren, eine
   Auswahl einsammeln, Korrekturen abstimmen oder Dateien empfangen.
3. Vergib einen Titel und wähle anschließend nur aus den Empfänger- und
   Quellenoptionen, die zu dieser Aufgabe passen. Vorhandene Ordner, neue Ordner
   und kuratierte Sammlungen bleiben dort verfügbar, wo sie sinnvoll sind.
   Auslieferung, Präsentation, Auswahl und Korrektur können außerdem private
   Links aus Event-Ordnern erzeugen.
4. Prüfe Quelle und Medienanzahl, bevor du die Auslieferung konfigurierst. Ein
   Empfangsprojekt beginnt mit genau einer moderierten Upload-Inbox und kann
   weder Sammlungen noch Event-Auslieferung verwenden.

Eine Ordnergalerie verweist auf einen vorhandenen Ordner. Eine Sammlung fasst
Dateien aus mehreren eigenen Ordnergalerien ohne Kopien zusammen. Sammlungen
können keine Gast-Uploads empfangen.

## Serien-Events privat ausliefern

Für Einschulungen, Sportveranstaltungen und andere Aufträge mit vielen
Empfängern liegen gemeinsame Fotos und die Fotos jedes Teilnehmers in getrennten
Unterordnern eines Projektordners. Wähle beim Erstellen **Private Links aus
Event-Ordnern**. Das Projekt öffnet direkt die Event-Auslieferung; eine
separate Veröffentlichung ist nicht nötig.

Arbeite die vier Aufgaben **Fotos**, **Zugriff**, **Empfänger** und
**Freigabe** nacheinander ab. Du kannst einen vorhandenen Nextcloud-Ordner
verwenden oder einen lokalen Event-Ordner samt Unterordnern auswählen oder
hineinziehen. Weise jedem Ordner genau eine Rolle zu: für alle, für eine Gruppe,
privat oder nicht ausliefern. Die Empfängerliste bündelt Kontaktdaten, den
exakten gemeinsamen, gruppenbezogenen und privaten Umfang, den aktuellen Link
und den Linkverlauf in einer Zeile je Empfänger. Die abschließende Aktion
veröffentlicht bei Bedarf automatisch die verborgene technische Basis und
erstellt die Kundenlinks.

Ordnernamen dienen als Empfängervorschlag. Für große Listen öffnest du beim
Empfängerschritt den CSV-Import mit den Spalten `folder`, `name`, `email`,
`locale`, `pin` und optional `groups`. Entwürfe, geplante Auslieferungen,
einzelne Links, Exporte, Wiederholungen, Reparaturen und Linkwechsel bleiben in
den Empfänger- und Freigabebereichen verfügbar. E-Mail-Adressen werden
verschlüsselt gespeichert.

## Projekt bearbeiten

Der Arbeitsbereich ist nach Aufgaben gegliedert:

- **Plan** zeigt Quelle, Status, Zweck und Medienübersicht.
- **Fotos** verwaltet Ordnerinhalt, Uploads, Metadaten und Sammlungen.
- **Auswahl** bietet Bewertungen, Picks, Ablehnungen, Farblabel, gespeicherte
  Ansichten und eine ausdrücklich ausgelöste XMP-Synchronisation.
- **Stil** steuert Einstieg, Sichtbarkeit und Größe des Titels, Sichtbarkeit
  der Fotoanzahl, Titelschrift, Layout, Theme, Logo, Titelbild, Akzentfarbe,
  Begrüßung, Metadaten und Vorschau-Wasserzeichen. Originale werden niemals
  mit einem Wasserzeichen verändert.
- **Ausliefern** erzeugt voneinander unabhängige öffentliche Links.
- **Ergebnisse** enthält Feedback, Kundenauswahlen, Exporte und Upload-Prüfung.
- **Verlauf** protokolliert relevante Galerieereignisse.

Änderungen verwenden Revisionsprüfungen. Hat ein anderes Browserfenster die
Galerie verändert, lade den aktuellen Stand, statt ihn unbemerkt zu überschreiben.

Lädt ein Eigentümer Dateien hoch, deren Namen bereits vorhanden sind, öffnet
Proofing Gallery vor der Übertragung den üblichen Nextcloud-Konfliktdialog. Jede
eingehende Datei kann die vorhandene ersetzen, unter nummeriertem Namen erhalten
bleiben oder übersprungen werden. Ersetzen legt eine neue Datei an und entfernt
die Galeriedaten der alten Datei; **Neue Version hochladen** erhält dagegen
Kommentare und Auswahlen.

## Fotos sichten

Die Sichtung ist per Tastatur bedienbar. Pfeiltasten wechseln das Bild, 0–5
setzt die Bewertung, **P** schaltet Pick, **X** Ablehnung, Leertaste die Auswahl
und Strg/Befehl+Z nimmt den letzten Stapel zurück. Benannte Ansichten speichern
Filter und Sortierung im Nextcloud-Konto. Der virtualisierte Filmstreifen bleibt
im Arbeitsbereich sichtbar und kann automatisch, rechts oder unten angeordnet
werden; die Wahl folgt dem Nextcloud-Konto über Geräte hinweg.

Bewertungen in der App bleiben von XMP getrennt, bis du eine Synchronisation
ausdrücklich prüfst und ausführst. Gleichzeitige Änderungen an Original oder
Sidecar stoppen den Schreibvorgang und werden als Konflikt gemeldet.

## Veröffentlichen und teilen

Erzeuge unter **Ausliefern** einen Link und konfiguriere seine Zielgruppe. Jeder
Link besitzt eigenen Startordner, Ordnertiefe, Sprache, Darstellung, Passwort,
Ablaufdatum, Downloadumfang, Metadaten, Feedbackrechte, Uploadrecht und eine
optionale Mindestbewertung. Die App verwendet native Nextcloud-Freigaben und
kann Instanzregeln nur verschärfen, nie lockern.

Kopiere den Link oder versende eine Einladung über den Nextcloud-Mailserver.
Ein leeres vorhandenes Passwortfeld behält das Passwort; entferne es nur über
die ausdrückliche Aktion. Das Widerrufen eines Links sperrt sofort genau diese
Zielgruppe, ohne andere Links oder Quelldateien anzutasten.

## Abstimmung und Kundenauswahl

Im Abstimmungsmodus können Gäste sich benennen und – sofern freigegeben – Likes,
Bewertungen, Picks, Ablehnungen, Farben, Kommentare, Markierungen und benannte
Auswahlen speichern. Ein Nextcloud-Konto ist nicht nötig. Identität und
Änderungstoken liegen in einer privaten Browsersitzung; gelöschte Website-Daten
beenden den Zugriff auf privates Feedback.

Klicke oder tippe direkt auf ein Bild, um einen nummerierten Punkt zu setzen
und den zugehörigen Kommentar zu schreiben. Wähle für die Tastaturplatzierung
**Punktkommentar hinzufügen**, verschiebe den Punkt mit den Pfeiltasten, drücke
zum Schreiben die Eingabetaste oder brich mit Escape ab. Kommentare ohne Punkt
bleiben unter **Feedback** verfügbar. Im Abstimmungsmodus bleiben die
Bedienelemente sichtbar; automatisches Ausblenden gilt nur für die
Präsentationsansicht. Eine explizite Filmstreifen-Einstellung bleibt wirksam.

Kundenbewertungen bleiben von der Eigentümer-Sichtung getrennt. Berechtigte
Personen können Zusammenfassungen prüfen und eine Übernahme ausdrücklich in
einer Vorschau bestätigen. XMP wird dadurch nie automatisch verändert.

## Prüfrunden und Nextcloud-Nachverfolgung

Jeder aktive Kundenlink kann Mindestanzahl, Höchstanzahl und Frist der Galerie
erben oder überschreiben. Gäste dürfen unvollständige Entwürfe speichern; bei
der Abgabe werden die Regeln geprüft und die Auswahl gesperrt. Der Eigentümer
kann freigeben, Änderungen anfordern oder die Freigabe in derselben Runde
erneut öffnen. Dies ist eine
Workflow-Entscheidung, keine elektronische Signatur oder rechtlich fixierte
Momentaufnahme.

Unter **Ergebnisse** stehen aktueller Status und nachvollziehbarer
Rundenverlauf. Sind die jeweiligen Apps verfügbar, lässt sich die Frist in
einen beschreibbaren Nextcloud-Kalender übernehmen und die Prüfung als
Deck-Karte anlegen. Die Ressourcen verwenden die Rechte des aktuellen
Benutzers; Proofing Gallery speichert weder Zugangsdaten noch den öffentlichen
Link-Token. Context Agent bietet experimentell ausschließlich lesende
Werkzeuge zum Auflisten von Galerien, für Details und Veröffentlichungsreife
sowie zur Suche nach Dateinamen. Veröffentlichung und Eigentümerentscheidungen
bleiben in der Oberfläche von Proofing Gallery.

## Downloads und Gast-Uploads

Abhängig vom Link dürfen Gäste einzelne Originale, ein ZIP ihrer Auswahl oder
einen Kontaktbogen aus Vorschauen laden. Für einzelne Dateien und Auswahl-ZIPs
stehen außerdem metadatenfreie JPEGs mit 2048 px oder 1600 px bereit, optional
mit dem Galerie-Wasserzeichen; kleinere Bilder werden nie hochskaliert.
Administrationslimits begrenzen große Auslieferungen.

Gast-Uploads sind fortsetzbar und landen zunächst in einem versteckten
Prüfeingang. Eigentümer oder berechtigte Manager nehmen sie unter konfliktfreien
Dateinamen an oder lehnen sie ab. Vor Annahme erscheinen sie nicht öffentlich.

## Metadaten und XMP

Ordnergalerien können begrenzte EXIF-/IPTC-Felder indexieren. Eigentümer filtern
nach Datum, Kamera, Objektiv, Stichwort oder Bewertung und bearbeiten
Beschreibungen in einem Adobe-kompatiblen `<Basisname>.xmp`-Sidecar. Das
Original bleibt unverändert.

Öffentliche Metadaten sind zunächst ausgeschaltet. Freigegeben werden können
ausgewählte Felder wie Datum, Kamera, Objektiv, Belichtung, Titel oder Copyright.
GPS, private Stichwörter, Eigentümerbewertungen und Workflow-Label bleiben privat.

## Manager, Archiv und Wiederherstellung

Eigentümer vergeben abgestufte Rollen an Nextcloud-Benutzer oder Gruppen.
Betrachter sehen Übersicht und Aktivität, Bearbeiter ändern erlaubte
Galerieeinstellungen und Manager auf Eigentümerebene veröffentlichen,
widerrufen, verwalten Zugriffe, archivieren und stellen wieder her. Diese Rollen
gewähren keinen Zugriff auf andere Dateien des Eigentümers.

Archivieren stoppt die Auslieferung, löscht aber weder Quelle noch Feedback.
Stelle die Galerie über **Archiv** wieder her. Fehlt ein Quellordner, wähle einen
eigenen Ersatzordner; der Server prüft ihn, bevor Link und Historie weiterlaufen.

## Datenschutz und Fehlerbehebung

Nach der Identifikation für die Zusammenarbeit können Gäste mit **Meine Daten
exportieren** ihre eigenen Bewertungsdaten herunterladen oder sie mit **Meine
Daten löschen** entfernen und die Gastsitzung beenden. Eigentümer können
vollständige App-Datensätze exportieren. Bei archivierten Galerien lässt sich
die Löschung der App-Daten mit 30 Tagen Widerrufsfrist planen; Quellordner und
ursprüngliche Nextcloud-Dateien werden nicht entfernt.

Ist ein Link möglicherweise bekannt geworden, widerrufe ihn zuerst und erzeuge
einen neuen. Versende Passwort und Link nicht im selben Kanal. Sicherheitsfehler
gehören in die private Sicherheitsmeldung des GitHub-Repositories, nicht in ein
öffentliches Issue.

Fehlen Inhalte, prüfe Quelle und Leserecht, Startordner, Bewertungs-/Typfilter
und den Abschluss der Hintergrundaufträge. Die Administration kann den
Systemstatus untersuchen, ohne Gastzugänge oder private Pfade offenzulegen.
