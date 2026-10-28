# 🏢 NexiLiving — Backend API

> Robust, Headless API and AI Engine built with Laravel 12, PHP 8.2, Sanctum, Reverb, and PostgreSQL/MySQL.

---

## 🚀 Quick Start

### Prerequisites

| Tool | Version |
|------|---------|
| PHP | ≥ 8.2 |
| Composer | ≥ 2.5 |
| MySQL / PostgreSQL | ≥ 8.0 / 14 |
| Node.js & NPM | (For Vite / Laravel Echo assets) |

### Setup

```bash
# 1. Clone the repo
git clone https://github.com/miguelcamilok/backend-rentus.git
cd backend-rentus

# 2. Install dependencies
composer install
npm install

# 3. Configure environment
cp .env.example .env
php artisan key:generate

# 4. Run Migrations and Seeders
php artisan migrate --seed

# 5. Start development servers
# You can run the dev command to start Laravel, Vite, and Queue listeners simultaneously:
composer run dev
```

---

## 🗂️ Project Structure

## 👥 Ownership & Core Technologies

This application follows a strict Domain-Driven Design (DDD) ownership model. Pull Requests must undergo code-reviews by the primary maintainers of the modified domains before merging.

### 🛡️ System Ownership (Backend Platform)

| Domain / Responsibility | Lead Developer | Specific Areas |
|-------------------------|----------------|----------------|
| **Lyra AI, Cloud & Core** | `@miguelcamilok` | Lyra AI Engine (RAG, Prompting), Cloud infrastructure, and Core Backend foundation. |
| **Backend Business Logic** | `@Alexanderbyrush` | Business rules, Data Logic, Eloquent Models, Database relationships, and Controllers. |
| **Map API & Features** | `@jefermar` | Map Engine functionality, Geo-spatial APIs, and endpoints integration logic. |

### 🛠️ Core Technologies
*   **Core Framework:** Laravel 12.0
*   **Language:** PHP 8.2 (Strict Types)
*   **Database Engine:** PostgreSQL / MySQL 8.0+
*   **Real-time Engine:** Laravel Reverb (WebSockets)
*   **AI Integrations:** Google Gemini / LLMs

```text
backend-rentus/
├── 📁 app/                    # 🔵 CORAZÓN DE LA LÓGICA DE SERVIDOR Y NEGOCIOS
│   ├── 📁 Console/            # (DevOps Team) Tareas programadas CRON y comandos Artisan custom
│   ├── 📁 Events/             # Eventos emitidos en el ciclo de vida (ej. PropertyCreated -> avisa a WebSockets)
│   ├── 📁 Http/               # (API Team) Capa de Transporte WEB/API
│   │   ├── 📁 Controllers/    # Enrutadores lógicos orientados a REST
│   │   ├── 📁 Middleware/     # Filtros de seguridad, permisos y JWT
│   │   └── 📁 Requests/       # Reglas de validación estrictas para datos entrantes (Formularios)
│   ├── 📁 Jobs/               # Trabajos asíncronos pesados enviados a colas (ej. enviar correos, procesar imágenes)
│   ├── 📁 Models/             # Modelos Eloquent ORM (Representación de las tablas de la Base de Datos)
│   └── 📁 Services/           # 🧠 MOTORES DE LA PLATAFORMA (Donde reside la magia):
│       ├── 📄 LyraBrainService.php           # (AI Team) Orquestador del asistente Inteligente y procesamiento de Prompt (RAG)
│       ├── 📄 LocalInterpreterEngine.php     # (AI Team) Analizador semántico y de intención local
│       ├── 📄 LyraVoicePersonality.php       # (AI Team) Inyección de personalidad y reglas directivas para el LLM
│       ├── 📄 GeoLocationEngine.php          # (Data Team) Computación de distancias (Haversine), radios y perímetros para mapas interactivos
│       └── 📄 PropertySearchEngine.php       # (Backend Team) Motor robusto de filtrado multivariable de propiedades
├── 📁 bootstrap/              # Arranque y caché de abstracción inicial del Framework
├── 📁 config/                 # Configuraciones maestras (Base de Datos, Pushers, SMTP, S3, IA Keys)
├── 📁 database/               # Relacionado con ESTRUCTURA de Base de Datos
│   ├── 📁 factories/          # Generadores de datos ficticios masivos
│   ├── 📁 migrations/         # (DBA Team) Historial de tablas y sus estructuras. Control de versiones SQL
│   └── 📁 seeders/            # Poblamiento inicial de la DB (Catálogos, Opciones)
├── 📁 public/                 # Carpeta raíz pública expuesta al servidor web. Intercepta llamadas hacia la app.
├── 📁 routes/                 # El mapa de URLs a puntos de control backend
│   ├── 📄 api.php             # Endpoints principales consumidos por frontend (Stateless)
│   ├── 📄 channels.php        # Canales de comunicación en tiempo real (Websockets Reverb/Echo)
│   └── 📄 web.php             # Rutas predeterminadas (Stateful). Por lo general no utilizadas en esta arquitectura
├── 📁 storage/                # Almacén de archivos cacheados, logs de sistema y cargas temporales
├── 📁 tests/                  # (QA Team) Pruebas de integración, Pest y validación de APIs
├── 📄 .env                    # (SecOps) Archivo que almacena TODOS los secretos (APIs, Passwords DB). IGNORADO EN GIT
└── 📄 composer.json           # Administrador e instalador de paquetes PHP
```

---

## 🧠 AI Engine (Lyra)

The Lyra AI ecosystem resides primarily in `app/Services/`. It functions using an adaptive RAG pattern, intercepting user intent before passing fully formatted metadata payloads to the LLM (Google Gemini / OpenAI), subsequently enriching responses directly with fresh DB queries avoiding LLM-hallucinations on property inventory.

---

## 📜 License

**Proprietary Software — All rights reserved.**

The **NexiLiving Backend Platform** (including the Core API, Server Architecture, Database Schema, and the entire Lyra AI Engine) is the exclusive intellectual property of its co-owners:  
**Miguel Camilo** (`@miguelcamilok`), **Alexander Byrush** (`@Alexanderbyrush`) y **Jefer Mar** (`@jefermar`).

Unauthorized copying, modification, distribution, or use of this project, via any medium, is strictly prohibited without explicit permission.
