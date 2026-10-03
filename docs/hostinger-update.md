# Actualizar ClassPulse en Hostinger (sitio YA instalado)

Esta guía es solo para un sitio que **ya está instalado y tiene datos reales** (clases, estudiantes, registros).
Si todavía no has instalado ClassPulse, usa la guía de instalación completa (`LEEME-HOSTINGER.md`, con
`classpulse-app.zip`, `classpulse-public_html.zip` e `install.sql`). **Nunca** uses `install.sql` en un sitio con datos.

Novedad de esta actualización: sección **Account** (Cuenta) dentro de Class Roster & Settings, para cambiar tu correo y
tu contraseña sin pedir ayuda. Esta actualización **no añade migraciones de base de datos** (ver paso 5).

Todo lo que ocurre en Hostinger lo haces tú, después de aprobar la versión local. Nada se sube solo. Las partes que
dependen de tu plan y del diseño actual de hPanel están marcadas **NO VERIFICADO** (no se probó en Hostinger).

Lo que recibes en `dist/update/`:

| Archivo | Para qué sirve |
|---|---|
| `classpulse-update-app.zip` | el código de la aplicación (se descomprime DENTRO de `classpulse-app`) |
| `classpulse-update-public_html.zip` | solo los archivos públicos: `css`, `js`, `brand`, `fonts`, `favicon.ico` (se descomprime DENTRO de `public_html`) |
| `migrations/` | SQL por cada migración + `README.txt` (para el paso 5; esta vez no hay que aplicar nada nuevo) |
| `manifest.json` | lista de archivos con su suma SHA-256, versión y suma de `composer.lock` |
| `SHA256SUMS.txt` | sumas de control para comprobar que nada se dañó al copiarlo |
| `LEEME-ACTUALIZAR-HOSTINGER.md` | esta guía |

El zip de la aplicación **no contiene** `vendor/` cuando `composer.lock` no cambió respecto a la versión instalada
(es el caso de esta actualización: `manifest.json` dice `"vendor_included": false`); tu carpeta `vendor` actual se queda como está.
Tampoco contiene `.env`, `storage/`, `bootstrap/cache/`, `index.php` ni `.htaccess`.

Comprobación opcional en tu computadora: `cd dist/update && shasum -a 256 -c SHA256SUMS.txt` (Mac) debe decir `OK` en todo.

## 0) Cuándo usar esta guía

- Úsala si ClassPulse ya funciona en tu dominio y quieres la versión nueva con los datos intactos.
- No la uses para una instalación nueva (guía completa) ni para cambiar de dominio o de base de datos.
- Tiempo: unos 15-25 minutos. Hazlo cuando nadie esté usando la planilla (por ejemplo, fuera de clase).

## 1) ANTES: copias de seguridad (no te saltes este paso)

1. **Base de datos** (phpMyAdmin): hPanel > Bases de datos > phpMyAdmin > Entrar > elige tu base > pestaña **Exportar** >
   método **Personalizado** > formato **SQL** > marca **estructura y datos** (Structure and Data) > codificación **utf8mb4**
   > Exportar. Guarda el archivo `.sql` en tu computadora (y copia en otro lugar). Es lo único que protege tus registros.
2. **Carpetas** (Administrador de archivos): comprime en un zip y descarga
   - `classpulse-app` (ojo: dentro están `storage/` y el archivo oculto `.env`; el zip los incluye si activas "Mostrar archivos ocultos");
   - `public_html`.
3. **Anota los números de hoy** para compararlos después: cuántas clases tienes, cuántos estudiantes en cada una (Roster)
   y, si quieres, un registro de la semana en Daily. No crees ni borres nada.
4. Anota la fecha y el tamaño del archivo `.env` (clic derecho > Propiedades o la columna de detalles). **No lo abras en un
   lugar público.** Tu `APP_KEY` y los datos de la base **no cambian**: esta actualización no te pide volver a escribirlos.

## 2) Dejar el sitio en estado seguro

1. Avisa a quien use la planilla y espera a que cierre sesión (la actualización tarda pocos minutos).
2. No hace falta poner el sitio en mantenimiento ni renombrar carpetas (renombrar es arriesgado). Mientras copias archivos,
   una página puede verse mal unos segundos; recarga al terminar.
3. Si algo se ve raro a mitad del proceso, no sigas: ve a la sección 9 (volver atrás).

## 3) Subir y descomprimir `classpulse-update-app.zip`

1. Administrador de archivos: entra en la carpeta `classpulse-app` (la que está al lado de `public_html`).
2. Sube `classpulse-update-app.zip` **dentro de `classpulse-app`** y descomprímelo ahí mismo (Extraer / Extract aquí),
   eligiendo **sobrescribir** (overwrite / replace) cuando pregunte. Después borra el `.zip`.
3. Deben haberse actualizado `app`, `bootstrap/app.php`, `bootstrap/providers.php`, `config`, `database/migrations`,
   `resources`, `routes`, `artisan`, `composer.json` y `composer.lock`. No debe aparecer ninguna carpeta `classpulse-app`
   dentro de `classpulse-app`.
4. **NO sobrescribas ni borres** (el zip no los contiene, pero si tu administrador te pregunta por ellos, di que no):
   - `classpulse-app/.env` (tus claves y datos de la base);
   - `classpulse-app/storage/` (registros, sesiones, caché);
   - `classpulse-app/bootstrap/cache/` (si existe);
   - `public_html/index.php` y `public_html/.htaccess` (las copias de tu sitio llevan las rutas de tu hosting y reglas propias).
5. Si el zip es demasiado grande para la subida del navegador, usa la subida por FTP de hPanel (NO VERIFICADO el límite de tu plan).

## 4) Subir y descomprimir `classpulse-update-public_html.zip`

1. Entra en `public_html`, sube `classpulse-update-public_html.zip` y descomprímelo **ahí mismo**, sobrescribiendo. Borra el `.zip`.
2. Solo se reemplazan `css/`, `js/`, `brand/`, `fonts/` y `favicon.ico`. `index.php`, `.htaccess` y `robots.txt` no vienen en el
   zip y no se tocan. Comprueba que existe `public_html/js/account.js`.
3. **Refresca el navegador a la fuerza** (Ctrl+Shift+R en Windows o Cmd+Shift+R en Mac): el navegador guarda CSS y JS en
   caché y sin esto verás la pantalla vieja o sin estilos nuevos. Repite en cada dispositivo.
4. Caché del servidor (NO VERIFICADO en tu plan): si ves pantallas viejas después de refrescar, en hPanel busca
   **Caché / Cache Manager** (o "LiteSpeed Cache") y pulsa "Purgar todo / Clear cache". Tu plan puede no tenerlo. Si Hostinger
   usa OPcache de PHP, los archivos nuevos se leen solos en uno o dos minutos; si no, cambiar la versión de PHP y volverla a la
   original en Configuración de PHP lo reinicia (NO VERIFICADO).
5. Si ves pantallas viejas de Laravel, vacía el contenido (no la carpeta) de `classpulse-app/storage/framework/views`.

## 5) Migraciones de base de datos

Esta actualización **no necesita ninguna migración nueva**. De todos modos, para estar segura:

1. phpMyAdmin > tu base > pestaña SQL > ejecuta: `SELECT migration FROM migrations ORDER BY id;`
2. Mira la lista. La carpeta `migrations/` tiene un archivo `.sql` por cada migración del proyecto (8 en total, en orden).
3. Si **todas** las migraciones de la carpeta aparecen en la lista: no hagas nada.
4. Si falta alguna (por ejemplo, instalaste una versión más antigua): aplica **solo las que faltan**, de la más antigua a la más nueva,
   pegando el contenido del archivo en la pestaña SQL. Cada archivo termina registrando su migración en la tabla `migrations`.
   Detalle en `migrations/README.txt`. Nunca uses `install.sql`.
5. Antes de aplicar una migración, confirma que hiciste la exportación del paso 1.

## 6) Comprobar que `.env` no cambió

1. En el Administrador de archivos mira `classpulse-app/.env`: la fecha y el tamaño deben ser los que anotaste. No lo abras en un lugar público.
2. No tienes que volver a escribir los datos de la base ni `APP_KEY`. Tus sesiones y tu contraseña siguen igual.

## 7) Prueba de humo (no crees nada)

- [ ] `https://tu-dominio/up` muestra "OK" y `https://tu-dominio/login` carga con el estilo normal.
- [ ] Entras con tu correo y contraseña de siempre.
- [ ] Tus clases y estudiantes están TODOS (compara con los números del paso 1) y los registros de Daily/Weekly se ven.
- [ ] Abre Daily, Weekly, Semester y Roster: sin errores. No registres puntos ni crees nada en esta prueba.
- [ ] Roster > grupo **Account**: se ve tu correo y los formularios "Change email" y "Change password". (También desde el avatar de arriba a la derecha > **Account settings**.)
      No cambies tus credenciales durante la prueba, salvo que quieras hacerlo ahora (sección 8).
- [ ] Cierra sesión (avatar > Log out).
- [ ] `https://tu-dominio/.env` y `https://tu-dominio/storage/logs/laravel.log` dan 403 o 404 (nunca texto).

## 8) Cómo usar la sección Account

1. Entra en el avatar (arriba a la derecha) > **Account settings**, o en Roster abre el grupo **Account**.
2. **Cambiar el correo:** escribe el correo nuevo y tu **contraseña actual** > "Change email". Se guarda en minúsculas, no puede
   estar usado por otra cuenta y debe ser distinto del actual. Sigues con la sesión abierta y verás "Your email was changed to ...".
   Si habías marcado "Remember my email" en el login de ese navegador, se actualiza solo a la dirección nueva.
3. **Cambiar la contraseña:** escribe la contraseña actual, la nueva (**mínimo 12 caracteres**) y su confirmación > "Change password".
   El ojito muestra u oculta lo que escribes. Al terminar **se cierra la sesión en todos los dispositivos** y vuelves a entrar con la nueva.
4. Tras 5 intentos fallidos en un minuto, la página pide esperar un minuto antes de volver a intentar. No existe recuperación por
   correo: si pierdes la contraseña usa `docs/access-and-recovery.md` (desde tu computadora con Docker o phpMyAdmin).
5. Anota la contraseña nueva en un gestor de contraseñas; nunca en estos archivos.

## 9) Volver atrás (rollback)

1. Borra el contenido actualizado y restaura tus copias del paso 1: vuelve a descomprimir el zip de `classpulse-app` sobre la carpeta
   (cuida de no pisar `.env` y `storage` con versiones más viejas que las actuales si no es necesario) y el zip de `public_html`.
   Es la forma más rápida: unos minutos.
2. **Solo si aplicaste una migración** (paso 5): restaura también la exportación `.sql` del paso 1 en phpMyAdmin
   (Importar sobre la misma base; si pide, primero vacía las tablas o crea una base nueva y cambia `DB_DATABASE` en `.env`).
3. Refresca el navegador a la fuerza y repite la prueba de humo.

## 10) Si algo falla, qué enviar

Envía (sin contraseñas ni `APP_KEY`):

1. Qué hacías y qué ves (captura de pantalla con la barra de direcciones).
2. Las últimas 30 líneas de `classpulse-app/storage/logs/laravel.log` (Administrador de archivos) y, si existe, el registro de errores de PHP de hPanel
   (Sitios web > Panel > Avanzado > Registros de errores; NO VERIFICADO el nombre exacto). Borra cualquier dato privado antes de enviarlas.
3. La versión del kit (`manifest.json` > `version`) y la versión de PHP (Configuración de PHP).

Errores frecuentes: pantalla en blanco o "500" justo después de subir = el zip de la aplicación quedó descomprimido en una carpeta
equivocada (revisa que `classpulse-app/app` y `classpulse-app/routes` se actualizaron) o se sobrescribió `.env` por error (restaura tu copia).
Pantalla sin estilos = falta el paso 4 o el refresco forzado.
