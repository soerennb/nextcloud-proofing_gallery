# Fotobox-Anbindung

Proofing Gallery stellt eine authentifizierte Schnittstelle für Galerie-Erstellung und JPEG-Uploads bereit. Die vorhandene Fotobox-Software wird dabei nicht verändert. Dieses Dokument beschreibt ihre künftigen Kommunikationswege. Das [OpenAPI-Dokument](../api/kiosk-v1.yaml) und die [englische Referenz mit curl-Beispielen](../en/kiosk-integration.md) enthalten den vollständigen Vertrag.

## Galerie einfach vorbereiten

Unter **Neues Projekt → Fotobox** Veranstaltungstitel, beschreibbaren Zielordner und Design wählen. Unter den Zugangseinstellungen lassen sich Galerie-Passwort und Ablaufdatum setzen; Nextcloud-Vorgaben gelten weiterhin. **Erstellen und veröffentlichen** erstellt den neuen Ordner und eine sofort erreichbare, zunächst leere Galerie. Danach Galerie-Link sowie Verbindungskonfiguration kopieren oder als JSON herunterladen. Konto- und Galerie-Passwörter werden nicht exportiert.

Die Galerie verwendet eine gemeinsame Standard-Auslieferung: Alle berechtigten Gäste sehen dieselben Fotos, neue Fotos stehen zuerst, einzelne Downloads sind verfügbar, soweit erlaubt. Gast-Uploads und Zusammenarbeit sind ausgeschaltet. Das vorhandene Event-Delivery-System mit privaten Empfängeralben wird hierfür nicht verwendet. Voreinstellungen liefern Standard-Zielordner und Design; gespeicherte Designs übertragen die Darstellung.

## Ablauf auf Kiosk-Seite

1. Nextcloud-Adresse, Konto und App-Passwort separat konfigurieren. HTTPS verwenden. Das Konto benötigt Veröffentlichungsrechte und muss Eigentümer der Galerie sein.
2. `GET /ocs/v2.php/apps/proofing_gallery/api/v1/kiosk/setup?format=json` liefert Standardordner, Design, Fähigkeiten, Freigabevorgaben und Upload-Limits.
3. Eine dauerhafte `eventId` lokal speichern. Mit `POST /ocs/v2.php/apps/proofing_gallery/api/v1/kiosk/galleries?format=json` die Galerie erstellen. Pflichtfelder: `eventId` und `title`. Optional: `parentFolderId`, `designPresetId`, `password`, `expiresAt`.
4. Die Antwort unter **`ocs.data`** speichern: `gallery.id`, `galleryUrl`, `eventId`, `schemaVersion` und `upload` mit URL-Vorlage und Limits.
5. Für jedes Foto vor dem Upload eine UUID `photoId` zusammen mit den unveränderten JPEG-Bytes dauerhaft speichern. In `upload.urlTemplate` den Platzhalter `PHOTO_ID` durch diese UUID ersetzen.
6. Per `PUT` mit `Content-Type: image/jpeg` die rohen JPEG-Bytes übertragen. Keine Multipart-Daten. Jeder API-Aufruf nutzt HTTP Basic mit Konto/App-Passwort und `OCS-APIRequest: true`.
7. Erst nach HTTP 200/201 die Bestätigung speichern und den QR-Code aus **`ocs.data.photoUrl`** erzeugen. Die Antwort enthält außerdem `photoId`, `fileId`, `status: stored` und `replayed`.

Beispiel für `photoUrl`: `https://cloud.example.test/s/SHARE_TOKEN?photo=12345`. Die Zahl ist die Nextcloud-Datei-ID. Der Link öffnet das Foto in der Galerie, nicht direkt die JPEG-Datei. Den zurückgegebenen Link unverändert verwenden. Ein gesetztes Galerie-Passwort wird weiterhin abgefragt und steht nicht im Link. Der QR-Code begrenzt den Zugang nicht auf dieses eine Foto.

## Parameter und Wiederholungen

`eventId` ist pro Konto eindeutig: 1–128 Zeichen aus Buchstaben, Ziffern, Punkt, Unterstrich, Doppelpunkt und Bindestrich, mit alphanumerischem Anfang. Der Titel umfasst nach Entfernen äußerer Leerzeichen 1–255 Zeichen. Fehlende oder `null`-Werte für Ordner/Design verwenden die persönlichen Vorgaben; `designPresetId: 0` verwendet das Studio-Design, positive IDs ein eigenes gespeichertes Design. Ein beschreibbarer Zielordner ist erforderlich. Das Ablaufdatum verwendet ein zukünftiges `YYYY-MM-DD`; leer bedeutet Nextcloud-Standard. Nextcloud kann Passwort oder maximale Gültigkeit erzwingen.

Dieselbe `eventId` mit denselben ursprünglichen Parametern setzt eine unterbrochene Erstellung fort oder gibt dieselbe Galerie zurück. Die zuerst aufgelösten Vorgaben bleiben erhalten, auch wenn Benutzereinstellungen später geändert werden. Abweichende Parameter führen zu HTTP 409. Ursprüngliche Anfrage einschließlich optionaler Werte und Passwort für Wiederholungen aufbewahren. Die Zuordnung verfällt nicht nach 24 Stunden, sondern bleibt bis zur endgültigen Galerielöschung oder Kontoentfernung bestehen.

Uploads verwenden eine dauerhafte UUID, normalisiert auf Kleinbuchstaben. Derselbe Inhalt unter derselben UUID liefert bei Wiederholung dieselbe Datei und URL. Unterschiedliche Bytes führen zu 409 und überschreiben nichts. Eine nach Unterbrechung vorhandene Datei wird anhand ihres deterministischen Namens und SHA-256-Prüfsumme wiedergefunden. Gespeicherte Dateien heißen `{photoId}.jpg`. Erfolg wird nach Dateiablage und Speicherung der Bestätigung gemeldet; Vorschauen können später fertig werden.

## Warteschlange und Fehler

| HTTP | Bedeutung und Reaktion |
| --- | --- |
| 201 | Neu erstellt/gespeichert: Bestätigung sichern, QR erzeugen |
| 200 | Erfolgreiche Wiederholung: dieselbe Bestätigung verwenden |
| 401 | Konto/App-Passwort prüfen |
| 403 | Berechtigung oder Richtlinie prüfen lassen |
| 404 | Eigene Galerie, Datei oder Freigabe nicht verfügbar |
| 409 | ID-Konflikt oder unbrauchbarer Galerie-Link: Zustand klären, nicht überschreiben |
| 422 | Parameter, JPEG, Dateigröße oder Freigabevorgaben korrigieren |
| 423 | Galerie beschäftigt: `Retry-After` beachten, derzeit 1 Sekunde |
| 429 | 60 neue Fotos pro Minute erreicht: `Retry-After` beachten, derzeit 60 Sekunden |
| Netzwerk/5xx | Unklarer Ausgang: ursprüngliche Anfrage beziehungsweise UUID und Bytes mit wachsendem Abstand erneut senden |

Erfolgreiche Wiederholungen zählen nicht gegen das Upload-Limit. Die lokale Warteschlange muss Neustarts überstehen und Fehler/ausstehende Uploads anzeigen. Ohne erfolgreiche Antwort keinen Erfolgs-QR erzeugen. Bei Wiederholung keine neue UUID für dieselbe Aufnahme vergeben. Originalbilder und Bestätigungen gemäß Betreiber-Vorgaben aufbewahren.

Widerrufene, abgelaufene, archivierte oder anders eingeschränkte Links werden nicht automatisch neu veröffentlicht. Eine externe Dateilöschung kann bereits erzeugte Links ungültig machen. Es gibt kein neues Upload-Geheimnis und keinen anonymen Upload-Endpunkt.

## Galerie während der Veranstaltung

Die leere Galerie zeigt einen Wartezustand. Sichtbare Fotobox-Galerien aktualisieren sich alle fünf Sekunden; ausgeblendete Tabs pausieren und aktualisieren beim Wiederanzeigen. Ein geöffnetes Foto bleibt anhand seiner Datei-ID ausgewählt. Direkte Ordnerabfragen vermeiden die Wartezeit auf die rekursive Indexierung. Unter normaler Last sollten neue Fotos innerhalb von zehn Sekunden erscheinen; Netz und Verarbeitung können dies verlängern.

Die kompatible Migration speichert Veranstaltungszuordnungen und Upload-Bestätigungen. Sperren verhindern parallele Duplikate, Transaktionen sichern Datenbankgrenzen und deterministische Dateinamen erlauben Wiederaufnahme. Konfigurationen enthalten keine Passwörter; der Anfrage-Fingerprint ist ein HMAC-SHA-256-Digest mit dem Nextcloud-Instanzgeheimnis und wird nicht exportiert. Datenschutzexport und endgültiges Löschen berücksichtigen diese Datensätze. Nach endgültigem Löschen keine Anfragen der alten Veranstaltung mehr wiederholen.
