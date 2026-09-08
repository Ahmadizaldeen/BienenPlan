# 🌱 Bienen Plan

Eine plattformübergreifende Projekt-Management-Anwendung zur Verwaltung von Aufgaben in übergeordneten Containern, mit Benutzerverwaltung, Authentifizierung und Gruppierung von Benutzern in Teams.

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
```text

## Tech-Stack
Backend
- PHP 8.2+
- Slim Framework 4 (REST API)
- MySQL
- Composer
- JWT (firebase/php-jwt) für Authentifizierung

Frontend (https://github.com/Ahmadizaldeen/bienenplan_frontend.git)
- Flutter
- Dart

## 🚀 Setup (Backend)

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

4. 4. Webserver konfigurieren:
   DocumentRoot muss auf `backend/public` zeigen, damit `.env`, `vendor/` und `config/` nicht über HTTP erreichbar sind.
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
DELETE	      /api/tasks/{id}	Aufgabe löschen

⚠️ Aktuell existieren noch keine Endpoints für groups, projects, containers, subtasks und comments — diese Ressourcen sind im DB-Schema bereits angelegt, aber noch nicht über die API erreichbar. Folgt in kommenden Iterationen.
## 🏗 Architektur

Flutter App
    │
    │ HTTP / JSON
    |
PHP REST API
    │
    │ SQL
    |
MySQL Database

📁 Projektstruktur
```text
BienenPlan/
│
├── backend/
│   ├── src/
│   │   ├── Config/          → DB-Connection (PDO)
│   │   ├── Controllers/     → Request-Handling pro Ressource
│   │   ├── Models/          → Datenbankzugriff pro Entität
│   │   ├── Services/        → z. B. JwtService
│   │   ├── Middleware/      → Auth, CORS
│   │   └── Error/           → zentrales Error-Handling
│   ├── public/
│   │   └── index.php        → Einstiegspunkt / Routing
│   ├── data/
│   │   └── sql/
│   │       ├── migrations/  → Schema-Definitionen
│   │       ├── seeds/       → Testdaten (geplant)
│   │       └── diagrams/    → ER-Diagramm
│   └── .env.example
│
├── docs/
├── .gitignore
└── README.md
```text

Datenbank

MySQL wird als Datenbank verwendet.

SQL-Dateien befinden sich unter:
backend/data/sql/ 

📌 Status
🚧 In Entwicklung

Fertig:

 Backend-Grundgerüst mit Slim Framework
 Authentifizierung (Register/Login mit JWT)
 Tasks CRUD über REST API
 DB-Schema für Users, Groups, Projects, Containers, Tasks, Subtasks, Comments

Geplant:
 Backend Batch, setup.bat entwicklen
 API-EndPoints /task vollstandigen
 API-Endpoints für Groups, Projects, Containers, Subtasks, Comments
 Automatisierte Tests (backend/tests/)
 Flutter-Frontend-Anbindung https://github.com/Ahmadizaldeen/BienenPlan.git
📄 Lizenz

Privates Ausbildungsprojekt, keine öffentliche Lizenz vergeben.
