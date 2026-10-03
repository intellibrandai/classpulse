# Desarrollo local

## Arrancar el entorno local

Atajo (primera vez): `./scripts/setup-local.sh --demo` hace todos los pasos (contenedores, `composer install`, clave, base de pruebas, migraciones y datos de demostración). Los pasos manuales están en el `README.md` en inglés. Para cambiar los puertos edita `CLASSPULSE_WEB_PORT` y `CLASSPULSE_DB_PORT` en `.env`.

Todo se ejecuta en Docker; PHP, Composer, MariaDB y Node no están instalados en tu computadora.

```bash
docker compose up -d
```

- Web: http://localhost:8090
- Base de datos MariaDB: 127.0.0.1:33061
- Solo se usa el proyecto Compose `classpulse`. No detengas, borres ni limpies contenedores o volúmenes de otros
  proyectos.
- Para detener sin perder datos: `docker compose stop`.
- Nunca ejecutes `docker compose down -v`: borra el volumen de la base de datos y todos los datos locales.
- El esqueleto de la aplicación está fijado a `laravel/laravel` `13.10.1` por el bloque Bootstrap.

## Ejecutar las pruebas

Estos tres comandos deben terminar con código 0:

```bash
docker compose exec -T app vendor/bin/pint --test
docker compose exec -T app vendor/bin/phpunit
docker compose --profile test run --rm jstest > /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) pass [1-9]' /tmp/classpulse-jstest.txt && grep -qE '(ℹ|#) fail 0' /tmp/classpulse-jstest.txt
```

La última línea comprueba el número de pruebas JS aprobadas, porque una ejecución sin archivos de prueba imprime
`pass 0` y también termina con código 0. Las pruebas de PHP usan la base `classpulse_test`, nunca la base de
desarrollo `classpulse`.

## Datos de demostración

```bash
docker compose exec -T app php artisan classpulse:demo-seed
```

- Solo para uso local: crea una clase con estudiantes de nombres inventados y varios días de participación.
- Imprime una vez la contraseña de la cuenta de demostración; anótala en ese momento.
- Para generar el paquete de publicación: `./scripts/package-release.sh && ./scripts/verify-release.sh`
  (crea `dist/classpulse-app.zip`, `dist/classpulse-public_html.zip`, `dist/install.sql`, `dist/SHA256SUMS.txt` y `dist/LEEME-HOSTINGER.md`; no sube nada, y `verify-release.sh` comprueba que no hay secretos, datos de prueba ni archivos de desarrollo en el paquete).
