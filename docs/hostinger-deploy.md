La versión 13 de Laravel NO se ha verificado en el plan real de la dueña; la publicación espera la aprobación de la versión local por parte de la dueña. Nada de lo que sigue se hace hasta entonces.

# Publicar ClassPulse en Hostinger (guía paso a paso)

Todo lo que ocurre en Hostinger lo haces tú, después de aprobar la versión local. Los archivos los genera otra persona
(o tú) en local con `./scripts/package-release.sh` y se comprueban con `./scripts/verify-release.sh`. Esta guía viaja
también como `LEEME-HOSTINGER.md` junto a los archivos.

Lo que recibes en la carpeta `dist/`:

| Archivo | Para qué sirve |
|---|---|
| `classpulse-app.zip` | la aplicación (va en una carpeta `classpulse-app`, al lado de `public_html`) |
| `classpulse-public_html.zip` | lo público (va dentro de `public_html`) |
| `install.sql` | las tablas vacías de la base de datos (sin usuarios ni datos) |
| `SHA256SUMS.txt` | sumas de control para comprobar que los 3 archivos no se dañaron al copiarlos |
| `LEEME-HOSTINGER.md` | esta guía |

Comprobación opcional en tu computadora: `shasum -a 256 -c SHA256SUMS.txt` (Mac) debe decir `OK` en los tres archivos.

## 0) Antes de empezar

- [ ] Tienes acceso a hPanel de Hostinger, al dominio ya apuntando al hosting y a una computadora con Docker (solo para generar la clave y el hash de la contraseña, pasos 5 y 6).
- [ ] Tienes los 3 archivos de `dist/` y un lugar para anotar datos (papel o gestor de contraseñas; nunca en estos archivos).
- [ ] Copia de seguridad: si `public_html` ya tiene algo, en hPanel haz una copia (Copias de seguridad) y descarga los archivos importantes. Si es un dominio nuevo y vacío, no hay nada que respaldar.
- [ ] Estás de acuerdo con el tiempo: 20-40 minutos.

## 1) Comprobar los requisitos del hosting ANTES de subir nada

Tabla completa (en inglés): `docs/hosting-requirements.md`. Lo esencial:

| Qué | Valor necesario | Dónde se mira en hPanel |
|---|---|---|
| Versión de PHP | 8.3 o más nueva (8.3, 8.4 u 8.5) | Sitios web > Panel > Avanzado > Configuración de PHP (PHP Configuration) |
| Extensiones de PHP | `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `session`, `tokenizer`, `xml`, `pdo`, `pdo_mysql` activadas | misma página, pestaña "Extensiones PHP" |
| Base de datos | MariaDB 10.3 o más nueva, o MySQL 5.7 o más nueva (mejor MariaDB 10.6+ o MySQL 8.0.16+) | phpMyAdmin, columna derecha "Servidor de base de datos" |
| Memoria y subidas | `memory_limit` 128M o más; `upload_max_filesize` y `post_max_size` 1M o más | misma página, pestaña "Opciones PHP" |
| Carpeta por encima de `public_html` | Puedes crear `classpulse-app` al lado de `public_html` y PHP puede leerla (`open_basedir`) | Administrador de archivos; el archivo de comprobación (más abajo) muestra `open_basedir` |
| SSL | Activo, con "Forzar HTTPS" | Sitios web > Seguridad > SSL |
| No hace falta | SSH, Composer, Node, cron, cola de trabajos, `curl`, `zip`, `intl` | - |

Todo lo de la columna "Dónde se mira" depende de tu plan y del diseño actual de hPanel: si un nombre no coincide, es lo
que hay que verificar en Hostinger (NO VERIFICADO aquí). Las ubicaciones salen de las páginas de ayuda de Hostinger.

### Archivo de comprobación `check-requirements.php` (sin phpinfo, sin secretos)

1. En el Administrador de archivos entra en `public_html` y crea un archivo nuevo llamado `check-requirements.php`.
2. Pega exactamente este contenido y guarda:

```php
<?php
// ClassPulse: comprobacion temporal de requisitos. No muestra contrasenas ni rutas secretas. BORRAR AL TERMINAR.
header('Content-Type: text/plain; charset=utf-8');
if (isset($_GET['borrar'])) { echo @unlink(__FILE__) ? "Archivo borrado.\n" : "No se pudo borrar: borralo a mano.\n"; exit; }
$ok = fn (bool $v): string => $v ? 'OK     ' : 'FALTA  ';
echo "PHP: ", PHP_VERSION, ' ', $ok(PHP_VERSION_ID >= 80300), "(se necesita 8.3 o mas)\n";
echo "Servidor: ", $_SERVER['SERVER_SOFTWARE'] ?? 'desconocido', "\n\n";
foreach (['ctype','dom','fileinfo','filter','hash','iconv','json','libxml','mbstring','openssl','pcre','session','tokenizer','xml','PDO','pdo_mysql'] as $e) {
    echo $ok(extension_loaded($e)), $e, "\n";
}
echo "\nmemory_limit: ", ini_get('memory_limit'), " (minimo 128M)\n";
echo "upload_max_filesize: ", ini_get('upload_max_filesize'), " (minimo 1M)\n";
echo "post_max_size: ", ini_get('post_max_size'), " (minimo 1M)\n";
echo "max_execution_time: ", ini_get('max_execution_time'), "\n";
echo "open_basedir: ", ini_get('open_basedir') ?: '(sin limite)', "\n";
$app = dirname(__DIR__) . '/classpulse-app';
echo "\nCarpeta classpulse-app al lado de public_html: ", $ok(is_dir($app)), "\n";
foreach (['storage', 'storage/logs', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache/data', 'bootstrap/cache'] as $d) {
    echo $ok(is_dir("$app/$d") && is_writable("$app/$d")), "escribible: $d\n";
}
echo "\nmod_rewrite: ", function_exists('apache_get_modules') ? ($ok(in_array('mod_rewrite', apache_get_modules(), true))) : "no se puede saber desde aqui (prueba /login despues)", "\n";
echo "\nAHORA BORRA ESTE ARCHIVO: abre este mismo enlace con ?borrar=1 al final, o bórralo en el Administrador de archivos.\n";
```

3. Abre `https://tu-dominio/check-requirements.php`. Todo debe decir `OK`. Las líneas `escribible` fallarán hasta
   el paso 4: es normal si aún no has subido la aplicación; vuelve a abrirlo después del paso 7.
4. **BORRA `check-requirements.php` AHORA MISMO al terminar** (abre `.../check-requirements.php?borrar=1` o bórralo en el
   Administrador de archivos). **No lo dejes en el servidor: revela datos del servidor.** Este archivo no viene en los zip.

## 2) Crear la base de datos y el usuario en hPanel

1. hPanel > Bases de datos > Administración (Management) > crear base de datos y usuario nuevos.
2. Elige nombre de base, usuario y una contraseña larga y única. Hostinger les añade un prefijo (por ejemplo `u123456789_`): anota los nombres completos tal como los muestra hPanel.
3. Anota: nombre de la base, usuario, contraseña y el host (en Hostinger compartido suele ser `localhost`; usa el que muestre hPanel, NO VERIFICADO en tu plan).
4. Si hPanel pregunta por la codificación, elige `utf8mb4` (intercalación `utf8mb4_unicode_ci`).

## 3) Importar `install.sql` con phpMyAdmin

1. hPanel > Bases de datos > phpMyAdmin > "Entrar" en la base del paso 2.
2. Pestaña Importar > elige `install.sql` > formato SQL > Importar.
3. Debe decir que terminó bien y mostrar 12 tablas (`migrations`, `users`, `sessions`, `school_classes`, `students`, ...). Todas vacías salvo `migrations` (8 filas). No contiene usuarios: la cuenta se crea en el paso 6.
4. Mira también la versión del servidor en la página de inicio de phpMyAdmin (columna derecha) y compárala con el paso 1.

## 4) Subir y descomprimir los dos zip (Administrador de archivos)

1. Abre el Administrador de archivos y mira el nivel que contiene `public_html`: ahí (al lado de `public_html`, NO dentro) va la aplicación.
2. Sube `classpulse-app.zip` a ese nivel y descomprímelo: debe aparecer la carpeta `classpulse-app` (con `app`, `bootstrap`, `config`, `vendor`, `storage`, ...). Borra después el `.zip`.
3. Entra en `public_html`, sube `classpulse-public_html.zip` y descomprímelo AHÍ: en `public_html` deben quedar directamente `index.php`, `.htaccess`, `css`, `js`, `fonts`, `brand`, `favicon.ico` y `robots.txt` (no una carpeta dentro de otra). Borra después el `.zip`.
4. Si el Administrador de archivos no muestra `.htaccess`, activa "Mostrar archivos ocultos" (engranaje de ajustes). Debe existir en `public_html`.
5. Si el zip es demasiado grande para la subida del navegador (unos 8 MB el de la aplicación), usa la subida por FTP de hPanel (NO VERIFICADO el límite de tu plan).
6. Subir un `vendor/` ya compilado no está documentado por Hostinger (NO VERIFICADO), pero son solo archivos PHP y no hace falta Composer.

Resultado esperado:

```
(nivel de tu cuenta)
├── classpulse-app/      <- aplicación (privada)
└── public_html/         <- lo público
    ├── index.php  .htaccess  css/  js/  fonts/  brand/  favicon.ico  robots.txt
```

## 5) Crear el archivo `.env` (producción)

1. En `classpulse-app`, copia `.env.example` con el nombre `.env` (clic derecho > Copiar/Renombrar; el archivo debe llamarse exactamente `.env`). Está dentro de `classpulse-app`, NUNCA en `public_html`.
2. Edita `.env` y reemplaza cada valor que empieza por `CAMBIAR`:

```
APP_NAME=ClassPulse
APP_ENV=production
APP_KEY=CAMBIAR_APP_KEY
APP_DEBUG=false
APP_URL=https://CAMBIAR_DOMINIO
APP_TIMEZONE=America/Toronto
DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=CAMBIAR_BASE
DB_USERNAME=CAMBIAR_USUARIO
DB_PASSWORD=CAMBIAR_CONTRASENA
SESSION_DRIVER=database
SESSION_LIFETIME=480
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error
```

   (El archivo trae además unas líneas extra sin secretos: déjalas.)
3. `APP_KEY`: genérala en tu computadora (sin SSH), en la carpeta del proyecto:

   ```bash
   docker compose exec -T app php artisan key:generate --show
   ```

   Imprime una línea `base64:...`. Pégala completa como valor de `APP_KEY`. Con `--show` el comando NO modifica tu `.env` local. NUNCA uses la clave de tu `.env` local ni la compartas ni la pongas en documentos. Alternativa sin Docker: cualquier generador de 32 bytes aleatorios en base64 con el prefijo `base64:`.
4. `APP_URL` sin barra final, con `https://`. `DB_*` son los datos anotados en el paso 2. Si phpMyAdmin dijo que el servidor es MySQL (no MariaDB), usa `DB_CONNECTION=mysql`.
5. Si `APP_KEY` cambia después de que haya sesiones abiertas, esas sesiones se invalidan (no pasa nada grave: vuelves a entrar).
6. Sin SSH no ejecutes `config:cache` ni `route:cache`. No subas el `.env` local ni nada con `APP_DEBUG=true`.

## 6) Crear la cuenta de la docente (sin SSH)

ClassPulse tiene una sola cuenta. `classpulse:hash` (en tu computadora) pide la contraseña dos veces (mínimo 12 caracteres), NO guarda nada y solo imprime un hash bcrypt (`$2y$...`) que sirve para comprobar la contraseña sin revelarla.

1. En tu computadora: `docker compose exec app php artisan classpulse:hash` (sin `-T`, es interactivo). Escribe la contraseña elegida y copia la línea que imprime. No guardes la contraseña ni el hash en ningún documento.
2. En phpMyAdmin > tu base > pestaña SQL, ejecuta (sustituye los tres valores `CAMBIAR_...`; el hash va entre comillas simples tal cual):

```sql
INSERT INTO users (name, email, password, created_at, updated_at)
VALUES ('CAMBIAR_NOMBRE', 'CAMBIAR_CORREO', 'CAMBIAR_HASH_DE_classpulse:hash', NOW(), NOW());
```

3. Debe decir "1 fila insertada". La tabla `users` debe tener una sola fila. Si ya existe una fila, NO insertes otra (edita su columna `password` con el hash nuevo).
4. Con SSH (planes Premium o superiores) es más simple, dentro de `classpulse-app`: `php artisan classpulse:create-teacher correo@ejemplo.com` (pide la contraseña). Recuperar o cambiar la contraseña: `docs/access-and-recovery.md`.

## 7) Permisos y carpetas

1. Las carpetas `storage` (y todo lo que hay dentro) y `bootstrap/cache` deben poder escribirse. En el Administrador de archivos, clic derecho > Permisos: carpetas `755`; si después aparece un error de escritura, prueba `775` en esas dos carpetas y sus subcarpetas (`storage/logs`, `storage/framework/cache/data`, `storage/framework/sessions`, `storage/framework/views`, `bootstrap/cache`). Los archivos, `644`. Nunca `777`.
2. Esas carpetas vienen vacías a propósito (solo un `.gitignore` de relleno). Laravel crea dentro los registros, las sesiones de archivo y la caché.
3. Vuelve a abrir el archivo de comprobación (paso 1) si aún no lo has borrado: todas las líneas `escribible` deben decir `OK`. Luego BÓRRALO.

## 8) Primera prueba y lista de seguridad

Prueba de humo (usa una clase de práctica con nombres inventados):

- [ ] `https://tu-dominio/up` muestra una página de "OK" (estado 200).
- [ ] `https://tu-dominio/login` carga con el logo y las letras; entras con tu correo y contraseña.
- [ ] Crear una clase de práctica y tres estudiantes inventados (Roster).
- [ ] En Daily: registrar un punto, recargar la página y ver que sigue ahí.
- [ ] Weekly: ver la matriz con el punto.
- [ ] Semester: abrir la pantalla y los comentarios de boletín.
- [ ] Imprimir (vista previa) Daily, Weekly o Semester: formato carta, sin cortes.
- [ ] Borrar la clase de práctica y salir de la sesión (Log out). Al pulsar "Atrás" no debe mostrar datos privados.

Seguridad (todo debe cumplirse):

- [ ] HTTPS forzado: abrir `http://tu-dominio` redirige a `https://`.
- [ ] `APP_DEBUG=false`: una dirección inexistente como `https://tu-dominio/no-existe` muestra una página de error sencilla, sin rutas ni código.
- [ ] `.env` no es accesible: `https://tu-dominio/.env` debe dar 403 o 404 (nunca mostrar texto).
- [ ] `https://tu-dominio/storage/logs/laravel.log` y `https://tu-dominio/composer.json` dan 403 o 404.
- [ ] `check-requirements.php` ya no existe en `public_html` (abre la URL: debe dar 404). Borra también cualquier otro archivo temporal que hayas subido.
- [ ] En `public_html` solo hay: `index.php`, `.htaccess`, `css`, `js`, `fonts`, `brand`, `favicon.ico`, `robots.txt`.
- [ ] Copia de seguridad hecha (sección 9).
- [ ] Cuenta: avatar > **Account settings** (o Roster > grupo Account) muestra tu correo y permite cambiar el correo y la contraseña (mínimo 12 caracteres; al cambiar la contraseña se cierran todas las sesiones). No lo pruebes con datos que no quieras conservar.

## 9) Actualizar, volver atrás y copias de seguridad

- **Antes de cualquier cambio:** exporta la base desde phpMyAdmin (Exportar > SQL) y guarda los zip de la versión anterior. Detalles en `docs/backups.md` (exportación semanal manual y copias de hPanel; restaurar en una base vacía).
- **Actualizar un sitio ya instalado:** usa el kit de actualización (`dist/update/`, guía `LEEME-ACTUALIZAR-HOSTINGER.md` = `docs/hostinger-update.md`): trae solo el código y los archivos públicos, conserva `.env`, `storage/`, `index.php` y `.htaccess`, y no toca tus datos. Lo que sigue es el resumen para la instalación completa.
- **Actualizar (resumen):** si la versión nueva trae un SQL de migración nuevo, impórtalo primero (siempre es aditivo). Luego sube y descomprime `classpulse-app.zip` sobre `classpulse-app` (sobrescribiendo) y `classpulse-public_html.zip` sobre `public_html`. NO borres ni sobrescribas `.env`: el zip no lo contiene. Vacía el contenido de `storage/framework/views` si ves pantallas viejas. Repite la prueba de humo.
- **Volver atrás:** vuelve a descomprimir los zip anteriores (unos minutos). La base solo se restaura desde tu exportación si una migración dañó datos.
- **Copias:** hPanel hace copias según el plan (frecuencia NO VERIFICADA); además exporta tú la base una vez por semana (`docs/backups.md`).

## 10) Si algo falla, qué enviar

Envía (sin contraseñas ni `APP_KEY`):

1. Qué hacías y qué ves (captura de pantalla con la barra de direcciones).
2. La versión de PHP (Configuración de PHP en hPanel) y la versión de base de datos (phpMyAdmin).
3. Las últimas 30 líneas de `classpulse-app/storage/logs/laravel.log` (Administrador de archivos) y, si existe, el registro de errores de PHP de hPanel (Sitios web > Panel > Avanzado > Registros de errores; NO VERIFICADO el nombre exacto). Borra de ellas cualquier dato privado antes de enviarlas.
4. El resultado de `check-requirements.php` (si lo vuelves a subir; bórralo después).

Errores frecuentes: pantalla en blanco o "500" = PHP menor que 8.3, extensión que falta, `.env` mal escrito o carpeta `storage` sin permiso de escritura. "No application encryption key" = falta `APP_KEY`. "SQLSTATE... Access denied" = `DB_*` mal copiados. `/login` da 404 pero `/` funciona = `.htaccess` ausente o reglas de reescritura desactivadas.

## Ruta alternativa con SSH

Solo en planes con SSH (Premium o superiores), dentro de `classpulse-app`:

```bash
php artisan migrate --force
php artisan classpulse:create-teacher correo@ejemplo.com
```

(`composer2 install --no-dev --optimize-autoloader` solo hace falta si subes el código sin `vendor/`; con los zip no es necesario.)

## Fuentes de Hostinger

- https://www.hostinger.com/support/1583494-what-is-the-path-to-your-website-s-root-home-directory-and-how-to-change-it-in-hostinger/
- https://www.hostinger.com/support/6152127-how-to-deploy-laravel-8-at-hostinger/
- https://www.hostinger.com/support/5792078-how-to-use-composer-at-hostinger
- https://www.hostinger.com/support/1583245-how-to-connect-to-a-hosting-plan-via-ssh-in-hostinger/
- https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/
- https://www.hostinger.com/support/1583765-how-many-cron-jobs-can-you-set-up-in-hostinger/
- https://www.hostinger.com/support/4667515-how-to-manage-php-extensions-and-options
- https://www.hostinger.com/support/1884149-how-to-import-a-database-with-phpmyadmin-in-hostinger/
- https://www.hostinger.com/support/1575755-how-to-change-the-php-version-of-your-hostinger-hosting-plan/
- https://www.hostinger.com/support/1583226-which-database-management-system-is-used-at-hostinger/
