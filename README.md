# BienenPlan

BienenPlan ist eine plattformübergreifende Projektmanagement-Anwendung. Das Backend verwaltet Benutzer, Gruppen, Projekte, Container und Aufgaben. Die fachliche Zuordnung von Aufgaben erfolgt über Gruppen: Ein Benutzer sieht eine Aufgabe, wenn er Mitglied einer der zugewiesenen Gruppen ist.

## Aktueller Stand

Der aktuelle Backend-Stand umfasst:

- PHP-REST-API mit Slim Framework 4
- MySQL-Datenbankschema für Benutzer, Gruppen, Projekte, Container, Tasks, Subtasks und Kommentare
- Registrierung und Login mit JWT
- Automatische persönliche Gruppe bei der Registrierung
- Task-CRUD
- Benutzerbezogene Task-Liste über Gruppenmitgliedschaften
- Gruppenverwaltung und Zuordnung von Benutzern zu Gruppen
- Zuordnung von Gruppen zu Tasks
- CORS- und Auth-Middleware

Noch nicht als API implementiert sind Projekte, Container, Subtasks und Kommentare. Diese Tabellen sind im Schema vorhanden und bilden die nächsten Ausbaustufen.

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
```

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
| `GET`    | `/api/tasks`                             | Tasks des angemeldeten Benutzers über seine Gruppenmitgliedschaften laden |
| `GET`    | `/api/tasks/{id}`                        | Eine nicht gelöschte Task laden                                           |
| `POST`   | `/api/tasks`                             | Task in einem Container anlegen                                           |
| `PUT`    | `/api/tasks/{id}`                        | Titel, Beschreibung, Status, Deadline und Attachment aktualisieren        |
| `DELETE` | `/api/tasks/{id}`                        | Task per Soft-Delete löschen                                              |
| `GET`    | `/api/groups`                            | Alle Gruppen laden                                                        |
| `POST`   | `/api/groups`                            | Eine Gruppe anlegen                                                       |
| `POST`   | `/api/groups/{groupId}/addUser/{userId}` | Benutzer einer Gruppe hinzufügen                                          |
| `GET`    | `/api/groups/{groupId}/users`            | Benutzer einer Gruppe laden                                               |
| `GET`    | `/api/users/{userId}/groups`             | Gruppen eines Benutzers laden                                             |
| `GET`    | `/api/tasks/{taskId}/groups`             | Gruppen einer Task laden                                                  |
| `POST`   | `/api/tasks/{taskId}/assign/{groupId}`   | Gruppe einer Task zuweisen                                                |
| `DELETE` | `/api/tasks/{taskId}/groups/{groupId}`   | Gruppenzuweisung von einer Task entfernen                                 |

### Aktuelle Task-Sichtbarkeit

`GET /api/tasks` verbindet `groups_tasks` mit `users_groups`. Dadurch werden nur Tasks geliefert, deren zugewiesene Gruppe dem angemeldeten Benutzer gehört. Die Antwort enthält neben den Task-Daten unter anderem `project_id`, `project_name`, `group_ids` und `group_names`.

Die Einzelabfrage `GET /api/tasks/{id}` prüft aktuell nur die Task-ID und den Soft-Delete-Status. Eine Berechtigungsprüfung anhand der Benutzergruppen ist als Sicherheits-Meilenstein vorgesehen.

## Datenmodell

| Tabelle        | Zweck                                     | Zentrale Beziehungen                                       |
| -------------- | ----------------------------------------- | ---------------------------------------------------------- |
| `users`        | Benutzer und Login-Daten                  | `groups.personal_user_id`, Ersteller/Löscher               |
| `groups`       | Team- und persönliche Gruppen             | `personal_user_id -> users.id`                             |
| `users_groups` | Gruppenmitgliedschaften und Rollen        | `user_id <-> groups_id`, Rolle `owner/admin/member`        |
| `projects`     | Oberste fachliche Einheit                 | `created_by`, wird von Containern referenziert             |
| `containers`   | Aufgabenbehälter innerhalb eines Projekts | `project_id`, `created_by`                                 |
| `tasks`        | Aufgaben                                  | `container_id`, `created_by`, Status, Deadline, Attachment |
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

| Bereich       | Aktuelle Funktionen                                                    | Nächste Funktionen                                                 |
| ------------- | ---------------------------------------------------------------------- | ------------------------------------------------------------------ |
| Auth          | `register`, `login`, Passwort-Hashing, JWT                             | Logout/Token-Sperre, Passwort-Reset, E-Mail-Validierung            |
| Benutzer      | `create`, `findByEmail`                                                | Profil lesen/ändern, Bild speichern, Benutzer deaktivieren         |
| Gruppen       | Gruppen lesen/erstellen, Benutzer hinzufügen, Mitglieder/Gruppen lesen | Rollen prüfen, Benutzer entfernen, Gruppe ändern/archivieren       |
| Tasks         | Erstellen, userbezogen lesen, Einzelansicht, ändern, Soft-Delete       | Berechtigungen, Statuswechsel, wiederherstellen, Filter/Pagination |
| Task-Gruppen  | Zuweisen, entfernen, Gruppen einer Task lesen                          | Duplicate-/Ownership-Prüfung, Transaktionen                        |
| Projekte      | Nur Datenbanktabelle                                                   | Vollständiges CRUD, Archivierung, Zugriffskontrolle                |
| Container     | Nur Datenbanktabelle                                                   | CRUD, Projektzuordnung, Soft-Delete                                |
| Subtasks      | Nur Datenbanktabelle                                                   | CRUD, Erledigungsstatus, Reihenfolge                               |
| Kommentare    | Nur Datenbanktabelle                                                   | CRUD, Autor, Soft-Delete                                           |
| Infrastruktur | PDO, CORS, Error Handler, Composer                                     | zentrale Validierung, Logging, API-Versionierung                   |

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

- `GET /api/tasks/{id}` muss dieselbe Gruppenberechtigung wie die Task-Liste prüfen.
- Schreiboperationen müssen prüfen, ob der Benutzer Mitglied ist und die erforderliche Rolle besitzt.
- Gruppen- und Task-Zuweisungen müssen Duplikate und nicht vorhandene Fremdschlüssel sauber behandeln.
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

Backend-MVP in Entwicklung. Authentifizierung, Gruppen und grundlegende Tasks sind vorhanden. Die Berechtigungsprüfung für Einzel-Tasks sowie die API für Projekte, Container, Subtasks und Kommentare sind die wichtigsten nächsten Schritte.

## Lizenz

Privates Ausbildungsprojekt ohne öffentliche Lizenz.
