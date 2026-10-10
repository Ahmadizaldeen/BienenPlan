# BienenPlan

BienenPlan ist eine plattformübergreifende Projektmanagement-Anwendung. Das Backend verwaltet Benutzer, Gruppen, Projekte, Container und Aufgaben. Die fachliche Zuordnung von Aufgaben erfolgt über Gruppen: Ein Benutzer sieht eine Aufgabe, wenn er Mitglied einer der zugewiesenen Gruppen ist.

## Zentrale Berechtigungen

Die verbindliche fachliche Quelle ist die
[Berechtigungsmatrix](docs/BERECHTIGUNGSMATRIX.md). Die technischen Regeln liegen
in `backend/src/Services/AccessService.php`; Controller und Models verwenden
dieselben Regeln fuer Einzelzugriff und SQL-gefilterte Listen.

- `users.is_admin` ist die einzige globale Sonderrolle. Registrierung kann sie
  nicht vergeben. Die Auth-Middleware prueft bei jedem Request den aktiven
  Benutzer; Admin-Entzug wirkt auch mit bereits ausgestelltem JWT.
- Projekt-, Container- und Task-Owner werden aus `created_by` abgeleitet.
  Jedes aktive Konto darf eigene Projekte erstellen.
- Projektmitglieder duerfen Container und Tasks erstellen. Container- und
  Task-Ownership gewaehrt nach vollstaendigem Projektmitgliedschaftsentzug
  keinen Zugriff mehr, ausser eine eigene lokale oder persoenliche
  Aufgabenzuweisung besteht weiter. Historische Ersteller bleiben gespeichert.
- Task-Inhalte bearbeiten duerfen Admin, Projekt-Owner und berechtigter
  Task-Ersteller. Sichtbare Tasks duerfen Mitglieder im Status aendern.
- Mitglieder jeder einer Aufgabe zugewiesenen lokalen Gruppe sowie der
  Eigentuemer einer zugewiesenen persoenlichen Gruppe duerfen vorlaeufig alle
  Task-Inhalte bearbeiten, lokale Gruppen desselben Projekts zuweisen/entfernen
  und Unteraufgaben vollstaendig verwalten. Persoenliche Gruppen geben nur
  Zugriff auf die zugewiesene Aufgabe. Task-Loeschung und das Loeschen fremder
  Anhaenge werden dadurch nicht erlaubt. Details stehen in der verbindlichen
  Berechtigungsmatrix.
- Container-Owner duerfen Tasks im eigenen Container loeschen, erhalten aber
  keinen automatischen Task-Lesezugriff. Leere Container werden weich geloescht;
  aktive Tasks verhindern das Entfernen mit `409`.
- Gruppen fuer Projekte und Task-Zuweisungen verwalten nur Admin und
  Projekt-Owner. Task-Gruppen muessen zum Projekt gehoeren.
  Ausnahme: Berechtigte Mitglieder lokaler Gruppen sowie der Eigentuemer einer
  zugewiesenen persoenlichen Gruppe duerfen lokale Gruppen desselben Projekts
  hinzufuegen und entfernen. `can_manage_local_groups` kennzeichnet dieses Recht;
  `can_manage_groups` bleibt das volle Owner-/Admin-Recht.
- Globale Gruppen (`is_global = true`, `project_id = NULL`) werden nur von
  Admin erstellt und in ihrer Mitgliedschaft verwaltet. Sie sind auswaehlbar,
  aber nicht automatisch allen Projekten zugeordnet.
- Lokale Gruppen (`project_id` gesetzt, `is_global = false`) gehoeren genau
  einem Projekt. Dessen Owner verwaltet die Mitglieder. Andere Projekte
  koennen sie nicht sehen oder zuweisen.
- Persoenliche Gruppen bleiben separat (`personal_user_id` gesetzt).
  Sie sind keine globalen Teams und duerfen nicht manuell als Projektgruppe
  zugeordnet werden.
- Unteraufgaben und Anhaenge setzen Task-Zugriff voraus. Container-Ownership
  allein erlaubt keine Unteraufgabenverwaltung. Anhaenge loeschen Admin,
  Projekt-Owner oder der berechtigte Uploader.

### Projektarchiv fuer Admin

- `GET /api/projects/archived` liefert ausschliesslich archivierte Projekte;
  nur aktive globale Admins duerfen diese Liste abrufen.
- `GET /api/projects/{id}` erlaubt Admins auch archivierte Projektinformationen.
- `GET /api/containers?project_id={id}` und `GET /api/tasks?project_id={id}`
  liefern die nicht geloeschten Inhalte des gewaehlten Projekts. Archivierte
  Inhalte sind ausschliesslich fuer Admin lesbar. Ohne Projektfilter bleiben
  die normalen Listen auf aktive Projekte begrenzt.
- Container-/Task-Details, Unteraufgaben und Anhang-Downloads bleiben fuer
  Admin im Archiv lesbar. Schreibaktionen einschliesslich Status, Checkboxen,
  Uploads, Gruppenmitgliedschaften lokaler Gruppen und Zuweisungen sind gesperrt.
- `POST /api/projects/{id}/restore` stellt das Projekt wieder her, nur fuer Admin.
  `archived_at` und `archived_by` werden zurueckgesetzt. Inhalte, Ownership und
  bestehende Mitgliedschaften bleiben unveraendert; individuell geloeschte
  Ressourcen oder entzogene Mitgliedschaften werden nicht wiederhergestellt.
  Nicht vorhandene Projekte liefern `404`, bereits aktive Projekte `409`.
- Projektlisten und -details enthalten `can_edit`, `can_delete`,
  `can_manage_groups` und `can_restore`. Das Frontend muss diese Aktionsrechte
  statt `is_owner` zur Anzeige von Buttons verwenden. `can_delete` bedeutet
  Archivieren, nicht physisches Loeschen.

Frontend (`BienenPlanFrontend`): Admin-Bereich in der aufgeklappten Profil-Sidebar
mit Projektarchiv, archivierten Karten, schreibgeschuetzter Inhaltsansicht und
bestaetigter Wiederherstellung. Aktive Projektaktionen verwenden die API-Rechte,
damit Admins auch fremde Projekte bearbeiten und archivieren koennen.

Der Admin-Bereich oeffnet eine separate Archivseite mit Projektkarten:
Projektname, Badge **Archiviert**, Archivierungsdatum sowie **Ansehen** und
**Wiederherstellen**. Archivansichten zeigen ein Banner **Archiviert - nur lesen**.
Vor Wiederherstellung bestaetigen; bei Erfolg Karte aus dem Archiv entfernen
und aktive Projektliste neu laden. Keine archivierten Karten zwischen aktiven
Projekten mischen. Ladezustand, leeres Archiv und API-Fehler sichtbar behandeln.

Task-Listen und Details liefern `can_edit`, `can_edit_title_deadline`, `can_delete`,
`can_manage_groups`, `can_manage_local_groups`, `can_change_status`
und `can_create_subtasks` fuer die Rechteuebersicht. Projektgruppen liefern
`member_count` und `is_current_user_member` bezogen auf den authentifizierten
Betrachter.
`creator_has_project_access` kennzeichnet, ob der
historische Ersteller noch Zugriff auf das Projekt hat. Bestehende
Unteraufgaben- und Anhang-`can_*`-Felder bleiben erhalten.

Task-Status und Task-Inhalte pruefen Projektzustand und Aktionsrechte erneut
direkt im SQL-UPDATE. Wird der Zugriff zwischen Controller-Pruefung und
Schreibaktion entzogen, liefert die API `409`, ohne die Task zu aendern.
Unveraenderte, weiterhin berechtigte Updates bleiben erfolgreich.
Ein Zugriffsverlust bei der erneuten Pruefung waehrend der Task-Erstellung
liefert `404`; eine fehlende persoenliche Standardgruppe bleibt ein Serverfehler.
Lokale und globale Gruppennamen muessen nach dem Trimmen 1 bis 100
Unicode-Codepoints enthalten; ungueltige Typen, UTF-8 oder Laengen liefern `400`.
Unteraufgaben und Anhaenge pruefen dieselben Task-Rechte ebenfalls beim
INSERT/UPDATE. Ein zwischenzeitlicher Zugriffsverlust liefert `409`.
Abgebrochene Uploads rollen Datenbankeintraege zurueck und entfernen bereits
verschobene Dateien; verbotene Anhang-Loeschungen entfernen keine Datei.
Auch Container-, Projekt- und Gruppen-Schreibaktionen pruefen den aktuellen
Akteur und Projektzustand im mutierenden Statement, statt sich allein auf eine
vorherige Controller-Pruefung zu verlassen. Die Model-Schreibmethoden benoetigen
deshalb die authentifizierte Akteur-ID. Bei Projektgruppen-Entfernung werden
Task-Zuweisungen zurueckgerollt, wenn die Projektzuordnung nicht entfernt werden kann.

Neue Endpunkte:

| Endpunkt | Verhalten |
|---|---|
| `DELETE /api/groups/{groupId}/users/{userId}` | Mitglied entfernen; Admin oder Owner der lokalen Gruppe |
| `PUT /api/projects/{id}/groups/{groupId}` | Lokalen Gruppennamen und Mitglieder atomar bearbeiten; Body `{"name": "Team", "user_ids": [2]}`; Projekt-Owner oder Admin, mindestens ein aktiver Benutzer |
| `POST /api/tasks/{id}/move` | Body `{"container_id": 123}`; Admin oder Projekt-Owner, nur innerhalb desselben Projekts |

### Migration und Aktivierung

Vor Deployment die Datenbank sichern und die versionierten Migrationen in
Reihenfolge anwenden. Fuer die neuen Rechte ist
`backend/data/sql/migrations/005_add_central_access.sql` erforderlich.
`000_schema.sql` ist ein destruktives Entwicklungsschema, keine
Bestandsdatenmigration. Die Anwendung fuehrt Migrationen nicht automatisch aus.

Bestandsdatenbanken brauchen zuerst alle noch fehlenden Migrationen 001 bis 004,
danach einmalig 005 und die gepruefte Altgruppen-Einordnung mit 006.
Auf einem frisch importierten aktuellen `000_schema.sql` die Migrationen 001 bis
005 nicht erneut ausfuehren: ihre Schema-Aenderungen sind bereits enthalten.
004 und 005 sind nicht wiederholbar. Die DDL-Schritte von 005 sind nicht als
Ganzes transaktional; nach einem Fehler den erreichten Schema-Zustand pruefen,
statt die gesamte Datei blind erneut auszufuehren.

Ein Push/Merge nach `main` startet das Azure-Deployment aus
`.github/workflows/azure_deploy.yml`, aber weder Tests noch Migrationen.
Schema und Altgruppen deshalb im Wartungsfenster vor Aktivierung des neuen
Backend-Codes vorbereiten; ein erfolgreicher Deploy bestaetigt keine Laufzeittests.

Die neue Migration uebernimmt eindeutig einem Projekt zugeordnete Altgruppen
als lokale Gruppen. Mehrfach zugeordnete oder unzugeordnete Altgruppen bleiben
unklassifiziert (`project_id = NULL`, `is_global = false`, keine persoenliche
Gruppe). Ihre Daten und Zuordnungen bleiben gespeichert, verleihen bis zur
expliziten Einordnung aber keinen Projektzugriff. Vor Aktivierung pruefen:

```sql
SELECT id, name FROM groups
WHERE project_id IS NULL AND is_global = FALSE AND personal_user_id IS NULL;
```

Nach fachlicher Pruefung kann ein Betreiber eine solche Gruppe ausdruecklich
global machen: `UPDATE groups SET is_global = TRUE WHERE id = <gepruefte_id>;`.
Eine lokale Einordnung darf nicht mit bestehenden Fremdprojekt-Zuordnungen
kollidieren. Gruppen-Admins aus `users_groups.role` werden niemals automatisch
Superuser; die alte Spalte bleibt vorerst bestehen.

Fuer ausdruecklich bestaetigte lokale Altgruppen steht nach Migration 005
`backend/data/sql/migrations/006_localize_project_groups.php` bereit.
Es teilt unklassifizierte, mehrfach zugeordnete Gruppen pro Projekt auf,
kopiert Mitglieder inklusive Rolle und Zuordnungsdatum und ordnet Tasks
anhand ihres Containers der passenden lokalen Kopie zu.
Persoenliche und globale Gruppen bleiben unveraendert.
Gruppen ohne Projektzuordnung brauchen eine ausdrueckliche Wahl; Namenskollisionen
und widerspruechliche Task-Zuordnungen brechen die Migration ab.

```text
php backend/data/sql/migrations/006_localize_project_groups.php 4:1 6:1 24:8 26:1
php backend/data/sql/migrations/006_localize_project_groups.php --apply 4:1 6:1 24:8 26:1
```

Ohne `--apply` wird nur der Plan angezeigt. `group_id:project_id` sind
Bestandsdaten-spezifische Entscheidungen, keine allgemeingueltigen Defaults.
Vor Anwendung Backup erstellen und in einem Wartungsfenster arbeiten.
Die Lokalisierung erfolgt in einer Transaktion; erneute Ausfuehrung mit denselben
Wahlen erzeugt keine weiteren Kopien. Nach der Aufteilung sind Mitgliedschaften
der lokalen Kopien voneinander unabhaengig.

Die lokale Datenbank wurde am 2026-10-08 nach Backup und Wiederherstellungsprobe
mit den oben bestaetigten Zuordnungen migriert. Fuer andere Umgebungen muessen
Schema-Migration und Altgruppen-Einordnung separat durchgefuehrt werden.

Die erste Admin-Vergabe erfolgt durch den berechtigten Datenbankbetreiber:
`UPDATE users SET is_admin = TRUE WHERE id = <gepruefte_user_id> AND deleted_at IS NULL;`.
Es gibt bewusst keinen oeffentlichen Admin-Vergabe-Endpunkt.

### Berechtigungstests

```text
php backend/tests/AccessServiceTest.php
php backend/tests/SingleMemberGroupsTest.php
php backend/tests/AccessMigrationTest.php
php backend/tests/TaskCreationTest.php
php backend/tests/SubtasksTest.php
php backend/tests/TaskAttachmentsTest.php
```

Die MySQL-Integrationstests verwenden verbindungslokale temporaere Tabellen.
Der Migrationstest braucht `CREATE DATABASE`/`DROP DATABASE` und verwendet
ausschliesslich selbst erzeugte zufaellige Testdatenbanken, die danach entfernt
werden. Bestehende Datenbanken werden nicht migriert oder geloescht.
Unteraufgaben-/Anhangtests verwenden SQLite im Speicher.

### Standardzuweisung bei der Task-Erstellung

`POST /api/tasks` benoetigt keine ausgewaehlte Gruppe. Der authentifizierte Ersteller
wird automatisch ueber seine persoenliche Gruppe zugewiesen; Task und Zuweisung
werden in einer Transaktion gespeichert. Fehlt diese Gruppe oder ihre
Mitgliedschaft, wird die Erstellung zurueckgerollt und ein Serverfehler gemeldet.

Admin und Projektbesitzer sehen alle aktiven Aufgaben. Andere Benutzer sehen
Tasks ueber ihre zugewiesenen lokalen/globalen Gruppen, eine zugewiesene
persoenliche Gruppe oder als Task-Ersteller mit Projektzugriff. Die persoenliche
Standardzuweisung fuegt keine Gruppe zum Projekt hinzu und gewaehrt nur Zugriff
auf die konkret zugewiesene Aufgabe, nicht auf andere Projektaufgaben. Dieselben
Regeln gelten fuer Unteraufgaben und Anhaenge. Geloeschte Container bleiben
ausgeschlossen.
Archivierte Projekte sind nur fuer Admin schreibgeschuetzt lesbar.
Eine Task dürfen Admin, Projekt-Owner sowie berechtigte
Projektmitglieder als Task-Ersteller oder Container-Inhaber löschen.
Andere Nutzer können sichtbare Tasks lesen, aber nicht
löschen.

Regressionstest: `php backend/tests/TaskCreationTest.php` verwendet die konfigurierte
MySQL-/MariaDB-Verbindung aus `backend/.env`, aber ausschliesslich verbindungslokale
temporaere Tabellen. Bestehende Daten werden nicht veraendert. Er prueft Erstellung,
Standardzuweisung, Listen-/Detailzugriff, Containerzugriff, Erstellernamen und
Löschberechtigungen mit verständlicher Fehlermeldung sowie Transaktions-Rollback.

## Aktueller Stand

Programm Hierarchie:

```text
User
 │
 ├── Groups
 │
 └── Projects
       │
       └── Containers
             │
             └── Tasks
                   │
                   └── Subtasks
```

````text

Der aktuelle Backend-Stand umfasst:

- PHP-REST-API mit Slim Framework 4
- MySQL-Datenbankschema für Benutzer, Gruppen, Projekte, Container, Tasks, Subtasks und Kommentare
- Registrierung und Login mit JWT
- Automatische persönliche Gruppe bei der Registrierung
- Task-CRUD
- Benutzerbezogene Task-Liste über Gruppenmitgliedschaften
- Gruppenverwaltung und Zuordnung von Benutzern zu Gruppen
- Zuordnung von Gruppen zu Tasks
- Projekt- und Container-CRUD für sichtbare Ressourcen
- Profilbild-Upload für den authentifizierten Benutzer
- CORS- und Auth-Middleware

Aktuell in der API umgesetzt sind Auth, Benutzerprofil, Gruppen, Tasks, Projekte, Container und Subtasks. Kommentare sind bisher nur im Schema vorhanden.

## Technologie

| Bereich           | Technologie                |
| ----------------- | -------------------------- |
| Sprache           | PHP 8.2+                   |
| HTTP/API          | Slim Framework 4, PSR-7    |
| Datenbank         | MySQL / PDO                |
| Authentifizierung | JWT mit `firebase/php-jwt` |
| Konfiguration     | `vlucas/phpdotenv`         |
| Client            | Flutter / Dart             |
| Webserver         | Apache, z. B. XAMPP        |

## Architektur

```text
Flutter Client
1. Abhängigkeiten installieren:
cd backend
   composer install
   composer require vlucas/phpdotenv
   composer require slim/slim
   composer require slim/psr7
   composer require firebase/php-jwt

2. Umgebungsvariablen konfigurieren:
   cp .env.example .env
   # .env mit eigenen DB-Zugangsdaten befüllen

3. Datenbankschema anlegen:
   mysql -u root -p < data/sql/migrations/000_schema.sql

   oder mit GUI-Tool wie. PHPmyAdmin

 4. Webserver konfigurieren:
   Für einen Apache-VirtualHost sollte der DocumentRoot auf `backend/public` zeigen, damit `.env`, `vendor/` und `config/` nicht über HTTP erreichbar sind. Bei der einfachen XAMPP-Installation unter `htdocs` bleibt der DocumentRoot dagegen unverändert; die API-URL enthält dann den Projektpfad, z. B. `/BienenPlanBackend/backend/public`.
   `mod_rewrite` muss aktiv sein . C:\xampp\apache\conf\httpd.conf -> LoadModule rewrite_module modules/mod_rewrite.so

📡 API Endpoints
Antwortformat-Konvention: Alle Endpoints antworten mit Content-Type: application/json. Fehler folgen einheitlich dem Muster { "error": "<Nachricht>" }, Erfolgsmeldungen bei Schreiboperationen dem Muster { "message": "<Nachricht>", ... }.
Öffentlich (keine Authentifizierung erforderlich)
Methode	   |Endpoint	      |Beschreibung
GET	      /	               Setup-/Start-Check-Route, zeigt API-Info
GET	      /api	            API-Info: Name, Version, Status, verfügbare Endpoints
POST	      /api/register	   Neuen Benutzer registrieren
POST	      /api/login	      Anmelden, liefert JWT zurück

Geschützt (JWT erforderlich)
Header bei jeder Anfrage mitschicken: Authorization: Bearer <token>
Ohne gültigen Token: 401 { "error": "Nicht autorisiert" } bzw. 401 { "error": "Ungültiges oder abgelaufenes Token" }
Methode	      |Endpoint	      |Beschreibung
GET	         /api/tasks	      Alle Aufgaben abrufen
GET	         /api/tasks/{id}	Einzelne Aufgabe abrufen
POST	         /api/tasks	      Neue Aufgabe erstellen
PUT	         /api/tasks/{id}	Aufgabe aktualisieren
DELETE	      /api/tasks/{id}	Aufgabe als Admin, Projekt-Owner oder berechtigter Task-/Container-Ersteller per Soft-Delete löschen

Gruppen-, Projekt-, Container- und Subtask-Endpunkte sind unten aufgeführt. Kommentare sind derzeit nur im Datenbankschema vorhanden.
## 🏗 Architektur

Flutter App
    │
    │ HTTP / JSON
    |
    | HTTP + JSON + Bearer JWT
    v
Slim REST API (backend/public/index.php)
    |
    +-- Middleware: CORS, Authentifizierung, Fehlerbehandlung
    +-- Controller: Request-Validierung und HTTP-Antworten
    +-- Models: PDO und SQL-Zugriff
    +-- Services: JWT und Environment-Konfiguration
    v
MySQL (bienenplan)
````

### Schichten

| Schicht               | Ort                        | Aufgabe                                             |
| --------------------- | -------------------------- | --------------------------------------------------- |
| Entry Point / Routing | `backend/public/index.php` | Abhängigkeiten erstellen und Routen registrieren    |
| Controller            | `backend/src/controllers/` | Request lesen, Eingaben prüfen, JSON-Antwort senden |
| Model                 | `backend/src/Models/`      | SQL-Abfragen und Datenbankoperationen               |
| Middleware            | `backend/src/Middleware/`  | JWT-Prüfung und CORS                                |
| Service               | `backend/src/Services/`    | JWT und Umgebungsvariablen                          |
| Config                | `backend/src/Config/`      | PDO-Verbindung                                      |
| Fehler                | `backend/src/Error/`       | Bootstrap- und 404-Fehler behandeln                 |

## Setup

### Voraussetzungen

- PHP 8.2 oder neuer
- Composer
- MySQL oder MariaDB
- Apache mit aktiviertem `mod_rewrite`

### Installation

```powershell
cd backend
composer install
```

Eine `.env` im Verzeichnis `backend` anlegen. Die benötigten Werte richten sich nach `backend/src/Services/Env.php` und `backend/src/Config/Database.php`.

Datenbank und Tabellen anlegen:

```powershell
mysql -u root -p < data/sql/migrations/000_schema.sql
```

Für eine bestehende Datenbank, die noch kein `tasks.deleted_by` und den zugehörigen Index/Fremdschlüssel enthält, stattdessen die Migration ausführen:

```powershell
mysql -u root -p bienenplan < data/sql/migrations/001_add_task_soft_delete_metadata.sql
```

Die Migration ist wiederholbar. `000_schema.sql` setzt die Entwicklungsdatenbank hingegen zurück (`DROP DATABASE`); nicht zum Aktualisieren einer bestehenden Datenbank verwenden.

Für Mehrfach-Anhänge auf einer bestehenden Datenbank anschließend `backend/data/sql/migrations/002_add_task_attachments.sql` ausführen. Sie übernimmt bereits hochgeladene Einzelanhänge aus `tasks.attachment` in die neue Tabelle; früher als Text eingegebene externe URLs sind keine hochgeladenen Dateien und werden nicht übernommen. Bestehende Dateien unter `public/uploads/tasks/` müssen auf dem Server erhalten bleiben. Apache muss `.htaccess` auswerten, damit weder alte Task-Dateien noch neue Dateien im Verzeichnis `backend/storage/` direkt über HTTP zugänglich sind. Die neue Tabelle ist im frischen Schema bereits enthalten.

Für Subtasks auf einer bestehenden Datenbank einmalig
`backend/data/sql/migrations/003_add_subtask_permissions.sql` in der konfigurierten
Datenbank ausführen. Die Migration ergänzt `created_by`, `deleted_at`, `deleted_by`
und einen Index samt Fremdschlüsseln, ohne bestehende Subtasks zu löschen.
Sie ist nicht wiederholbar; auf einem frisch importierten `000_schema.sql` ist sie
nicht erforderlich. Alte Subtasks behalten `created_by = NULL` und können von
Admin oder Projekt-Eigentümern mit Task-Zugriff verwaltet werden.

Optional Testdaten laden:

```powershell
mysql -u root -p bienenplan < data/sql/seeds/seeds.sql
```

Der Apache DocumentRoot sollte auf `backend/public` zeigen. Bei XAMPP muss `mod_rewrite` in `apache/conf/httpd.conf` aktiviert sein.

## API

Alle Endpoints liefern JSON. Schreiboperationen verwenden in der Regel das Format `{ "message": "..." }`; Fehler verwenden `{ "error": "..." }`.

### Öffentliche Endpoints

| Methode | Endpoint        | Funktion                                              |
| ------- | --------------- | ----------------------------------------------------- |
| `GET`   | `/`             | Setup-/Start-Check                                    |
| `GET`   | `/api`          | API-Informationen                                     |
| `POST`  | `/api/register` | Benutzer registrieren und persönliche Gruppe erzeugen |
| `POST`  | `/api/login`    | Benutzer anmelden und JWT ausgeben                    |

### Geschützte Endpoints

Für alle folgenden Routen ist dieser Header erforderlich:

```http
Authorization: Bearer <token>
Content-Type: application/json
```

| Methode  | Endpoint                                 | Aktuelle Funktion                                                         |
| -------- | ---------------------------------------- | ------------------------------------------------------------------------- |
| `GET`    | `/api/me`                                | Aktuellen Benutzer mit Authentifizierungsdaten laden                      |
| `POST`   | `/api/me/picture`                        | Profilbild für den angemeldeten Benutzer hochladen                        |
| `GET`    | `/api/tasks`                             | Tasks laden, auf die der angemeldete Benutzer laut Berechtigungsmatrix Zugriff hat |
| `GET`    | `/api/tasks/{id}`                        | Eine nicht gelöschte Task laden                                           |
| `POST`   | `/api/tasks`                             | Task in einem Container anlegen                                           |
| `PUT`    | `/api/tasks/{id}`                        | Titel, Beschreibung, Status und Deadline aktualisieren                    |
| `DELETE` | `/api/tasks/{id}`                        | Task als Admin, Projekt-Owner oder berechtigter Task-/Container-Ersteller per Soft-Delete löschen |
| `GET`    | `/api/tasks/{taskId}/subtasks`             | Aktive Subtasks und Berechtigungen laden                                  |
| `POST`   | `/api/tasks/{taskId}/subtasks`             | Subtask gemaess Berechtigungsmatrix erstellen |
| `PUT`    | `/api/tasks/{taskId}/subtasks/{subtaskId}`  | Titel und/oder completed aktualisieren                                   |
| `DELETE` | `/api/tasks/{taskId}/subtasks/{subtaskId}`  | Subtask per Soft-Delete löschen                                           |
| `GET`    | `/api/tasks/{id}/attachments`            | Hochgeladene Dateien auflisten (inkl. `can_delete`)                       |
| `POST`   | `/api/tasks/{id}/attachments`            | Mehrere Dateien als `multipart/form-data`, Feld `files[]`, hochladen      |
| `GET`    | `/api/tasks/{id}/attachments/{attachmentId}/download` | Datei authentifiziert herunterladen                    |
| `DELETE` | `/api/tasks/{id}/attachments/{attachmentId}` | Datei als berechtigter Uploader, Admin oder Projekt-Owner löschen       |
| `GET`    | `/api/groups`                            | Für den angemeldeten Benutzer sichtbare Gruppen laden                     |
| `POST`   | `/api/groups`                            | Als Admin eine globale Gruppe anlegen (persönliche Namen sind reserviert) |
| `POST`   | `/api/groups/{groupId}/addUser/{userId}` | Benutzer einer Gruppe hinzufügen                                          |
| `GET`    | `/api/groups/{groupId}/users`            | Benutzer einer Gruppe laden                                               |
| `GET`    | `/api/groups/{groupId}/personal-user`    | Benutzerdaten einer persönlichen Gruppe (`Personal user {id}`) laden      |
| `GET`    | `/api/users/{userId}/groups`             | Gruppen eines Benutzers laden                                             |
| `GET`    | `/api/tasks/{taskId}/groups`             | Gruppen einer Task laden                                                  |
| `POST`   | `/api/tasks/{taskId}/assign/{groupId}`   | Gruppe einer Task zuweisen                                                |
| `DELETE` | `/api/tasks/{taskId}/groups/{groupId}`   | Gruppenzuweisung von einer Task entfernen                                 |
| `GET`    | `/api/projects`                          | Projekte des angemeldeten Benutzers laden                                 |
| `GET`    | `/api/projects/{id}`                     | Einzelnes Projekt laden                                                   |
| `POST`   | `/api/projects`                          | Projekt anlegen                                                           |
| `PUT`    | `/api/projects/{id}`                     | Projekt aktualisieren                                                     |
| `DELETE` | `/api/projects/{id}`                     | Projekt archivieren                                                       |
| `GET`    | `/api/containers`                        | Container sichtbar für den aktuellen Benutzer laden                       |
| `GET`    | `/api/containers/{id}`                   | Einzelnen Container laden                                                 |
| `POST`   | `/api/containers`                        | Container anlegen                                                         |
| `PUT`    | `/api/containers/{id}`                   | Container aktualisieren                                                   |
| `DELETE` | `/api/containers/{id}`                   | Container löschen                                                         |

Gruppen-Listen (`/api/groups`, `/api/tasks/{taskId}/groups`, `/api/users/{userId}/groups`) liefern je Gruppe `id`, `name`, `personal_user_id`, `personal_user_name`, `project_id`, `is_global` und `created_at`. Bei persönlichen Gruppen enthält `personal_user_name` den Benutzernamen (sonst `null`), sodass kein Zusatz-Request pro Gruppe nötig ist.

### Subtasks

`GET /api/tasks/{taskId}/subtasks` liefert `{ "can_create": true, "subtasks": [...] }`,
nach ID sortiert. Ein Subtask enthält `id`, `task_id`, `title`, `completed`,
`created_by`, `can_edit`, `can_delete` und `can_complete`; Status und Berechtigungen
sind JSON-Booleans. `POST` erwartet `{ "title": "Schritt" }` und liefert den neuen
Subtask mit `201`. `PUT` akzeptiert nur die übergebenen Felder `title` und/oder
`completed` und liefert den aktualisierten Subtask mit `200`.

Titel müssen nach dem Trimmen 1 bis 100 Unicode-Codepoints enthalten.
Die verbindliche Rechtevergabe fuer Erstellen, Aendern, Abhaken und Loeschen
steht in der [Berechtigungsmatrix](docs/BERECHTIGUNGSMATRIX.md).
Alle Rechte werden im Backend geprüft,
auch bei kombinierten Änderungsrequests. Fehlende Ressourcen oder fehlender
Task-Zugriff liefern `404`, verbotene Aktionen `403`, ungültige Daten `400`.

`DELETE` setzt `deleted_at` und `deleted_by` und liefert mit `200` eine
Erfolgsmeldung. Bei gelöschten Aufgaben/Containern sind Subtasks nicht verfügbar.
Im Projektarchiv darf nur Admin lesen; alle Schreibaktionen sind gesperrt.
Der Hauptaufgabenstatus wird nicht verändert.
Regressionstest: `php backend/tests/SubtasksTest.php` (PDO SQLite erforderlich).

### Aktuelle Task-Sichtbarkeit

`GET /api/tasks` und `GET /api/tasks/{id}` verwenden dieselbe zentrale Zugriffspolitik: Admin und Projekt-Owner sehen alle aktiven Tasks. Andere Benutzer sehen Tasks ihrer zugewiesenen lokalen/globalen Gruppen, eine ihrer persoenlichen Gruppe zugewiesene Task oder eigene Tasks bei fortbestehendem Projektzugriff. Persoenliche Gruppen verleihen keine Projektmitgliedschaft. Die Antwort enthält neben den Task-Daten unter anderem `project_id`, `project_name`, `group_ids`, `group_names` und Aktionsrechte.

Gelöschte Tasks und Container bleiben ausgeschlossen. Ohne Projektfilter liefern Listen nur aktive Projekte; mit `project_id` darf Admin auch archivierte Inhalte lesen.

Anhang-Endpunkte setzen Task-Zugriff voraus; Container-Ownership allein reicht nicht. In aktiven Projekten dürfen Benutzer mit Task-Zugriff Dateien ansehen und hochladen; berechtigte Uploader, Admin und Projekt-Owner dürfen löschen. Im Archiv darf nur Admin lesen und herunterladen, nicht hochladen oder löschen. Es gelten dieselben Dateitypen wie beim bisherigen Upload (`pdf`, `png`, `jpg`, `jpeg`, `gif`, `docx`, `xlsx`, `txt`) und 10 MB pro Datei. Bei ungültiger Datei wird der gesamte Mehrfach-Upload abgelehnt. `POST /api/tasks/{id}/attachment` mit Feld `file` bleibt als kompatibler Einzel-Upload verfügbar und erzeugt ebenfalls einen neuen Eintrag, statt den bisherigen zu überschreiben. PHP-Limits `post_max_size`, `upload_max_filesize` und `max_file_uploads` müssen für den gewünschten Mehrfach-Upload passend konfiguriert sein.

Gruppenzuweisungen sind nur für vorhandene, nicht gelöschte Tasks und vorhandene Gruppen möglich. Ungültige IDs führen zu `400`, nicht vorhandene Tasks oder Gruppen zu `404`. Wird dieselbe Gruppe einem Task erneut zugewiesen, antwortet `POST /api/tasks/{taskId}/assign/{groupId}` mit `409 Conflict` und `{ "error": "Gruppe ist diesem Task bereits zugeordnet" }`; die bestehende Zuordnung bleibt unverändert.

## Datenmodell

| Tabelle        | Zweck                                     | Zentrale Beziehungen                                       |
| -------------- | ----------------------------------------- | ---------------------------------------------------------- |
| `users`        | Benutzer und Login-Daten                  | `groups.personal_user_id`, Ersteller/Löscher               |
| `groups`       | Team- und persönliche Gruppen             | `personal_user_id -> users.id`                             |
| `users_groups` | Gruppenmitgliedschaften und Rollen        | `user_id <-> groups_id`, Rolle `owner/admin/member`        |
| `projects`     | Oberste fachliche Einheit                 | `created_by`, wird von Containern referenziert             |
| `containers`   | Aufgabenbehälter innerhalb eines Projekts | `project_id`, `created_by`                                 |
| `tasks`        | Aufgaben                                  | `container_id`, `created_by`, `deleted_by`, Status, Deadline, bisheriges Attachment-Feld (nur Altbestand) |
| `task_attachments` | Hochgeladene Task-Dateien             | `task_id`, `uploaded_by`, Originalname, Speichername                      |
| `groups_tasks` | Task-Zuweisungen an Gruppen               | `group_id <-> task_id`                                     |
| `subtasks`     | Unteraufgaben                             | `task_id`                                                  |
| `comments`     | Kommentare zu Tasks                       | `task_id`, `user_id`                                       |

### Gruppenlogik

Bei der Registrierung werden atomar angelegt:

1. Benutzer in `users`
2. persönliche Gruppe in `groups`
3. Mitgliedschaft mit Rolle `owner` in `users_groups`

Tasks werden nicht direkt einzelnen Benutzern zugewiesen. Eine direkte Benutzerzuweisung wird fachlich über die persönliche Gruppe des Benutzers abgebildet.

## Backend-Funktionen

| Bereich       | Aktuelle Funktionen                                                                         | Nächste Funktionen                                                 |
| ------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| Auth          | `register`, `login`, Passwort-Hashing, JWT                                                  | Logout/Token-Sperre, Passwort-Reset, E-Mail-Validierung            |
| Benutzer      | `create`, `findByEmail`, `findById`, `setPicture`, Profilbild-Upload über `/api/me/picture` | Profil ändern, Benutzer deaktivieren, Bild-Optimierung             |
| Gruppen       | Gruppen lesen/erstellen, Benutzer hinzufügen, Mitglieder/Gruppen lesen                      | Rollen prüfen, Benutzer entfernen, Gruppe ändern/archivieren       |
| Tasks         | Erstellen, userbezogen lesen, Einzelansicht, ändern, eigentümergeschütztes Soft-Delete     | Weitere Berechtigungen, Statuswechsel, wiederherstellen, Filter/Pagination |
| Task-Gruppen  | Zuweisen (Duplikat liefert `409`), entfernen, Gruppen einer Task lesen                      | Ownership-Prüfung, Transaktionen                                    |
| Projekte      | CRUD über API                                                                               | Archivierung, Zugriffskontrolle, Filterung                         |
| Container     | CRUD über API                                                                               | Soft-Delete, erweitertes ACL, Sortierung                           |
| Subtasks      | Nur Datenbanktabelle                                                                        | CRUD, Erledigungsstatus, Reihenfolge                               |
| Kommentare    | Nur Datenbanktabelle                                                                        | CRUD, Autor, Soft-Delete                                           |
| Infrastruktur | PDO, CORS, Error Handler, Composer                                                          | zentrale Validierung, Logging, API-Versionierung                   |

## Meilensteine

| Meilenstein                  | Ziel                                       | Ergebnis / Abnahmekriterium                                                    |
| ---------------------------- | ------------------------------------------ | ------------------------------------------------------------------------------ |
| M0 - Grundlage               | Setup und reproduzierbare Umgebung         | Composer, `.env`, Datenbankmigration und Health-Route funktionieren            |
| M1 - Auth                    | Benutzer sicher anmelden                   | Register erzeugt User + persönliche Gruppe; Login liefert JWT                  |
| M2 - Gruppen                 | Gruppen und Mitgliedschaften verwalten     | Gruppen, Rollen und Benutzerzuordnungen können gelesen und angelegt werden     |
| M3 - Task-MVP                | Kern-Workflow bereitstellen                | Task-CRUD, Soft-Delete, Status, Deadline und Attachment funktionieren          |
| M4 - Zugriffsschutz          | Daten nur berechtigten Benutzern zeigen    | Jede Task-Lese-/Schreiboperation prüft Gruppenmitgliedschaft und Rolle         |
| M5 - Projekte und Container  | Hierarchie aus dem Schema als API umsetzen | Projekt- und Container-CRUD mit Archivierung und Ownership                     |
| M6 - Subtasks und Kommentare | Zusammenarbeit an Tasks ermöglichen        | Subtasks und Kommentare können erstellt, gelesen, geändert und gelöscht werden |
| M7 - API-Qualität            | API stabil und wartbar machen              | Validierung, einheitliche Fehlercodes, Pagination, Filter und OpenAPI-Doku     |
| M8 - Tests und Betrieb       | Release-Fähigkeit herstellen               | Integrations-/API-Tests, Logging, Backup-Konzept und Deployment-Dokumentation  |

## Geplante Endpoint-Erweiterungen

Diese Routen sind aus dem Datenbankschema abgeleitet und aktuell noch nicht registriert:

| Ressource  | Geplante Endpoints                                                                     | Features                                |
| ---------- | -------------------------------------------------------------------------------------- | --------------------------------------- |
| Benutzer   | `GET/PUT /api/users/me`, `DELETE /api/users/me`                                        | Profil, Bild, Deaktivierung             |
| Gruppen    | `PUT/DELETE /api/groups/{id}`, `DELETE /api/groups/{id}/users/{userId}`                | Verwaltung, Rollen und Mitgliedschaften |
| Projekte   | `GET/POST /api/projects`, `GET/PUT/DELETE /api/projects/{id}`                          | CRUD und Archivierung                   |
| Container  | `GET/POST /api/projects/{projectId}/containers`, `GET/PUT/DELETE /api/containers/{id}` | Projektstruktur und Soft-Delete         |
| Subtasks   | `GET/POST /api/tasks/{taskId}/subtasks`, `PUT/DELETE /api/subtasks/{id}`               | Unteraufgaben und Erledigung            |
| Kommentare | `GET/POST /api/tasks/{taskId}/comments`, `PUT/DELETE /api/comments/{id}`               | Diskussion und Moderation               |

## Sicherheit und technische To-dos

- `DELETE /api/tasks/{id}` setzt `deleted_at` und `deleted_by`, löscht keine Daten physisch und ist dem Task-Ersteller, Container-Inhaber oder Projekt-Owner erlaubt, sofern Container und Projekt aktiv sind. Für sichtbare Tasks liefert ein anderer Benutzer `403` mit einer verständlichen Meldung; nicht sichtbare und bereits gelöschte Tasks liefern `404`.
- Listen und Einzelzugriffe verwenden die zentrale Zugriffspolitik; Mitgliedschaftsentzug sperrt auch eigene Tasks.
- Schreiboperationen pruefen die passende Aktion; Leserechte allein erlauben keine Task-Inhaltsaenderung.
- Doppelte Gruppenmitgliedschaften und Task-Gruppenzuweisungen liefern `409`; ungueltige beziehungsweise nicht vorhandene IDs liefern `400` beziehungsweise `404`.
- Fehlermeldungen sollten keine internen Datenbankdetails an Clients ausgeben.
- Eingabevalidierung, Pagination und Rate-Limiting fehlen noch.
- Das Schema nutzt für die Entwicklungsphase `DROP DATABASE IF EXISTS`; für Produktion muss eine versionierte Migration ohne destruktives Zurücksetzen verwendet werden.

## Projektstruktur

```text
BienenPlan/
├── backend/
│   ├── public/index.php
│   ├── src/
│   │   ├── Config/
│   │   ├── controllers/
│   │   ├── Error/
│   │   ├── Middleware/
│   │   ├── Models/
│   │   └── Services/
│   ├── data/sql/
│   │   ├── migrations/000_schema.sql
│   │   ├── seeds/seeds.sql
│   │   └── diagrams/
│   ├── tests/
│   ├── composer.json
│   └── vendor/
├── docs/
└── README.md
```

## Status

Backend-MVP in Entwicklung. Authentifizierung, zentrale Berechtigungen sowie
Projekt-, Container-, Task-, Unteraufgaben- und Anhang-Endpunkte sind vorhanden.
In anderen Umgebungen ist vor Aktivierung die dokumentierte Migration erforderlich.
Kommentare und weitere API-/Betriebsverbesserungen bleiben naechste Schritte.

## Lizenz

Privates Ausbildungsprojekt ohne öffentliche Lizenz.
Privates Ausbildungsprojekt, keine öffentliche Lizenz vergeben.

## Deploy:

[![Deploy API to Azure](https://github.com/Ahmadizaldeen/BienenPlan/actions/workflows/azure_deploy.yml/badge.svg)](https://github.com/Ahmadizaldeen/BienenPlan/actions/workflows/azure_deploy.yml)
