# Copias de seguridad

## Copias de seguridad de hPanel

Hostinger ofrece copias automáticas según el plan: Premium semanal y Business/Cloud diaria (NO VERIFICADO), y una copia
manual una vez cada 24 horas en Business o superior. Confirma en hPanel qué frecuencia tiene realmente tu plan. No
dependas solo de ellas.

## Exportación semanal manual

Una vez por semana, descarga tu propia copia:

1. En hPanel abre phpMyAdmin y selecciona la base de datos de ClassPulse.
2. Ve a Exportar, formato SQL, y descarga el archivo a tu computadora.
3. Guárdalo con la fecha en el nombre.

Automatizarlo con un cron de `mysqldump` en Hostinger es NO VERIFICADO; por eso el método documentado es manual. La
aplicación también permite exportar CSV semanal, semestral y por estudiante desde sus pantallas.

## Restaurar una copia

1. Crea una base de datos vacía en hPanel (o vacía la existente).
2. En phpMyAdmin importa el archivo SQL. El límite de importación es de 256 MB.
3. Comprueba que puedes entrar y ver las clases.

La carpeta `storage/` no guarda nada crítico: solo sesiones, caché y registros. Todo lo importante está en la base.
