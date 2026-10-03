# ClassPulse — Blueprint

> Generado por The Architect el 2026-09-30
> Shape: internal-tool (recortado: una sola docente, sin roles ni multiusuario; el audit log se sustituye por el registro de eventos de participación) · `knowledge/shapes/internal-tool.md`
> Runtime track: rails-laravel, Sub-track B (Laravel), con las desviaciones documentadas en §2 y §20.3 · `knowledge/runtime-tracks/rails-laravel.md`
> Emission mode: bundle (25 pasos → bundle)
> Blueprint version: 2 (revisión tras la validación: pasos partidos al tope de 5 archivos, gates con recuento positivo)
> Nota de revisión (2026-10-01): el límite de estudiantes activos por clase pasó de 30 a 35. La única definición es `config/classpulse.php` (`max_roster`); la migración `2026_10_01_090000_raise_roster_cap_default_to_35` sube a 35 solo los cupos guardados que valían exactamente 30 y recrea el CHECK. Los pasos, pruebas y mensajes de más abajo describen la construcción original (30) y se dejan como registro histórico; el mensaje vigente es `This class is full: N of 35 active students. Archive a student to free a seat.`
> Versions last verified: 2026-09-30 — ver §11 para la procedencia de cada versión

**Cómo se usa este bundle.** `blueprint.md` explica el porqué. `tasks.json` es el grafo de construcción (protocolo de
reanudación). `epics/01-foundation-access.md`, `epics/02-roster-daily-tracking.md` y
`epics/03-reports-import-release.md` son las unidades de ejecución autocontenidas. `workspace/` se copia **una sola
vez y sin sobrescribir** a la raíz del proyecto (`<project-root>`) con el bloque Bootstrap de
§10: contiene `CLAUDE.md`, `AGENTS.md`, `.claude/` y todos los archivos de configuración que los comandos `Verify`
necesitan (§19.6). El bundle vive dentro del proyecto en `blueprints/classpulse/` y todos los comandos se ejecutan
desde la raíz del proyecto.

---

## 1. Project Overview & Non-Goals

### Vision

ClassPulse es la "tabla de participación digital" privada de **docentes** de Ontario (Canadá, zona horaria
America/Toronto). Sustituye la hoja de papel en la que anota, clase por clase y día por día, cuántas veces participa
cada estudiante: en el aula toca tarjetas grandes (`[−]  número  [+]`, botón `Absent`) y, fuera del aula, consulta la
matriz semanal con mapa de calor, la analítica acumulada del semestre y exporta CSV para su libro de notas. La
aplicación es una web Laravel 13 servida por PHP y MariaDB, pensada para el hosting compartido Hostinger que la dueña ya
tiene; en v1 se construye y verifica **solo en local (Docker)** y la publicación espera la aprobación explícita de la
dueña.

Lo que la distingue de un tracker genérico: cada toque es una operación idempotente con `op_id` que nunca pierde un
punto aunque la docente pulse 50 veces seguidas o la red falle; un cero registrado cuenta como día presente (no como
"sin datos"); las ausencias y los días sin registro jamás entran en un promedio; y todo se puede deshacer por día.

### Users

| Persona | What they come to do | Frequency |
|---|---|---|
| La docente (única cuenta) | Registrar participación durante la clase con toques rápidos; marcar ausencias; deshacer errores | Diaria, varias clases por día |
| La docente, fuera del aula | Revisar la matriz semanal, la analítica del semestre, el historial por estudiante; exportar CSV; importar la lista | Semanal |
| La dueña del sitio (misma persona, rol administrativo) | Crear la cuenta, recuperar la contraseña, hacer copias de seguridad, publicar en Hostinger | Una vez / rara vez |

### Goals — v1 scope

1. Registro diario por clase con tarjetas táctiles: +1, −1, "Record 0", ausencia/presencia, "Mark remaining as 0",
   "Reset day" y deshacer, sin perder puntos ante clics rápidos o fallos de red.
2. Vistas Weekly Matrix (mapa de calor 0 | 1-2 | 3-5 | 6+ | A) y Semester Analytics (Master Cumulative Roster y
   detalle por estudiante) calculadas en cada petición a partir de enteros exactos.
3. Gestión de clases (sin límite de cantidad) y listas de hasta 35 estudiantes activos por clase (30 en la construcción original; ver la nota de revisión), con archivado,
   importación CSV/pegado con vista previa, y exportación CSV segura contra inyección de fórmulas.
4. Una única cuenta protegida por sesión, límite de intentos de acceso, CSP estricta sin inline y recuperación por
   CLI o SQL.
5. Un paquete de publicación verificado localmente para Hostinger (Layout B) y documentación en español para la dueña.

### Non-Goals — explicitly out of scope for v1

| Not building | Why not now | Revisit when |
|---|---|---|
| Registro público, multiusuario, roles | Hay una sola docente; cada usuario extra exige aislamiento por usuario, invitaciones y roles | Una segunda docente necesita su propia cuenta |
| Conexión con Google Classroom / Drive / Sheets | OAuth, tokens y cuotas para un flujo que CSV/pegado ya resuelve | La docente importa más de 5 listas por semestre a mano |
| Cualquier IA (servicios, claves, dependencias, interfaces vacías) | No aporta al registro y añade coste, privacidad y superficie de fallo | Nunca en v1; una petición explícita de la dueña |
| Modo offline, PWA, service worker | v1 requiere internet; la cola en memoria cubre cortes breves | Se pierden datos reales por cortes largos en el aula |
| Persistir cambios pendientes tras recargar la página | Solo reintento en memoria + aviso `beforeunload`; guardar en el navegador exige resolver conflictos | Un corte provoca pérdida confirmada tras recarga |
| Email y restablecimiento de contraseña por email | Sin servidor de correo configurado; la recuperación es por CLI o SQL (§8) | La dueña configura correo transaccional |
| Libro de notas, "Day Slip", hoja imprimible, analítica de tendencias/"Optimal cadence"/"Teacher Engine", filtros Q1/Q2, botones de compartir, insignia PRO | Elementos del moodboard sin requisito real; duplican la exportación CSV | La docente pide un formato concreto que el CSV no cubre |
| Importación masiva de historial | Solo se importan listas de estudiantes; el historial previo se queda en papel | Una nueva institución exige migrar datos previos |
| Sincronización en tiempo real entre dispositivos | v1 asume una pestaña activa; recargar muestra los cambios de otra pestaña | La docente usa dos dispositivos a la vez en clase |
| Fuentes externas, CDN, Node/npm/Vite o cualquier bundler | CSP estricta, privacidad y cero paso de compilación en hosting compartido | Nunca en v1 |
| Livewire, Filament, Inertia, Sanctum, Fortify, Pest, Horizon, Telescope, Cashier, Reverb | Añaden dependencias o procesos que un hosting compartido sin worker no sostiene | Migración a VPS con procesos persistentes |
| Colas y workers | Hostinger compartido no tiene worker; todo es por petición (`QUEUE_CONNECTION=sync`) | Una tarea tarda más de 10 s por petición |
| Publicación automática en Hostinger | Puerta de aprobación de la dueña: el build se detiene en la versión local | La dueña aprueba la versión local (lista de lanzamiento, §20.1) |

**The builder must not implement anything in this table**, even if it seems like a small addition while working on an
adjacent step. If a step appears to require a non-goal, that is a blueprint defect — stop and report it rather than
expanding scope.

### Success metrics

Medibles solo con pruebas automáticas (no hay usuarios reales ni analítica en v1).

| Metric | Target | How measured |
|---|---|---|
| Casos de cálculo correctos | 100 % de los casos de `tests/Unit/ParticipationStatsTest.php` y `tests/Unit/SchoolCalendarTest.php` pasan, en el paso 13 y en cada gate posterior | `docker compose exec -T app vendor/bin/phpunit tests/Unit` sale con 0 |
| Puntos perdidos bajo clics rápidos | 0 puntos perdidos en 50 incrementos con `op_id` distintos | `tests/Feature/ParticipationIdempotencyTest.php` (paso 14) |
| Doble conteo por reintento | 0 cambios al repetir un `op_id` | mismo archivo de prueba |
| Exportación semanal exacta | cuerpo CSV idéntico byte a byte al fixture | `tests/Feature/WeeklyReportTest.php` (paso 19) |
| Paquete de publicación válido | `/up` y `/login` responden 200 desde el paquete descomprimido | `./scripts/verify-release.sh` (paso 24) |

---

## 2. Tech Stack

**Runtime track: rails-laravel, Sub-track B (Laravel).** This table names *choices*, not versions. Every version pin
lives in Section 11 and nowhere else.

Las versiones vienen del informe de `stack-researcher` de esta sesión (verificado el 2026-09-30); el archivo del
track es solo respaldo y aquí no se usó para ninguna versión.

| Layer | Choice | Why this, over what |
|---|---|---|
| Language / runtime | PHP 8.3 dentro de Docker (`php:8.3-cli-bookworm`), `config.platform.php` fijado a `8.3.0` en Composer | Hostinger ofrece PHP 8.2-8.5 con 8.3 por defecto y Laravel 13 exige ≥ 8.3: 8.3 es el suelo común. Se rechaza PHP 8.5 (el del track) porque un `vendor/` construido para 8.5 podría no correr en el plan real |
| Framework | Laravel 13 (esqueleto `laravel/laravel`) | Sesiones, CSRF, validación, migraciones y Eloquent incluidos; corre en PHP compartido sin procesos persistentes. Se rechaza Node/TS (ts-node): Hostinger compartido no ejecuta Node |
| Styling | CSS escrito a mano con custom properties en `public/css/tokens.css` y `public/css/app.css` | Cero paso de compilación y CSP sin inline. Se rechaza Tailwind/Vite: exige Node, prohibido |
| Component layer | Componentes Blade (`<x-layouts.app>`) + JS clásico en `public/js` | Renderizado en servidor; el único estado cliente es la cola de guardado. Se rechaza Livewire (peticiones por tecla y otra dependencia) |
| Database | MariaDB (local `mariadb:10.11`; Hostinger usa MariaDB sin versión publicada) | Es lo que ofrece Hostinger; 10.11 LTS es un suelo conservador con CHECK constraints aplicadas. Se prohíbe sintaxis solo de MariaDB 11+ |
| ORM / data access | Eloquent + query builder con bindings | Nativo de Laravel. Se rechaza una capa de repositorios: no aporta nada con 5 tablas |
| Auth | Sesión de Laravel con login escrito a mano (`Auth::attempt`), sin starter kit | Una sola cuenta; los starter kits traen registro, reset por email y Node. El throttling se añade con `RateLimiter::for('login')` porque sin starter kit no es automático |
| Background work | Ninguno: `QUEUE_CONNECTION=sync`, `CACHE_STORE=file` | Hostinger compartido no tiene worker. Se borran las migraciones de cache y jobs del esqueleto |
| Payments | NOT APPLICABLE — la aplicación no maneja dinero | — |
| File storage | NOT APPLICABLE — los CSV se generan en la petición y la importación se analiza en memoria (vista previa en sesión) | — |
| Email / notifications | Ninguno (`MAIL_MAILER=log`) | Sin email: recuperación por CLI/SQL (§8) |
| Hosting | Local: Docker Compose (proyecto `classpulse`). Destino: Hostinger compartido, Layout B, publicado solo por la dueña | Docker porque en el host no hay PHP/Composer/MariaDB/Node y Docker Desktop ya existe. Se rechaza Homebrew (instalaría runtimes globales) |
| Package manager | Composer (dentro de la imagen) | El del ecosistema; no hay npm |
| Test runner | PHPUnit 12 (predeterminado del esqueleto) + `node --test` en el contenedor desechable `node:24-alpine` | Se rechaza Pest: la última versión exige PHP 8.4; Pest 4 funcionaría pero añade una dependencia sin beneficio |
| Formatter | Pint, preset `laravel` | El formateador que trae el esqueleto |

### Compatibility check

Checked against `knowledge/stack-compatibility.md` — ninguna de sus filas aplica (no hay ORM de TypeScript, ni edge
runtime, ni Prisma/Drizzle). Tensión documentada: el track rails-laravel supone una VM de larga vida con worker de
colas y `config:cache` en cada despliegue. ClassPulse usa a propósito solo funciones por petición (cola `sync`, caché
en archivo, sin tareas programadas obligatorias, sin `config:cache` cuando no hay SSH), por lo que el hosting
compartido de Hostinger es un destino legal.

---

## 3. Directory Structure

Origen de cada archivo: **[W]** emitido en `workspace/` y copiado por Bootstrap; **[B]** creado por un comando de
Bootstrap (§10); **[S]** viene del esqueleto `laravel/laravel` 13.10.1 (Bootstrap); **[N]** lo escribe el paso N de §9.

```
ClassPulse/                                   # raíz del proyecto = <project-root>
  .claude/
    settings.json                             # [W] permisos (§19.3)
    rules/database.md frontend.md security.md calculations.md   # [W] reglas con paths: (§19.5)
    skills/add-migration/SKILL.md             # [W] (§19.4)
    skills/add-participation-operation/SKILL.md   # [W]
    skills/release-package/SKILL.md           # [W]
  .dockerignore                               # [W] contexto de build = solo Dockerfile
  .editorconfig .gitattributes                # [S]
  .env                                        # [B] copia de .env.example + APP_KEY; ignorado por git
  .env.example                                # [W] sustituye al del esqueleto
  .gitignore                                  # [W] sustituye al del esqueleto
  AGENTS.md CLAUDE.md                         # [W] sustituyen a los del esqueleto
  Dockerfile docker-compose.yml               # [W] servicios db, app, jstest
  phpunit.xml pint.json                       # [W] sustituyen/añaden configuración de pruebas y formato
  README.md                                   # [S], reescrito en español por [25]
  artisan composer.json composer.lock         # [S]; composer.json editado por [1]; lock por Bootstrap
  app/
    Console/Commands/CreateTeacherCommand.php ResetPasswordCommand.php HashPasswordCommand.php   # [5]
    Console/Commands/DemoSeedCommand.php      # [15] datos demo solo en local, vía ParticipationService
    Exceptions/ParticipationException.php     # [14] código + estado HTTP + mensaje
    Http/Controllers/Controller.php           # [S]
    Http/Controllers/Auth/LoginController.php # [7]
    Http/Controllers/ClassController.php      # [10] crear/editar; [11] página y borrado
    Http/Controllers/StudentController.php    # [12]
    Http/Controllers/Api/DayController.php    # [16] API JSON del día
    Http/Controllers/DailyController.php      # [17]
    Http/Controllers/WeeklyController.php     # [19]
    Http/Controllers/SemesterController.php   # [20]
    Http/Controllers/StudentHistoryController.php   # [21]
    Http/Controllers/ImportController.php     # [23]
    Http/Middleware/SecurityHeaders.php       # [6] CSP y cabeceras globales
    Http/Requests/StoreClassRequest.php UpdateClassRequest.php   # [10]
    Http/Requests/DestroyClassRequest.php     # [11]
    Http/Requests/StudentRequest.php          # [12] alta y renombrado (reglas idénticas)
    Http/Requests/DayOperationRequest.php     # [16]
    Models/User.php                           # [S]
    Models/SchoolClass.php Student.php ParticipationEntry.php ParticipationOperation.php ParticipationEvent.php   # [3]
    Providers/AppServiceProvider.php          # [S]; limitador login [7]; singleton SchoolCalendar [13]
    Services/ParticipationService.php         # [14], ampliado en [15]: único escritor de participación
    Services/RosterImporter.php               # [22]
    Support/CurrentClass.php                  # [9] clase actual en sesión
    Support/SchoolCalendar.php ParticipationStats.php   # [13] puros
    Support/CsvWriter.php                     # [19]
  bootstrap/app.php                           # [S]; middleware [6]; errores JSON [16]
  config/app.php                              # [S]; zona horaria [1]
  config/classpulse.php                       # [1]
  database/
    factories/UserFactory.php                 # [S]
    factories/SchoolClassFactory.php StudentFactory.php ParticipationEntryFactory.php   # [4]
    migrations/0001_01_01_000000_create_users_table.php   # [S] users, password_reset_tokens, sessions
    migrations/<timestamp>_create_classpulse_schema.php   # [2] nombre elegido por make:migration
    seeders/DatabaseSeeder.php                # [S], vaciado en [2]
  docs/local-development.md hostinger-deploy.md access-and-recovery.md backups.md   # [25] en español
  public/
    index.php .htaccess favicon.ico robots.txt   # [S]
    css/tokens.css app.css                    # [8]
    js/theme-init.js theme.js                 # [8]
    js/save-queue.js daily.js dialogs.js      # [18]
  resources/views/
    auth/login.blade.php                      # [7]
    components/layouts/app.blade.php          # [9]
    roster/index.blade.php                    # [11], ampliada en [12]
    daily/index.blade.php card.blade.php      # [17]; scripts añadidos en [18]
    reports/weekly.blade.php                  # [19]
    reports/semester.blade.php                # [20]
    reports/student.blade.php                 # [21]
    import/index.blade.php preview.blade.php  # [23]
  routes/web.php                              # [S], vaciado en [1]; rutas en [7] [10] [11] [12] [16] [17] [19] [20] [21] [23]
  routes/console.php                          # [S]
  scripts/package-release.sh release-exclude.txt verify-release.sh   # [24]
  storage/ bootstrap/cache/                   # [S]
  tests/
    TestCase.php                              # [S]
    Feature/EnvironmentGuardTest.php          # [1]
    Feature/SchemaConstraintsTest.php         # [2]
    Feature/ModelsTest.php                    # [4]
    Feature/TeacherCommandsTest.php           # [5]
    Feature/SecurityHeadersTest.php           # [6]
    Feature/AuthTest.php                      # [7]
    Feature/LayoutShellTest.php               # [9] también escanea todas las vistas
    Feature/ClassManagementTest.php           # [10]
    Feature/RosterPageTest.php                # [11]
    Feature/StudentRosterTest.php             # [12]
    Unit/SchoolCalendarTest.php ParticipationStatsTest.php   # [13]
    Feature/ParticipationServiceTest.php ParticipationIdempotencyTest.php   # [14]
    Feature/ParticipationBatchUndoTest.php DemoSeedTest.php   # [15]
    Feature/DayApiTest.php                    # [16]
    Feature/DailyPageTest.php                 # [17]
    js/save-queue.test.js                     # [18] node --test
    Feature/WeeklyReportTest.php              # [19]
    Fixtures/weekly-export.csv                # [W] salida esperada byte a byte (§19.6)
    Feature/SemesterReportTest.php            # [20]
    Feature/StudentHistoryTest.php            # [21]
    Feature/RosterImporterTest.php            # [22]
    Feature/ImportTest.php                    # [23]
  vendor/                                     # [B] composer; ignorado por git
  dist/                                       # generado por [24]; ignorado por git; nunca se edita a mano
    classpulse-app.zip classpulse-public_html.zip install.sql
  blueprints/classpulse/                      # este bundle; versionado en git; excluido por pint y el paquete
```

**Boundary rules**
- Solo `app/Services/ParticipationService.php` escribe en `participation_entries`, `participation_operations` y
  `participation_events` (también el seed de demo). Los controladores leen modelos pero no escriben participación.
- `app/Support/SchoolCalendar.php` es el único lugar que calcula "hoy" y los días lectivos (America/Toronto, lunes a
  viernes). `app/Support/ParticipationStats.php` es el único lugar con fórmulas de totales y promedios, y recibe
  arrays simples (sin Eloquent).
- Toda consulta sobre `students` y `participation_entries` filtra por `school_class_id`.
- Las vistas usan solo `{{ }}`; ningún `{!! !!}`, `<script>` sin `src`, `<style>` ni `style=""`.
- `public/js/*.js` son scripts clásicos sin `import`/`export`. La convención de resolución (PSR-4 para PHP, `require()`
  de un archivo UMD para las pruebas JS) está reconciliada contra cada cargador en la *Resolution convention matrix*
  de §19.6.
- Los valores que aparecen en más de un artefacto (puertos, nombres de base de datos, rutas de `dist/`, nombre de la
  carpeta `classpulse-app`, pin del esqueleto) se toman de la tabla *Cross-artifact value reconciliation* de §19.6.

---

## 4. Data Model

Todas las tablas: InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`, sintaxis compatible con MariaDB 10.3-10.11 (nunca
funciones exclusivas de MariaDB 11+). Las restricciones CHECK están permitidas y MariaDB ≥ 10.2 las aplica.

**Regla de fechas.** Las fechas de negocio son columnas `DATE` con la fecha de calendario de America/Toronto,
calculadas solo por `App\Support\SchoolCalendar` (`today()`, `isWeekday()`, `isFuture()`, `previousWeekday()`,
`nextWeekday()`, `weekStart()`, `weekDays()`, `defaultDate()`), con `APP_TIMEZONE=America/Toronto`. Sábados y domingos
se rechazan en toda escritura; las fechas posteriores a hoy (Toronto) no son editables y "Next school day" se
deshabilita en hoy; si hoy es sábado o domingo la fecha por defecto es el viernes anterior; un `?date=` de fin de
semana en la página diaria redirige al viernes anterior.

### Entities

**`users`** (del esqueleto) — la cuenta de la docente. En uso real hay exactamente una fila. `password_reset_tokens`
existe por la migración del esqueleto y queda sin uso.

| Field | Type | Constraints | Notes |
|---|---|---|---|
| id | bigint unsigned | PK AI | |
| name | varchar(255) | not null | nombre visible |
| email | varchar(255) | unique, not null | usuario de acceso |
| password | varchar(255) | not null | hash bcrypt (12 rondas; 4 en pruebas) |
| remember_token, email_verified_at, timestamps | — | del esqueleto | sin uso funcional |

**`sessions`** (del esqueleto) — sesiones con `SESSION_DRIVER=database`; borrar filas invalida sesiones
(`classpulse:reset-password` las borra todas).

**`school_classes`** — una clase/sección (código de curso). Se crea y edita en Roster & Settings; borrarla es
permanente y arrastra todo lo que cuelga de ella.

| Field | Type | Constraints | Notes |
|---|---|---|---|
| id | bigint unsigned | PK AI | valor de `{class}` en rutas |
| name | varchar(60) | not null, UNIQUE | código oficial, p. ej. `HNL 2O` |
| subject_description | varchar(120) | null | descripción de la materia |
| period_label | varchar(40) | null | periodo o bloque |
| roster_cap | tinyint unsigned | not null, default 30, CHECK 1-30 | máximo de estudiantes **activos** |
| semester_start, semester_end | date | null; CHECK fin ≥ inicio | periodo de Semester Analytics |
| created_at, updated_at | timestamp | null | |

**`students`** — un estudiante de una clase. El `id` es estable: renombrar nunca cambia el `id`. Archivar conserva
todas sus entradas.

| Field | Type | Constraints | Notes |
|---|---|---|---|
| id | bigint unsigned | PK AI | |
| school_class_id | bigint unsigned | FK → school_classes(id) ON DELETE CASCADE | |
| display_name | varchar(120) | not null | nombre visible (inventado en dev/pruebas) |
| student_number | varchar(40) | null | número escolar opcional; en los CSV, NULL se escribe `""` (§5) |
| archived_at | timestamp | null | no null = archivado |
| created_at, updated_at | timestamp | null | |

**`participation_entries`** — el estado de un estudiante en un día. **Sin fila = "Not recorded"**; `present` + puntos
N (N ≥ 0, el cero cuenta) = "Present con N"; `absent` = "Absent". Nunca existe una fila `present` con `points` NULL.

| Field | Type | Constraints | Notes |
|---|---|---|---|
| id | bigint unsigned | PK AI | |
| school_class_id | bigint unsigned | FK → school_classes(id) CASCADE | |
| student_id | bigint unsigned | FK compuesta (student_id, school_class_id) → students(id, school_class_id) CASCADE | impide emparejar un estudiante con otra clase |
| work_date | date | not null | fecha de Toronto |
| status | enum('present','absent') | not null default 'present' | |
| points | smallint unsigned | null; CHECK ≤ 99; CHECK `status = 'absent' OR points IS NOT NULL` | NULL mientras está ausente |
| restore_points | smallint unsigned | null; CHECK ≤ 99 | puntos que tenía antes de la ausencia; NULL = el día no estaba registrado |
| revision | int unsigned | not null default 1 | +1 en cada cambio de la fila |
| created_at, updated_at | timestamp | null | |

**`participation_operations`** — una operación aplicada (clave de idempotencia y pila de deshacer).

| Field | Type | Constraints | Notes |
|---|---|---|---|
| seq | bigint unsigned | PK AI | monótono; el máximo por clase/fecha es el `day_version` |
| op_id | char(36) | not null UNIQUE | UUID v4 del cliente = clave de idempotencia |
| school_class_id | bigint unsigned | FK CASCADE | |
| work_date | date | not null | |
| kind | varchar(20) | not null | `increment`, `decrement`, `set_zero`, `absent_on`, `absent_off`, `zero_remaining`, `reset_day`, `undo` |
| undone_at | timestamp | null | no null = ya deshecha |
| created_at | timestamp | null | |

**`participation_events`** — foto "antes" de cada estudiante afectado por una operación; solo se agrega (salvo
cascada) y solo lo usa `undo`. Sustituye al `audit_log` del shape internal-tool.

| Field | Type | Constraints | Notes |
|---|---|---|---|
| id | bigint unsigned | PK AI | |
| operation_seq | bigint unsigned | FK → participation_operations(seq) CASCADE | |
| school_class_id, student_id | bigint unsigned | not null | |
| before_exists | tinyint(1) | not null | 0 = no había fila |
| before_status | enum('present','absent') | null | |
| before_points, before_restore_points | smallint unsigned | null | |
| created_at | timestamp | null | |

### Relationships

- `school_classes` —(1:N)→ `students`: ON DELETE CASCADE.
- `school_classes` —(1:N)→ `participation_entries`: ON DELETE CASCADE.
- `students` —(1:N)→ `participation_entries` por la FK compuesta `(student_id, school_class_id)`: ON DELETE CASCADE.
  Los estudiantes no se borran nunca desde la app (solo se archivan); solo desaparecen con su clase.
- `school_classes` —(1:N)→ `participation_operations`: ON DELETE CASCADE.
- `participation_operations` —(1:N)→ `participation_events`: ON DELETE CASCADE.

Borrar una clase elimina en cascada estudiantes, entradas, operaciones y eventos: es permanente, por eso la interfaz
exige escribir el nombre de la clase y muestra los recuentos. Los estudiantes archivados conservan todas sus entradas,
no aparecen en Daily, no reciben operaciones (`422 student_archived`) y aparecen en Weekly, Semester, historial y CSV
con el sufijo ` (archived)` cuando tienen entradas en el periodo.

**Aislamiento entre clases.** Toda consulta de estudiantes o entradas se filtra por `school_class_id`; los
controladores resuelven `{class}` y rechazan un `{student}`/`student_id` de otra clase con 404; la FK compuesta es el
respaldo en base de datos. Las pruebas de los pasos 2 y 14 demuestran ambos niveles.

### Indexes

| Table | Index | Why |
|---|---|---|
| school_classes | UNIQUE (name) | nombres de clase únicos |
| students | UNIQUE students_id_class_unique (id, school_class_id) | destino de la FK compuesta |
| students | (school_class_id, archived_at) | lista de activos por clase |
| participation_entries | UNIQUE (school_class_id, student_id, work_date) | identidad pedida por la dueña: una fila por estudiante y día |
| participation_entries | (school_class_id, work_date) | estado del día y semana |
| participation_entries | (student_id, school_class_id) | índice de la FK compuesta; historial por estudiante |
| participation_operations | UNIQUE (op_id) | idempotencia |
| participation_operations | (school_class_id, work_date, seq) | `day_version` y pila de deshacer |

### Schema

Referencia SQL equivalente a lo que crea la migración del paso 2 (la migración usa el schema builder de Laravel más
`DB::statement` para los CHECK; el código exacto está en el bloque E1-T2 de `epics/01-foundation-access.md`):

```sql
CREATE TABLE school_classes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  subject_description VARCHAR(120) NULL,
  period_label VARCHAR(40) NULL,
  roster_cap TINYINT UNSIGNED NOT NULL DEFAULT 30,
  semester_start DATE NULL,
  semester_end DATE NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY school_classes_name_unique (name),
  CONSTRAINT school_classes_roster_cap_check CHECK (roster_cap BETWEEN 1 AND 30),
  CONSTRAINT school_classes_semester_check CHECK (semester_end IS NULL OR semester_start IS NULL OR semester_end >= semester_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE students (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_class_id BIGINT UNSIGNED NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  student_number VARCHAR(40) NULL,
  archived_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY students_id_class_unique (id, school_class_id),
  KEY students_class_archived_index (school_class_id, archived_at),
  CONSTRAINT students_school_class_id_foreign FOREIGN KEY (school_class_id) REFERENCES school_classes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participation_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  school_class_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  status ENUM('present','absent') NOT NULL DEFAULT 'present',
  points SMALLINT UNSIGNED NULL,
  restore_points SMALLINT UNSIGNED NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY participation_entries_class_student_date_unique (school_class_id, student_id, work_date),
  KEY participation_entries_class_date_index (school_class_id, work_date),
  KEY participation_entries_student_class_index (student_id, school_class_id),
  CONSTRAINT participation_entries_school_class_id_foreign FOREIGN KEY (school_class_id) REFERENCES school_classes (id) ON DELETE CASCADE,
  CONSTRAINT participation_entries_student_class_foreign FOREIGN KEY (student_id, school_class_id) REFERENCES students (id, school_class_id) ON DELETE CASCADE,
  CONSTRAINT participation_entries_points_check CHECK (points IS NULL OR points <= 99),
  CONSTRAINT participation_entries_restore_points_check CHECK (restore_points IS NULL OR restore_points <= 99),
  CONSTRAINT participation_entries_status_points_check CHECK (status = 'absent' OR points IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participation_operations (
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  op_id CHAR(36) NOT NULL,
  school_class_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  kind VARCHAR(20) NOT NULL,
  undone_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  UNIQUE KEY participation_operations_op_id_unique (op_id),
  KEY participation_operations_class_date_seq_index (school_class_id, work_date, seq),
  CONSTRAINT participation_operations_school_class_id_foreign FOREIGN KEY (school_class_id) REFERENCES school_classes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participation_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  operation_seq BIGINT UNSIGNED NOT NULL,
  school_class_id BIGINT UNSIGNED NOT NULL,
  student_id BIGINT UNSIGNED NOT NULL,
  before_exists TINYINT(1) NOT NULL,
  before_status ENUM('present','absent') NULL,
  before_points SMALLINT UNSIGNED NULL,
  before_restore_points SMALLINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  CONSTRAINT participation_events_operation_seq_foreign FOREIGN KEY (operation_seq) REFERENCES participation_operations (seq) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Calculation contract

Clase pura `App\Support\ParticipationStats`, cuyas entradas son arrays simples
`['student_id' => int, 'work_date' => 'Y-m-d', 'status' => 'present'|'absent', 'points' => ?int]`. Por estudiante y
periodo:

| Magnitud | Fórmula |
|---|---|
| total_points | SUM(points) de las filas `present` |
| present_days_recorded | COUNT(filas `present` con points NOT NULL) — un cero registrado cuenta |
| absences | COUNT(filas `absent`) |
| days_recorded | present_days_recorded + absences |
| participation_days | COUNT(filas `present` con points > 0) |
| average_per_present_day | total_points / present_days_recorded, o el texto `No data` si present_days_recorded = 0 (nunca 0) |
| semanal | total, promedio y ausencias por semana lunes-viernes, recortada a los límites del periodo |
| semestre | total_points / present_days_recorded sobre **todo** el periodo — nunca la media de promedios semanales (hay una prueba donde difieren: 14/5 = 2.8 frente a 5.5) |

Los días sin registro y los días de ausencia nunca entran en un promedio. Nada se cachea ni se guarda derivado: editar
un día antiguo cambia los resultados en la siguiente petición. Se guardan enteros exactos y se redondea **solo al
mostrar**: UI y CSV usan `number_format($v, 2, '.', '')`; el CSV incluye también total_points y
present_days_recorded para poder recomputar el valor exacto.

**Orden de lista** (todas las vistas y exportaciones): `strcmp(mb_strtolower(display_name))` y, a igualdad, `id`.

### Migrations

Herramienta: migraciones de Laravel. Se crean con `docker compose exec -T app php artisan make:migration create_classpulse_schema`
(o el nombre descriptivo del cambio); el prefijo de fecha lo elige la herramienta y nunca se escribe a mano. Se aplican
con `docker compose exec -T app php artisan migrate --force`. Reglas: nunca se edita una migración que ya corrió en
cualquier base (dev `classpulse`, pruebas `classpulse_test`, paquete `classpulse_release`); cada cambio es una migración
nueva; `migrate:fresh` solo lo ejecuta `RefreshDatabase` en las pruebas. En producción (Hostinger) el esquema se instala
importando `dist/install.sql` (estructura + filas de `migrations`), de modo que un `php artisan migrate` posterior con SSH
vea todo como `Ran`; un cambio futuro se publica como migración nueva (sin SSH: se regenera y se entrega el SQL de esa
migración, nunca se borra una tabla con datos). Las tablas `cache`/`jobs` que la migración del esqueleto crea durante
Bootstrap en la base dev quedan como restos inofensivos: el paso 1 borra esas migraciones y no se vuelve a crear nada.

### Seed data

No existen credenciales fijas en ningún sitio: `database/seeders/DatabaseSeeder.php` queda vacío (paso 2). Para datos de
demostración: `docker compose exec -T app php artisan classpulse:demo-seed` (paso 15), que se niega a correr salvo con
`APP_ENV=local` o si ya existe alguna clase, crea 3 clases **ficticias** (`HNL 2O`, `HNC 3C`, `HLS 3O`) con estudiantes
de nombres inventados (p. ej. `Alex Rivera`), unos 20 días lectivos de operaciones aleatorias aplicadas **a través de
`ParticipationService`**, y la cuenta `teacher@classpulse.test` con una contraseña aleatoria que se imprime una sola vez.
Las pruebas usan factories (paso 4), nunca el seed de demostración. Solo estudiantes ficticios en dev y pruebas.

---

## 5. API Design

### Conventions
- Base path: las páginas HTML en `/`; la API JSON en `/api/classes/{class}/days/{date}` dentro de `routes/web.php`
  (sesión + CSRF), no en un `routes/api.php` sin estado.
- Response envelope: éxito `{"data": <estado del día>}`; error, por ejemplo
  `{"error": {"code": "points_min", "message": "Points cannot go below 0.", "day": <estado del día>}}`. La clave `day`
  aparece solo en los errores de negocio de participación. Una sola forma en toda la API.
- Error codes (tabla completa abajo): `validation` 422, `weekend` 422, `future_date` 422, `points_max` 422, `points_min`
  422, `not_recorded` 422, `student_archived` 422, `nothing_to_do` 422, `student_absent` 409, `already_recorded` 409,
  `already_absent` 409, `not_absent` 409, `nothing_to_undo` 409, `not_found` 404, `unauthenticated` 401,
  `csrf_mismatch` 419. En páginas HTML: 401 → redirección a `/login`; 419 → página de sesión caducada de Laravel.
- Validation: FormRequests en `app/Http/Requests` (excepciones: los dos campos del login usan `$request->validate()`, y
  la importación valida con `Validator::make` dentro de `App\Services\RosterImporter`).
- Pagination: ninguna — como máximo 35 estudiantes activos por clase y 5 días por semana.
- Idempotency: `POST .../operations` exige `op_id` (UUID v4) guardado en `participation_operations.op_id UNIQUE`; si ya
  existe, no se aplica nada y se responde 200 con el estado actual y `replayed: true`.
- Rate limits: solo `POST /login` (`throttle:login`, 5 por minuto por email en minúsculas + IP, almacén = caché de
  archivos; en pruebas, array). Respuesta 429 con `Retry-After` y el texto
  `Too many sign-in attempts. Try again in N seconds.`

### Routes

| Method | Path | Description | Auth | Rate limit |
|---|---|---|---|---|
| GET | /up | salud de Laravel | público | — |
| GET | /login | formulario de acceso | invitado | — |
| POST | /login | iniciar sesión | invitado | 5/min por email+IP |
| POST | /logout | cerrar sesión | docente | — |
| GET | / | redirige a /daily | docente | — |
| GET | /daily?class=&date= | Daily Tracker | docente | — |
| GET | /weekly?class=&week= | Weekly Matrix (week = lunes) | docente | — |
| GET | /semester?class= | Semester Analytics | docente | — |
| GET | /students/{student} | historial del estudiante | docente | — |
| GET | /roster?class= | Class Roster & Settings | docente | — |
| POST | /classes | crear clase | docente | — |
| PUT | /classes/{class} | editar clase | docente | — |
| DELETE | /classes/{class} | borrar clase (exige `confirm_name`) | docente | — |
| POST | /classes/{class}/students | añadir estudiante | docente | — |
| PUT | /students/{student} | renombrar / número | docente | — |
| POST | /students/{student}/archive | archivar | docente | — |
| POST | /students/{student}/restore | restaurar | docente | — |
| GET | /classes/{class}/import | formulario de importación | docente | — |
| POST | /classes/{class}/import/preview | vista previa | docente | — |
| POST | /classes/{class}/import/commit | confirmar importación | docente | — |
| GET | /export/weekly.csv?class=&week= | CSV semanal | docente | — |
| GET | /export/semester.csv?class= | CSV semestral | docente | — |
| GET | /export/student/{student}.csv | CSV del estudiante | docente | — |
| GET | /api/classes/{class}/days/{date} | estado del día (JSON) | docente | — |
| POST | /api/classes/{class}/days/{date}/operations | aplicar operación (JSON) | docente | — |

No hay ruta de registro ni de restablecimiento de contraseña (las pruebas afirman 404). Ninguna ruta acepta un
identificador de "tenant" distinto de `{class}`.

### Critical endpoints — full detail

**`POST /api/classes/{class}/days/{date}/operations`** — `{date}` restringido a `[0-9]{4}-[0-9]{2}-[0-9]{2}`.

Request (`DayOperationRequest`):
```json
{"op_id": "3f2b8c1e-5d7a-4e2b-9c1d-0a1b2c3d4e5f", "kind": "increment", "student_id": 12}
```
- `op_id`: obligatorio, UUID.
- `kind`: obligatorio, uno de `increment`, `decrement`, `set_zero`, `absent_on`, `absent_off`, `zero_remaining`,
  `reset_day`, `undo`.
- `student_id`: obligatorio y entero para los cinco primeros tipos.

Response 200:
```json
{"data": {"day_version": 42, "entries": [{"student_id": 12, "status": "present", "points": 3, "revision": 4}], "summary": {"active_students": 3, "recorded": 2, "present_recorded": 1, "absent": 1, "not_recorded": 1, "total_points": 3}, "can_undo": true, "replayed": false}}
```
`entries` cubre a cada estudiante **activo** (≤ 30) en orden de lista; `status` es `none` cuando no hay fila;
`day_version` = máximo `seq` de las operaciones de esa clase y fecha, o 0; `recorded` = `present_recorded` + `absent`;
`not_recorded` = `active_students` − `recorded`. El cliente siempre pinta desde la última respuesta e ignora una
respuesta con `day_version` menor que el mayor ya aplicado.

Transiciones (NR = sin fila, P(n) = presente con n, A(r) = ausente recordando r):

| Kind | NR | P(n) | A(r) |
|---|---|---|---|
| `increment` | P(1) | P(n+1); n = 99 ⇒ 422 `points_max` | 409 `student_absent` |
| `decrement` | 422 `not_recorded` | n > 0 ⇒ P(n−1); n = 0 ⇒ 422 `points_min` | 409 `student_absent` |
| `set_zero` | P(0) | 409 `already_recorded` | 409 `already_recorded` |
| `absent_on` | A(NULL) | A(n) | 409 `already_absent` |
| `absent_off` | 409 `not_absent` | 409 `not_absent` | r no NULL ⇒ P(r); r NULL ⇒ borrar fila (vuelve a NR) |
| `zero_remaining` | cada estudiante activo sin fila ⇒ P(0); ausentes y registrados intactos; 0 afectados ⇒ 422 `nothing_to_do`; un lote deshacible | | |
| `reset_day` | borra todas las entradas de esa clase y fecha; 0 filas ⇒ 422 `nothing_to_do`; un lote deshacible | | |
| `undo` | revierte la operación no deshecha más reciente (tipo ≠ `undo`) de esa clase y fecha con las fotos "antes" de sus eventos; marca `undone_at`; repetible (pila); ninguna ⇒ 409 `nothing_to_undo`; no hay "deshacer el deshacer" | | |

Errores comunes: 422 `weekend` (sábado/domingo), 422 `future_date` (posterior a hoy en Toronto), 422 `validation`
(payload inválido o fecha imposible como `2026-02-30`), 404 `not_found` (clase inexistente o estudiante de otra
clase), 422 `student_archived`, 401 `unauthenticated` (JSON), 419 `csrf_mismatch`.

Mensajes literales (propios del proyecto, nunca traducciones del framework):

| Code | HTTP | Message |
|---|---|---|
| `validation` | 422 | primer mensaje de validación |
| `weekend` | 422 | `Weekends are not school days.` |
| `future_date` | 422 | `Future dates cannot be edited.` |
| `points_max` | 422 | `Points cannot go above 99.` |
| `points_min` | 422 | `Points cannot go below 0.` |
| `not_recorded` | 422 | `Nothing is recorded for this student yet.` |
| `student_archived` | 422 | `This student is archived.` |
| `nothing_to_do` | 422 | `Nothing to do for this day.` |
| `student_absent` | 409 | `This student is marked absent. Mark present first.` |
| `already_recorded` | 409 | `This student already has a record for this day.` |
| `already_absent` | 409 | `This student is already marked absent.` |
| `not_absent` | 409 | `This student is not marked absent.` |
| `nothing_to_undo` | 409 | `Nothing to undo for this day.` |
| `not_found` | 404 | `Not found.` |
| `unauthenticated` | 401 | `Session expired — sign in again` |
| `csrf_mismatch` | 419 | `Session expired — sign in again` |

Efectos: una transacción que primero bloquea la fila de la clase (`lockForUpdate`), luego comprueba el `op_id`, luego
escribe la entrada, una fila en `participation_operations` y una fila de `participation_events` por estudiante afectado.
Las operaciones son **deltas**, nunca valores absolutos: 50 clics rápidos con 50 `op_id` suman 50.

**`GET /api/classes/{class}/days/{date}`** — 200 `{"data": <estado del día>}` con `replayed: false`; mismas reglas de
404/401.

**`POST /classes/{class}/import/preview` y `/commit`** — acepta un `.csv`/`.txt` subido (máx. 256 KB, UTF-8 con o sin
BOM, delimitador detectado entre `,` `;` tabulador) o nombres pegados (uno por línea). Cabeceras reconocidas
(sin distinguir mayúsculas): `name`, o `first_name` + `last_name`; `student_number` opcional. Cada fila queda `OK`,
`WARNING` (nombre normalizado o `student_number` igual a un estudiante existente de esa clase, o repetida en el
archivo) o `ERROR` (nombre vacío, más de 120 caracteres, o supera el cupo). Los nombres nunca se fusionan solos; las
filas `WARNING` salen desmarcadas y solo se importan si la docente las marca. La vista previa se guarda en la sesión
bajo un token; `commit` recibe solo el token y los números de fila, revalida desde lo guardado y crea los estudiantes
en una transacción respetando el cupo; al terminar redirige a `/roster?class=<id>`.

**Exportaciones CSV** (`App\Support\CsvWriter`): celda `null` ⇒ celda vacía (nada entre comas); entero ⇒ dígitos sin
comillas; texto ⇒ con prefijo `'` si empieza por `=`, `+`, `-`, `@`, tabulador o CR, comillas dobles internas
duplicadas, siempre entre comillas; separador `,`; cada línea termina en LF (`\n`), también la última; sin BOM. Los
promedios son texto (`"2.67"`, `"No data"`); una ausencia es `"A"`. **`student_number` es siempre una celda de texto:
en las tres exportaciones (semanal, semestral y por estudiante) un `student_number` NULL se escribe `""` (celda de
texto vacía entre comillas), exactamente como en el fixture `tests/Fixtures/weekly-export.csv`.** El controlador pasa
`$student->student_number ?? ''`, nunca el NULL crudo. La exportación semanal y la semestral tienen columna
`Student number`; la del estudiante no la tiene, así que allí la regla no llega a aplicarse (y se aplicaría igual si se
añadiera). Solo las celdas de día y de puntos usan la celda vacía sin comillas para NULL. Nombre de archivo:
`classpulse-<tipo>-<slug de la clase>-<periodo>-exported-<hoy>.csv`, donde el slug sustituye cada tramo fuera de
`A-Za-z0-9` por `-` (`HNL 2O` ⇒ `HNL-2O`); periodo semanal = el lunes; semestral y por estudiante =
`<desde>-to-<hasta>`; la exportación por estudiante añade el slug del nombre tras el de la clase. Todas las
exportaciones exigen sesión.

---

## 6. Frontend Architecture

### Routes

| Route | Page | Data source | Auth |
|---|---|---|---|
| /login | Sign in | estático + errores de sesión | invitado |
| /daily | Daily Tracker | consulta en servidor (`ParticipationService::dayState`) + `GET /api/.../days/{date}` al cargar | docente |
| /weekly | Weekly Matrix | consulta en servidor (`ParticipationStats`) | docente |
| /semester | Semester Analytics | consulta en servidor | docente |
| /students/{student} | Historial del estudiante | consulta en servidor | docente |
| /roster | Class Roster & Settings | consulta en servidor | docente |
| /classes/{class}/import | Importar lista | formulario + vista previa en sesión | docente |

### Rendering strategy

Todo se renderiza en el servidor con Blade en cada petición (sin caché de página; las respuestas autenticadas llevan
`Cache-Control: no-store, private`). No hay rutas estáticas ni de solo cliente. El único comportamiento de cliente es
la cola de guardado del Daily Tracker (`public/js/save-queue.js` + `public/js/daily.js`), el conmutador de tema
(`theme-init.js` bloqueante en `<head>`, `theme.js` con `defer`) y los diálogos (`dialogs.js`). Scripts clásicos con
`defer`, sin módulos ES (evita problemas de tipo MIME en hosting compartido). Los enlaces a páginas que llegan en pasos
posteriores se escriben con `url()`, nunca con `route()`.

### Component hierarchy

```
<x-layouts.app>  (server)                                   resources/views/components/layouts/app.blade.php
  header: brand "ClassPulse" · class selector (GET, chip con el código) · nav tabs · slot actions · theme toggle · logout
  main#main
    Daily (server-rendered, enhanced by daily.js)           resources/views/daily/index.blade.php
      date bar: Previous school day · "Wednesday, Oct 21, 2026" · Next school day · Today
      toolbar: #save-pill (aria-live) · Reset day [data-dialog=reset-dialog] · Mark remaining as 0 [data-dialog=zero-dialog] · #student-search + #search-count · chips All/Active/Absent
      summary line: "N recorded · N absent · N not recorded · N pts"
      card grid → article.student-card × activos           resources/views/daily/card.blade.php
        [−] · name (link al historial) · score / "—" + "Not recorded" + "Record 0" · [+] · Absent / Mark present
      dialog#reset-dialog · dialog#zero-dialog
    Weekly: KPI tiles · matrix (heat cells + texto) · legend 0 | 1-2 | 3-5 | 6+ | A · Export CSV
    Semester: KPI tiles · Master Cumulative Roster · enlace al detalle por estudiante · Export CSV
    Roster & Settings: parámetros de la clase · <details> Create New Course / Section · alumnos activos/archivados · borrar clase
```

El contrato de DOM entre las vistas Daily y `daily.js` (valores de `data-action`, `data-dialog` e ids) está como tabla
en el paso 17 y lo comprueba con `grep` el gate del paso 18.

### State management

- Estado del servidor: la base de datos; cada página se recalcula en cada petición. No hay librería de fetching.
- Cola de guardado (`save-queue.js`, lógica pura probada con `node --test`): FIFO por clase/fecha; cada acción recibe un
  UUID v4 `op_id`; se envía de una en una para preservar el orden; la UI optimista muestra la acción al instante con
  `Saving…`; `Saved` solo tras un 2xx que vacía la cola; un fallo de red, timeout (10 s) o 5xx deja pendiente esa
  operación y todas las siguientes y muestra `Save failed — Retry`, y reintentar reenvía el **mismo** `op_id` (la
  idempotencia del servidor impide contar dos veces); 409/422 descartan esa operación, repintan desde `error.day` y
  muestran el mensaje; se ignoran respuestas con `day_version` menor que el mayor aplicado; `beforeunload` avisa si la
  cola no está vacía; 401/419 muestran `Session expired — sign in again` sin perder lo que hay en pantalla. El JS nunca
  calcula totales guardados: pinta la respuesta del servidor.
- Deliberadamente fuera del estado cliente: datos de estudiantes en `localStorage` (solo se guarda la preferencia visual
  `classpulse-theme`), cambios pendientes tras recargar (non-goal), sincronización entre pestañas (non-goal).
- Clase actual: sesión (`CurrentClass`, clave `classpulse.current_class_id`), cambiada con `?class=`.

### Loading, empty, and error states

| Superficie | Loading | Empty | Error |
|---|---|---|---|
| Daily | página completa del servidor; pill `Saving…` durante envíos | sin clases: `Create your first class` (enlace a /roster); clase sin activos: `Add students or import a CSV` | pill `Save failed — Retry`; `Session expired — sign in again`; mensajes 409/422 visibles como texto |
| Weekly | página del servidor | semana sin filas: `No data` | 302 a /login si no hay sesión |
| Semester / historial | página del servidor | periodo sin entradas: `No data`; promedio sin días presentes: `No data` | 404 para estudiante inexistente |
| Roster | página del servidor | sin clases: `Create your first class`; sin estudiantes: `Add students or import a CSV` | errores de validación junto a cada campo, como texto |
| Importación | página del servidor | 0 filas válidas: `No valid rows to import.` | token desconocido o cupo alcanzado: error de validación |
| Búsqueda | instantánea | `0 matches` en `#search-count` | — |

---

## 7. Design System

Valores literales derivados del moodboard "Classroom Cadence" (pantallas claras y oscuras) y de
`knowledge/capabilities/styling.md`. Contrastes calculados el 2026-09-30 con la fórmula WCAG.

### Colors

| Token | Light | Dark | Usage |
|---|---|---|---|
| `--bg` | `#F5F6FB` | `#0B1120` | página |
| `--surface` | `#FFFFFF` | `#111A2E` | tarjetas, paneles, diálogos |
| `--surface-2` | `#ECEEF7` | `#182440` | filas alternas, pistas |
| `--border` | `#C9D0E3` | `#2A3757` | solo separadores decorativos |
| `--border-strong` | `#76819F` (3.88:1 sobre surface) | `#6F7EA8` (4.32:1 sobre surface) | bordes de controles e inputs |
| `--fg` | `#141A2E` (17.26:1) | `#E8ECF6` (14.67:1) | texto |
| `--muted` | `#4A5675` (7.29:1) | `#A3AECB` (7.82:1) | texto secundario |
| `--primary` | `#5140D8` (6.83:1) | `#8B7CFF` (5.31:1) | botones, enlaces, anillo de foco |
| `--primary-fg` | `#FFFFFF` (6.83:1 sobre primary) | `#0B1120` (5.76:1 sobre primary) | texto sobre primary |
| `--primary-soft` | `#E7E4FF` | `#1E1B4B` | pestaña activa, resaltado de búsqueda |
| `--success` / `--success-bg` | `#166534` (7.13) / `#DCFCE7` (6.49) | `#34D399` (9.02) / `#0F3B2E` (6.48) | Saved, Present |
| `--destructive` / `--destructive-bg` | `#B42318` (6.57) / `#FEE4E2` (5.45) | `#F87171` (6.27) / `#3B1620` (5.74) | ausente, errores, borrar |
| `--warning` | `#92400E` (7.09) | `#FBBF24` (10.39) | WARNING en importación, Saving… |

Escala de calor semanal (la celda **siempre** muestra también el número, `A` o `—` como texto):

| Nivel | Light (texto) | Dark (texto) |
|---|---|---|
| 0 | `#ECEEF7` (`#141A2E`) | `#182440` (`#E8ECF6`) |
| 1-2 | `#DDD8FF` (`#141A2E`) | `#2B2A66` (`#E8ECF6`) |
| 3-5 | `#C9EEF4` (`#141A2E`) | `#1F4D5C` (`#E8ECF6`) |
| 6+ | `#BBF0D2` (`#141A2E`) | `#0F5B3F` (`#E8ECF6`) |
| A | `#FAD4D8` (`#7A1020`) | `#5A1E2B` (`#FFE4E8`) |

Todos los pares de texto de calor cumplen ≥ 8.05:1 en claro y ≥ 6.86:1 en oscuro.

**Contrast:** los tres pares más arriesgados son `--border-strong` claro `#76819F` sobre `#FFFFFF` = 3.88:1 (límite de
componente, requiere 3:1), `--border-strong` oscuro `#6F7EA8` sobre `#111A2E` = 4.32:1, y `--primary-fg` oscuro
`#0B1120` sobre `#8B7CFF` = 5.76:1. `--border` no llega a 3:1 a propósito y por eso nunca es el único borde de un
control. El estado nunca se comunica solo con color: las tarjetas muestran `Not recorded`, `Present · 0`, `Present`,
`Absent`; el pill muestra `Saving…`, `Saved`, `Save failed — Retry`.

Los valores hex viven solo en `public/css/tokens.css`; `public/css/app.css` no contiene ningún `#` (ni hex ni
selectores de id) y lo comprueba el gate del paso 8.

### Typography

| Role | Family | Size / line-height | Weight | Tracking |
|---|---|---|---|---|
| Display (puntuación de tarjeta) | `system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif` con `font-variant-numeric: tabular-nums` | 3rem / 1 (2.5rem por debajo de 640px) | 700 | 0 |
| Heading | misma pila del sistema | h1 1.75rem/1.25 · h2 1.375rem/1.3 · h3 1.125rem/1.4 | 600 | 0 |
| Body | misma pila del sistema | 1rem (16px) / 1.5 | 400 | 0 |
| Mono | `ui-monospace, SFMono-Regular, Menlo, Consolas, monospace` | 0.875rem / 1.5 | 400 | 0 |

**Font loading:** ninguna fuente web (CSP `font-src 'self'` y privacidad); solo pilas del sistema, sin `@font-face`.

### Spacing, radius, elevation
- Spacing scale: base 4px — 4, 8, 12, 16, 24, 32, 48.
- Radius: controles 8px, tarjetas 12px, diálogos 16px, pills 999px.
- Shadows: 1px `--border` más `0 1px 2px rgba(0,0,0,.25)` en oscuro / `0 1px 2px rgba(20,26,46,.08)` en claro.
- Max content width: 1440px · Breakpoints: 640px y 1024px. Rejilla de tarjetas
  `repeat(auto-fill, minmax(240px, 1fr))` (4 columnas en escritorio, 2 en tableta, 1 en teléfono).
- Objetivos táctiles: botones +/− de tarjeta mínimo 56×56px; todo lo demás ≥ 44px de alto; botón Absent a lo ancho, 44px.
- Foco: anillo de 3px `--primary` con 2px de separación.

### Motion
120ms `ease-out` solo en `transform` y `opacity` (pulsación de botones, aparición del pill y de diálogos).
`@media (prefers-reduced-motion: reduce)` elimina toda transición. Nada se anima en bucle.

### Component style
Un portapapeles de aula sobrio: superficies planas con borde de 1px y sombra mínima, números grandes y tabulares como
protagonistas, color reservado para el estado (violeta primario para acciones, verde para presente/guardado, rojo
tenue para ausente) y siempre acompañado de texto. Tema oscuro por defecto cuando el sistema lo prefiere
(`<html data-theme="light|dark">`, conmutador que solo guarda `classpulse-theme` en `localStorage` dentro de
try/catch). Referencia analizada: el moodboard "Classroom Cadence", quitando todo lo que §1 declara non-goal.

---

## 8. Authentication & Authorization

### Provider and rationale
Autenticación de sesión de Laravel con un login escrito a mano (`Auth::attempt`) y sin starter kit
(`knowledge/capabilities/auth.md`): hay una sola cuenta y los starter kits añaden registro, reset por email y Node.

### Flows
- **Alta de la cuenta (una vez):** en local,
  `docker compose exec app php artisan classpulse:create-teacher teacher@classpulse.test --name=Teacher` (con el email
  real de la docente en su lugar; solicita la contraseña oculta dos veces, mínimo 12 caracteres; se niega si ya existe un
  usuario). No hay registro web.
- **Inicio de sesión:** email + contraseña → `Auth::attempt` → `session()->regenerate()` → redirección a `/daily` (o a
  la URL prevista). Fallo: vuelve con `These credentials do not match our records.`. Sexto intento en un minuto para el
  mismo email+IP: 429 con `Retry-After`.
- **Caducidad:** sesión de 480 minutos (`SESSION_LIFETIME`). Una página HTML sin sesión redirige a `/login`; una
  llamada JSON recibe 401 `unauthenticated` y la cola muestra `Session expired — sign in again` sin perder la pantalla.
- **Cierre de sesión:** `POST /logout` → `Auth::logout()`, `invalidate()`, `regenerateToken()` → `/login`.
- **Restablecer contraseña:** `classpulse:reset-password teacher@classpulse.test` (también borra todas las filas de
  `sessions`).
- **Sin SSH:** `classpulse:hash` en local imprime solo un hash bcrypt para pegarlo en `users.password` con phpMyAdmin.
  El peor caso (sin ninguna fila) es un `INSERT` documentado en `docs/access-and-recovery.md` con valores marcados para
  reemplazar. No hay restablecimiento por email.
- **Borrado de cuenta:** no aplica (una sola cuenta; se borra la base de datos completa si la dueña lo decide).

### Route protection

| Surface | Rule | Enforced where |
|---|---|---|
| todas las rutas salvo `/login` y `/up` | autenticada | grupo `auth` en `routes/web.php` |
| `GET/POST /login` | solo invitado | grupo `guest` en `routes/web.php` |
| `POST /login` | 5 intentos/minuto por email+IP | limitador `login` en `app/Providers/AppServiceProvider.php` + `throttle:login` |
| `/api/*` | autenticada; 401/419 en JSON | `routes/web.php` + render de excepciones en `bootstrap/app.php` |
| `{student}` / `student_id` | debe pertenecer a `{class}` | `app/Services/ParticipationService.php`, controladores |

**Enforcement rule:** authorization is checked server-side on every request. Client-side route guards are cosmetic and
never the only check. A hidden button is not a permission.

### Roles and permissions

| Role | Can | Cannot |
|---|---|---|
| Docente (única cuenta) | todo lo de la aplicación: clases, estudiantes, registro, informes, importación, exportación | registrar cuentas, cambiar su contraseña desde la web (solo CLI/SQL), publicar |

### Sessions
Cookie de sesión de Laravel con driver `database` (borrable para invalidar), `http_only` true, `same_site` lax,
`secure` = `SESSION_SECURE_COOKIE` (`true` en producción, `false` en local por HTTP), vida 480 minutos. CSRF: middleware
`Illuminate\Foundation\Http\Middleware\PreventRequestForgery` de Laravel 13 (en el grupo `web` por defecto); los
formularios usan `@csrf` y las llamadas JSON envían `X-CSRF-TOKEN` desde `<meta name="csrf-token">`. Hash bcrypt con
`BCRYPT_ROUNDS=12` (4 en pruebas). Las respuestas autenticadas llevan `Cache-Control: no-store, private`.

### Multi-tenancy / row-level isolation
No hay multi-tenancy (una docente). El aislamiento relevante es **entre clases**: el servicio y los controladores
resuelven la clase por `{class}` y filtran por `school_class_id`; un estudiante de otra clase devuelve 404; la FK
compuesta `(student_id, school_class_id) → students(id, school_class_id)` impide físicamente una entrada cruzada.

---

## 9. BUILD ORDER

Reglas de cada paso (resumen de la plantilla, obligatorias): un paso por sesión; como máximo 5 archivos escritos o
editados (contados uno a uno; un glob solo cuenta por los archivos a los que se expande) y 6 criterios; los cuatro
campos `Do`, `Done when`, `Verify` y `Checkpoint`; criterios en forma EARS decidibles por una máquina; `Verify` es
shell literal que sale con 0 cuando el paso es correcto (los casos de error se comprueban dentro de las pruebas o con el
código exacto, nunca con un `!` genérico); ningún `Verify` depende de su propio `Checkpoint`; un paso no está terminado
hasta que sus comandos pasan **y** los de los pasos anteriores siguen pasando; nunca se salta un paso bloqueado; nunca
se edita un comando de verificación.

Todos los comandos se ejecutan desde la raíz del proyecto y con los servicios en marcha (`docker compose up -d --build`).
Los pasos 1-17 usan como gate de epic `pint --test` + `phpunit` (`phpunit.xml` lleva `failOnEmptyTestSuite="true"`,
así que una ejecución sin ninguna prueba falla). La suite JS entra en el gate desde el paso 18, cuando existe
`tests/js/`, y siempre con la comprobación de recuento:
`docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt`
— una ejecución que no encuentra ningún archivo de prueba imprime `pass 0` y sale con 0, así que el código de salida
solo no prueba nada; el `&&` corta si el contenedor falla, sin depender de `pipefail`; `/tmp` es el directorio temporal
del host; el patrón acepta el reportero `spec` (`ℹ pass N`) y el `tap` (`# pass N`). El contenedor `jstest` se usa ya
desde el paso 8 para `node --check`.

**Recuento positivo de pruebas PHP.** Cada paso cuya prueba principal es un archivo PHPUnit añade, además de ejecutarlo,
la línea `docker compose exec -T app vendor/bin/phpunit --list-tests <archivo> | grep -q '<Clase>::<método>'`, con un
método que el propio paso nombra en su **Do**. Así un archivo vacío, renombrado o con un método mal nombrado hace fallar
el gate (verificado en esta máquina: `phpunit <archivo>` de una clase sin pruebas sale con 1; una ruta inexistente,
con 2).

### One step, one unit — the counting rule

**One Section 9 step = one `tasks.json` task = one task block in an epic file.** Este blueprint tiene **25 pasos** →
25 tareas → 25 bloques. La plantilla fija por defecto 10-18 pasos; aquí se supera porque el tope por paso (como máximo
5 archivos escritos o editados, contados uno a uno) obliga a partir el esquema, las cuentas, el diseño, las clases, los
informes y la importación. El alcance de v1 no cambia (la dueña lo pidió todo); la decisión consta en §20.3, fila 17.
Con 25 pasos las epics legales van de `ceil(25 ÷ 9)` = 3 a `floor(25 ÷ 5)` = 5, cada una de 5-9 pasos. Se mantienen
**3 epics**: `01-foundation-access` (pasos 1-9, E1-T1…E1-T9), `02-roster-daily-tracking` (pasos 10-18,
E2-T1…E2-T9) y `03-reports-import-release` (pasos 19-25, E3-T1…E3-T7).

### Step map

La columna *Files* cuenta los archivos que el paso escribe o edita (las eliminaciones del paso 1 no son autoría).

| # | Step | Depends on | Touches | Files | Gate |
|---|---|---|---|---|---|
| 1 | Limpieza del esqueleto y configuración | — | config/classpulse.php, config/app.php, composer.json, routes/web.php, EnvironmentGuardTest | 5 | `curl` a `/up` = 200 y `artisan --version` = 13.x |
| 2 | Migración del esquema y restricciones | 1 | migración, DatabaseSeeder, SchemaConstraintsTest | 3 | `phpunit tests/Feature/SchemaConstraintsTest.php` |
| 3 | Modelos Eloquent | 2 | SchoolClass, Student, ParticipationEntry, ParticipationOperation, ParticipationEvent | 5 | `artisan model:show` de los 5 modelos |
| 4 | Factories y pruebas de modelos | 3 | 3 factories, ModelsTest | 4 | `phpunit tests/Feature/ModelsTest.php` |
| 5 | Comandos de cuenta | 1 | 3 comandos, TeacherCommandsTest | 4 | `phpunit tests/Feature/TeacherCommandsTest.php` |
| 6 | Cabeceras de seguridad | 1 | SecurityHeaders, bootstrap/app.php, SecurityHeadersTest | 3 | `curl -sI /up` con CSP y nosniff |
| 7 | Login, logout, throttling, protección de rutas | 4, 5, 6 | LoginController, login view, routes, AppServiceProvider, AuthTest | 5 | `phpunit tests/Feature/AuthTest.php` |
| 8 | Tokens, CSS y scripts de tema | 7 | tokens.css, app.css, theme-init.js, theme.js | 4 | `curl` 200 + `jstest node --check` |
| 9 | Layout y clase actual | 8 | layout, CurrentClass, LayoutShellTest | 3 | `phpunit tests/Feature/LayoutShellTest.php` |
| 10 | Crear y editar clases | 9 | ClassController, StoreClassRequest, UpdateClassRequest, routes, ClassManagementTest | 5 | `phpunit tests/Feature/ClassManagementTest.php` |
| 11 | Página Roster & Settings y borrado | 10 | ClassController, DestroyClassRequest, roster view, routes, RosterPageTest | 5 | `phpunit tests/Feature/RosterPageTest.php` |
| 12 | Lista de estudiantes con cupo | 11 | StudentController, StudentRequest, roster view, routes, StudentRosterTest | 5 | `phpunit tests/Feature/StudentRosterTest.php` |
| 13 | SchoolCalendar y ParticipationStats | 12 | 2 clases, AppServiceProvider, 2 pruebas unitarias | 5 | `phpunit tests/Unit/ParticipationStatsTest.php` |
| 14 | Operaciones por estudiante, idempotencia, bloqueo | 13 | ParticipationService, ParticipationException, 2 pruebas | 4 | `phpunit tests/Feature/ParticipationIdempotencyTest.php` |
| 15 | Lotes, deshacer y seed de demo | 14 | ParticipationService, DemoSeedCommand, 2 pruebas | 4 | `phpunit tests/Feature/ParticipationBatchUndoTest.php` |
| 16 | API JSON del día | 15 | Api/DayController, DayOperationRequest, routes, bootstrap/app.php, DayApiTest | 5 | `phpunit tests/Feature/DayApiTest.php` + `curl` 401 |
| 17 | Página Daily Tracker | 16 | DailyController, daily/index, daily/card, routes, DailyPageTest | 5 | `phpunit tests/Feature/DailyPageTest.php` |
| 18 | Cola de guardado JS | 17 | save-queue.js, daily.js, dialogs.js, prueba JS, daily/index | 5 | `jstest` con `pass` ≥ 1 + `node --check` + contrato DOM |
| 19 | Weekly Matrix y CSV seguro | 18 | CsvWriter, WeeklyController, weekly view, routes, WeeklyReportTest | 5 | CSV idéntico al fixture |
| 20 | Semester Analytics y su CSV | 19 | SemesterController, semester view, routes, SemesterReportTest | 4 | `phpunit tests/Feature/SemesterReportTest.php` |
| 21 | Historial del estudiante y su CSV | 20 | StudentHistoryController, student view, routes, StudentHistoryTest | 4 | `phpunit tests/Feature/StudentHistoryTest.php` |
| 22 | Servicio de importación | 21 | RosterImporter, RosterImporterTest | 2 | `phpunit tests/Feature/RosterImporterTest.php` |
| 23 | Páginas de importación | 22 | ImportController, import/index, import/preview, routes, ImportTest | 5 | `phpunit tests/Feature/ImportTest.php` |
| 24 | Paquete de publicación verificado | 23 | 3 scripts | 3 | `./scripts/verify-release.sh` |
| 25 | Documentación de la dueña (español) | 24 | README.md, 4 docs | 5 | `grep` de encabezados + escaneo de secretos |

Orden: andamiaje → datos → cuenta y acceso → diseño → clases y lista → motor de operaciones → API → página diaria →
cliente → informes → importación → paquete → documentación. El punto de entrada servido (`/up`) se ejecuta ya en el paso 1,
las cabeceras en vivo en el paso 6, la página de login en el paso 7, los assets servidos y `node --check` en el paso 8, y
los scripts del cliente en el paso 18; cada contrato entre artefactos se prueba en el primer paso donde existen ambos
lados (§19.6).

---

#### Step 1 — Limpieza del esqueleto y configuración

**Do**
Borrar los restos de Node y de ejemplo del esqueleto y añadir la configuración propia:
- `rm -f package.json vite.config.js resources/views/welcome.blade.php tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php database/migrations/*_create_cache_table.php database/migrations/*_create_jobs_table.php`,
  después `test ! -d resources/js || rm -r resources/js` y `test ! -d resources/css || rm -r resources/css` (cada guarda
  sale con 0 si el directorio ya no existe; las tres formas están en `permissions.allow`). La línea `rm -f` se ejecuta **una sola vez**: en zsh un glob sin coincidencias hace fallar el comando, así que si el paso se repite esa línea se omite (los ficheros ya no existen). El directorio `tests/Unit`
  queda vacío hasta el paso 13, y la suite completa sigue saliendo con 0 porque `tests/Feature` tiene pruebas
  (verificado en esta máquina).
- `config/classpulse.php` — devuelve `school_timezone` (`env('APP_TIMEZONE', 'America/Toronto')`), `max_roster` 30,
  `max_points` 99, `import_max_kb` 256.
- `config/app.php` — `'timezone' => env('APP_TIMEZONE', 'America/Toronto'),`.
- `composer.json` — quitar las entradas `scripts.setup` y `scripts.dev` (llaman a `npm`/`npx`).
- `routes/web.php` — solo `<?php` y un comentario (la ruta `welcome` desaparece; `/up` la registra `bootstrap/app.php`).
- `tests/Feature/EnvironmentGuardTest.php` — método `test_suite_uses_the_test_database_and_toronto_time`: afirma
  conexión `mariadb`, base `classpulse_test`, zona `America/Toronto`, `max_roster` 30 y `max_points` 99 (las pruebas
  nunca tocan la base dev).

**Done when**
- [ ] **WHEN** `docker compose exec -T app php artisan --version` runs **THE SYSTEM SHALL** print a line containing `Laravel Framework 13.` and exit 0.
- [ ] **WHEN** `curl` requests `http://localhost:8090/up` **THE SYSTEM SHALL** return HTTP 200.
- [ ] **WHEN** `vendor/bin/phpunit tests/Feature/EnvironmentGuardTest.php` runs in the app container **THE SYSTEM SHALL** assert that the connection is `mariadb`, the database is `classpulse_test`, `app.timezone` is `America/Toronto`, `classpulse.max_roster` is 30 and `classpulse.max_points` is 99, and exit 0.
- [ ] **WHEN** the project root is listed **THE SYSTEM SHALL** contain no `package.json`, no `vite.config.js`, no `resources/js`, no `resources/css`, no `resources/views/welcome.blade.php` and no migration whose name contains `create_cache_table` or `create_jobs_table`.
- [ ] **WHEN** `composer.json` is searched for `npm` or `npx` **THE SYSTEM SHALL** find 0 occurrences.
- [ ] **WHEN** `vendor/bin/pint --test` runs in the app container **THE SYSTEM SHALL** exit 0.

**Verify**
```bash
docker compose exec -T app php artisan --version | grep -q 'Laravel Framework 13\.'      # expect: exit 0 (13.34.0 observed)
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/up)" = 200            # expect: exit 0 — the served entry point runs
docker compose exec -T app php artisan config:show app.timezone | grep -q 'America/Toronto' # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/EnvironmentGuardTest.php      # expect: exit 0, 0 failures
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/EnvironmentGuardTest.php | grep -q 'EnvironmentGuardTest::test_suite_uses_the_test_database_and_toronto_time'   # expect: exit 0 — the named test exists
test ! -e package.json && test ! -e vite.config.js && test ! -e resources/js && test ! -e resources/css && test ! -e resources/views/welcome.blade.php   # expect: exit 0
test "$(ls database/migrations | grep -cE 'create_(cache|jobs)_table')" = 0                # expect: exit 0
test "$(grep -cE 'npm|npx' composer.json)" = 0                                             # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                          # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 1: skeleton-config (E1-T1)"
git tag step-01-skeleton-config
```

#### Step 2 — Migración del esquema y restricciones

**Do**
- `docker compose exec -T app php artisan make:migration create_classpulse_schema` y rellenar el archivo más nuevo de
  `database/migrations/` (nombre elegido por la herramienta) con las cinco tablas de §4 mediante el schema builder, más
  los CHECK con `DB::statement` (código exacto en el bloque E1-T2 de `epics/01-foundation-access.md`).
- `database/seeders/DatabaseSeeder.php`: `run()` vacío (se elimina el usuario fijo del esqueleto).
- `tests/Feature/SchemaConstraintsTest.php` con `RefreshDatabase`, trabajando solo con `DB::table()` (los modelos llegan
  en el paso 3); incluye el método `test_rejects_entry_for_student_of_another_class`.

**Done when**
- [ ] **WHEN** `php artisan migrate --force` runs in the app container **THE SYSTEM SHALL** exit 0 and `php artisan migrate:status` SHALL list the `create_classpulse_schema` migration as `Ran`.
- [ ] **WHEN** a `participation_entries` row pairs a student with a `school_class_id` other than that student's class **THE SYSTEM SHALL** reject the insert with a `QueryException`.
- [ ] **WHEN** a second `participation_entries` row is inserted for the same `school_class_id`, `student_id` and `work_date` **THE SYSTEM SHALL** reject it with a `QueryException`.
- [ ] **WHEN** an entry is written with `points` 100, or with `status` `present` and `points` NULL, or a class is written with `roster_cap` 31 or with `semester_end` before `semester_start` **THE SYSTEM SHALL** reject the write with a `QueryException`.
- [ ] **WHEN** a `school_classes` row is deleted **THE SYSTEM SHALL** cascade-delete its students, entries, operations and events, leaving 0 rows that reference it.
- [ ] **WHEN** `database/seeders/DatabaseSeeder.php` is searched for `password` or `@example.com` **THE SYSTEM SHALL** find 0 occurrences.

**Verify**
```bash
docker compose exec -T app php artisan migrate --force                                                   # expect: exit 0
docker compose exec -T app php artisan migrate:status | grep 'create_classpulse_schema' | grep -q 'Ran'  # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/SchemaConstraintsTest.php                    # expect: exit 0, 0 failures
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/SchemaConstraintsTest.php | grep -q 'SchemaConstraintsTest::test_rejects_entry_for_student_of_another_class'   # expect: exit 0
test "$(grep -cE 'password|@example\.com' database/seeders/DatabaseSeeder.php)" = 0                       # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                                        # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 2: domain-schema (E1-T2)"
git tag step-02-domain-schema
```

#### Step 3 — Modelos Eloquent

**Do**
`app/Models/`: `SchoolClass` (`activeStudents()`, `activeStudentCount()`, `remainingCapacity()`), `Student`
(`scopeActive()`, `isArchived()`), `ParticipationEntry`, `ParticipationOperation` (`$primaryKey = 'seq'`,
`UPDATED_AT = null`) y `ParticipationEvent`; todos con `use HasFactory;` y `protected $fillable` explícito, nunca
`$guarded` (detalle en el bloque E1-T3). `php artisan model:show <Modelo>` lee la base dev migrada en el paso 2 y sale
con 1 para un modelo desconocido (verificado en esta máquina).

**Done when**
- [ ] **WHEN** `php artisan model:show` runs in the app container for `SchoolClass`, `Student`, `ParticipationEntry`, `ParticipationOperation` and `ParticipationEvent` **THE SYSTEM SHALL** exit 0 for each and print the table name `school_classes`, `students`, `participation_entries`, `participation_operations` and `participation_events` respectively.
- [ ] **WHEN** `php -l` runs in the app container on each of the five model files **THE SYSTEM SHALL** exit 0.
- [ ] **WHEN** the five model files are searched **THE SYSTEM SHALL** find `protected $fillable` in every one of them and `guarded` in none of them.
- [ ] **WHEN** `app/Models/ParticipationOperation.php` is searched **THE SYSTEM SHALL** find `primaryKey = 'seq';` and `UPDATED_AT = null;`.
- [ ] **WHEN** `vendor/bin/pint --test` runs in the app container **THE SYSTEM SHALL** exit 0.

**Verify**
```bash
docker compose exec -T app php artisan model:show SchoolClass | grep -q 'school_classes'                    # expect: exit 0
docker compose exec -T app php artisan model:show Student | grep -q 'students'                              # expect: exit 0
docker compose exec -T app php artisan model:show ParticipationEntry | grep -q 'participation_entries'       # expect: exit 0
docker compose exec -T app php artisan model:show ParticipationOperation | grep -q 'participation_operations'   # expect: exit 0
docker compose exec -T app php artisan model:show ParticipationEvent | grep -q 'participation_events'       # expect: exit 0
docker compose exec -T app php -l app/Models/SchoolClass.php                                                # expect: exit 0
docker compose exec -T app php -l app/Models/Student.php                                                    # expect: exit 0
docker compose exec -T app php -l app/Models/ParticipationEntry.php                                         # expect: exit 0
docker compose exec -T app php -l app/Models/ParticipationOperation.php                                     # expect: exit 0
docker compose exec -T app php -l app/Models/ParticipationEvent.php                                         # expect: exit 0
test "$(grep -LF 'protected $fillable' app/Models/SchoolClass.php app/Models/Student.php app/Models/ParticipationEntry.php app/Models/ParticipationOperation.php app/Models/ParticipationEvent.php | wc -l | tr -d ' ')" = 0   # expect: exit 0 — no model lacks $fillable
test "$(grep -l 'guarded' app/Models/SchoolClass.php app/Models/Student.php app/Models/ParticipationEntry.php app/Models/ParticipationOperation.php app/Models/ParticipationEvent.php | wc -l | tr -d ' ')" = 0   # expect: exit 0
grep -qF "primaryKey = 'seq';" app/Models/ParticipationOperation.php && grep -qF 'UPDATED_AT = null;' app/Models/ParticipationOperation.php   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                                           # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 3: models (E1-T3)"
git tag step-03-models
```

#### Step 4 — Factories y pruebas de modelos

**Do**
- `database/factories/SchoolClassFactory.php`, `StudentFactory.php` (nombres inventados, estado `archived()`) y
  `ParticipationEntryFactory.php` (estudiante de la misma clase, `present`, 0 puntos) — detalle en el bloque E1-T4.
- `tests/Feature/ModelsTest.php` (`RefreshDatabase`) con el método `test_active_student_count_and_remaining_capacity`.

**Done when**
- [ ] **WHEN** `SchoolClass::factory()->create()` runs **THE SYSTEM SHALL** store a class whose `roster_cap` is 30 and whose `name` starts with `HNL `.
- [ ] **WHEN** a class with `roster_cap` 30 has 3 active students and 1 archived student **THE SYSTEM SHALL** return 3 from `activeStudentCount()`, 27 from `remainingCapacity()` and exactly the 3 active students from `activeStudents()`.
- [ ] **WHEN** `Student::active()` is queried **THE SYSTEM SHALL** exclude every student whose `archived_at` is not null, and `isArchived()` SHALL return true only for those students.
- [ ] **WHEN** `Student::factory()->archived()->create()` runs **THE SYSTEM SHALL** set `archived_at` and leave `student_number` null.
- [ ] **WHEN** `ParticipationEntry::factory()->create()` runs **THE SYSTEM SHALL** store an entry with status `present`, 0 points and a student that belongs to the entry's class.
- [ ] **WHEN** a `ParticipationOperation` is created through the model **THE SYSTEM SHALL** assign it an integer `seq` key and set `created_at` without writing an `updated_at` column.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ModelsTest.php     # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ModelsTest.php | grep -q 'ModelsTest::test_active_student_count_and_remaining_capacity'   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                              # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 4: factories (E1-T4)"
git tag step-04-factories
```

#### Step 5 — Comandos de cuenta

**Do**
- `app/Console/Commands/CreateTeacherCommand.php` (`classpulse:create-teacher {email} {--name=Teacher}`),
  `ResetPasswordCommand.php` (`classpulse:reset-password {email}`, borra todas las filas de `sessions`) y
  `HashPasswordCommand.php` (`classpulse:hash`, imprime solo el hash): contraseña oculta con `secret()` dos veces,
  mínimo 12 caracteres, salida 1 en cualquier rechazo.
- `tests/Feature/TeacherCommandsTest.php` (usa `expectsQuestion`) con el método
  `test_create_teacher_creates_exactly_one_user`.
- La tercera línea de `Verify` escribe la lista de comandos en el archivo temporal del host `/tmp/classpulse-commands.txt`.

**Done when**
- [ ] **WHEN** `classpulse:create-teacher teacher@classpulse.test --name=Teacher` runs on an empty `users` table and the password prompt and its confirmation receive the same value of 12 or more characters **THE SYSTEM SHALL** create exactly 1 user whose password verifies with `Hash::check` and exit 0.
- [ ] **WHEN** `classpulse:create-teacher` runs while any user exists, or the password is shorter than 12 characters, or the confirmation differs **THE SYSTEM SHALL** exit 1 and leave the `users` row count unchanged.
- [ ] **WHEN** `classpulse:reset-password teacher@classpulse.test` completes **THE SYSTEM SHALL** store a hash that verifies the new password and leave 0 rows in `sessions`, and for an unknown email it SHALL exit 1.
- [ ] **WHEN** `classpulse:hash` receives a password **THE SYSTEM SHALL** print a bcrypt hash containing `$2y$` and SHALL NOT print the password text.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/TeacherCommandsTest.php     # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/TeacherCommandsTest.php | grep -q 'TeacherCommandsTest::test_create_teacher_creates_exactly_one_user'   # expect: exit 0
docker compose exec -T app php artisan list classpulse > /tmp/classpulse-commands.txt && grep -q 'classpulse:create-teacher' /tmp/classpulse-commands.txt && grep -q 'classpulse:reset-password' /tmp/classpulse-commands.txt && grep -q 'classpulse:hash' /tmp/classpulse-commands.txt   # expect: exit 0 — the three commands are registered
docker compose exec -T app vendor/bin/pint --test                                       # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 5: teacher-commands (E1-T5)"
git tag step-05-teacher-commands
```

#### Step 6 — Cabeceras de seguridad

**Do**
- `app/Http/Middleware/SecurityHeaders.php`: añade tras `$next()` la CSP de §14, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: same-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, HSTS solo si la
  petición es segura y `Cache-Control: no-store, private` si hay usuario autenticado.
- `bootstrap/app.php`: `$middleware->append(\App\Http\Middleware\SecurityHeaders::class);`.
- `tests/Feature/SecurityHeadersTest.php` (pide `/up` por `http://` y `https://`) con el método
  `test_http_response_carries_csp_and_no_hsts`.

**Done when**
- [ ] **WHEN** any response is served over plain HTTP **THE SYSTEM SHALL** carry `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` and `Permissions-Policy: camera=(), microphone=(), geolocation=()`, and no `Strict-Transport-Security` header.
- [ ] **WHEN** a response is served over HTTPS **THE SYSTEM SHALL** add `Strict-Transport-Security: max-age=31536000`.
- [ ] **WHEN** `curl -sI http://localhost:8090/up` runs against the live server **THE SYSTEM SHALL** receive an `X-Content-Type-Options: nosniff` header and a `Content-Security-Policy` header starting with `default-src 'self'`.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/SecurityHeadersTest.php     # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/SecurityHeadersTest.php | grep -q 'SecurityHeadersTest::test_http_response_carries_csp_and_no_hsts'   # expect: exit 0
curl -sI http://localhost:8090/up | grep -qi '^x-content-type-options: nosniff'          # expect: exit 0 on the live server
curl -sI http://localhost:8090/up | grep -qi "^content-security-policy: default-src 'self'"   # expect: exit 0 on the live server
docker compose exec -T app vendor/bin/pint --test                                       # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 6: security-headers (E1-T6)"
git tag step-06-security-headers
```

#### Step 7 — Login, logout, throttling y protección de rutas

**Do**
- `app/Http/Controllers/Auth/LoginController.php` (`show`, `store`, `destroy`) con el mensaje literal
  `These credentials do not match our records.`.
- `resources/views/auth/login.blade.php`: HTML completo, `<meta name="csrf-token">`, `theme-init.js` y las dos hojas de
  estilo (existen desde el paso 8; hasta entonces devuelven 404 y ninguna prueba las pide), un único `<h1>`, campos con
  etiqueta y `autocomplete`, errores con `role="alert"`, sin bloquear el pegado, sin inline.
- `app/Providers/AppServiceProvider.php`: limitador `login` (§5).
- `routes/web.php`: grupo `guest` (`GET /login` con nombre `login`, `POST /login` con `throttle:login`) y grupo `auth`
  (`POST /logout`, `Route::redirect('/', '/daily')`).
- `tests/Feature/AuthTest.php` con el método `test_sixth_attempt_is_throttled`.

**Done when**
- [ ] **WHEN** a guest requests `/` **THE SYSTEM SHALL** redirect to `/login`, and `/login` SHALL return 200 with an email field, a password field and the `Content-Security-Policy` header.
- [ ] **WHEN** valid credentials are posted to `/login` **THE SYSTEM SHALL** authenticate the teacher, regenerate the session id and redirect to `/daily`.
- [ ] **WHEN** an invalid password is posted to `/login` **THE SYSTEM SHALL** redirect back with the error `These credentials do not match our records.` and remain a guest.
- [ ] **WHEN** a 6th login attempt for the same email and IP arrives within one minute **THE SYSTEM SHALL** respond 429 with a `Retry-After` header.
- [ ] **WHEN** an authenticated teacher posts to `/logout` **THE SYSTEM SHALL** end the session, rotate the CSRF token and redirect to `/login`, and every authenticated response SHALL carry a `Cache-Control` header containing `no-store`.
- [ ] **WHEN** `/register`, `/forgot-password` or `/reset-password/abc` is requested **THE SYSTEM SHALL** return 404.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/AuthTest.php                      # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/AuthTest.php | grep -q 'AuthTest::test_sixth_attempt_is_throttled'   # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/login)" = 200             # expect: exit 0 — login page served
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/)" = 302                  # expect: exit 0 — guest redirected
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/register)" = 404          # expect: exit 0 — no register route
docker compose exec -T app vendor/bin/pint --test                                             # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 7: login (E1-T7)"
git tag step-07-login
```

#### Step 8 — Tokens, CSS y scripts de tema

**Do**
- `public/css/tokens.css` (todos los tokens de §7: claro en `:root`, oscuro bajo `[data-theme="dark"]` y bajo
  `@media (prefers-color-scheme: dark)` para `:root:not([data-theme="light"])`; los hex son la lista de tokens del paso 9)
  y `public/css/app.css` (todas las clases de componente, sin ningún `#`: colores solo con `var(--…)`, estilos por clase
  y atributo, nunca por id; incluye `@media (prefers-reduced-motion: reduce)`).
- `public/js/theme-init.js` (bloqueante) y `public/js/theme.js` (`defer`; conmutador y envío automático del selector
  de clase), scripts clásicos que guardan solo `classpulse-theme`.
- Sin archivo de prueba: el gate sirve cada asset (`curl` 200) y analiza los dos scripts con `node --check` en el
  contenedor `jstest` (sale con 0 si el JS es válido y con 1 ante un error de sintaxis, verificado en esta máquina).
  La imagen `node:24-alpine` se descarga la primera vez aquí.

**Done when**
- [ ] **WHEN** `curl` requests `http://localhost:8090/css/tokens.css`, `/css/app.css`, `/js/theme-init.js` and `/js/theme.js` **THE SYSTEM SHALL** return HTTP 200 for each.
- [ ] **WHEN** `node --check` runs in the `jstest` container on `public/js/theme-init.js` and `public/js/theme.js` **THE SYSTEM SHALL** exit 0 for each.
- [ ] **WHEN** `public/css/tokens.css` is read **THE SYSTEM SHALL** contain `[data-theme="dark"]`, `:root:not([data-theme="light"])` and `prefers-color-scheme: dark`.
- [ ] **WHEN** `public/css/app.css` is searched **THE SYSTEM SHALL** find 0 `#` characters (no raw hex colour and no id selector) and SHALL find `prefers-reduced-motion: reduce`.
- [ ] **WHEN** `public/js/theme-init.js` and `public/js/theme.js` are read **THE SYSTEM SHALL** find the key `classpulse-theme` in both and no line starting with `import ` or `export ` in either.

**Verify**
```bash
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/css/tokens.css)" = 200     # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/css/app.css)" = 200        # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/theme-init.js)" = 200   # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/theme.js)" = 200        # expect: exit 0
docker compose --profile test run --rm jstest node --check public/js/theme-init.js              # expect: exit 0 — parses as a classic script
docker compose --profile test run --rm jstest node --check public/js/theme.js                   # expect: exit 0
grep -qF '[data-theme="dark"]' public/css/tokens.css && grep -qF ':root:not([data-theme="light"])' public/css/tokens.css && grep -qF 'prefers-color-scheme: dark' public/css/tokens.css   # expect: exit 0
test "$(grep -c '#' public/css/app.css)" = 0 && grep -qF 'prefers-reduced-motion: reduce' public/css/app.css   # expect: exit 0
grep -qF 'classpulse-theme' public/js/theme-init.js && grep -qF 'classpulse-theme' public/js/theme.js   # expect: exit 0
test -z "$(grep -hcE '^(import|export) ' public/js/theme-init.js public/js/theme.js | grep -v '^0$')"   # expect: exit 0 — no ES module syntax
```

**Checkpoint**
```bash
git add -A && git commit -m "step 8: design-assets (E1-T8)"
git tag step-08-design-assets
```

#### Step 9 — Layout y clase actual

**Do**
- `resources/views/components/layouts/app.blade.php` (`<x-layouts.app>` con props `title`, `active`, `classes`,
  `currentClass` y slot `actions`; enlaces de navegación con `url()`, porque sus rutas llegan en pasos posteriores).
- `app/Support/CurrentClass.php` (`resolve(?int $requested): ?SchoolClass`, sesión `classpulse.current_class_id`).
- `tests/Feature/LayoutShellTest.php`: renderiza el componente, prueba `CurrentClass`, escanea todas las vistas en
  busca de `style=`, `<style`, `<script>` sin `src`, atributos `on*=`, `{!!` y `@vite` (método
  `test_views_contain_no_inline_script_or_style`), y comprueba que `tokens.css` contiene la **lista de tokens del paso 9**:
  `#0B1120 #111A2E #182440 #2A3757 #6F7EA8 #E8ECF6 #A3AECB #8B7CFF #1E1B4B #34D399 #0F3B2E #F87171 #3B1620 #FBBF24
  #F5F6FB #FFFFFF #ECEEF7 #C9D0E3 #76819F #141A2E #4A5675 #5140D8 #E7E4FF #166534 #DCFCE7 #B42318 #FEE4E2 #92400E
  #2B2A66 #1F4D5C #0F5B3F #5A1E2B #FFE4E8 #DDD8FF #C9EEF4 #BBF0D2 #FAD4D8 #7A1020`.

**Done when**
- [ ] **WHEN** the `x-layouts.app` component renders for an authenticated teacher **THE SYSTEM SHALL** output the nav tabs `Daily Tracker`, `Weekly Matrix`, `Semester Analytics` and `Class Roster & Settings`, a theme toggle button with an accessible name, and a skip link to `#main`.
- [ ] **WHEN** the layout renders **THE SYSTEM SHALL** load `/js/theme-init.js` in `<head>` without `defer` or `async`, before the `/css/tokens.css` and `/css/app.css` stylesheets.
- [ ] **WHEN** three classes exist and `CurrentClass::resolve` receives a valid class id **THE SYSTEM SHALL** return that class and store its id in the session, with no id it SHALL return the session's class, with a stale id it SHALL fall back to the first class by name, and with no classes it SHALL return null.
- [ ] **WHEN** `LayoutShellTest` scans every file under `resources/views` **THE SYSTEM SHALL** find 0 occurrences of `style=`, `<style`, a `<script>` tag without `src`, an `on*=` event attribute, `{!!` or `@vite`.
- [ ] **WHEN** `LayoutShellTest` reads `public/css/tokens.css` **THE SYSTEM SHALL** find every hex value of the ClassPulse palette listed in the step 9 token list, compared case-insensitively.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php               # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/LayoutShellTest.php | grep -q 'LayoutShellTest::test_views_contain_no_inline_script_or_style'   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                              # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 9: layout-shell (E1-T9)"
git tag step-09-layout-shell
```

#### Step 10 — Crear y editar clases

**Do**
- `app/Http/Controllers/ClassController.php` con `store` (redirige a `url('/roster?class='.$id)`) y `update`.
- `app/Http/Requests/StoreClassRequest.php` y `UpdateClassRequest.php` (reglas de §5 y del bloque E2-T1; `roster_cap`
  entre 1 y `config('classpulse.max_roster')`; al editar, nunca por debajo de los activos actuales).
- `routes/web.php` (grupo `auth`: `POST /classes`, `PUT /classes/{class}`) y `tests/Feature/ClassManagementTest.php`
  con el método `test_teacher_creates_a_class`. La página `/roster` llega en el paso 11; la prueba afirma la dirección de
  redirección sin seguirla.
- La tercera línea de `Verify` escribe la lista de rutas en el archivo temporal del host `/tmp/classpulse-routes.txt`.

**Done when**
- [ ] **WHEN** a teacher posts a valid class with `name`, `subject_description`, `period_label`, `roster_cap` between 1 and 30 and optional semester dates to `/classes` **THE SYSTEM SHALL** create 1 `school_classes` row and redirect to `/roster?class=` followed by its id.
- [ ] **WHEN** the posted `name` duplicates an existing class, `roster_cap` is 0 or 31, or `semester_end` precedes `semester_start` **THE SYSTEM SHALL** return a validation error for that field and create 0 rows.
- [ ] **WHEN** a teacher sends changed fields with `PUT /classes/{class}` **THE SYSTEM SHALL** update that row and keep its id.
- [ ] **WHEN** `PUT /classes/{class}` sets `roster_cap` below that class's active student count **THE SYSTEM SHALL** return a `roster_cap` validation error and keep the stored cap.
- [ ] **WHEN** a guest posts to `/classes` or sends `PUT /classes/{class}` **THE SYSTEM SHALL** redirect to `/login` and change nothing.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ClassManagementTest.php        # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ClassManagementTest.php | grep -q 'ClassManagementTest::test_teacher_creates_a_class'   # expect: exit 0
docker compose exec -T app php artisan route:list --path=classes > /tmp/classpulse-routes.txt && grep -q 'ClassController@store' /tmp/classpulse-routes.txt && grep -q 'ClassController@update' /tmp/classpulse-routes.txt   # expect: exit 0 — both routes registered
docker compose exec -T app vendor/bin/pint --test                                          # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 10: classes (E2-T1)"
git tag step-10-classes
```

#### Step 11 — Página Roster & Settings y borrado de clases

**Do**
- `app/Http/Controllers/ClassController.php`: añadir `index` (`GET /roster`, clase por `CurrentClass`) y `destroy`.
- `app/Http/Requests/DestroyClassRequest.php` (`confirm_name` debe coincidir exactamente con el nombre).
- `resources/views/roster/index.blade.php`: estado vacío `Create your first class`, `<details>` "Create New Course /
  Section" con `Official course code`, `Subject description`, `Period / Block`, `Target roster cap`, fechas de semestre,
  parámetros de la clase y la confirmación de borrado `This permanently deletes HNL 2O, 3 students and 12 entries.`
  (con el nombre y los recuentos reales de la clase).
- `routes/web.php` (`GET /roster`, `DELETE /classes/{class}`) y `tests/Feature/RosterPageTest.php` con el método
  `test_delete_requires_the_exact_class_name`.

**Done when**
- [ ] **WHEN** `DELETE /classes/{class}` arrives with a `confirm_name` different from the class name **THE SYSTEM SHALL** keep the class and return a `confirm_name` error, and with the exact name it SHALL delete the class and every student, entry, operation and event of it.
- [ ] **WHEN** `/roster` is requested with no classes **THE SYSTEM SHALL** show `Create your first class`, and for an existing class it SHALL show a delete confirmation naming the class and its student and entry counts.
- [ ] **WHEN** `/roster?class=` with a class id renders **THE SYSTEM SHALL** show a `Create New Course / Section` form with labelled fields `Official course code`, `Subject description`, `Period / Block`, `Target roster cap`, `Semester start` and `Semester end`.
- [ ] **WHEN** a guest requests `/roster` or sends `DELETE /classes/{class}` **THE SYSTEM SHALL** redirect to `/login` and delete nothing.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/RosterPageTest.php            # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/RosterPageTest.php | grep -q 'RosterPageTest::test_delete_requires_the_exact_class_name'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php           # expect: exit 0 — new view stays CSP-clean
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/roster)" = 302       # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                         # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 11: roster-page (E2-T2)"
git tag step-11-roster-page
```

#### Step 12 — Lista de estudiantes con cupo

**Do**
- `app/Http/Controllers/StudentController.php` (`store`, `update`, `archive`, `restore`) con la comprobación de cupo
  antes de añadir o restaurar y el mensaje `This class already has 30 active students (cap 30).` (con el recuento de
  activos y el cupo reales de la clase).
- `app/Http/Requests/StudentRequest.php` — una sola FormRequest para alta y renombrado, porque las reglas son idénticas
  (`display_name` 1-120, `student_number` ≤ 40).
- `resources/views/roster/index.blade.php` (tablas de activos y archivados, estado vacío
  `Add students or import a CSV` con enlace `url('/classes/'.$id.'/import')`, página que llega en el paso 23).
- `routes/web.php` y `tests/Feature/StudentRosterTest.php` (incluye el cupo en 30) con el método
  `test_cap_blocks_the_31st_active_student`.

**Done when**
- [ ] **WHEN** a teacher posts a `display_name` of 1 to 120 characters and an optional `student_number` to `/classes/{class}/students` **THE SYSTEM SHALL** create 1 active student in that class.
- [ ] **WHEN** a class with `roster_cap` 30 already has 30 active students **THE SYSTEM SHALL** reject another add and a restore with the error `This class already has 30 active students (cap 30).` and keep 30 active students.
- [ ] **WHEN** `/students/{student}/archive` is posted **THE SYSTEM SHALL** set `archived_at` and keep every entry of that student, and `/students/{student}/restore` SHALL clear it while the class is under its cap.
- [ ] **WHEN** `PUT /students/{student}` renames a student **THE SYSTEM SHALL** keep the same `id`.
- [ ] **WHEN** `/roster?class=` with a class id renders **THE SYSTEM SHALL** list active and archived students in separate tables, and a class with 0 students SHALL show `Add students or import a CSV`.
- [ ] **WHEN** a student route receives an id that does not exist **THE SYSTEM SHALL** return 404.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/StudentRosterTest.php     # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/StudentRosterTest.php | grep -q 'StudentRosterTest::test_cap_blocks_the_31st_active_student'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php       # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                     # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 12: students (E2-T3)"
git tag step-12-students
```

#### Step 13 — SchoolCalendar y ParticipationStats

**Do**
- `app/Support/SchoolCalendar.php` y `app/Support/ParticipationStats.php` (firmas en el bloque de contratos de
  `epics/02-roster-daily-tracking.md`; fórmulas de §4).
- `app/Providers/AppServiceProvider.php`: singleton de `SchoolCalendar` con `config('classpulse.school_timezone')`.
- `tests/Unit/SchoolCalendarTest.php` (método `test_today_uses_toronto_midnight`) y
  `tests/Unit/ParticipationStatsTest.php` (método `test_semester_average_is_not_the_mean_of_weekly_averages`); extienden
  `PHPUnit\Framework\TestCase`, congelan el reloj con `CarbonImmutable::setTestNow` e incluyen los cambios de horario del
  2026-03-08 y 2026-11-01. Son los primeros archivos de `tests/Unit`.

**Done when**
- [ ] **WHEN** the clock is `2026-10-21 23:30` America/Toronto **THE SYSTEM SHALL** return `2026-10-21` from `SchoolCalendar::today()`, and at `2026-10-22 00:30` America/Toronto it SHALL return `2026-10-22`.
- [ ] **WHEN** `previousWeekday('2026-03-09')`, `nextWeekday('2026-10-23')` and `previousWeekday('2026-11-02')` are called **THE SYSTEM SHALL** return `2026-03-06`, `2026-10-26` and `2026-10-30`.
- [ ] **WHEN** `isWeekday('2026-10-24')` and `weekStart('2026-10-21')` are called **THE SYSTEM SHALL** return false and `2026-10-19`, and `weekDays('2026-10-19')` SHALL return the five dates `2026-10-19` to `2026-10-23`.
- [ ] **WHEN** a student has present rows with 3, 0 and 5 points, one absent row and one not-recorded day **THE SYSTEM SHALL** report total_points 8, present_days_recorded 3, absences 1, days_recorded 4, participation_days 2 and an average that formats as `2.67`.
- [ ] **WHEN** a student has 0 present days recorded in the period **THE SYSTEM SHALL** format the average as `No data`, never `0.00`.
- [ ] **WHEN** week 1 has one present day with 10 points and week 2 has four present days with 1 point each **THE SYSTEM SHALL** return a period average of 2.8 (14 / 5) rather than the 5.5 mean of weekly averages, and weekly buckets SHALL be clipped to the period bounds.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Unit/SchoolCalendarTest.php       # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Unit/SchoolCalendarTest.php | grep -q 'SchoolCalendarTest::test_today_uses_toronto_midnight'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Unit/ParticipationStatsTest.php   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Unit/ParticipationStatsTest.php | grep -q 'ParticipationStatsTest::test_semester_average_is_not_the_mean_of_weekly_averages'   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                     # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 13: calendar-stats (E2-T4)"
git tag step-13-calendar-stats
```

#### Step 14 — Operaciones por estudiante, idempotencia y bloqueo

**Do**
- `app/Services/ParticipationService.php`: `apply()` y `dayState()` con el orden rechazo de fin de semana/futuro →
  transacción → `lockForUpdate` de la clase → comprobación de `op_id` → estudiante de la clase → entrada con
  `lockForUpdate` → transición (tabla de §5) → fila de operación + eventos → estado del día.
- `app/Exceptions/ParticipationException.php` (`errorCode`, `status`, mensaje literal de §5).
- `tests/Feature/ParticipationServiceTest.php` (método `test_absent_off_restores_remembered_points`) y
  `tests/Feature/ParticipationIdempotencyTest.php` (método `test_fifty_distinct_op_ids_store_fifty_points`: 50
  incrementos con `Str::uuid()`, repetición de un `op_id`, registro de consultas con `DB::enableQueryLog()` para comprobar
  el `for update` antes del primer `insert`, aislamiento entre clases).

**Done when**
- [ ] **WHEN** `increment` is applied **THE SYSTEM SHALL** turn no row into present with 1 point and present n into n+1, and SHALL raise `points_max` (422) at 99 points and `student_absent` (409) for an absent student.
- [ ] **WHEN** `decrement` is applied **THE SYSTEM SHALL** lower present n above 0 by 1, and SHALL raise `points_min` (422) at 0, `not_recorded` (422) when no row exists and `student_absent` (409) when the student is absent.
- [ ] **WHEN** `set_zero`, `absent_on` and `absent_off` are applied **THE SYSTEM SHALL** record present 0 only on a day with no row (otherwise `already_recorded` 409), remember the points held before an absence and restore them on `absent_off`, and delete the row on `absent_off` when nothing was recorded before the absence.
- [ ] **WHEN** 50 `increment` operations with 50 distinct `op_id` values are applied to one student **THE SYSTEM SHALL** store 50 points and 50 `participation_operations` rows.
- [ ] **WHEN** an `op_id` that already exists is applied again **THE SYSTEM SHALL** change no row and return the day state with `replayed` true.
- [ ] **WHEN** any operation runs **THE SYSTEM SHALL** lock the `school_classes` row with a `for update` query before its first write, and SHALL raise `not_found` (404) for a student of another class, `student_archived` (422) for an archived student, `weekend` (422) for a Saturday or Sunday and `future_date` (422) for a date after today in America/Toronto.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ParticipationServiceTest.php       # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ParticipationServiceTest.php | grep -q 'ParticipationServiceTest::test_absent_off_restores_remembered_points'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/ParticipationIdempotencyTest.php   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ParticipationIdempotencyTest.php | grep -q 'ParticipationIdempotencyTest::test_fifty_distinct_op_ids_store_fifty_points'   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                              # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 14: participation-service (E2-T5)"
git tag step-14-participation-service
```

#### Step 15 — Lotes, deshacer y seed de demostración

**Do**
- `app/Services/ParticipationService.php`: `zero_remaining`, `reset_day` y `undo` (§5), cada uno como un lote con sus
  eventos; `undo` inserta su propia fila de operación con el `op_id` recibido y sin eventos.
- `app/Console/Commands/DemoSeedCommand.php` (`classpulse:demo-seed {--days=20}`, §4 *Seed data*).
- `tests/Feature/ParticipationBatchUndoTest.php` (método `test_undo_restores_reset_day_as_one_batch`) y
  `tests/Feature/DemoSeedTest.php` (método `test_refuses_outside_local`; el caso de éxito fija
  `$this->app['env'] = 'local'` y usa `--days=2`).

**Done when**
- [ ] **WHEN** `zero_remaining` is applied **THE SYSTEM SHALL** record present 0 for every active student without a row that day, leave absent, recorded and archived students untouched, and raise `nothing_to_do` (422) when no student qualifies.
- [ ] **WHEN** `reset_day` is applied **THE SYSTEM SHALL** delete every entry of that class and date only, and raise `nothing_to_do` (422) when there are none.
- [ ] **WHEN** `undo` is applied repeatedly **THE SYSTEM SHALL** revert the most recent not-undone operation of that class and date each time, restoring the exact prior status, points and restore_points, and raise `nothing_to_undo` (409) when none remain.
- [ ] **WHEN** the undone operation was `zero_remaining`, `reset_day` or `absent_on` **THE SYSTEM SHALL** restore every affected student to the state before that operation as one batch.
- [ ] **WHEN** `classpulse:demo-seed` runs outside `APP_ENV=local` **THE SYSTEM SHALL** exit 1 and write 0 rows.
- [ ] **WHEN** `classpulse:demo-seed --days=2` runs with `APP_ENV=local` on an empty database **THE SYSTEM SHALL** create 3 classes with invented student names, at least one `participation_operations` row for every seeded class and day, and the user `teacher@classpulse.test`, print its random password once and exit 0.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ParticipationBatchUndoTest.php   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ParticipationBatchUndoTest.php | grep -q 'ParticipationBatchUndoTest::test_undo_restores_reset_day_as_one_batch'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/DemoSeedTest.php                 # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/DemoSeedTest.php | grep -q 'DemoSeedTest::test_refuses_outside_local'   # expect: exit 0
docker compose exec -T app php artisan list classpulse | grep -q 'classpulse:demo-seed'     # expect: exit 0 — command registered
docker compose exec -T app vendor/bin/pint --test                                            # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 15: batch-undo (E2-T6)"
git tag step-15-batch-undo
```

#### Step 16 — API JSON del día

**Do**
- `app/Http/Controllers/Api/DayController.php` (`show`, `store`): rechaza fechas imposibles con 422 `validation`,
  delega en el servicio y convierte `ParticipationException` en el sobre de error con `day`.
- `app/Http/Requests/DayOperationRequest.php` (reglas de §5).
- `routes/web.php`: las dos rutas `/api/...` dentro del grupo `auth` con la restricción de `{date}`.
- `bootstrap/app.php` (`withExceptions`, solo cuando `$request->is('api/*')`): `AuthenticationException` → 401
  `unauthenticated`; `TokenMismatchException` → 419 `csrf_mismatch`; `ValidationException` → 422 `validation`;
  `ModelNotFoundException` y `NotFoundHttpException` → 404 `not_found`.
- `tests/Feature/DayApiTest.php` (método `test_replayed_op_id_changes_nothing`; la forma del 419 se prueba con una ruta
  sonda registrada dentro de la prueba, porque Laravel omite la comprobación CSRF real mientras corren las pruebas).

**Done when**
- [ ] **WHEN** an authenticated teacher requests `GET /api/classes/{class}/days/{date}` **THE SYSTEM SHALL** return 200 with `data.day_version`, one `data.entries` item per active student, `data.summary`, `data.can_undo` and `data.replayed` false.
- [ ] **WHEN** a valid operation is posted to `/api/classes/{class}/days/{date}/operations` **THE SYSTEM SHALL** return 200 with the updated entry and a `day_version` greater than before, and the same `op_id` posted again SHALL return 200 with `replayed` true and unchanged points.
- [ ] **WHEN** an operation fails a business rule **THE SYSTEM SHALL** return the mapped status with a body whose `error` object carries `code`, `message` and `day`, for example 422 `points_min` and 409 `student_absent`.
- [ ] **WHEN** the date is a Saturday, after today or not a real calendar date, or `kind` is unknown, or `student_id` is missing for a per-student kind **THE SYSTEM SHALL** return 422 with code `weekend`, `future_date` or `validation`.
- [ ] **WHEN** `student_id` belongs to another class or `{class}` does not exist **THE SYSTEM SHALL** return 404 with code `not_found`.
- [ ] **WHEN** an unauthenticated JSON request reaches an `/api` route **THE SYSTEM SHALL** return 401 with code `unauthenticated`, and a `TokenMismatchException` on an `/api` route SHALL render as 419 with code `csrf_mismatch`.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/DayApiTest.php     # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/DayApiTest.php | grep -q 'DayApiTest::test_replayed_op_id_changes_nothing'   # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' http://localhost:8090/api/classes/1/days/2026-10-21)" = 401   # expect: exit 0 — the live endpoint answers JSON 401
docker compose exec -T app vendor/bin/pint --test                              # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 16: day-api (E2-T7)"
git tag step-16-day-api
```

#### Step 17 — Página Daily Tracker

**Do**
- `app/Http/Controllers/DailyController.php` (`GET /daily`): clase por `CurrentClass`, fecha por defecto
  `SchoolCalendar::defaultDate()`, fin de semana ⇒ redirige al viernes anterior, futuro ⇒ redirige a hoy, tarjetas desde
  `ParticipationService::dayState()`.
- `resources/views/daily/index.blade.php` y `resources/views/daily/card.blade.php` con el contrato de DOM de la tabla
  siguiente (detalle en el bloque E2-T8), fecha con formato `l, M j, Y`, nombres accesibles con el nombre del estudiante,
  el nombre del estudiante enlazado con `url('/students/'.$id)` (página del paso 21) y la línea de resumen en la forma
  `1 recorded · 1 absent · 1 not recorded · 3 pts` (presentes registrados, ausentes, sin registro, total de puntos).
- `routes/web.php` y `tests/Feature/DailyPageTest.php` (reloj congelado en `2026-10-21 10:00` America/Toronto; método
  `test_absent_card_disables_point_buttons`).

**Contrato de DOM con `public/js/daily.js` (paso 18).** Son los únicos ganchos que usa el script; el gate del paso 18
comprueba ambos lados con `grep`.

| Gancho en la vista | Archivo | Valores | Qué hace `daily.js` |
|---|---|---|---|
| `data-action` en los botones de la tarjeta | `daily/card.blade.php` | exactamente `increment`, `decrement`, `set_zero`, `absent_on`, `absent_off` | encola ese tipo para el `data-student-id` de la tarjeta |
| `data-dialog` en los botones de la barra | `daily/index.blade.php` | `reset-dialog`, `zero-dialog` | abre ese `<dialog>`; al confirmar encola `reset_day` o `zero_remaining` |
| `id="undo-btn"` | `daily/index.blade.php` | — | encola `undo`; activa `disabled` según `can_undo` |
| `id="save-pill"` | `daily/index.blade.php` | — | muestra `Saving…`, `Saved`, `Save failed — Retry`, `Session expired — sign in again` |
| `id="reset-dialog"`, `id="zero-dialog"` | `daily/index.blade.php` | — | se confirman con `ClassPulseDialogs.confirm` |
| `id="student-search"`, `id="search-count"` | `daily/index.blade.php` | — | resaltado de búsqueda y `N matches` |
| `main[data-api]`, `data-day-version`, `button[data-filter]`, `article.student-card[data-student-id][data-status]` | ambos | — | endpoint, versión, filtros y repintado de tarjetas |

**Done when**
- [ ] **WHEN** `/daily` renders for a class on `2026-10-21` at `2026-10-21 10:00` America/Toronto **THE SYSTEM SHALL** output one card with a `data-student-id` attribute per active student, none for archived students, and the date text `Wednesday, Oct 21, 2026`.
- [ ] **WHEN** a card is not recorded, present with 0, present with points, or absent **THE SYSTEM SHALL** show the text `Not recorded` with a `Record 0` button, `Present · 0`, `Present`, or `Absent` with both point buttons `disabled`.
- [ ] **WHEN** the requested date is Saturday `2026-10-24` **THE SYSTEM SHALL** redirect to `date=2026-10-23`, and a date after today SHALL redirect to today.
- [ ] **WHEN** the page shows Monday `2026-10-19` **THE SYSTEM SHALL** link `Previous school day` to `2026-10-16`, and when it shows today it SHALL render `Next school day` as a disabled button with no link.
- [ ] **WHEN** a card renders for `Alex Rivera` **THE SYSTEM SHALL** label its buttons `Add one point to Alex Rivera`, `Remove one point from Alex Rivera` and `Mark Alex Rivera absent`, render the save status pill with `aria-live=polite`, and render the summary line as `1 recorded · 1 absent · 1 not recorded · 3 pts` for one present student with 3 points, one absent student and one not-recorded student.
- [ ] **WHEN** no class exists or the selected class has 0 active students **THE SYSTEM SHALL** show `Create your first class` or `Add students or import a CSV`.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/DailyPageTest.php        # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/DailyPageTest.php | grep -q 'DailyPageTest::test_absent_card_disables_point_buttons'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php      # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/daily)" = 302   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                    # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 17: daily-page (E2-T8)"
git tag step-17-daily-page
```

#### Step 18 — Cola de guardado JS y conexión con la página diaria

**Do**
- `public/js/save-queue.js` (puro, UMD; API `createSaveQueue({ send, newId, onApply, onStateChange, onError })` →
  `{ enqueue, retry, applyServerState, pendingCount, state, highestVersion }` y `shouldWarnBeforeUnload(queue)`;
  comportamiento de §6).
- `public/js/daily.js` (declara en una línea exactamente
  `const CARD_ACTIONS = ['increment', 'decrement', 'set_zero', 'absent_on', 'absent_off'];` e ignora cualquier otro
  `data-action`; busca los ids del contrato del paso 17; fetch con `X-CSRF-TOKEN`, timeout de 10 s, UI optimista,
  búsqueda con `<mark>`, filtros, `beforeunload`) y `public/js/dialogs.js` (`ClassPulseDialogs.confirm`).
- `resources/views/daily/index.blade.php`: tres `<script src="..." defer>` (save-queue, dialogs, daily).
- `tests/js/save-queue.test.js` con `node:test`, `node:assert/strict` y `require('../../public/js/save-queue.js')`.
- `daily.js` y `dialogs.js` tocan el DOM: se analizan con `node --check` (sin ejecutarlos); la lógica ejecutable vive
  en `save-queue.js`, que sí se ejecuta en `tests/js/`. La primera línea de `Verify` guarda la salida en el archivo
  temporal del host `/tmp/classpulse-jstest.txt` y exige un recuento `pass` que empiece por 1-9 y `fail 0`.

**Done when**
- [ ] **WHEN** 10 actions are enqueued before any response arrives **THE SYSTEM SHALL** call `send` one operation at a time, in enqueue order, with 10 distinct UUID v4 `op_id` values.
- [ ] **WHEN** `send` rejects, times out or returns 500 **THE SYSTEM SHALL** keep that operation and every later one pending, report state `failed`, and on `retry()` resend the same `op_id`.
- [ ] **WHEN** a 409 or 422 response arrives **THE SYSTEM SHALL** drop that operation, apply the `day` state carried in the error, expose the error message and send the next operation.
- [ ] **WHEN** a response carries a `day_version` lower than the highest already applied **THE SYSTEM SHALL** not apply it, report state `saved` only after a 2xx response leaves the queue empty, return true from `shouldWarnBeforeUnload` while operations are pending, and set state `expired` on a 401 or 419 response while keeping every pending operation.
- [ ] **WHEN** `node --check` runs in the `jstest` container on `public/js/save-queue.js`, `public/js/dialogs.js` and `public/js/daily.js` **THE SYSTEM SHALL** exit 0 for each, and the Daily page view SHALL reference all three as external scripts that return HTTP 200.
- [ ] **WHEN** `public/js/daily.js` is compared with the Daily views **THE SYSTEM SHALL** find the `CARD_ACTIONS` list of `increment`, `decrement`, `set_zero`, `absent_on` and `absent_off` in `daily.js`, each of those values as a `data-action` attribute in `resources/views/daily/card.blade.php`, no other `data-action` value in the Daily views, and the ids `save-pill`, `undo-btn`, `reset-dialog`, `zero-dialog`, `student-search` and `search-count` in both `daily.js` and `resources/views/daily/index.blade.php`.

**Verify**
```bash
docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt   # expect: exit 0 — at least one JS test ran and passed, none failed
docker compose --profile test run --rm jstest node --check public/js/save-queue.js           # expect: exit 0
docker compose --profile test run --rm jstest node --check public/js/dialogs.js              # expect: exit 0
docker compose --profile test run --rm jstest node --check public/js/daily.js                # expect: exit 0
grep -q 'js/save-queue.js' resources/views/daily/index.blade.php                             # expect: exit 0
grep -q 'js/dialogs.js' resources/views/daily/index.blade.php                                # expect: exit 0
grep -q 'js/daily.js' resources/views/daily/index.blade.php                                  # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/save-queue.js)" = 200 # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/dialogs.js)" = 200    # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/js/daily.js)" = 200      # expect: exit 0
grep -qF "const CARD_ACTIONS = ['increment', 'decrement', 'set_zero', 'absent_on', 'absent_off'];" public/js/daily.js   # expect: exit 0 — JS side of the contract
grep -qF 'data-action="increment"' resources/views/daily/card.blade.php && grep -qF 'data-action="decrement"' resources/views/daily/card.blade.php && grep -qF 'data-action="set_zero"' resources/views/daily/card.blade.php && grep -qF 'data-action="absent_on"' resources/views/daily/card.blade.php && grep -qF 'data-action="absent_off"' resources/views/daily/card.blade.php   # expect: exit 0 — every JS action exists in the view
test "$(grep -ohE 'data-action="[a-z_]+"' resources/views/daily/index.blade.php resources/views/daily/card.blade.php | grep -cvE 'data-action="(increment|decrement|set_zero|absent_on|absent_off)"')" = 0   # expect: exit 0 — the view uses no action the JS ignores
grep -qF 'save-pill' public/js/daily.js && grep -qF 'undo-btn' public/js/daily.js && grep -qF 'reset-dialog' public/js/daily.js && grep -qF 'zero-dialog' public/js/daily.js && grep -qF 'student-search' public/js/daily.js && grep -qF 'search-count' public/js/daily.js   # expect: exit 0
grep -qF 'id="save-pill"' resources/views/daily/index.blade.php && grep -qF 'id="undo-btn"' resources/views/daily/index.blade.php && grep -qF 'id="reset-dialog"' resources/views/daily/index.blade.php && grep -qF 'id="zero-dialog"' resources/views/daily/index.blade.php && grep -qF 'id="student-search"' resources/views/daily/index.blade.php && grep -qF 'id="search-count"' resources/views/daily/index.blade.php   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/DailyPageTest.php                # expect: exit 0 — step 17 still green
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php              # expect: exit 0 — scripts are external
```

**Checkpoint**
```bash
git add -A && git commit -m "step 18: save-queue (E2-T9)"
git tag step-18-save-queue
```

#### Step 19 — Weekly Matrix y exportación CSV segura

**Do**
- `app/Support/CsvWriter.php` (reglas de §5, incluida la de `student_number` NULL ⇒ `""`).
- `app/Http/Controllers/WeeklyController.php` (`index` = `GET /weekly`, `export` = `GET /export/weekly.csv`).
- `resources/views/reports/weekly.blade.php` (KPI, matriz con clases `heat-0`/`heat-low`/`heat-mid`/`heat-high`/
  `heat-absent` y texto, leyenda `0 | 1-2 | 3-5 | 6+ | A`, estado vacío `No data`).
- `routes/web.php` y `tests/Feature/WeeklyReportTest.php`, que construye exactamente los **datos del fixture del paso
  19** con el reloj en `2026-10-26 10:00` America/Toronto: clase `HNL 2O` (cupo 30) con `Alex Rivera` (número
  `S-1001`), `Jordan Lee`, `=Robin Sky` (activos) y `Morgan Diaz` (archivado el `2026-10-24 12:00:00`); entradas de
  `HNL 2O`: Alex `2026-10-19` presente 3, `2026-10-20` presente 0, `2026-10-21` ausente (points y restore_points NULL),
  `2026-10-23` presente 5; Robin `2026-10-19` presente 1 y `2026-10-20` presente 2; Morgan `2026-10-21` presente 4; y
  una segunda clase `HNC 3C` con `Casey Moon` presente 7 el `2026-10-19`, que no debe aparecer. El método
  `test_weekly_csv_matches_fixture_byte_for_byte` compara el cuerpo con `tests/Fixtures/weekly-export.csv` (emitido en
  §19.6) con `assertSame`.

**Done when**
- [ ] **WHEN** `/weekly` renders for a class and week `2026-10-19` **THE SYSTEM SHALL** show one row per active student plus each archived student with entries that week suffixed `(archived)`, and each day cell SHALL show its points, `A` or `—` as text with a heat class.
- [ ] **WHEN** the `week` parameter is not a Monday **THE SYSTEM SHALL** redirect to the Monday of that week.
- [ ] **WHEN** `GET /export/weekly.csv` runs for week `2026-10-19` on the step 19 fixture data at `2026-10-26 10:00` America/Toronto **THE SYSTEM SHALL** return a body byte-identical to `tests/Fixtures/weekly-export.csv` with `Content-Type: text/csv; charset=UTF-8`.
- [ ] **WHEN** that export is downloaded **THE SYSTEM SHALL** name it `classpulse-weekly-HNL-2O-2026-10-19-exported-2026-10-26.csv` in `Content-Disposition`.
- [ ] **WHEN** `CsvWriter` writes a text cell starting with `=`, `+`, `-`, `@`, a tab or a carriage return **THE SYSTEM SHALL** prefix it with a single quote, quote every text cell and double embedded double quotes.
- [ ] **WHEN** a guest requests `/weekly` or `/export/weekly.csv` **THE SYSTEM SHALL** redirect to `/login`.

**Verify**
```bash
test -f tests/Fixtures/weekly-export.csv                                               # expect: exit 0 — emitted by workspace/
docker compose exec -T app vendor/bin/phpunit tests/Feature/WeeklyReportTest.php       # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/WeeklyReportTest.php | grep -q 'WeeklyReportTest::test_weekly_csv_matches_fixture_byte_for_byte'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php        # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/weekly)" = 302    # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                      # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 19: weekly (E3-T1)"
git tag step-19-weekly
```

#### Step 20 — Semester Analytics y exportación semestral

**Do**
- `app/Http/Controllers/SemesterController.php` (`index`, `export`); periodo = `semester_start` (o la primera entrada)
  hasta el menor entre `semester_end` y hoy.
- `resources/views/reports/semester.blade.php` (KPI, `Master Cumulative Roster`, enlace al detalle con
  `url('/students/'.$id)`, página del paso 21).
- CSV semestral con cabecera `Student`, `Student number`, `Total points`, `Present days recorded`, `Absences`,
  `Days recorded`, `Participation days`, `Average per present day`; `student_number` NULL ⇒ `""` (§5).
- `routes/web.php` y `tests/Feature/SemesterReportTest.php` (clase `HNL 2O`, semestre `2026-09-08` a `2027-01-29`,
  reloj en `2026-10-26 10:00` America/Toronto; método `test_semester_csv_header_and_empty_student_number`).

**Done when**
- [ ] **WHEN** `/semester` renders for a class with semester dates `2026-09-08` to `2027-01-29` at `2026-10-26 10:00` America/Toronto **THE SYSTEM SHALL** list every active student and every archived student with entries in the period with total points, present days recorded, absences, days recorded, participation days and the average over the whole period.
- [ ] **WHEN** a student has 0 present days recorded in the period **THE SYSTEM SHALL** show `No data` as the average, and a class with no entries SHALL show the `No data` empty state.
- [ ] **WHEN** `/export/semester.csv` is requested **THE SYSTEM SHALL** return a first line whose quoted cells are `Student`, `Student number`, `Total points`, `Present days recorded`, `Absences`, `Days recorded`, `Participation days` and `Average per present day`, then one line per listed student, with a null `student_number` written as `""`.
- [ ] **WHEN** the semester export of class `HNL 2O` is downloaded at `2026-10-26 10:00` America/Toronto **THE SYSTEM SHALL** name it `classpulse-semester-HNL-2O-2026-09-08-to-2026-10-26-exported-2026-10-26.csv` in `Content-Disposition`.
- [ ] **WHEN** a guest requests `/semester` or `/export/semester.csv` **THE SYSTEM SHALL** redirect to `/login`.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/SemesterReportTest.php       # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/SemesterReportTest.php | grep -q 'SemesterReportTest::test_semester_csv_header_and_empty_student_number'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php          # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/semester)" = 302    # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                        # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 20: semester (E3-T2)"
git tag step-20-semester
```

#### Step 21 — Historial del estudiante y su exportación

**Do**
- `app/Http/Controllers/StudentHistoryController.php` (`show`, `export`) sobre el periodo del semestre de la clase.
- `resources/views/reports/student.blade.php` (panel del estudiante, fechas registradas, subtotal semanal).
- CSV por estudiante con cabecera `Date`, `Weekday`, `Status`, `Points` (sin columna de número escolar, así que la regla
  de `student_number` de §5 no llega a aplicarse aquí); las filas ausentes dejan vacía la celda de puntos.
- `routes/web.php` y `tests/Feature/StudentHistoryTest.php` (`Alex Rivera` en `HNL 2O`, semestre `2026-09-08` a
  `2027-01-29`, reloj en `2026-10-26 10:00` America/Toronto; método `test_student_csv_lists_each_recorded_date`).

**Done when**
- [ ] **WHEN** `/students/{student}` renders for a student of a class with semester dates `2026-09-08` to `2027-01-29` at `2026-10-26 10:00` America/Toronto **THE SYSTEM SHALL** list each recorded date in the period with its status and points plus a subtotal per Monday-to-Friday week.
- [ ] **WHEN** `/export/student/{student}.csv` is requested **THE SYSTEM SHALL** return the quoted header cells `Date`, `Weekday`, `Status` and `Points`, then one line per recorded date such as `"2026-10-19","Monday","Present",3`, with an empty points cell on absent rows.
- [ ] **WHEN** the export of `Alex Rivera` in class `HNL 2O` is downloaded at `2026-10-26 10:00` America/Toronto **THE SYSTEM SHALL** name it `classpulse-student-HNL-2O-Alex-Rivera-2026-09-08-to-2026-10-26-exported-2026-10-26.csv` in `Content-Disposition`.
- [ ] **WHEN** `/students/{student}` or `/export/student/{student}.csv` receives an id that does not exist **THE SYSTEM SHALL** return 404.
- [ ] **WHEN** a guest requests `/students/{student}` or `/export/student/{student}.csv` **THE SYSTEM SHALL** redirect to `/login`.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/StudentHistoryTest.php        # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/StudentHistoryTest.php | grep -q 'StudentHistoryTest::test_student_csv_lists_each_recorded_date'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php           # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/students/1)" = 302   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                         # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 21: student-history (E3-T3)"
git tag step-21-student-history
```

#### Step 22 — Servicio de importación (vista previa y confirmación)

**Do**
- `app/Services/RosterImporter.php` (`preview()`, `commit()`; validación con `Validator::make`; BOM, delimitador,
  cabeceras, estados `OK`/`WARNING`/`ERROR`, token en sesión y cupo según §5 y el bloque E3-T4).
- `tests/Feature/RosterImporterTest.php`: llama al servicio directamente (archivos con
  `UploadedFile::fake()->createWithContent()`); método `test_bom_semicolon_file_previews_joined_names_as_ok`.

**Done when**
- [ ] **WHEN** `RosterImporter::preview()` receives a UTF-8 file with a BOM, the `;` delimiter and the header `first_name;last_name;student_number` **THE SYSTEM SHALL** return every row with the joined name, its student number and status `OK`.
- [ ] **WHEN** a row matches an existing student of that class by normalized name or by `student_number`, or repeats an earlier row **THE SYSTEM SHALL** mark it `WARNING`.
- [ ] **WHEN** a row has an empty name, a name longer than 120 characters, or would exceed the class roster cap **THE SYSTEM SHALL** mark it `ERROR`.
- [ ] **WHEN** `RosterImporter::commit()` receives the preview token and selected row numbers **THE SYSTEM SHALL** create exactly the selected `OK` and `WARNING` rows in one transaction and never an `ERROR` row, and an unknown token SHALL raise a `ValidationException` and create 0 students.
- [ ] **WHEN** pasted names, one per line, are previewed **THE SYSTEM SHALL** treat each non-empty line as one row, and a file over 256 KB or with an extension other than `.csv` or `.txt` SHALL raise a `ValidationException`.
- [ ] **WHEN** the roster cap is reached between preview and commit **THE SYSTEM SHALL** create 0 students and raise a `ValidationException` on the key `roster_cap`.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/RosterImporterTest.php     # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/RosterImporterTest.php | grep -q 'RosterImporterTest::test_bom_semicolon_file_previews_joined_names_as_ok'   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                      # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 22: roster-importer (E3-T4)"
git tag step-22-roster-importer
```

#### Step 23 — Páginas de importación

**Do**
- `app/Http/Controllers/ImportController.php` (`show`, `preview`, `commit`; `commit` redirige a
  `url('/roster?class='.$id)` con el estado `Imported N students.`).
- `resources/views/import/index.blade.php` y `resources/views/import/preview.blade.php` (casilla por fila: `OK`
  marcada, `WARNING` desmarcada, `ERROR` deshabilitada; estado como texto; estado vacío `No valid rows to import.`).
- `routes/web.php` y `tests/Feature/ImportTest.php` (método `test_preview_page_sets_checkbox_states`).

**Done when**
- [ ] **WHEN** an authenticated teacher requests `/classes/{class}/import` **THE SYSTEM SHALL** return 200 with a file input accepting `.csv,.txt` and a `Paste names, one per line` text area.
- [ ] **WHEN** a file is posted to `/classes/{class}/import/preview` **THE SYSTEM SHALL** render one table row per data row with its status as text, `OK` rows checked, `WARNING` rows unchecked, `ERROR` rows disabled and a hidden `token` field.
- [ ] **WHEN** the preview token and selected row numbers are posted to `/classes/{class}/import/commit` **THE SYSTEM SHALL** create the selected students and redirect to `/roster?class=` followed by the class id.
- [ ] **WHEN** `/classes/{class}/import/commit` receives an unknown token **THE SYSTEM SHALL** redirect back with a `token` error and create 0 students, and a preview whose rows are all `ERROR` SHALL show `No valid rows to import.`
- [ ] **WHEN** a guest requests `/classes/{class}/import` or posts to either import endpoint **THE SYSTEM SHALL** redirect to `/login` and create nothing.

**Verify**
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/ImportTest.php          # expect: exit 0
docker compose exec -T app vendor/bin/phpunit --list-tests tests/Feature/ImportTest.php | grep -q 'ImportTest::test_preview_page_sets_checkbox_states'   # expect: exit 0
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php     # expect: exit 0
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/classes/1/import)" = 302   # expect: exit 0
docker compose exec -T app vendor/bin/pint --test                                   # expect: exit 0
```

**Checkpoint**
```bash
git add -A && git commit -m "step 23: import-pages (E3-T5)"
git tag step-23-import-pages
```

#### Step 24 — Paquete de publicación verificado

**Do**
Escribir exactamente los tres archivos del bloque E3-T6 de `epics/03-reports-import-release.md` y hacerlos ejecutables
con `chmod +x scripts/package-release.sh scripts/verify-release.sh`:
- `scripts/package-release.sh` — el prototipo verificado en esta máquina, sin cambios.
- `scripts/release-exclude.txt` — la lista del prototipo más `/phpunit.xml`, `/.phpunit.result.cache`, `/pint.json`,
  `/README.md` y `/scripts/`, **sin** línea de excepción `!`: `rsync --exclude-from` no tiene negación al estilo
  gitignore, así que `/.env` y `/.env.*` dejan fuera todos los archivos de entorno, `.env.example` incluido, que es el
  resultado buscado.
- `scripts/verify-release.sh` — descomprime en `dist/verify/` con Layout B, busca contenido prohibido en el zip, crea
  la base temporal `classpulse_verify` desde `dist/install.sql`, escribe un `.env` desechable, comprueba `migrate:status`,
  sirve el paquete con `php -S` en el puerto 8001 dentro del contenedor `app`, pide `/up` y `/login`, y siempre limpia
  (base temporal y `dist/verify`).
- El `-eq 3` de ambos scripts cuenta las tres líneas `__DIR__.'/../` (mantenimiento, autoload, bootstrap) del
  `public/index.php` del esqueleto 13.10.1, medido en esta máquina.

**Done when**
- [ ] **WHEN** `./scripts/package-release.sh` runs **THE SYSTEM SHALL** exit 0 and create `dist/classpulse-app.zip`, `dist/classpulse-public_html.zip` and `dist/install.sql`.
- [ ] **WHEN** the packaged `public_html/index.php` is inspected **THE SYSTEM SHALL** contain exactly 3 references to `/../classpulse-app/`, one for each `__DIR__.'/../` line (maintenance, autoload, bootstrap) of skeleton 13.10.1's `public/index.php`.
- [ ] **WHEN** `./scripts/verify-release.sh` lists the app zip **THE SYSTEM SHALL** find no `.env` file, no `phpunit.xml` or `phpunit/` path, no `tests/` directory and no `blueprints/` directory.
- [ ] **WHEN** `dist/install.sql` is imported into the scratch database `classpulse_verify` **THE SYSTEM SHALL** show at least one migration as `Ran` and none as `Pending` in `migrate:status`, and the dump SHALL contain 0 `INSERT INTO` statements for `users`.
- [ ] **WHEN** the unpacked release is served by `php -S` on port 8001 inside the app container **THE SYSTEM SHALL** answer `/up` and `/login` with HTTP 200.
- [ ] **WHEN** `./scripts/verify-release.sh` finishes **THE SYSTEM SHALL** exit 0, remove `dist/verify` and drop `classpulse_verify`.

**Verify**
```bash
./scripts/package-release.sh                                                                        # expect: exit 0, "release built: ..."
./scripts/verify-release.sh                                                                         # expect: exit 0, "release verified: ..."
test -f dist/classpulse-app.zip && test -f dist/classpulse-public_html.zip && test -f dist/install.sql   # expect: exit 0
grep -q 'CREATE TABLE `participation_entries`' dist/install.sql                                     # expect: exit 0
test "$(grep -c 'INSERT INTO `users`' dist/install.sql)" = 0                                         # expect: exit 0 — no account data in the dump
test ! -e dist/verify                                                                               # expect: exit 0 — cleaned up
```

**Checkpoint**
```bash
git add -A && git commit -m "step 24: release (E3-T6)"
git tag step-24-release
```

#### Step 25 — Documentación de la dueña (español)

**Do**
Escribir en español (comandos e identificadores en inglés) `README.md`, `docs/local-development.md`,
`docs/hostinger-deploy.md`, `docs/access-and-recovery.md` y `docs/backups.md` con los encabezados y contenidos exactos
del bloque E3-T7 de `epics/03-reports-import-release.md`; el contenido de Hostinger sale de §12 (lista de 9
comprobaciones, Layout B, instalación con phpMyAdmin, `.env` a mano, ruta SSH opcional, avisos NO VERIFICADO). Ningún
documento contiene un hash real ni su prefijo, ni una `APP_KEY` real; los valores a reemplazar se escriben como tokens
`CAMBIAR_` (p. ej. `DB_PASSWORD=CAMBIAR_POR_LA_CONTRASEÑA_DE_HPANEL`).

**Done when**
- [ ] **WHEN** `README.md` is read **THE SYSTEM SHALL** contain the headings `## Qué es`, `## Primeros pasos` and `## Documentación` and the path `docs/hostinger-deploy.md`.
- [ ] **WHEN** `docs/local-development.md` is read **THE SYSTEM SHALL** contain `## Arrancar el entorno local`, `## Ejecutar las pruebas` and `## Datos de demostración`, and the commands `docker compose up -d` and `classpulse:demo-seed`.
- [ ] **WHEN** `docs/hostinger-deploy.md` is read **THE SYSTEM SHALL** contain `## Lista de verificación en hPanel`, `## Estructura de carpetas (Layout B)`, `## Instalar la base de datos con phpMyAdmin`, `## Crear el archivo .env en el servidor` and `## Ruta alternativa con SSH`, and the literals `classpulse-app`, `public_html`, `APP_DEBUG=false` and `NO VERIFICADO`.
- [ ] **WHEN** `docs/access-and-recovery.md` is read **THE SYSTEM SHALL** contain `## Crear la cuenta de la docente`, `## Restablecer la contraseña`, `## Recuperación sin SSH` and `## Recuperación de último recurso con SQL`, and the commands `classpulse:hash` and `classpulse:reset-password`.
- [ ] **WHEN** `docs/backups.md` is read **THE SYSTEM SHALL** contain `## Copias de seguridad de hPanel`, `## Exportación semanal manual` and `## Restaurar una copia`.
- [ ] **WHEN** `README.md` and every file in `docs/` are scanned **THE SYSTEM SHALL** find 0 lines containing `APP_KEY=base64:` followed by 20 or more base64 characters, or a bcrypt prefix made of `$2y$`, two digits and `$`.

**Verify**
```bash
grep -qF '## Qué es' README.md && grep -qF '## Primeros pasos' README.md && grep -qF '## Documentación' README.md && grep -qF 'docs/hostinger-deploy.md' README.md
grep -qF '## Arrancar el entorno local' docs/local-development.md && grep -qF '## Ejecutar las pruebas' docs/local-development.md && grep -qF '## Datos de demostración' docs/local-development.md && grep -qF 'docker compose up -d' docs/local-development.md && grep -qF 'classpulse:demo-seed' docs/local-development.md
grep -qF '## Lista de verificación en hPanel' docs/hostinger-deploy.md && grep -qF '## Estructura de carpetas (Layout B)' docs/hostinger-deploy.md && grep -qF '## Instalar la base de datos con phpMyAdmin' docs/hostinger-deploy.md && grep -qF '## Crear el archivo .env en el servidor' docs/hostinger-deploy.md && grep -qF '## Ruta alternativa con SSH' docs/hostinger-deploy.md
grep -qF 'classpulse-app' docs/hostinger-deploy.md && grep -qF 'public_html' docs/hostinger-deploy.md && grep -qF 'APP_DEBUG=false' docs/hostinger-deploy.md && grep -qF 'NO VERIFICADO' docs/hostinger-deploy.md
grep -qF '## Crear la cuenta de la docente' docs/access-and-recovery.md && grep -qF '## Restablecer la contraseña' docs/access-and-recovery.md && grep -qF '## Recuperación sin SSH' docs/access-and-recovery.md && grep -qF '## Recuperación de último recurso con SQL' docs/access-and-recovery.md && grep -qF 'classpulse:hash' docs/access-and-recovery.md && grep -qF 'classpulse:reset-password' docs/access-and-recovery.md
grep -qF '## Copias de seguridad de hPanel' docs/backups.md && grep -qF '## Exportación semanal manual' docs/backups.md && grep -qF '## Restaurar una copia' docs/backups.md
test -z "$(grep -hcE 'APP_KEY=base64:[A-Za-z0-9+/=]{20,}|\$2y\$[0-9]{2}\$' README.md docs/local-development.md docs/hostinger-deploy.md docs/access-and-recovery.md docs/backups.md | grep -v '^0$')"   # expect: exit 0 — every file reports 0 matching lines
```
(Cada línea sale con 0 cuando todos sus `grep -qF` encuentran el texto; la última sale con 0 cuando ningún archivo
tiene una línea con secreto.)

**Checkpoint**
```bash
git add -A && git commit -m "step 25: owner-docs (E3-T7)"
git tag step-25-owner-docs
```

---

### 9.1 Parity and cutover

NOT APPLICABLE — greenfield build, no system is being replaced.

---

## 10. Environment Setup

### Prerequisites

| Tool | Version | Check |
|---|---|---|
| Docker Desktop (Compose v2 incluido) | la instalada en el host (sin fijar; debe ejecutar `docker compose`) | `docker --version && docker compose version` |
| git, con `user.name` y `user.email` configurados | la del sistema | `git --version && git config user.email` |
| rsync | la de macOS | `rsync --version` |
| zip / unzip | las de macOS | `zip -v` · `unzip -v` |
| curl | la de macOS | `curl --version` |

PHP, Composer, MariaDB y Node **no** se instalan en el host: todo corre en los contenedores del proyecto Compose
`classpulse`. Nunca se tocan otros contenedores del host (por ejemplo los `wp-env-*` de otro proyecto).

### Accounts to create first
Ninguna para el build: no hay servicios de terceros. La cuenta de Hostinger ya existe y solo se usa en la lista de
lanzamiento posterior al build (§12, §20.1), con aprobación de la dueña.

### Environment variables

ClassPulse no añade un validador de variables al arrancar: cada variable tiene un valor local funcional desde
Bootstrap, así que ningún paso rompe el gate de un paso anterior por motivos de entorno. Laravel lee `.env` para
`artisan`, el servidor de desarrollo y PHPUnit; PHPUnit fuerza sus propios valores de base de datos desde `phpunit.xml`;
Docker Compose lee `.env` solo para interpolar puertos.

| Variable | Purpose | Where to get it | Required by step | Secret? |
|---|---|---|---|---|
| `APP_NAME` | nombre de la app | `.env.example` (`ClassPulse`) | Bootstrap | no |
| `APP_ENV` | entorno | `.env.example` (`local`); producción `production` | Bootstrap | no |
| `APP_KEY` | cifrado y sesiones | `php artisan key:generate` (Bootstrap); en producción `key:generate --show` en local | Bootstrap | sí |
| `APP_DEBUG` | trazas de error | `.env.example` (`true`); producción `false` | Bootstrap | no |
| `APP_URL` | URL base | `.env.example` (`http://localhost:8090`); producción `https://` + dominio | Bootstrap | no |
| `APP_TIMEZONE` | zona horaria escolar | `.env.example` (`America/Toronto`) | Bootstrap (leída desde el paso 1) | no |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE` | idioma de la UI | `.env.example` (`en`) | Bootstrap | no |
| `APP_FAKER_LOCALE` | factories de prueba | `.env.example` (`en_US`) | Bootstrap | no |
| `APP_MAINTENANCE_DRIVER` | modo mantenimiento | `.env.example` (`file`) | Bootstrap | no |
| `BCRYPT_ROUNDS` | coste del hash | `.env.example` (`12`); pruebas `4` (`phpunit.xml`) | Bootstrap | no |
| `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | registro en `storage/logs/laravel.log` | `.env.example` (`stack`, `single`, `debug`); producción `error` | Bootstrap | no |
| `DB_CONNECTION` | driver | `.env.example` (`mariadb`) | Bootstrap | no |
| `DB_HOST` | host de la base | `.env.example` (`db`, nombre del servicio Compose) | Bootstrap | no |
| `DB_PORT` | puerto dentro de la red Compose | `.env.example` (`3306`) | Bootstrap | no |
| `DB_DATABASE` | base dev | `.env.example` (`classpulse`); pruebas `classpulse_test` (`phpunit.xml`); paquete `classpulse_release` (sobrescrita con `-e` en `package-release.sh`) | Bootstrap | no |
| `DB_USERNAME` | usuario | `.env.example` (`classpulse`) | Bootstrap | no |
| `DB_PASSWORD` | contraseña local desechable | `.env.example` (`classpulse_local`); producción: la que crea la dueña en hPanel | Bootstrap | sí en producción |
| `SESSION_DRIVER` | sesiones en base de datos | `.env.example` (`database`); pruebas `array` | Bootstrap | no |
| `SESSION_LIFETIME` | minutos de sesión | `.env.example` (`480`) | Bootstrap | no |
| `SESSION_ENCRYPT`, `SESSION_PATH`, `SESSION_DOMAIN` | ajustes de cookie | `.env.example` (`false`, `/`, `null`) | Bootstrap | no |
| `SESSION_SECURE_COOKIE` | cookie solo HTTPS | `.env.example` (`false`); producción `true` | Bootstrap | no |
| `CACHE_STORE` | caché (limitador de login) | `.env.example` (`file`); pruebas `array` | Bootstrap | no |
| `QUEUE_CONNECTION` | sin worker | `.env.example` (`sync`) | Bootstrap | no |
| `FILESYSTEM_DISK`, `BROADCAST_CONNECTION`, `MAIL_MAILER` | sin almacenamiento externo, broadcast ni correo | `.env.example` (`local`, `log`, `log`); pruebas `null`/`array` | Bootstrap | no |
| `CLASSPULSE_WEB_PORT` | puerto web en el host | `.env.example` (`8090`), solo interpolación de Compose | Bootstrap | no |
| `CLASSPULSE_DB_PORT` | puerto de MariaDB en el host | `.env.example` (`33061`), solo interpolación de Compose | Bootstrap | no |
| `CLASSPULSE_APP_DIR` | nombre de la carpeta de la app en el paquete | opcional; exportar en la misma línea del comando; por defecto `classpulse-app` | 24 | no |
| `MARIADB_ROOT_PASSWORD`, `MARIADB_DATABASE`, `MARIADB_USER`, `MARIADB_PASSWORD` | inicialización del contenedor `db` | valores literales locales en `docker-compose.yml` (`classpulse_local_root`, `classpulse`, `classpulse`, `classpulse_local`) | Bootstrap | no (desechables, solo locales) |

Variables internas de los scripts del paso 24 (variables de shell, **no** de entorno; nadie las exporta ni las lee
desde `.env`): `APP_DIR_NAME` (toma `CLASSPULSE_APP_DIR` o `classpulse-app`), `DBROOT` (`classpulse_local_root`),
`VERIFY_DB` (`classpulse_verify`), `PORT` (`8001`), `APP_KEY_VALUE` (clave desechable generada para el `.env` temporal).

`.env.example` se versiona con todas las claves y valores locales o vacíos (`APP_KEY=` vacío). `.env` y `.env.*` están
ignorados salvo `.env.example`. Las credenciales de producción nunca están en el repositorio: la dueña escribe el `.env`
del servidor a mano en el File Manager. El agente puede leer `.env.example` (versionado) pero tiene denegada la lectura
de `.env`, `.env.local` y `.env.production` (§19.3).

### Files that must be committed

| File | Why it is committed | Ignore-file exception line |
|---|---|---|
| `.env.example` | documenta todas las variables | `!.env.example` después de `.env.*` |
| `.gitignore`, `.dockerignore` | gobiernan git y el contexto de build | — not matched by any ignore pattern |
| `.claude/settings.json`, `.claude/rules/*`, `.claude/skills/*` | configuración del agente | — not matched (solo se ignora `.claude/settings.local.json`) |
| `CLAUDE.md`, `AGENTS.md` | instrucciones del agente | — not matched |
| `Dockerfile`, `docker-compose.yml`, `phpunit.xml`, `pint.json` | configs que los `Verify` necesitan | — not matched |
| `composer.json`, `composer.lock` | dependencias reproducibles | — not matched |
| `tests/Fixtures/weekly-export.csv` | salida esperada byte a byte | — not matched |
| `scripts/*.sh`, `scripts/release-exclude.txt` | empaquetado | — not matched |
| `blueprints/classpulse/**` | este bundle (reanudación) | — not matched |

### Bootstrap

Se ejecuta desde la raíz del proyecto, sin interacción. Esta revisión fija el esqueleto en `laravel/laravel`
`"13.10.1"` (antes `"^13.0"`, que ya resolvía esa misma versión); tras la edición, el hilo principal vuelve a ejecutar
este bloque literalmente dos veces y confirma que la segunda ejecución sale con 0, igual que con la versión anterior del
bloque. Hechos verificados en esta máquina: `docker compose exec -T app php artisan --version` imprime
`Laravel Framework 13.34.0` (el framework que resuelve el esqueleto 13.10.1); la suite por defecto del esqueleto pasa;
`vendor/bin/pint --test` pasa sobre el esqueleto; `php artisan migrate` funciona sobre MariaDB 10.11.

```bash
# order matters: workspace copy (ignore file + configs) -> repo init -> first commit -> services -> scaffold -> platform pin -> env -> key -> test db -> migrate
rsync -a --ignore-existing blueprints/classpulse/workspace/ ./   # never overwrites files the build changed; exits 0 when it skips (unlike BSD cp -n)
git rev-parse --git-dir >/dev/null 2>&1 || git init -b main
git add -A && git commit -m "chore: scaffold" --allow-empty
docker compose up -d --build
docker compose exec -T app sh -c 'php artisan --version >/dev/null 2>&1 || { rm -rf /tmp/skeleton && composer create-project laravel/laravel /tmp/skeleton "13.10.1" --no-interaction --prefer-dist --no-scripts && tar -C /tmp/skeleton -cf - . | tar -C /app -xf - --skip-old-files; }'
docker compose exec -T app sh -c 'composer config platform.php 8.3.0 && composer update --lock --no-interaction'
test -f .env || cp .env.example .env
grep -q '^APP_KEY=base64:' .env || docker compose exec -T app php artisan key:generate --no-interaction
docker compose exec -T db mariadb -uroot -pclasspulse_local_root -e "CREATE DATABASE IF NOT EXISTS classpulse_test; GRANT ALL ON classpulse_test.* TO 'classpulse'@'%';"
docker compose exec -T app php artisan migrate --force
```

Por qué este orden y qué garantiza cada guarda:

- **La copia de `workspace/` va primero** y con `rsync --ignore-existing`: deja `.gitignore`, `.dockerignore`,
  `pint.json`, `phpunit.xml`, `.env.example` y los archivos del agente en su sitio **antes** del primer commit, del
  primer build de imagen y del primer comando de formato. En una segunda ejecución no sobrescribe nada y sale con 0.
  Nunca se sobrescriben `composer.json`, `composer.lock`, `.env` ni ningún archivo que un paso haya editado.
- **`.gitignore` precede al primer commit**, así que `vendor/`, `.env` y `dist/` nunca se versionan. El primer commit
  contiene solo el workspace y el bundle; el esqueleto se versiona en el Checkpoint del paso 1.
- **El repositorio se crea aquí**, de forma idempotente (`git rev-parse … || git init -b main`), con un primer commit
  (`--allow-empty`) al que pueden apuntar las etiquetas. En una reejecución, `git commit --allow-empty` añade un commit
  extra "chore: scaffold" que no afecta a ninguna etiqueta.
- **El esqueleto se crea solo si `artisan` no arranca**, fijado a la versión exacta `13.10.1` (su `public/index.php`
  tiene las tres líneas `__DIR__.'/../` que cuenta el `-eq 3` del paso 24), y se copia con `tar --skip-old-files`: como
  el workspace ya está en disco, **ganan nuestros** `CLAUDE.md`, `AGENTS.md`, `.gitignore`, `phpunit.xml` y
  `.env.example` sobre los que trae el esqueleto. El origen del manifiesto es el scaffold (`composer.json` no se emite en
  `workspace/`); la única edición es `composer config platform.php 8.3.0`, para que un `vendor/` construido en local
  funcione en PHP ≥ 8.3 de hPanel. `--no-scripts` evita que el esqueleto cree SQLite, copie su `.env` o ejecute npm.
- **`.env` y `APP_KEY`** solo se crean si faltan (`test -f .env ||`, `grep -q '^APP_KEY=base64:' ||`).
- **La base de pruebas** `classpulse_test` se crea con `IF NOT EXISTS` y el `GRANT` es idempotente.
- **`migrate --force`** es idempotente. Crea en la base dev también las tablas `cache`/`jobs` del esqueleto; el paso 1
  borra esas migraciones y las tablas quedan como restos inofensivos (nunca `migrate:fresh` en dev).
- Servicios: arrancar `docker compose up -d --build`; detener sin perder datos `docker compose stop`; reiniciar la base
  dev borra datos locales, así que se pide permiso a la dueña primero (nunca `docker compose down -v` sin preguntar).
- No hay descargas de binarios de runner (no hay navegadores ni Playwright). La imagen `node:24-alpine` se descarga sola
  la primera vez que se ejecuta `docker compose --profile test run --rm jstest node --check …` (paso 8).

---

## 11. Dependencies

Procedencia: informe de `stack-researcher` de esta sesión, comprobado el 2026-09-30, más las comprobaciones que el hilo
principal ejecutó en esta máquina (esqueleto 13.10.1 → framework 13.34.0). Las etiquetas de imagen se escriben
literalmente en los archivos emitidos (`Dockerfile`, `docker-compose.yml`); aquí se justifica su origen.

### Runtime

| Package | Version | Source (registry URL or track file) | Checked | Installed by | Purpose |
|---|---|---|---|---|---|
| `laravel/laravel` (esqueleto) | 13.10.1, fijada literalmente (publicada 2026-08-25; requiere php ^8.3 y laravel/framework ^13.17) | https://repo.packagist.org/p2/laravel/laravel.json | 2026-09-30 | §10 Bootstrap — `composer create-project laravel/laravel /tmp/skeleton "13.10.1" --no-interaction --prefer-dist --no-scripts` | estructura inicial del proyecto |
| `laravel/framework` | 13.34.0 (publicado 2026-09-29; resuelto desde el `^13.17` del esqueleto 13.10.1, observado en esta máquina) | https://repo.packagist.org/p2/laravel/framework.json | 2026-09-30 | §10 Bootstrap — `composer create-project` (require del esqueleto), bloqueado por `composer update --lock` | framework |
| PHP (imagen `php:8.3-cli-bookworm`) | línea 8.3 (8.3.35 observado con `docker compose exec app php -v`); `config.platform.php` = `8.3.0` | https://laravel.com/docs/13.x/releases · https://www.php.net/releases/index.php?json&max=3 · https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/ | 2026-09-30 | `Dockerfile` (emitido), construido por `docker compose up -d --build` en §10 | runtime local; Hostinger ofrece 8.2-8.5 (8.3 por defecto) |
| MariaDB (imagen `mariadb:10.11`) | 10.11 LTS (`docker pull` correcto 2026-09-30). Versión de Hostinger: **UNVERIFIED — confirmar en phpMyAdmin** | https://laravel.com/docs/13.x/database · https://mariadb.org/about/ · https://www.hostinger.com/support/1583226-which-database-management-system-is-used-at-hostinger/ | 2026-09-30 | `docker-compose.yml` (emitido), servicio `db` arrancado en §10 | base de datos; Laravel exige ≥ 10.3; 10.11 es el suelo conservador |

### Development

| Package | Version | Source (registry URL or track file) | Checked | Installed by | Purpose |
|---|---|---|---|---|---|
| `phpunit/phpunit` | `^12.5` (12.5.37; predeterminado del esqueleto `^12.5.12`; requiere PHP ≥ 8.3) | https://repo.packagist.org/p2/phpunit/phpunit.json | 2026-09-30 | §10 Bootstrap — `composer create-project` (require-dev del esqueleto) | pruebas Unit y Feature |
| `laravel/pint` | `^1.32` (1.32.1; PHP ^8.3) | https://repo.packagist.org/p2/laravel/pint.json | 2026-09-30 | §10 Bootstrap — `composer create-project` (require-dev del esqueleto) | formato (`pint --test` en cada gate) |
| Composer (imagen `composer:2.10.3`) | 2.10.3 (build correcto 2026-09-30) | https://getcomposer.org/versions | 2026-09-30 | `Dockerfile` (`COPY --from=composer:2.10.3`), construido en §10 | gestor de dependencias dentro de la imagen |
| Node (imagen `node:24-alpine`) | línea 24 (v24.21.0 observado; `docker pull` correcto 2026-09-30) | Docker Hub, imagen oficial `node` | 2026-09-30 | `docker-compose.yml` (servicio `jstest`, perfil `test`), descargado por `docker compose --profile test run --rm jstest node --check …` en el paso 8 | solo ejecutor desechable de `node --test` y `node --check`; cero paquetes; no es dependencia de runtime ni existe en Hostinger |
| `fakerphp/faker` | sin fijar — la versión que resuelva el `require-dev` del esqueleto; léase en `composer.lock` (UNVERIFIED: no está en el informe de versiones) | UNVERIFIED — verify before install | 2026-09-30 | §10 Bootstrap — `composer create-project` (require-dev del esqueleto) | nombres inventados en factories de prueba |

### Deliberately not used

| Rejected | Instead | Why |
|---|---|---|
| Pest | PHPUnit | Pest 5.2.1 exige PHP ^8.4 / PHPUnit 13 (inviable en el suelo 8.3); Pest 4.7.8 funcionaría pero es una dependencia sin beneficio |
| Livewire, Filament, Inertia | Blade + JS clásico | peticiones por tecla, más dependencias; el admin generado no aporta a una sola pantalla táctil |
| Vite, npm, Node en runtime, cualquier bundler | CSS/JS escritos a mano servidos tal cual | Hostinger compartido no ejecuta Node; CSP sin inline; cero paso de build |
| Sanctum, Fortify, starter kits | sesión + login propio | una sola cuenta; los kits traen registro, reset por email y Node |
| Redis, Horizon, colas con worker | `QUEUE_CONNECTION=sync`, `CACHE_STORE=file` | no hay worker ni Redis en hosting compartido |
| Telescope, Cashier, Reverb, laravel/boost, Larastan | nada | sin uso en v1; cada paquete es superficie extra |
| Fuentes externas, Google Fonts, CDNs | pilas de fuentes del sistema | CSP `font-src 'self'` y privacidad |
| Doctrine u otros ORM | Eloquent | nativo, suficiente para 5 tablas |
| Cualquier SDK o servicio de IA | nada | non-goal explícito |
| jsdom u otra librería para ejecutar `daily.js` en Node | `node --check` + contrato DOM comprobado con `grep` | añadiría un paquete npm; la lógica ejecutable está en `save-queue.js`, que sí se prueba |

---

## 12. Deployment Strategy

**Laravel 13 NO se ha verificado en el plan real de la dueña; la publicación espera la aprobación de la versión local
por parte de la dueña.** El build termina con un paquete verificado en local (paso 24) y la documentación (paso 25).
Todo lo que ocurre en Hostinger es la lista de lanzamiento posterior al build (§20.1), nunca una tarea.

### Hosting

- **Local (build):** Docker Compose, proyecto `classpulse`, servicios `db` (`mariadb:10.11`, `127.0.0.1:33061`), `app`
  (`php artisan serve` en el 8000 del contenedor → http://localhost:8090) y `jstest` (perfil `test`). Contenedores,
  puertos y volúmenes exclusivos de ClassPulse.
- **Producción (post-build, no creada aún):** hosting compartido Hostinger. La dueña **no** ha dicho qué plan tiene,
  así que la instalación está diseñada para el peor caso: **sin SSH, sin Composer y con `public_html` fija**.

Hechos verificados por el investigador el 2026-09-30: el plan Single no tiene SSH ni Composer, máximo 2 bases de datos y
2 cron jobs; Premium y superiores tienen SSH y Composer 2 (`composer2`); la raíz del sitio **no** se puede cambiar en
planes Web/WordPress/Cloud (solo VPS); PHP 8.2-8.5 seleccionable (8.3 por defecto); motor MariaDB (versión no publicada,
NO VERIFICADO); límite de base de datos 3 GB; importación por phpMyAdmin hasta 256 MB; SSL gratis con "Force HTTPS";
copias: Premium semanal, Business/Cloud diaria (fragmentos de búsqueda, NO VERIFICADO en la página), copia manual una vez
cada 24 h en Business+; el despliegue por git no ejecuta Composer; las extensiones por defecto (`pdo_mysql`, `mbstring`,
`openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `dom`) NO VERIFICADO → la dueña revisa la pestaña de extensiones PHP;
el Auto Installer solo declara Laravel 12.x → instalación **manual**; límites de inodos Single 200,000 / Premium 400,000
(el paquete tiene unos 7,400 archivos, medido con el prototipo).

Fuentes: https://www.hostinger.com/support/1583494-what-is-the-path-to-your-website-s-root-home-directory-and-how-to-change-it-in-hostinger/ ·
https://www.hostinger.com/support/6152127-how-to-deploy-laravel-8-at-hostinger/ ·
https://www.hostinger.com/support/5792078-how-to-use-composer-at-hostinger ·
https://www.hostinger.com/support/1583245-how-to-connect-to-a-hosting-plan-via-ssh-in-hostinger/ ·
https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/ ·
https://www.hostinger.com/support/1583765-how-many-cron-jobs-can-you-set-up-in-hostinger/ ·
https://www.hostinger.com/support/4667515-how-to-manage-php-extensions-and-options ·
https://www.hostinger.com/support/1884149-how-to-import-a-database-with-phpmyadmin-in-hostinger/ ·
https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/ ·
https://www.hostinger.com/support/1583226-which-database-management-system-is-used-at-hostinger/

**Decisión de instalación: Layout B** (la de la propia guía de Laravel de Hostinger). La app vive en una carpeta
hermana **por encima** de `public_html` (nombre por defecto `classpulse-app`); el contenido de `public/` de Laravel va
**dentro** de `public_html`, y `public_html/index.php` se reescribe para requerir `../classpulse-app/vendor/autoload.php`,
`../classpulse-app/bootstrap/app.php` y el archivo de mantenimiento. `.env`, `vendor/`, `storage/` y las copias nunca
viven en el directorio servido; `classpulse-app/.htaccess` contiene `Require all denied` como defensa adicional. Se
rechaza **Layout A** (todo en `public_html` + reescritura hacia `/public`) porque `.env` y `vendor/` quedarían en la raíz
web. La dueña debe confirmar en el File Manager qué hay por encima de `public_html`.

Build: `./scripts/package-release.sh` construye `vendor/` en local (`composer install --no-dev --optimize-autoloader`
dentro de la imagen, con plataforma PHP 8.3.0) y genera `dist/classpulse-app.zip`, `dist/classpulse-public_html.zip` y
`dist/install.sql` (estructura + filas de `migrations`, sin usuarios ni datos). Ningún archivo de entorno entra en el
paquete (`/.env` y `/.env.*` en `scripts/release-exclude.txt`, sin excepción). **Subir un `vendor/` precompilado no
está documentado por Hostinger: NO VERIFICADO.** La base de datos se crea en hPanel; el esquema se instala importando
`dist/install.sql` con phpMyAdmin; la única fila de docente se inserta pegando un hash bcrypt generado en local con
`classpulse:hash`. **Nunca** se crea una ruta web de instalación o migración. El `.env` del servidor se escribe a mano en
el File Manager. Sin SSH no se ejecutan `config:cache` ni `route:cache`. Ruta opcional con SSH (Premium+):
`composer2 install --no-dev --optimize-autoloader`, `php artisan migrate --force`,
`php artisan classpulse:create-teacher`.

### Environments

| Environment | Branch | URL | Database | Third-party mode |
|---|---|---|---|---|
| Local | `main` (repositorio local) | http://localhost:8090 | `classpulse` (dev) y `classpulse_test` (pruebas) en el contenedor `db` | ninguno (no hay terceros) |
| Preview | NOT APPLICABLE — no hay entorno de vista previa; la verificación del paquete (`verify-release.sh`) cumple ese papel en local | — | `classpulse_verify` temporal | — |
| Production | la versión aprobada por la dueña | `https://` + el dominio de la dueña (aún no creado) | la base MariaDB creada en hPanel | ninguno |

### CI/CD

No hay CI remota en v1 (no hay repositorio remoto; `git push` está denegado al agente). La canalización es el gate de
§20.1, ejecutado en local en este orden: `docker compose exec -T app vendor/bin/pint --test` →
`docker compose exec -T app vendor/bin/phpunit` → la suite JS con su comprobación de recuento → comprobaciones HTTP
de `/up` y `/login` → `./scripts/package-release.sh` → `./scripts/verify-release.sh`. Si más adelante se añade CI,
ejecuta exactamente estos comandos.

### Release and rollback

Publicar (solo la dueña, tras aprobar): conservar los zips de la versión anterior; subir y extraer los nuevos; si la
versión incluye una migración nueva, importar su SQL **antes** de extraer el código nuevo (una migración siempre es
aditiva; nunca se borra una columna en la misma publicación que el código que deja de usarla). Revertir: volver a
extraer los zips anteriores (unos minutos en el File Manager); la base solo se restaura desde la exportación semanal si
una migración dañó datos. Duración esperada: 10-20 minutos manuales.

### Domain, DNS, TLS

El dominio y su DNS ya los gestiona la dueña en Hostinger (fuera del alcance). TLS: SSL gratuito de hPanel + "Force
HTTPS" (Security > SSL); `APP_URL=https://…` y `SESSION_SECURE_COOKIE=true`; la cabecera HSTS se envía solo sobre
HTTPS. Sin redirecciones apex ↔ www propias: se usan las de hPanel.

### Lista de comprobación de la dueña en hPanel (también en `docs/hostinger-deploy.md`)

1. Nombre del plan.
2. Página de SSH (¿disponible?).
3. Versión de PHP ≥ 8.3 y pestaña de extensiones (`pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`,
   `fileinfo`, `dom`).
4. Qué hay por encima de `public_html` y la cuota de inodos.
5. Crear la base de datos y el usuario; leer la versión de MariaDB en phpMyAdmin.
6. Disponibilidad de cron.
7. SSL + Force HTTPS.
8. Frecuencia de copias de seguridad o complemento contratado.
9. Git (opcional).

**Copias de seguridad.** Un cron con `mysqldump` NO está verificado en Hostinger, así que la línea base documentada es:
copias de hPanel + una exportación manual semanal desde phpMyAdmin descargada al ordenador de la dueña. Restaurar =
importar ese SQL en una base vacía con phpMyAdmin (`storage/` no contiene nada crítico).

---

## 13. Testing Strategy

Las pruebas existen para que los "Done when" de §9 sean decidibles por una máquina
(`knowledge/capabilities/testing.md`).

| Layer | Framework | What it covers | Where | Runs |
|---|---|---|---|---|
| Unit | PHPUnit 12 | `SchoolCalendar` (medianoche de Toronto, DST 2026-03-08 y 2026-11-01, fines de semana) y `ParticipationStats` (ceros, ausencias, sin registro, `No data`, semestre ≠ media semanal, recorte de semanas) | `tests/Unit/` | cada paso desde el 13 y el gate |
| Feature (integración) | PHPUnit 12 + `RefreshDatabase` contra MariaDB real (`classpulse_test`) | entorno, esquema y restricciones, modelos, comandos, cabeceras, auth, layout, CRUD, servicio de operaciones, idempotencia, bloqueo, lotes, deshacer, API JSON, DOM de las páginas, CSV byte a byte, importación, escaneo CSP de vistas | `tests/Feature/` | cada paso y el gate |
| Estructura de modelos | `artisan model:show` + `php -l` | los cinco modelos cargan, apuntan a su tabla y declaran `$fillable` | `app/Models/` | paso 3 |
| JS unit | `node --test` en `node:24-alpine` (sin paquetes) | cola de guardado: orden, reintento con el mismo `op_id`, errores de negocio, versiones, `beforeunload`, sesión caducada | `tests/js/save-queue.test.js` | desde el paso 18 y el gate, siempre con recuento `pass` ≥ 1 |
| Sintaxis JS | `node --check` en `node:24-alpine` | los cinco scripts de `public/js` se analizan como scripts clásicos | `public/js/` | pasos 8 y 18 |
| Contrato DOM | `grep` sobre `daily.js` y las vistas Daily | valores de `data-action` e ids que el script usa existen en la vista y viceversa | `public/js/daily.js`, `resources/views/daily/` | paso 18 |
| Paquete | `scripts/verify-release.sh` | contenido prohibido, importación de `install.sql`, arranque real del paquete (`/up`, `/login`) | `scripts/` | paso 24 y el gate |
| E2E en navegador | **NO presente** (hueco deliberado) | — | — | sustituido por pases manuales antes del lanzamiento |

**Nunca un gate vacío.** `phpunit.xml` lleva `failOnEmptyTestSuite="true"` (una ejecución completa sin pruebas falla);
cada gate de archivo PHPUnit lista su método con `--list-tests`; la suite JS exige `pass` ≥ 1 y `fail 0`.

### Critical flows to cover E2E
No hay automatización de navegador en v1. Los flujos críticos se cubren a nivel de servidor + cola JS y con pases
manuales en la lista de lanzamiento:
1. Registrar una clase completa en Daily (clics rápidos, ausencia, deshacer, reset) — servidor: pasos 14-17; cliente:
   paso 18; manual: pase de teclado y de lector de pantalla.
2. Exportar la semana y abrirla en una hoja de cálculo sin que se ejecute ninguna fórmula — paso 19 (byte a byte).
3. Importar una lista con duplicados y respetar el cupo — pasos 22 y 23.

### Test data
Cada prueba Feature usa `RefreshDatabase` sobre `classpulse_test` (creada por Bootstrap, apuntada por `phpunit.xml`
con `force="true"`) y factories; nunca el seed de demo ni la base dev (lo afirma `EnvironmentGuardTest`). El reloj se
congela con `CarbonImmutable::setTestNow` y se restaura en `tearDown`. El servicio real que usan las pruebas es el
contenedor `db` de `docker-compose.yml` (emitido en §19.6), con `DB_HOST=db`. Las pruebas JS no tocan red ni DOM real.
Ninguna prueba depende del orden de ejecución.

### What is deliberately not tested
- Automatización de navegador y axe: fuera del gate automático (se hace a mano: §15, §20.1).
- Ejecución de `daily.js` y `dialogs.js` bajo un DOM: sin dependencias nuevas no hay DOM en Node; se cubren con
  `node --check`, el contrato DOM por `grep` y el pase manual.
- Concurrencia real entre procesos: PHPUnit corre en un solo proceso; se prueba el bloqueo por el registro de consultas
  (`for update` antes de escribir) más la idempotencia y la restricción UNIQUE.
- El comportamiento en Hostinger: no hay acceso; se prueba el paquete en local con `verify-release.sh`.
- Apariencia visual: pase manual a 1440/768/390 px en claro y oscuro.

---

## 14. Security & Secrets

| Concern | Control | Implemented in |
|---|---|---|
| Secret storage | `.env` fuera de git, fuera del paquete y fuera de `public_html`; credenciales de producción escritas a mano en el File Manager | `.gitignore`, `scripts/release-exclude.txt` (`/.env`, `/.env.*`), `docs/hostinger-deploy.md` |
| Secret rotation | `APP_KEY` nueva por instalación; contraseña con `classpulse:reset-password` (borra sesiones) o `classpulse:hash` + phpMyAdmin | `app/Console/Commands/*.php` |
| Input validation | FormRequests (login y la importación: validador en el controlador/servicio) | `app/Http/Requests/`, `app/Services/RosterImporter.php` |
| Output encoding / XSS | solo `{{ }}` en Blade; prueba que falla con `{!!` | `resources/views/**`, `tests/Feature/LayoutShellTest.php` |
| SQL injection | Eloquent/query builder con bindings; los únicos SQL literales son los CHECK estáticos de la migración | `app/**`, `database/migrations/` |
| AuthN / AuthZ | §8: grupo `auth` en todas las rutas salvo login y `/up`; clase por `{class}` y 404 para estudiantes ajenos | `routes/web.php`, `app/Services/ParticipationService.php` |
| CSRF | `PreventRequestForgery` (grupo `web`), `@csrf`, `X-CSRF-TOKEN` en JSON | Laravel, vistas, `public/js/daily.js` |
| Rate limiting / abuse | login 5/min por email+IP, 429 con `Retry-After` | `app/Providers/AppServiceProvider.php` |
| Webhook verification | NOT APPLICABLE — no hay webhooks | — |
| Dependency audit | `docker compose exec -T app composer audit` antes de cada paquete de publicación; lo revisa quien construye (paso manual: necesita red) | `.claude/skills/release-package/SKILL.md`, paso 2 de la skill `release-package` (auditoría manual previa al paquete; no es el paso 2 de §9) |
| Security headers | `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` · `X-Content-Type-Options: nosniff` · `Referrer-Policy: same-origin` · `Permissions-Policy: camera=(), microphone=(), geolocation=()` · `Strict-Transport-Security: max-age=31536000` solo sobre HTTPS · `Cache-Control: no-store, private` en respuestas autenticadas | `app/Http/Middleware/SecurityHeaders.php` |
| CSV / fórmulas | prefijo `'` para `=`, `+`, `-`, `@`, tabulador, CR; texto siempre entre comillas; la importación nunca evalúa celdas | `app/Support/CsvWriter.php`, `app/Services/RosterImporter.php` |
| PII handling | nombres (y número escolar opcional) de estudiantes menores; solo en la base de la dueña; sin terceros; se borran borrando la clase; en dev y pruebas solo nombres inventados | base de datos, `CLAUDE.md` |
| Logging hygiene | nunca se registran contraseñas, hashes, ids de sesión ni valores de `.env`; `APP_DEBUG=false` y `LOG_LEVEL=error` en producción | `.env` de producción, `CLAUDE.md` |
| Agent access to secrets | lectura denegada de `.env`, `.env.local` y `.env.production`; `.env.example` sigue legible; `rm -rf` denegado | `.claude/settings.json` (§19.3) |

**Hard rules**
- No secret is ever committed, printed in a log, sent to an error tracker, or embedded in a client bundle. Anything
  reaching the browser is public — treat it that way.
- All server-side authorization checks run before the work, not after.
- Third-party webhooks are verified by signature before their body is parsed as trusted (no hay ninguno en v1).

**Datos regulados.** Son datos educativos de menores en Ontario. No se añade ningún tratamiento a terceros (sin
analítica, fuentes externas, CDN ni IA); los datos quedan en la cuenta de hosting de la dueña, y el borrado de una clase
los elimina de forma permanente. La dueña es responsable de la política de su centro sobre dónde guardar estos datos;
este blueprint no asume ninguna certificación.

---

## 15. Accessibility

**Target: WCAG 2.2 Level AA.**

### Baseline requirements

| Requirement | Rule |
|---|---|
| Semantic HTML | `header`/`nav`/`main`; un `h1` por página; tablas con `<th scope>` en Weekly/Semester/Roster; listas para listas |
| Keyboard | todo se opera con teclado; orden lógico; sin trampas; enlace "Skip to content" a `#main`; Enter en la búsqueda enfoca la primera coincidencia |
| Focus visible | anillo de 3px `--primary` con 2px de separación (≥ 3:1 en ambos temas) |
| Contrast | la paleta de §7 cumple 4.5:1 en texto y 3:1 en bordes de controles |
| Forms | cada campo tiene `<label>`; errores como texto junto al campo y en `role="alert"`; nunca solo color |
| Images | sin imágenes de contenido; los iconos decorativos llevan `aria-hidden="true"` |
| Motion | todo respeta `prefers-reduced-motion: reduce` |
| Zoom / reflow | usable al 200 % y a 320 px sin desplazamiento horizontal (rejilla de 1 columna) |
| Live regions | `#save-pill` con `role="status"` y `aria-live="polite"`; `#search-count` con `aria-live="polite"` |

### WCAG 2.2 additions — the ones most often missed

| SC | Requirement |
|---|---|
| 2.4.11 Focus Not Obscured (Min) | la cabecera no es fija sobre el contenido enfocado; los diálogos usan `<dialog>` modal nativo |
| 2.5.7 Dragging Movements | no hay arrastre en ninguna parte |
| 2.5.8 Target Size (Min) | +/− de tarjeta 56×56 px; todo lo demás ≥ 44 px de alto (supera 24×24) |
| 3.3.7 Redundant Entry | la importación conserva la vista previa por token; nada se vuelve a escribir |
| 3.3.8 Accessible Authentication (Min) | email + contraseña con `autocomplete`, se permite pegar y gestores de contraseñas |

Además: los botones que actúan sobre un estudiante incluyen su nombre en el nombre accesible
(`Add one point to Alex Rivera`); el estado de cada tarjeta está en texto (`Not recorded`, `Present · 0`, `Present`,
`Absent`); las celdas de calor muestran siempre número, `A` o `—`.

### Verification
```bash
docker compose exec -T app vendor/bin/phpunit tests/Feature/LayoutShellTest.php   # expect: exit 0 — skip link, nav, no inline
docker compose exec -T app vendor/bin/phpunit tests/Feature/DailyPageTest.php     # expect: exit 0 — accessible names, aria-live, disabled states
```
Las pruebas Feature afirman el contrato del DOM (nombres accesibles, `aria-live`, `disabled`, textos de estado).
**No hay axe ni automatización de navegador en el gate automático.** Antes del lanzamiento se hacen a mano (§20.1): pase
solo con teclado de Daily/Weekly/Semester/Roster, un pase con lector de pantalla sobre Daily, y un pase al 200 % en el
ancho más estrecho, en ambos temas.

---

## 16. Observability & Cost

### Instrumentation

| Signal | Tool | What it captures | Who looks at it |
|---|---|---|---|
| Errors | log de Laravel (`storage/logs/laravel.log`, canal `stack` → `single`) | excepciones no controladas con contexto de petición, sin secretos | la dueña, cuando algo falla |
| Logs | el mismo archivo; nivel `debug` en local, `error` en producción | errores y avisos | la dueña |
| Metrics | ninguno automático; consultas SQL manuales en phpMyAdmin (abajo) | los números de la tabla siguiente | la dueña, mensualmente |
| Uptime | `/up` (Laravel), comprobable a mano o con un monitor gratuito que elija la dueña | que la app arranca | la dueña |

No hay rastreador de errores externo: enviaría datos de menores a un tercero y el volumen (una usuaria) no lo justifica.

### The metrics that matter for this project

| Metric | Target | Alert at |
|---|---|---|
| Días lectivos con registro por clase (`SELECT school_class_id, COUNT(DISTINCT work_date) FROM participation_entries GROUP BY school_class_id`) | todos los días de clase | una semana sin registros en una clase activa |
| Guardados fallidos (líneas `ERROR` en `laravel.log`) | 0 por semana | cualquier línea nueva |
| Bloqueos de acceso (respuestas 429 de login) | 0 | más de 3 en un día (posible intento de acceso) |
| Tamaño de la base (phpMyAdmin) | muy por debajo de 3 GB | 1 GB |

### Health check
`/up` (ruta de salud de Laravel) confirma que la aplicación arranca y responde 200; no comprueba la base de datos. La
comprobación de base de datos la hace `verify-release.sh` en local (`migrate:status` y `/login`, que escribe una sesión
en la base). Nadie la sondea automáticamente en v1.

### Cost model

| Service | Free tier | Cost at v1 scale (1 docente) | Cost at 10× | Cliff to watch |
|---|---|---|---|---|
| Hostinger (plan existente de la dueña) | — | coste actual del plan de la dueña, sin cambio | igual | inodos (Single 200,000) y bases de datos (Single: 2) |
| Docker Desktop (local) | uso personal | 0 | 0 | licencia si se usa en una organización grande |

**Estimated monthly cost at launch: 0 adicional** — se usa el plan que la dueña ya paga y ningún servicio extra. La
partida mayor es el propio plan; nada escala de forma superlineal.

---

## 17. Model Routing

NOT APPLICABLE — this project does not call an LLM at runtime.

---

## 18. Skills to Use During Build

Nombres e instalación copiados de `knowledge/skills-registry.md`. Ninguna es obligatoria: si una no está instalada, el
constructor sigue la guía de este blueprint, lo anota en una línea y continúa.

| Skill | Build steps | Why | Install |
|---|---|---|---|
| ui-ux-pro-max | 8, 9 | estilo de componentes densos (tarjetas, tablas, diálogos) sobre la paleta fija de §7, sin cambiar un solo valor | `/plugin marketplace add nextlevelbuilder/ui-ux-pro-max-skill` then `/plugin install ui-ux-pro-max@ui-ux-pro-max-skill` |
| frontend-design | 9, 17, 19, 20, 21 | maquetación de Daily, Weekly, Semester e historial con calidad de producción, respetando CSP y §7 | `/plugin marketplace add anthropics/skills` then `/plugin install example-skills@anthropic-agent-skills` |

Ambas se activan solas por intención (sin barra). No se recomiendan `playwright-cli` (no hay E2E en v1) ni skills de
SEO (sitio privado).

---

## 19. Agent Workspace

En modo bundle, los artefactos son **archivos reales** en `blueprints/classpulse/workspace/`, que refleja exactamente la
estructura del proyecto. Se copian una sola vez con la primera línea de Bootstrap:

```bash
rsync -a --ignore-existing blueprints/classpulse/workspace/ ./   # never overwrites files the build changed; exits 0 when it skips (unlike BSD cp -n)
```

`--ignore-existing` hace que una segunda ejecución no sobrescriba nada y salga con 0 (a diferencia de `cp -Rn`, que en
macOS/BSD sale con 1 al saltarse un archivo). Nunca se sobrescriben `composer.json`, `composer.lock`, `.env` ni ningún
archivo que un paso haya editado. Ningún archivo de `workspace/` es PHP, así que Pint no los inspecciona; además
`pint.json` excluye `blueprints` y `dist`. `.claude/commands/` no se emite.

```
blueprints/classpulse/workspace/
├── CLAUDE.md                         # §19.1
├── AGENTS.md                         # §19.2
├── Dockerfile  .dockerignore  docker-compose.yml  phpunit.xml  pint.json  .env.example  .gitignore   # §19.6
├── tests/Fixtures/weekly-export.csv  # §19.6 (byte-exact)
└── .claude/
    ├── settings.json                 # §19.3
    ├── skills/add-migration/SKILL.md  skills/add-participation-operation/SKILL.md  skills/release-package/SKILL.md   # §19.4
    └── rules/database.md  frontend.md  security.md  calculations.md   # §19.5
```

### 19.1 `CLAUDE.md`

Archivo real: `workspace/CLAUDE.md` (162 líneas, por debajo del tope de 200; la tabla de comandos va primero, con las
formas Docker, seguida del gate en tres líneas —la de JS con su comprobación de recuento—; luego stack con el pin del
esqueleto 13.10.1, arquitectura con la ruta de una petición real
`routes/web.php → app/Http/Controllers/Api/DayController.php → app/Services/ParticipationService.php → app/Models/*`,
tabla de fronteras, dónde vive cada cosa, convención de resolución, reglas de código, tokens de diseño literales,
entorno, tabla de reglas y no negociables). El archivo es la fuente; este apartado no lo duplica para que no diverjan.
No negociables que contiene: tocar solo el proyecto Compose `classpulse` (nunca otros contenedores o volúmenes, nunca
`docker compose down -v` sin preguntar); nunca versionar `.env`; nunca añadir Node/npm/Vite/bundler/CDN/fuente web/IA;
nada inline ni `{!!` en vistas; solo estudiantes ficticios y nada de dinero; nunca publicar en Hostinger; nunca marcar
una tarea con un gate en rojo ni editar un comando de verificación.

### 19.2 `AGENTS.md`

Archivo real: `workspace/AGENTS.md` — puente neutral para agentes que no son Claude Code: descripción de una línea,
tabla de comandos, gate en tres líneas (la de JS con recuento), los siete no negociables y el puntero a `CLAUDE.md`
como fuente de verdad.

### 19.3 `.claude/settings.json`

Archivo real: `workspace/.claude/settings.json`, idéntico a este bloque. Cada comando `Verify` de §9, cada **Do** que
el constructor ejecuta y cada comando de §20.1 (incluidas las comprobaciones manuales) está formado solo por comandos
con prefijo en `allow`: `docker compose exec` (incluye `php -l`, `artisan model:show`, `artisan list`,
`artisan route:list`, `phpunit --list-tests`), `docker compose --profile test run` (la suite JS y `node --check`),
`docker compose up`, `curl`, `test`, `grep`, `ls`, `wc`, `tr`, `unzip`, `git tag`, `git log`, `git ls-files`,
`git check-ignore`, `./scripts/package-release.sh`, `./scripts/verify-release.sh`, más las tres formas exactas de `rm`
del paso 1 (`rm -f …` con la lista literal, `rm -r resources/js`, `rm -r resources/css`). `rm -rf` sigue denegado. La
lectura de `.env`, `.env.local` y `.env.production` está denegada; `.env.example` (versionado) sigue legible.

```json
{
  "permissions": {
    "allow": [
      "Bash(docker compose exec:*)",
      "Bash(docker compose --profile test run:*)",
      "Bash(docker compose up:*)",
      "Bash(docker compose ps:*)",
      "Bash(docker compose logs:*)",
      "Bash(docker compose stop:*)",
      "Bash(curl:*)",
      "Bash(rsync:*)",
      "Bash(unzip:*)",
      "Bash(zip:*)",
      "Bash(test:*)",
      "Bash(grep:*)",
      "Bash(ls:*)",
      "Bash(wc:*)",
      "Bash(tr:*)",
      "Bash(cp .env.example .env)",
      "Bash(rm -f package.json vite.config.js resources/views/welcome.blade.php tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php database/migrations/*_create_cache_table.php database/migrations/*_create_jobs_table.php)",
      "Bash(rm -r resources/js)",
      "Bash(rm -r resources/css)",
      "Bash(git status:*)",
      "Bash(git diff:*)",
      "Bash(git log:*)",
      "Bash(git add:*)",
      "Bash(git commit:*)",
      "Bash(git tag:*)",
      "Bash(git init:*)",
      "Bash(git rev-parse:*)",
      "Bash(git ls-files:*)",
      "Bash(git check-ignore:*)",
      "Bash(chmod +x scripts/package-release.sh scripts/verify-release.sh)",
      "Bash(./scripts/package-release.sh)",
      "Bash(./scripts/verify-release.sh)"
    ],
    "deny": [
      "Read(./.env)",
      "Read(./.env.local)",
      "Read(./.env.production)",
      "Bash(git push:*)",
      "Bash(docker stop:*)",
      "Bash(docker rm:*)",
      "Bash(docker volume rm:*)",
      "Bash(docker system prune:*)",
      "Bash(docker compose down -v:*)",
      "Bash(rm -rf:*)"
    ]
  }
}
```

### 19.4 Project skills — `.claude/skills/<name>/SKILL.md`

| Skill | Triggers on | What it automates |
|---|---|---|
| `add-migration` | "add a column", "new table", "change the schema", "add an index" | nueva migración con `make:migration`, aplicarla en dev, comprobar `migrate:status` sin `Pending` (falla también si el contenedor está parado), probar la restricción en `SchemaConstraintsTest`, sin editar migraciones ya ejecutadas |
| `add-participation-operation` | "new operation kind", "change increment", "fix undo", "add a batch action" | tabla de transiciones → servicio (bloqueo, eventos) → `DayOperationRequest` → pruebas de servicio, deshacer, repetición y API → si hay botón, `CARD_ACTIONS` + `data-action` + suite JS con recuento |
| `release-package` | "a release", "the Hostinger files", "package it", "rebuild the zips" | gate (con recuento JS) → `composer audit` (manual, con red) → `package-release.sh` → `verify-release.sh`; nunca sube nada |

### 19.5 `.claude/rules/*.md`

| File | `paths` globs | Covers |
|---|---|---|
| `.claude/rules/database.md` | `database/**`, `app/Models/**` | sintaxis MariaDB 10.3-10.11, migraciones nunca editadas, CHECK con nombre fijo, identidad de entradas, `$fillable`, único escritor, nombres inventados |
| `.claude/rules/frontend.md` | `resources/views/**`, `public/**` | sin Node/CDN/fuentes, CSP sin inline, `{{ }}` solo, scripts clásicos UMD, tokens, estado en texto, tamaños táctiles, diálogos, tema, movimiento |
| `.claude/rules/security.md` | `app/Http/**`, `routes/**` | grupo `auth`, sin registro ni reset, limitador de login, FormRequests, `{class}` como única clave, bindings, CSRF de Laravel 13, sobre JSON, cabeceras, CSV seguro, higiene de logs |
| `.claude/rules/calculations.md` | `app/Support/**`, `app/Services/**`, `tests/**` | semántica de filas, fórmulas, `No data`, promedio de semestre, redondeo solo al mostrar, deltas e idempotencia, bloqueo, eventos, fechas, límites, reglas de pruebas |

### 19.6 Verify-critical config and local infrastructure

Todos los archivos que los comandos `Verify` necesitan para ejecutarse existen como archivos reales con contenido
completo en `workspace/` y llegan al proyecto con la copia de Bootstrap (antes del primer commit, del primer build y del
primer `pint --test`). Servicios que exigen los `Verify` — los cuatro elementos obligatorios:
1. **Quién lo arranca:** `docker-compose.yml` con etiquetas fijadas (`mariadb:10.11`, `node:24-alpine`; la app se
   construye desde `Dockerfile` con `php:8.3-cli-bookworm` y `composer:2.10.3`), volumen con nombre `db_data` y
   healthcheck `healthcheck.sh --connect --innodb_initialized`; la app espera a `service_healthy` y además repite
   `until php artisan --version` antes de servir (lección aprendida: esperar solo a `vendor/autoload.php` competía con un
   `vendor/` a medio copiar).
2. **La variable que apunta a él:** `DB_HOST=db`, `DB_PORT=3306`, `DB_DATABASE=classpulse` en `.env.example` (§10);
   las pruebas usan `classpulse_test` desde `phpunit.xml`; nunca comparten base con dev.
3. **Los comandos:** subir `docker compose up -d --build` (Bootstrap, `CLAUDE.md`); detener `docker compose stop`;
   reiniciar la base dev solo con permiso de la dueña (borra datos locales).
4. **El permiso:** `Bash(docker compose up:*)`, `Bash(docker compose exec:*)`, `Bash(docker compose stop:*)`,
   `Bash(docker compose --profile test run:*)` en §19.3.

| File | Path in the project | Which `Verify` commands need it | Resolution/env handling it carries | Bundle-path exclusion |
|---|---|---|---|---|
| `Dockerfile` | `/Dockerfile` | todos (contenedor `app`: `php`, `composer`, `curl`, `pdo_mysql`, `zip`) | none needed: every mandated package resolves plainly and this tool reads no env var | `.dockerignore` reduce el contexto a `Dockerfile`, así que `docker build` nunca lee `blueprints/` |
| `.dockerignore` | `/.dockerignore` | build de `app` en §10 | ninguna | línea literal `*` (excluye todo, incluido `blueprints/`) seguida de `!Dockerfile` |
| `docker-compose.yml` | `/docker-compose.yml` | todos los `docker compose …` de §9, §10, §20.1 y los scripts del paso 24 | Compose lee `.env` de la raíz automáticamente solo para `CLASSPULSE_WEB_PORT`/`CLASSPULSE_DB_PORT` (con valores por defecto 8090/33061 si falta); el servicio `jstest` ejecuta por defecto `node --test tests/js/*.test.js` y los pasos 8 y 18 le pasan `node --check <archivo>` como comando | n/a — Compose no recorre el árbol; monta `.:/app` y el glob de `jstest` es `tests/js/*.test.js`, que nunca alcanza `blueprints/` |
| `phpunit.xml` | `/phpunit.xml` | todos los `vendor/bin/phpunit` (pasos 1-25), incluidos los `--list-tests` de recuento positivo | fuerza con `force="true"` `DB_CONNECTION=mariadb`, `DB_HOST=db`, `DB_DATABASE=classpulse_test`, `DB_USERNAME`/`DB_PASSWORD` locales, `SESSION_DRIVER=array`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `APP_ENV=testing`, `APP_TIMEZONE=America/Toronto`, `BCRYPT_ROUNDS=4`, `MAIL_MAILER=array`; `bootstrap="vendor/autoload.php"`; `failOnEmptyTestSuite="true"` (una ejecución sin ninguna prueba sale con fallo); `APP_KEY` lo carga Laravel desde `.env` | n/a — this tool never walks the tree: solo recorre `tests/Unit`, `tests/Feature` y `<source>` `app` |
| `pint.json` | `/pint.json` | `vendor/bin/pint --test` (todos los pasos PHP) | preset `laravel` | `"exclude": ["blueprints", "dist"]` |
| `.env.example` | `/.env.example` | Bootstrap (`cp .env.example .env`), que alimenta `artisan`, el servidor y los `curl` | Laravel carga `.env` con Dotenv en cada `artisan`/petición; Compose lo lee para interpolar puertos | n/a — datos, no recorre el árbol |
| `.gitignore` | `/.gitignore` | Checkpoints (`git add -A`) y las comprobaciones de §20.1 | ninguna | `blueprints/` se versiona a propósito (no aparece); `/dist/` sí se ignora |
| `tests/Fixtures/weekly-export.csv` | `/tests/Fixtures/weekly-export.csv` | paso 19 (`WeeklyReportTest`) y `test -f` del paso 19 | ninguna | n/a — archivo de datos |

**Loaders de variables de entorno (herramientas que no son el framework):** Docker Compose (interpolación de puertos:
lee `.env` de la raíz automáticamente, en cada llamada); `scripts/package-release.sh` y `scripts/verify-release.sh`
(solo leen `CLASSPULSE_APP_DIR` opcional del shell, con valor por defecto en el propio script; todo lo demás son valores
literales locales, y `DB_DATABASE=classpulse_release` se pasa con `docker compose exec -e`); `node --test` y
`node --check` (no leen variables); `mariadb`/`mariadb-dump` (credenciales en la propia línea `-uroot -p…`). `artisan` y
PHPUnit son el framework: Laravel carga `.env` y `phpunit.xml` fuerza sus valores.

#### Resolution convention matrix

**The convention, stated once:** PHP resuelve clases por PSR-4 según `composer.json` del esqueleto (`App\\` → `app/`,
`Database\\Factories\\` → `database/factories/`, `Database\\Seeders\\` → `database/seeders/`, `Tests\\` → `tests/`),
vistas por nombre con punto bajo `resources/views`, y componentes Blade `x-layouts.app` → `resources/views/components/layouts/app.blade.php`.
JavaScript: scripts clásicos sin `import`/`export`; `public/js/save-queue.js` termina con el pie UMD
`if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { globalThis.ClassPulseSaveQueue = api; }`,
y las pruebas lo cargan con `require('../../public/js/save-queue.js')`.

| Context | Command that exercises it | Convention as it appears there | Config + literal setting that makes it work |
|---|---|---|---|
| Application source | `docker compose exec -T app php artisan route:list` y `curl http://localhost:8090/login` | `use App\Services\ParticipationService;`, `view('daily.index')`, `<x-layouts.app>` | `composer.json` (scaffold) — `"autoload": {"psr-4": {"App\\": "app/"}}` junto a las entradas de `Database\\`; autoloader de Composer generado en Bootstrap |
| Models | `docker compose exec -T app php artisan model:show SchoolClass` (paso 3) | `App\Models\SchoolClass` resuelto desde el nombre corto | mismo `autoload` PSR-4; `model:show` antepone `App\Models` |
| Test files (PHP) | `docker compose exec -T app vendor/bin/phpunit` y `--list-tests <archivo>` | `namespace Tests\Feature;`, `use Tests\TestCase;` | `phpunit.xml` — `bootstrap="vendor/autoload.php"`; `composer.json` — `"autoload-dev": {"psr-4": {"Tests\\": "tests/"}}` |
| Test files (JS) | `docker compose --profile test run --rm jstest` | `const api = require('../../public/js/save-queue.js');` | sin `package.json` en el proyecto ⇒ Node trata `.js` como CommonJS; el archivo no contiene sintaxis ESM, así que la detección automática de módulos de Node 24 no lo reinterpreta; `docker-compose.yml` — `command: ["node", "--test", "tests/js/*.test.js"]` |
| JS syntax check | `docker compose --profile test run --rm jstest node --check public/js/<archivo>.js` (pasos 8 y 18) | los cinco scripts clásicos de `public/js` | el comando pasado tras el nombre del servicio sustituye el `command` de `jstest`; sin `package.json` ⇒ se analizan como CommonJS/script, válido para scripts clásicos |
| Browser | carga de `/daily` | `<script src="/js/save-queue.js" defer>` define `globalThis.ClassPulseSaveQueue` | `resources/views/daily/index.blade.php` (paso 18) — tres scripts `defer` en orden save-queue, dialogs, daily; CSP `script-src 'self'` |
| Standalone scripts | `./scripts/package-release.sh`, `./scripts/verify-release.sh` | bash que llama a `docker compose exec`; no importa módulos | ninguno necesario |
| Build / bundle | `./scripts/package-release.sh` (`composer install --no-dev --optimize-autoloader`) | mismo PSR-4, convertido en classmap | `composer.json` — mismo `autoload`; `config.platform.php` = `8.3.0` |
| Hostinger runtime (Layout B) | `./scripts/verify-release.sh` (sirve `dist/verify/public_html` con `php -S`) | `public_html/index.php` requiere `__DIR__.'/../classpulse-app/vendor/autoload.php'` | `sed` del paquete reescribe las 3 líneas `__DIR__.'/../` del `public/index.php` del esqueleto 13.10.1 y el script lo comprueba con `grep -c … -eq 3` |

#### Cross-artifact value reconciliation

| Shared value | Single source — the file that decides it | Literal value | Every other place it appears | Compared |
|---|---|---|---|---|
| Pin del esqueleto | §10 Bootstrap — `composer create-project laravel/laravel /tmp/skeleton "13.10.1"` | `13.10.1` | §11 fila `laravel/laravel` (versión e `Installed by`) · criterio 2 del paso 24 y bloque E3-T6 · `workspace/CLAUDE.md` (Stack) · `epics/01-foundation-access.md` (Stack) · bloque E3-T7 (`docs/local-development.md`) · §3 y §19.6 (matriz, fila Hostinger) | yes |
| Puerto web en el host | `docker-compose.yml` — `ports` de `app` | `8090` (`${CLASSPULSE_WEB_PORT:-8090}`) | `.env.example` `CLASSPULSE_WEB_PORT=8090` · `.env.example` `APP_URL=http://localhost:8090` · `workspace/CLAUDE.md` · `workspace/AGENTS.md` · §9 pasos 1, 6, 7, 8, 11, 16, 17, 18, 19, 20, 21, 23 (`curl http://localhost:8090/…`) · §20.1 · epics | yes |
| Puerto del servidor en el contenedor | `docker-compose.yml` — `command` de `app` (`--port=8000`) | `8000` | `docker-compose.yml` `ports` (`:8000`) | yes |
| Puerto de MariaDB en el host | `docker-compose.yml` — `ports` de `db` | `33061` (`${CLASSPULSE_DB_PORT:-33061}`) | `.env.example` `CLASSPULSE_DB_PORT=33061` · `workspace/CLAUDE.md` | yes |
| Host de la base | `docker-compose.yml` — nombre del servicio | `db` | `.env.example` `DB_HOST=db` · `phpunit.xml` `DB_HOST` · `docker compose exec -T db …` en §10 y scripts · `.env` temporal de `verify-release.sh` | yes |
| Base dev | `docker-compose.yml` — `MARIADB_DATABASE` | `classpulse` | `.env.example` `DB_DATABASE=classpulse` | yes |
| Base de pruebas | `phpunit.xml` — `DB_DATABASE` | `classpulse_test` | §10 Bootstrap `CREATE DATABASE IF NOT EXISTS classpulse_test` · `EnvironmentGuardTest` (paso 1) · `workspace/CLAUDE.md` · `workspace/.claude/rules/calculations.md` | yes |
| Base del paquete | `scripts/package-release.sh` | `classpulse_release` | §10 tabla de variables · §4 *Migrations* · `workspace/.claude/rules/database.md` | yes |
| Base de verificación | `scripts/verify-release.sh` — `VERIFY_DB` | `classpulse_verify` | paso 24 criterios 4 y 6 · §10 · `workspace/.claude/skills/release-package/SKILL.md` | yes |
| Usuario / contraseña locales | `docker-compose.yml` — `MARIADB_USER`, `MARIADB_PASSWORD` | `classpulse` / `classpulse_local` | `.env.example` · `phpunit.xml` · `.env` de `verify-release.sh` · `GRANT … TO 'classpulse'@'%'` en §10 y scripts | yes |
| Contraseña root local | `docker-compose.yml` — `MARIADB_ROOT_PASSWORD` | `classpulse_local_root` | §10 Bootstrap (`-pclasspulse_local_root`) · `DBROOT` en ambos scripts | yes |
| Imágenes | `Dockerfile` / `docker-compose.yml` | `php:8.3-cli-bookworm`, `composer:2.10.3`, `mariadb:10.11`, `node:24-alpine` | §11 | yes |
| Carpeta de la app en el paquete | `scripts/package-release.sh` — `APP_DIR_NAME` | `classpulse-app` | `scripts/verify-release.sh` · `public_html/index.php` reescrito · `docs/hostinger-deploy.md` · §12 · paso 24 | yes |
| Artefactos de publicación | `scripts/package-release.sh` | `dist/classpulse-app.zip`, `dist/classpulse-public_html.zip`, `dist/install.sql` | `scripts/verify-release.sh` · paso 24 · §3 · `.gitignore` (`/dist/`) · `pint.json` (`dist`) · `scripts/release-exclude.txt` (`/dist/`) · `workspace/.claude/skills/release-package/SKILL.md` | yes |
| Ruta del bundle | ubicación del bundle | `blueprints/` (`blueprints/classpulse/`) | `pint.json` (`blueprints`) · `scripts/release-exclude.txt` (`/blueprints/`) · `verify-release.sh` (patrón `blueprints/`) · §10 Bootstrap (`rsync … blueprints/classpulse/workspace/`) · `workspace/CLAUDE.md` · `workspace/AGENTS.md` · `workspace/.claude/skills/release-package/SKILL.md` | yes |
| Glob de pruebas JS | `docker-compose.yml` — `command` de `jstest` | `tests/js/*.test.js` | paso 18 (`tests/js/save-queue.test.js`) · `workspace/CLAUDE.md` · epics 02 y 03 | yes |
| Comprobación de la suite JS | `workspace/CLAUDE.md` — gate | `docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt` | §9 (preámbulo y paso 18) · `tasks.json` E2-T9 · §20.1 · `workspace/AGENTS.md` · `workspace/.claude/skills/release-package/SKILL.md` · `workspace/.claude/skills/add-participation-operation/SKILL.md` · epics 02 y 03 | yes |
| Lista de acciones de tarjeta | `public/js/daily.js` — `CARD_ACTIONS` (paso 18) | `'increment', 'decrement', 'set_zero', 'absent_on', 'absent_off'` | `resources/views/daily/card.blade.php` (`data-action`, paso 17) · tabla de contrato DOM del paso 17 · gate del paso 18 · §5 transiciones | yes |
| Fixture semanal | archivo emitido | `tests/Fixtures/weekly-export.csv` | paso 19 (`test -f` y `WeeklyReportTest`) · §5 regla de `student_number` · §19.6 byte-exact | yes |
| Cupo máximo | `config/classpulse.php` — `max_roster` | `30` | CHECK `roster_cap BETWEEN 1 AND 30` (§4) · `StoreClassRequest` (paso 10) · `EnvironmentGuardTest` · mensajes del paso 12 | yes |
| Puntos máximos | `config/classpulse.php` — `max_points` | `99` | CHECK `points <= 99` · tabla de transiciones §5 · mensaje `Points cannot go above 99.` | yes |
| Zona horaria | `.env.example` — `APP_TIMEZONE` | `America/Toronto` | `phpunit.xml` · `config/app.php` y `config/classpulse.php` (defecto) · `.env` de `verify-release.sh` | yes |
| CSP | `app/Http/Middleware/SecurityHeaders.php` | la cadena de §14 | criterio 1 del paso 6 · `SecurityHeadersTest` · §14 · `.claude/rules/security.md` | yes |
| Puerto de verificación | `scripts/verify-release.sh` — `PORT` | `8001` | paso 24 criterio 5 · §10 · `workspace/.claude/skills/release-package/SKILL.md` | yes |
| Clave de tema en `localStorage` | `public/js/theme-init.js` y `public/js/theme.js` (paso 8) | `classpulse-theme` | §6 · §7 · criterio del paso 8 y su `grep -qF` · `epics/01-foundation-access.md` · `workspace/.claude/rules/frontend.md` | yes |
| Correo de la cuenta local | `workspace/CLAUDE.md` (comando `classpulse:create-teacher`) | `teacher@classpulse.test` | §4 (semilla demo) · §8 · pasos 5 y 15 · `epics/01-foundation-access.md` · `epics/02-roster-daily-tracking.md` | yes |
| Clave de sesión de la clase actual | `app/Support/CurrentClass.php` (paso 9) | `classpulse.current_class_id` | §6 · paso 9 · `epics/01-foundation-access.md` · `epics/02-roster-daily-tracking.md` | yes |

Contratos entre artefactos y el primer paso que los ejerce (fail fast): `docker-compose.yml` ↔ servidor servido → paso 1
(`curl …/up`); `phpunit.xml` ↔ base de pruebas de Bootstrap → paso 1 (`EnvironmentGuardTest`); `pint.json` ↔ árbol con
bundle → paso 1 (`pint --test`); migración ↔ modelos → paso 3 (`model:show`); modelos ↔ factories → paso 4;
`SecurityHeaders` ↔ servidor → paso 6 (`curl -sI`); ruta `login` ↔ vista → paso 7 (`curl …/login`); assets ↔ servidor
estático y scripts de tema ↔ Node → paso 8 (`curl` + `node --check`); `tokens.css` ↔ `LayoutShellTest` → paso 9;
`DayController` ↔ servidor → paso 16 (`curl` 401); vista Daily ↔ `daily.js` (contrato DOM) y `save-queue.js` ↔ vista ↔
servidor estático → paso 18; `weekly-export.csv` ↔ `CsvWriter` → paso 19; `package-release.sh` ↔ `verify-release.sh` ↔
`index.php` reescrito del esqueleto 13.10.1 → paso 24.

#### Byte-exact artifact reconciliation

| Byte-exact artifact | Authored by | First diffed at | Blueprint rules that constrain it | Runtime call that produces it, on the §11 pin | Both confirmed |
|---|---|---|---|---|---|
| `tests/Fixtures/weekly-export.csv` | §19.6 (workspace) | paso 19 | §5 reglas CSV (celda NULL de día vacía, enteros sin comillas, texto entre comillas, `student_number` NULL ⇒ `""` en las tres exportaciones, `'` delante de `=`, LF, línea final con LF, sin BOM, ausencia `"A"`) · §4 fórmulas (Alex: 3+0+5 = 8, 3 días presentes, 1 ausencia; Robin 1+2 = 3 en 2 días; Morgan 4 en 1 día; Jordan sin filas ⇒ `No data`) · §4 orden de lista (`strcmp` sobre minúsculas: `=` 0x3D < `a` 0x61, así que `=Robin Sky` va primero) · §4 sufijo ` (archived)` solo con entradas en el periodo · Casey Moon de otra clase excluido | `number_format(8/3, 2, '.', '')` ⇒ `2.67`; `number_format(1.5, 2, '.', '')` ⇒ `1.50`; `number_format(4, 2, '.', '')` ⇒ `4.00` en PHP 8.3 (`php:8.3-cli-bookworm`) — comando exacto en los huecos entregados al hilo principal | yes (reglas leídas campo por campo; literales de runtime entregados para ejecutar) |
| Cabecera y nombre de archivo del CSV semestral | paso 20 (prueba) | paso 20 | §5 reglas CSV y de nombre de archivo (slug `HNL-2O`, periodo `2026-09-08-to-2026-10-26` = inicio del semestre hasta hoy congelado, `exported-2026-10-26`); nombres de columna de §4 | ninguno: texto propio y fechas del reloj congelado, sin salida variable del runtime | yes |
| Cabecera, línea de ejemplo y nombre de archivo del CSV por estudiante | paso 21 (prueba) | paso 21 | §5 reglas CSV y de nombre (slug de clase y de estudiante `Alex-Rivera`); `"Monday"` para `2026-10-19` | `(new DateTimeImmutable('2026-10-19'))->format('l')` ⇒ `Monday` en PHP 8.3 — comando en los huecos | yes (runtime entregado para ejecutar) |
| Cadena CSP | paso 6 (middleware y prueba) | paso 6 | §14 | ninguno: cadena propia | yes |
| Texto de fecha `Wednesday, Oct 21, 2026` | paso 17 (prueba) | paso 17 | §6 formato `l, M j, Y` | `(new DateTimeImmutable('2026-10-21'))->format('l, M j, Y')` ⇒ `Wednesday, Oct 21, 2026` en PHP 8.3 — comando en los huecos | yes (runtime entregado para ejecutar) |
| Promedio `2.67` en `ParticipationStatsTest` | paso 13 | paso 13 | §4 redondeo solo al mostrar | igual que la fila 1 | yes (runtime entregado para ejecutar) |
| `Laravel Framework 13.` en el paso 1 | paso 1 | paso 1 | §11 | `php artisan --version` ⇒ `Laravel Framework 13.34.0` (observado por el hilo principal con el esqueleto 13.10.1) | yes |
| Recuento `pass`/`fail` de la suite JS | gate del paso 18 y §20.1 | paso 18 | §9 preámbulo (patrón que acepta los reporteros `spec` y `tap`) | `node --test` en `node:24-alpine` imprime `ℹ pass N` / `ℹ fail N` (observado por el hilo principal: `tests 0 / pass 0 / fail 0` con cero archivos) | yes (formato con archivos reales entregado para ejecutar) |

---

## 20. Acceptance Gate, Risks & Decision Log

### 20.1 Global acceptance gate

El proyecto está **terminado** cuando cada comando siguiente sale con 0 desde la raíz del proyecto, con el bundle
presente, y no antes.

```bash
docker compose up -d --build                                                          # expect: exit 0, db healthy
docker compose exec -T app vendor/bin/pint --test                                     # expect: exit 0
docker compose exec -T app vendor/bin/phpunit                                         # expect: exit 0, 0 failures, 0 errors (failOnEmptyTestSuite: an empty run fails)
docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt   # expect: exit 0 — at least one JS test passed, none failed
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/up)" = 200       # expect: exit 0 — the served entry point runs
test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/login)" = 200    # expect: exit 0
./scripts/package-release.sh                                                          # expect: exit 0
./scripts/verify-release.sh                                                           # expect: exit 0 — the packaged release boots (/up and /login 200)
```

Cada expectativa es una propiedad, no un recuento, salvo el número de etiquetas de abajo, que es el número de pasos de
§9 (25, contado en el step map, en `tasks.json` y en las tres epics: 9 + 9 + 7) y la exigencia de al menos una prueba
JS. Ninguna línea acepta "cualquier salida distinta de cero" como éxito.

Manual gates (local, una vez tras el paso 25; cada línea sale con 0 cuando la condición se cumple):

- [ ] Cada paso de §9 tiene su etiqueta: `test "$(git tag -l 'step-*' | wc -l | tr -d ' ')" = 25`. El repositorio lo
      crea el Bootstrap de §10.
- [ ] Cada archivo de la tabla *Files that must be committed* está versionado, **una ruta por invocación**:
      `git ls-files --error-unmatch .env.example`, `git ls-files --error-unmatch .gitignore`,
      `git ls-files --error-unmatch .claude/settings.json`, `git ls-files --error-unmatch phpunit.xml`,
      `git ls-files --error-unmatch pint.json`, `git ls-files --error-unmatch composer.lock`,
      `git ls-files --error-unmatch tests/Fixtures/weekly-export.csv`,
      `git ls-files --error-unmatch scripts/release-exclude.txt`.
- [ ] Ninguno está ignorado por las reglas (una ruta por línea, afirmando el código exacto; `--no-index` evalúa las
      reglas aunque el archivo ya esté versionado): `git check-ignore -q --no-index .env.example; test $? -eq 1`,
      `git check-ignore -q --no-index .claude/settings.json; test $? -eq 1`,
      `git check-ignore -q --no-index tests/Fixtures/weekly-export.csv; test $? -eq 1`
      (1 = ninguna regla la ignora; 0 = ignorada; 128 = error de uso — tanto 0 como 128 hacen fallar la línea).
- [ ] El archivo ignore estaba antes del primer commit:
      `test "$(git log --diff-filter=A --format=%s -- .gitignore)" = "chore: scaffold"`.
- [ ] Todas las filas de *Byte-exact artifact reconciliation* dicen `yes` y los literales de runtime entregados al hilo
      principal se ejecutaron sobre el pin de §11 y coincidieron.
- [ ] El bloque Bootstrap de §10 se volvió a ejecutar sobre el árbol ya construido, **salió con 0**, y a continuación
      `docker compose exec -T app vendor/bin/phpunit` sigue saliendo con 0 (`composer.json` y `composer.lock` intactos).
- [ ] Todas las filas de *Cross-artifact value reconciliation* dicen `Compared: yes`, y `pint --test` y `phpunit` se
      ejecutaron desde la raíz con `blueprints/` presente.
- [ ] §9.1 no aplica (proyecto nuevo).
- [ ] Todos los non-goals de §1 siguen sin construir: `test ! -e package.json` y
      `test "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8090/register)" = 404`.
- [ ] Errores (§16; no hay rastreador externo): el destino del log es escribible —
      `docker compose exec -T app test -w storage/logs` — y la dueña revisa las líneas `ERROR` de
      `storage/logs/laravel.log` cuando exista.

**Post-build launch checklist (NO son tareas; requieren a la dueña o al servidor real):**

- [ ] `docker compose exec -T app composer audit` revisado (necesita red; paso 2 de la skill `release-package`).
- [ ] Revisión visual de Daily, Weekly, Semester y Roster a ~1440, 768 y 390 px, en claro y en oscuro.
- [ ] Pase solo con teclado de los cuatro flujos, un pase con lector de pantalla sobre Daily y un pase al 200 % (§15).
- [ ] **Aprobación explícita de la dueña de la versión local.** Sin ella no se publica nada.
- [ ] Las 9 comprobaciones de hPanel de §12 (`docs/hostinger-deploy.md`).
- [ ] Instalación en el plan real siguiendo Layout B; `/up` y `/login` responden 200 en el dominio con HTTPS.
- [ ] Todas las variables de producción de §10 están en el `.env` del servidor (`APP_ENV=production`, `APP_DEBUG=false`,
      `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`) y ninguna está en el repositorio.
- [ ] En el servidor, `classpulse-app/storage/logs/` es escribible según el File Manager (permisos de la carpeta).
- [ ] Una reversión ensayada una vez: volver a extraer los zips anteriores y comprobar `/up`.
- [ ] Primera exportación manual de la base desde phpMyAdmin descargada al ordenador de la dueña (§12).

**No warnings are ignored.** A tolerated warning becomes a permanent warning, and the next real one hides inside it.

### 20.2 Risk register

| Risk | Likelihood | Impact | Early signal | Mitigation |
|---|---|---|---|---|
| El plan de Hostinger de la dueña es desconocido (sin SSH, sin Composer, raíz fija) | H | M | la lista de hPanel revela plan Single | Layout B diseñada para el peor caso; `vendor/` precompilado; `install.sql`; recuperación sin SSH (dueña) |
| Subir un `vendor/` precompilado no está documentado por Hostinger | M | H | error de extensión o de plataforma al abrir `/up` en el servidor | `platform.php` 8.3.0; revisar extensiones en hPanel; alternativa con SSH `composer2 install` (dueña, post-build) |
| Versión de MariaDB de Hostinger desconocida | M | M | error de sintaxis al importar `install.sql` | suelo 10.11 y regla de no usar sintaxis de 11+; leer la versión en phpMyAdmin antes de importar |
| Suposición de una sola pestaña: dos pestañas pueden mostrar estados distintos | M | L | la docente ve un número "antiguo" en otra pestaña | operaciones delta + idempotencia evitan pérdidas; recargar muestra la verdad; non-goal documentado |
| Empaquetado probado solo con prototipo hasta la instalación real | M | M | `verify-release.sh` pasa pero el servidor devuelve 500 | la verificación replica Layout B con `php -S` y `.env` de producción; ensayo de reversión en la lista de lanzamiento |
| `daily.js` y `dialogs.js` solo se analizan (`node --check`), no se ejecutan en el gate | M | M | un botón de la tarjeta no guarda en el pase manual | contrato DOM comprobado con `grep` en el paso 18; la lógica de guardado vive en `save-queue.js`, que sí se prueba; pase manual antes del lanzamiento |
| Cortes de red largos en el aula con recarga de página | L | M | la docente reporta puntos perdidos tras recargar | aviso `beforeunload`, pill `Save failed — Retry`; persistencia local es un non-goal con disparador |
| Datos de menores en un hosting compartido | L | H | petición del centro sobre dónde se guardan los datos | sin terceros, sin IA, borrado por clase, copias descargadas por la dueña; la política del centro decide (dueña) |
| El prefijo CSV `'` altera nombres legítimos que empiezan por `-` o `+` | L | L | un nombre exportado aparece con `'` | comportamiento documentado; la seguridad frente a fórmulas pesa más |

### 20.3 Decision log

| # | Decision | Rejected alternative | Why | Would reverse if |
|---|---|---|---|---|
| 1 | Laravel 13 + Blade (track rails-laravel, sub-track B) | TypeScript/Node (track por defecto del shape) | Hostinger compartido ejecuta PHP, no Node | la dueña se muda a un VPS o PaaS con Node |
| 2 | Docker Compose para todo lo local | instalar PHP/Composer/MariaDB con Homebrew | en el host no hay nada instalado y Docker Desktop ya existe; aislamiento total del proyecto | Docker deja de estar disponible en la máquina |
| 3 | MariaDB 10.11 como suelo local, sin sintaxis de 11+ | MariaDB 11.x o MySQL 8 | Hostinger usa MariaDB sin publicar versión; 10.11 LTS es conservador | phpMyAdmin muestra una versión menor que 10.3 o mayor con requisitos distintos |
| 4 | PHPUnit 12 | Pest | Pest 5 exige PHP 8.4; Pest 4 añade dependencia sin beneficio | el suelo de PHP sube a 8.4 y el equipo prefiere Pest |
| 5 | Operaciones delta con `op_id` idempotente y bloqueo de la fila de clase | enviar valores absolutos | 50 clics rápidos o reintentos nunca pierden ni duplican puntos | se necesita edición masiva de valores exactos (p. ej. corrección de un día) |
| 6 | Identidad `UNIQUE (school_class_id, student_id, work_date)` + FK compuesta | FK simple a `students` | lo pidió la dueña; impide entradas cruzadas entre clases a nivel de base | un estudiante debe pertenecer a varias clases a la vez |
| 7 | Sin Node nunca; Node solo como contenedor de pruebas desechable | cero pruebas JS, o npm + Vitest | la cola de guardado es la lógica más delicada del cliente y merece pruebas; sin tocar el runtime | la lógica JS crece hasta necesitar un bundler |
| 8 | Layout B en Hostinger | Layout A (todo en `public_html` + `.htaccess`) | `.env` y `vendor/` fuera de la raíz web | el plan permite cambiar la raíz del documento (VPS) |
| 9 | CSS/JS escritos a mano como scripts clásicos | módulos ES o Tailwind | sin build, sin problemas de MIME en hosting compartido, CSP estricta | se añade un paso de build aprobado |
| 10 | CSP sin `unsafe-inline` y prueba que escanea vistas | CSP permisiva | defensa en profundidad para datos de menores | una librería imprescindible exige inline (no previsto) |
| 11 | Fines de semana rechazados; fechas futuras no editables; fecha por defecto = hoy o el viernes anterior | permitir cualquier fecha | evita registros en días sin clase y errores de fecha | el centro tiene clases en sábado |
| 12 | Máximo 99 puntos por día y 30 activos por clase | sin límites | detecta toques desbocados; coincide con aulas de Ontario | una clase supera 30 estudiantes |
| 13 | Estudiantes archivados conservan entradas y salen en informes con sufijo | borrar estudiantes | los datos del semestre no se pierden cuando alguien deja la clase | la dueña pide borrado definitivo por privacidad |
| 14 | Recuperación por CLI/SQL (`classpulse:hash`) sin email | reset por email | no hay correo configurado ni SSH garantizado | la dueña configura correo transaccional |
| 15 | Sesión de base de datos, caché en archivo, cola `sync` | Redis / worker | hosting compartido sin procesos persistentes | migración a VPS |
| 16 | Sin E2E en navegador; pases manuales antes del lanzamiento | Playwright en el gate | añadiría Node/npm y navegadores al proyecto | se acepta Node como dependencia de desarrollo |
| 17 | 25 pasos en §9 (más que el rango por defecto 10-18 de la plantilla), repartidos en 3 epics de 9 + 9 + 7 | 18 pasos con pasos de 6-11 archivos | steps > 18 porque el tope de 5 archivos por paso obliga a partir esquema, cuentas, diseño, clases, informes e importación; el alcance no cambia (la dueña pidió todo v1) | Would reverse if the cap is relaxed — se volverían a fusionar los pasos partidos |
| 18 | Esqueleto fijado a `laravel/laravel` `13.10.1` | `"^13.0"` | la versión exacta hace reproducible el `public/index.php` cuyas 3 líneas cuenta el paso 24 | se actualiza el esqueleto a propósito y se re-mide `index.php` |
| 19 | Una sola `StudentRequest` para alta y renombrado | `StoreStudentRequest` + `UpdateStudentRequest` idénticas | las reglas son las mismas; un archivo menos mantiene el paso 12 en 5 archivos | alta y renombrado necesitan reglas distintas |

### 20.4 What to build next

1. Persistencia local de cambios pendientes tras recargar — cuando un corte provoque pérdida confirmada tras recarga.
2. Segunda cuenta de docente con aislamiento por usuario — cuando otra docente necesite su propia cuenta.
3. Correo transaccional y restablecimiento de contraseña por email — cuando la dueña configure correo.
4. Integración con Google Sheets — cuando la docente importe más de 5 listas por semestre a mano.
5. Sincronización entre dispositivos — cuando la docente use dos dispositivos a la vez en clase.

---

*End of blueprint. Build order is §9. Stop when §20.1 is green.*
