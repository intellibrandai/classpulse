# ClassPulse — build progress

## Estado (2026-09-30)
- Las 25 tareas de `tasks.json` están `done` (tags `step-01-…` a `step-25-…`).
- Rediseño aprobado aplicado (specs en `docs/design-reference/SPEC.md`, imágenes de referencia en la misma carpeta):
  logo de ondas vectorial (`public/brand/`, generado con `scripts/brand/build-logo.py`), iconos SVG propios, acabado de vidrio,
  cabecera con chips (Enrolled/Present/Absent), Daily (filtros con contadores, + Student, Day Slip, tendencia y notas),
  Weekly (indicadores con gráficos, búsqueda/orden, editor rápido de celdas con Undo entre fechas, Copy Summary, impresión),
  Semester (períodos Q1/Q2 configurables, tabla + inspector, notas, Report Card Comments, exportación/impresión),
  Roster (clases, detalles, reglas bloqueadas, capacidad, perfiles de alumnos, archivar/restaurar) e Import.
- Gate: pint, 357 pruebas PHPUnit, 82 pruebas JS y `./scripts/package-release.sh && ./scripts/verify-release.sh` en verde.
- Migraciones nuevas ya aplicadas en la base dev: perfiles de alumno, detalles de clase, `student_notes`, `academic_periods`.

## Pendiente (real)
1. Publicar en Hostinger (manual, con `dist/`; no hecho). NO VERIFICADO: versión de PHP/extensiones del plan.
2. Impresión: PDFs generados con Chrome y revisados (`node scripts/dev/print-pdfs.mjs`, ver `docs/dev-tools.md`); falta solo una prueba en una impresora real.
3. Sin implementar por diseño: peso de participación/calificación (no hay escala de conversión), comparaciones sin datos previos,
   rankings/objetivos inventados, Google, IA. Los borradores de Report Card Comments ya se guardan en la base de datos (alumno + clase + período).
4. Sin probar: Safari/Firefox, dispositivo táctil real, lector de pantalla, 100+ alumnos.
