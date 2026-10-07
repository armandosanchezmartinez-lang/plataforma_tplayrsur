# TalIAHierarchyResolver v1.0

## Objetivo
Extraer de `ranking_productividad.php` una sola fuente canónica para identidad, estructura y atribución temporal sin cambiar cifras del Ranking productivo. Ranking es el módulo patrón; REAI será el segundo consumidor.

## Reglas congeladas por la certificación
1. **Identidad**: folio/talento + HIC da continuidad únicamente a la misma persona.
2. **Estructura**: `id_posicion` / `posicion_lr` resuelven Líder > Coach > Vendedor. Mercy/Jovany se resuelve por plaza 1739397, no por HIC.
3. **Evento histórico**: una instalación válida conserva la estructura registrada en la fecha del evento.
4. **Fallback**: HC/HIC sólo completa una atribución faltante; en mensual no sustituye una atribución directa válida.
5. **Coach > Vendedor**: event-first; el universo nace de instalaciones atribuidas al coach, no del HC de cierre.
6. **Deduplicación**: mantener `coaches_match` para no multiplicar instalaciones al comparar snapshots.
7. **Performance**: rangos por `fecha BETWEEN ...`; no usar YEAR/MONTH/DAY sobre `instalaciones.fecha` para filtrar periodos.

## Archivos
- `config/talia_hierarchy.php`: catálogo estructural y aliases productivos.
- `lib/TaliaHierarchyResolverInterface.php`: contrato estable para cualquier módulo.
- `lib/TaliaHierarchyResolver.php`: implementación v1.0 y fragmentos SQL canónicos.
- `lib/TaliaHierarchyContext.php`: contexto temporal del corte.
- `lib/TaliaCertifiedModule.php`: registro de consumidores certificados.
- `cert/certify_hierarchy.php`: smoke tests sin escritura.
- `database/talia_hierarchy_indexes.sql`: índices sugeridos, deliberadamente comentados hasta revisar `SHOW INDEX`.
- `ranking_productividad_CERTIFICADO.php`: copia productiva conectada al resolver v1.0 en los puntos compartidos sin alterar la lógica de métricas/UI.

## Despliegue recomendado
Ruta canónica aprobada: `/plataforma/includes/talia_hierarchy/`. El Ranking certificado incluido en este paquete ya referencia esa ubicación; no requiere mover ni corregir `require_once`. Primero `armando-dev`; comparar Ranking original vs certificado con los mismos parámetros.

## Gate de certificación Ranking
Debe cumplirse simultáneamente en semanal y mensual:
- Totales del archivo original = totales del certificado.
- Líder = suma Coaches = suma Vendedores para instalaciones atribuibles.
- Mercy/Jovany conserva continuidad estructural sin HIC entre personas.
- Laura Cen y Deyner conservan su coach histórico de septiembre.
- Jahir no pierde vendedores por snapshot de cierre.
- No reaparece duplicación 2x ni 504.

No migrar REAI hasta que estas pruebas pasen en `armando-dev`.
