# Framework Upgrade Roadmap & Instructions

This document outlines the step-by-step sequence to upgrade the PHP framework to a modern, modular, AI-ready architecture with a Vue 3 TypeScript dashboard and CLI tooling.

## Phase 1: Foundation & Folder Restructuring (Goals 1 & 8)
*Objective: Completely isolate user application code from framework system code.*

- [ ] **1. Create the new root directories:**
  - Create `src/uploads/` (move any existing uploads here).
  - Ensure `src/api/middleware/` exists for user-specific middleware.
- [ ] **2. Shift system files to Core (`src/api/core/`):**
  - Move system controllers (e.g., `DocsController.php`, `MonitoringController.php`, `InitController.php`) into `src/api/core/Controllers/`.
  - Move system middleware (e.g., `AdminMiddleware`, `MonitoringMiddleware`, `LogMiddleware`) into `src/api/core/Middlewares/`.
  - Leave only application-specific controllers, services, and repositories in their respective `api/` folders.
- [ ] **3. Implement Namespaces:**
  - Assign `namespace Framework\Core\...` to all files inside the `core` directory.
  - Assign `namespace App\Controllers\...`, `App\Services\...`, etc., to user files.
- [ ] **4. Update `composer.json`:**
  - Setup PSR-4 autoloading for `"Framework\\Core\\": "src/api/core/"` and `"App\\": "src/api/"`.
  - Run `composer dump-autoload`.

## Phase 2: Configuration & Data Layer (Goals 7 & 4)
*Objective: Make the framework adaptive without requiring Docker container restarts.*

- [ ] **1. Dynamic System Settings (Goal 7):**
  - Create a new core class: `Framework\Core\ConfigManager`.
  - Create a `src/api/core/config/settings.json` file.
  - Move `SHOW_ERRORS`, `DEBUG_MODE`, and `LOG_ERRORS` out of `.env` and into this JSON/DB.
  - Update `ExceptionHandler.php` to read from `ConfigManager` dynamically on every request.
- [ ] **2. Independent Auto-fill Feature (Goal 4):**
  - Extract the dummy-data generation logic into a new class: `Framework\Core\Services\DataFakerService`.
  - Design it to accept parameters (table name, column types, row count) so it can be called programmatically via UI, AI, or CLI.

## Phase 3: Developer Tooling (Goal 5)
*Objective: Build a powerful CLI tool for rapid development.*

- [ ] **1. Create the CLI Entry Point:**
  - Create a file at the root of the project: `console` (or `console.php`).
- [ ] **2. Build the Command Router:**
  - Parse `$argv` inputs to route commands.
- [ ] **3. Implement Core Commands:**
  - `php console api:call [METHOD] [ENDPOINT]` (Direct route testing).
  - `php console db:fill [TABLE] [COUNT]` (Triggers the `DataFakerService` from Phase 2).
  - `php console make:controller [NAME]` (Scaffolds a new user controller).

## Phase 4: Vue.js Dashboard Setup (Goals 2 & 9)
*Objective: Set up the modern UI workflow without polluting the PHP backend.*

- [ ] **1. Initialize Vue 3 + TypeScript:**
  - Create a folder `src/api/core/ui-src/` (Add this to `.gitignore` so raw Vue files aren't tracked if you prefer them in a separate repo).
  - Run `npm create vite@latest . -- --template vue-ts` inside that folder.
- [ ] **2. Configure `package.json` & Vite:**
  - Update `package.json` with necessary UI libraries (Tailwind, Axios, Vue Router).
  - Update `vite.config.ts` to set `base: '/_dashboard/'`.
  - Configure Vite's `build.outDir` to compile directly into `src/api/core/Dashboard/dist/`.
- [ ] **3. Setup PHP System Route:**
  - In `SystemRoutes.php`, add a catch-all route for `GET /_dashboard(.*)` that reads and serves the files from `src/api/core/Dashboard/dist/`.

## Phase 5: Dashboard Features (Goals 3 & 6)
*Objective: Rebuild the built-in testers and settings managers in the new Vue SPA.*

- [ ] **1. Advanced API Tester (Goal 3):**
  - Build a Vue component that reads your registered framework routes.
  - Add dynamic form generation supporting `multipart/form-data` (File uploads).
  - Add UI controls to dynamically add/remove Array items or construct JSON payloads.
- [ ] **2. Environment Settings Manager (Goal 6):**
  - Create a Vue Settings page.
  - Create a highly secure PHP Core Controller (`EnvManagerController`) that reads/writes the `.env` file.
  - Add a "Reset to Default" feature that copies from `.env.example` to `.env`.
  - *Security Check:* Ensure this API route rejects requests if `APP_ENV=production`.