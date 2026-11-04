<?php
declare(strict_types=1);
/**
 * TotalXpedient · CORRECCIÓN JERÁRQUICA DE LÍNEA DE REPORTE — Región SUR
 * Fecha: 04-nov-2026 | Alcance: consulta, NO edición de HC ni HIC.
 *
 * Sólo cuando se consulta la posición regional 5455382 (Alma), permite que
 * dos directores distritales con referencia 1740346 sean considerados hijos
 * directos. Requiere coincidencia de id_posicion, distrito, puesto y nombre
 * completo del superior. No define equivalencia global entre las posiciones.
 *
 * El predicado se utiliza tanto para la recursión del selector como para
 * obtener hijos directos. Se inactiva por sí solo si HC ya trae 5455382.
 *
 * Pendiente: solicitar a Capital Humano la rectificación del origen.
 */
if (!function_exists('tx_sur_hc_relacion_excepcional_sql')) {
    function tx_sur_hc_relacion_excepcional_sql(string $idSuperior, ?int $anio = null): string
    {
        if (trim($idSuperior) !== '5455382' || ($anio !== null && $anio < 2026)) {
            return '';
        }

        // Todas las piezas del predicado son constantes, nunca parámetros del usuario.
        // La comparación de tokens acepta ambos órdenes del nombre de Alma en HC.
        return " OR (
            posicion_lr = '1740346'
            AND anio >= 2026
            AND UPPER(TRIM(posicion)) = 'DIRECTOR DISTRITAL'
            AND (
                (id_posicion = '1739413'
                 AND UPPER(TRIM(distrito)) IN ('COATZA MINA','COATZA / MINA','COATZA-MINA'))
                OR
                (id_posicion = '5050741'
                 AND UPPER(TRIM(distrito)) IN ('TUXTLA','TUXTLA GUTIERREZ'))
            )
            AND UPPER(COALESCE(nombre_linea_reporte,'')) LIKE '%ALMA%'
            AND UPPER(COALESCE(nombre_linea_reporte,'')) LIKE '%YADIRA%'
            AND UPPER(COALESCE(nombre_linea_reporte,'')) LIKE '%CHAVEZ%'
            AND UPPER(COALESCE(nombre_linea_reporte,'')) LIKE '%CARRILLO%'
        )";
    }
}
