# 🌱 Bienen Plan

Eine plattformübergreifende Projekt-Management-Anwendung für das Verwatung von Aufgaben in Übergeordente Contienern.
Benutzerverwaltung und authenirern und für Team mit gruppierung von Benutzen.

Programm Hierarchie:

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
### Backend
- PHP
- REST API
- MySQL
- Composer

## 🚀 Setup (Backend)

1. Abhängigkeiten installieren:
   cd backend && composer install

2. Umgebungsvariablen konfigurieren:
   cp .env.example .env
   # .env mit eigenen DB-Zugangsdaten befüllen

3. Datenbankschema anlegen:
   mysql -u root -p < data/sql/migrations/000_schema.sql

### Frontend
- Flutter
- Dart

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
BienenPlan/
│
├── backend/
│   ├── config/
│   ├── src/
│   │   ├── Controllers/
│   │   ├── Models/
│   │   ├── Services/
│   │   ├── Repositories/
│   │   └── Routes/
│   ├── public/
│   ├── data/
│   │   └── sql/
│   │       ├── migrations/
│   │       ├── seeds/
│   │       └── diagrams/
│   └── tests/
│
├── frontend/
│   ├── lib/
│   │   ├── models/
│   │   ├── services/
│   │   ├── screens/
│   │   ├── widgets/
│   │   └── utils/
│   ├── assets/
│   └── test/
│
├── docs/
├── .gitignore
└── README.md

Datenbank

MySQL wird als Datenbank verwendet.

SQL-Dateien befinden sich unter:
backend/data/sql/

📌 Status
🚧 In Entwicklung
