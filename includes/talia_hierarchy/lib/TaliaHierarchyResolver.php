<?php
require_once __DIR__ . '/TaliaHierarchyResolverInterface.php';

/**
 * TaliaHierarchyResolver v1.0
 * Fuente canónica de reglas de IDENTIDAD + ESTRUCTURA + ATRIBUCIÓN TEMPORAL.
 *
 * Principios certificados desde Ranking Productividad:
 *  - Evento directo válido > fallback HC/HIC en mensual.
 *  - HIC da continuidad a la MISMA persona, no a ocupantes distintos de plaza.
 *  - id_posicion / posicion_lr resuelven continuidad estructural.
 *  - matching comercial se desacopla del snapshot HC para no duplicar eventos.
 *  - consultas de instalaciones usan rangos sargables por fecha.
 */
final class TaliaHierarchyResolver implements TaliaHierarchyResolverInterface
{
    private $db;
    private array $config;

    public function __construct($mysqli, array $config)
    {
        $this->db = $mysqli;
        $this->config = $config;
    }

    public function version(): string
    {
        return (string)($this->config['version'] ?? '1.0.0');
    }

    public function normalizeIdentity(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function q(?string $value): string
    {
        if ($value === null) return 'NULL';
        return "'" . mysqli_real_escape_string($this->db, $value) . "'";
    }

    public function sqlLideresActivosCte(string $cteName = 'lideres_activos'): string
    {
        $rows = $this->config['leaders'] ?? [];
        if (!$rows) throw new RuntimeException('TalIA Hierarchy: catálogo de líderes vacío.');

        $selects = [];
        foreach ($rows as $i => $r) {
            $prefix = $i === 0 ? 'SELECT ' : 'UNION ALL SELECT ';
            $selects[] = $prefix
                . $this->q($r['distrito_reporte']) . ' AS distrito_reporte, '
                . $this->q($r['distrito_hc']) . ' AS distrito_hc, '
                . $this->q($r['lider_hc']) . ' AS lider_hc, '
                . $this->q($r['lider_instalaciones']) . ' AS lider_instalaciones, '
                . $this->q($r['lider_pos'] ?? null) . ' AS lider_pos';
        }
        return $cteName . " AS (\n    " . implode("\n    ", $selects) . "\n)";
    }

    /** Sargable: nunca envuelve fecha con YEAR/MONTH/DAY para filtrar rango. */
    public function sqlDateRangeCondition(string $alias, string $startDate, string $endDate, array $mysqlDays = []): string
    {
        $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
        $start = mysqli_real_escape_string($this->db, $startDate);
        $end = mysqli_real_escape_string($this->db, $endDate);
        $sql = "{$a}.fecha BETWEEN '{$start}' AND '{$end}'";
        if ($mysqlDays) {
            $days = array_values(array_unique(array_filter(array_map('intval', $mysqlDays), fn($d) => $d >= 1 && $d <= 7)));
            if ($days) $sql .= ' AND DAYOFWEEK('.$a.'.fecha) IN ('.implode(',', $days).')';
        }
        return $sql;
    }

    /**
     * Regla productiva exacta del Ranking:
     * mensual: directo si existe; fallback sólo si directo=0.
     * semanal: conserva GREATEST(directo,fallback).
     */
    public function sqlAttributedSalesExpr(string $periodo, string $directAlias, string $directColumn, string $fallbackAlias, string $fallbackColumn): string
    {
        foreach ([$directAlias,$directColumn,$fallbackAlias,$fallbackColumn] as $id) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $id)) throw new InvalidArgumentException('Identificador SQL inválido.');
        }
        $d = "COALESCE({$directAlias}.{$directColumn},0)";
        $f = "COALESCE({$fallbackAlias}.{$fallbackColumn},0)";
        return $periodo === 'mensual'
            ? "CASE WHEN {$d} > 0 THEN {$d} ELSE {$f} END"
            : "GREATEST({$d}, {$f})";
    }

    /** Match de una instalación contra las cuatro identidades que ya certificó Ranking. */
    public function sqlFolioMatch(string $installationAlias, string $personAlias): string
    {
        $i = preg_replace('/[^A-Za-z0-9_]/', '', $installationAlias);
        $p = preg_replace('/[^A-Za-z0-9_]/', '', $personAlias);
        return "({$i}.folio_empleado = {$p}.folio_empleado OR {$i}.folio_empleado = {$p}.folio_unificado OR {$i}.folio_empleado = {$p}.folio_anterior OR {$i}.folio_empleado = {$p}.folio_nuevo)";
    }

    /**
     * Canonicalización event-first por HIC. Devuelve una expresión SQL escalar.
     * Mantiene la prioridad del último movimiento HIC usada por Coach->Vendedor.
     */
    public function sqlCanonicalFolioExpr(string $folioExpr): string
    {
        return "COALESCE(NULLIF((SELECT COALESCE(NULLIF(hx.numero_talento_nuevo,''), NULLIF(hx.numero_talento_anterior,'')) FROM historial_identidad_colaborador hx WHERE {$folioExpr} = hx.numero_talento_anterior OR {$folioExpr} = hx.numero_talento_nuevo ORDER BY hx.fecha_movimiento DESC, hx.id DESC LIMIT 1),''), {$folioExpr})";
    }

    /** Relación estructural Coach <- Vendedor, incluyendo equivalencia de plaza por HIC. */
    public function sqlCoachStructurePredicate(string $hcAlias, string $coachAlias): string
    {
        $h = preg_replace('/[^A-Za-z0-9_]/', '', $hcAlias);
        $c = preg_replace('/[^A-Za-z0-9_]/', '', $coachAlias);
        return "({$h}.nombre_linea_reporte = {$c}.coach OR {$h}.posicion_lr = {$c}.coach_pos OR EXISTS (SELECT 1 FROM historial_identidad_colaborador hicx WHERE ({$h}.posicion_lr = hicx.id_posicion_anterior OR {$h}.posicion_lr = hicx.id_posicion_nueva) AND ({$c}.coach_pos = hicx.id_posicion_anterior OR {$c}.coach_pos = hicx.id_posicion_nueva)))";
    }
}
