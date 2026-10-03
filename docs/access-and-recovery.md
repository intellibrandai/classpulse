# Acceso y recuperación

## Crear la cuenta de la docente

ClassPulse tiene una sola cuenta. Se crea con el comando (interactivo, pide la contraseña dos veces):

```bash
docker compose exec app php artisan classpulse:create-teacher teacher@classpulse.test
```

Usa el correo real de la docente en lugar del ejemplo. No se crea una segunda cuenta.

## Restablecer la contraseña

```bash
docker compose exec app php artisan classpulse:reset-password teacher@classpulse.test
```

El comando cambia la contraseña y cierra todas las sesiones abiertas. En un servidor con SSH se ejecuta con
`php artisan classpulse:reset-password <correo>` dentro de la carpeta `classpulse-app`.

## Recuperación sin SSH

Cuando el servidor no ofrece SSH:

1. En tu computadora ejecuta `docker compose exec app php artisan classpulse:hash` y escribe la contraseña nueva.
2. Copia la cadena que imprime `classpulse:hash`.
3. En phpMyAdmin abre la tabla `users`, edita la fila de la docente y pega esa cadena en la columna `password`.
4. Guarda y entra con la contraseña nueva.

No pegues contraseñas en texto plano en la base de datos, y no guardes la cadena generada en ningún documento.

## Recuperación de último recurso con SQL

Solo si la tabla `users` está vacía. Sustituye cada valor `CAMBIAR_...` en phpMyAdmin (pestaña SQL). Esta plantilla no
contiene datos reales.

```sql
INSERT INTO users (name, email, password, created_at, updated_at)
VALUES ('CAMBIAR_NOMBRE', 'CAMBIAR_CORREO', 'CAMBIAR_CADENA_DE_classpulse:hash', NOW(), NOW());
```

`CAMBIAR_CADENA_DE_classpulse:hash` es la cadena que imprime `classpulse:hash` ejecutado en local.

## Cambiar correo o contraseña estando dentro

En la aplicación: avatar > **Account settings** (Roster > grupo **Account**). Pide la contraseña actual; la contraseña nueva debe tener 12 caracteres o más y al cambiarla se cierran todas las sesiones. Tras 5 intentos fallidos en un minuto hay que esperar un minuto. Si no recuerdas la contraseña actual, usa los métodos de este documento.
