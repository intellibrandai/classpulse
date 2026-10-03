# ClassPulse (README del propietario, en español)

> El README principal del repositorio, en inglés, está en la raíz (`README.md`). Este archivo conserva el texto original en español; los pasos de instalación local actualizados están en el README en inglés (`./scripts/setup-local.sh`).

## Qué es

ClassPulse es una "planilla digital de participación" para docentes de Ontario (zona horaria
America/Toronto). Permite registrar puntos de participación por estudiante y por día escolar (lunes a viernes),
consultar la matriz semanal y la analítica del semestre, ver el historial de cada estudiante y importar o exportar
listas en CSV. Cada clase admite hasta 35 estudiantes activos (los archivados no ocupan lugar).

- Los borradores de "Report Card Comments" (Semestre) se guardan solos en la base de datos, por estudiante, clase y período; "Reset to template" vuelve al texto generado. No usan IA ni envían nada.
- Requiere conexión a internet para usarse en el navegador cuando esté publicada; en local funciona con Docker.
- No se publica nada automáticamente: el paquete de Hostinger se genera en tu computadora y solo lo subes tú, después
  de aprobar la versión local.
- Cuenta: desde el avatar > Account settings (Roster) la docente cambia su propio correo y contraseña (pide la contraseña actual; la nueva, mínimo 12 caracteres, cierra todas las sesiones). No hay registro público ni recuperación por correo.
- Solo usa nombres ficticios en los datos de demostración y en las pruebas.

## Primeros pasos

1. Instala Docker Desktop (incluye `docker compose`).
2. Prepara el proyecto con el bloque Bootstrap descrito en `../../blueprints/classpulse/blueprint.md` (sección 10): crea el
   esqueleto `laravel/laravel` 13.10.1, copia el espacio de trabajo y genera el archivo `.env` local a partir de
   `.env.example`.
3. Arranca los servicios: `docker compose up -d --build`.
4. Aplica las migraciones: `docker compose exec -T app php artisan migrate --force`.
5. Abre http://localhost:8090.
6. Crea la cuenta de la docente (comando interactivo, pide la contraseña):
   `docker compose exec app php artisan classpulse:create-teacher teacher@classpulse.test`.

## Documentación

- [Entorno local y pruebas](../local-development.md)
- [Publicación en Hostinger](../hostinger-deploy.md)
- [Acceso y recuperación de la cuenta](../access-and-recovery.md)
- [Actualizar un sitio ya instalado en Hostinger (kit de actualización)](../hostinger-update.md)
- [Copias de seguridad](../backups.md)
- [Notas de API y definiciones de cálculo (en inglés, para desarrollo)](../api-notes.md)
