<?php
declare(strict_types=1);
/**
 * TalIA – Fachada transversal de consulta jerárquica v0.2 (SOLO LECTURA).
 * No reimplementa atribución: consume EXCLUSIVAMENTE RankingAtribucionService v0.1.
 * Las claves DISTRITO|NOMBRE y EVENT| son claves de reporte, NO IDs certificados de persona.
 * SIN_COACH/SIN_VENDEDOR son categorías de conservación, NO responsables inventados.
 */
require_once __DIR__ . '/ranking_atribucion_service.php';

final class TaliaJerarquiaConsultaService
{
    private RankingAtribucionService $atribucion;
    private array $lideres;

    public function __construct(RankingAtribucionService $atribucion, ?array $lideres = null)
    {
        $this->atribucion = $atribucion;
        $this->lideres = $lideres ?? RankingAtribucionService::lideresStaging();
    }

    /**
     * Entrada real: UN cálculo de atribución para el rango; luego se agrupa el mismo universo.
     * $filtro es identificador de reporte en el nivel elegido; null = todos en ese nivel.
     * No implementa ventas (tabla ventas, fecha de cierre y folio aún por certificar).
     */
    public function consultar(array $opciones): array
    {
        $desde = (string)($opciones['desde'] ?? '');
        $hasta = (string)($opciones['hasta'] ?? '');
        $dias = $opciones['dias_iso'] ?? [];
        if (!is_array($dias)) throw new InvalidArgumentException('dias_iso debe ser arreglo');
        $carga = $this->atribucion->obtenerInstalacionesJerarquia($desde, $hasta, null, null, null, $dias);
        return $this->agruparFilas($carga['cuentas'], array_merge($opciones, [
            'fotografias_hc' => $carga['fotografias_hc'],
            'version_atribucion' => $carga['version'],
        ]));
    }

    /** Función pura de agrupación, apta para tests sin acceso a MariaDB. */
    public function agruparFilas(array $filas, array $opciones): array
    {
        $nivel = strtoupper(trim((string)($opciones['nivel'] ?? 'LIDER')));
        if (!in_array($nivel, ['REGION','DISTRITO','LIDER','COACH','VENDEDOR'], true)) {
            throw new InvalidArgumentException('Nivel inválido');
        }
        $filtro = $opciones['id'] ?? null;
        $filtro = $filtro === null ? null : trim((string)$filtro);
        $idLider = $opciones['id_lider'] ?? null;
        $idCoach = $opciones['id_coach'] ?? null;
        $idVendedor = $opciones['id_vendedor'] ?? null;
        $detalle = ($opciones['detalle_cuentas'] ?? false) === true;
        $agrupados=[]; $cuentasIncluidas=[]; $estados=[]; $alertas=[];
        foreach ($filas as $r) {
            $lid = $r['lider_id'] ?? null;
            $distrito = ($lid !== null && isset($this->lideres[$lid])) ? $this->lideres[$lid]['distrito'] : 'SIN_DISTRITO';
            $coach = $r['coach_id'] ?? 'SIN_COACH';
            $vend = $r['vendedor_id'] ?? 'SIN_VENDEDOR';
            if ($idLider !== null && $lid !== $idLider) continue;
            if ($idCoach !== null && $coach !== $idCoach) continue;
            if ($idVendedor !== null && $vend !== $idVendedor) continue;
            if (($opciones['distrito'] ?? null) !== null && $distrito !== $opciones['distrito']) continue;
            $key = match($nivel) {
                'REGION' => 'REGION_SUR',
                'DISTRITO' => $distrito,
                'LIDER' => $lid ?? 'SIN_LIDER',
                'COACH' => ($lid ?? 'SIN_LIDER').'|COACH|'.$coach,
                'VENDEDOR' => ($lid ?? 'SIN_LIDER').'|COACH|'.$coach.'|VENDEDOR|'.$vend,
            };
            if ($filtro !== null && $key !== $filtro) continue;
            $cuenta = (string)($r['cuenta'] ?? '');
            $checkKey = $cuenta;
            if ($checkKey === '') throw new LogicException('Instalación sin cuenta en universo resuelto');
            if (isset($cuentasIncluidas[$checkKey])) {
                throw new LogicException('El motor proporcionó cuenta duplicada: '.$cuenta);
            }
            $cuentasIncluidas[$checkKey]=true;
            if (!isset($agrupados[$key])) {
                $agrupados[$key]=['id'=>$key,'nivel'=>$nivel,'distrito'=>$distrito,
                    'lider_id'=>$lid,'coach_id'=>in_array($nivel,['COACH','VENDEDOR'],true)?$coach:null,
                    'vendedor_id'=>$nivel==='VENDEDOR'?$vend:null,
                    'instalaciones'=>0,'estados'=>[]];
                if ($detalle) $agrupados[$key]['cuentas']=[];
            }
            $agrupados[$key]['instalaciones']++;
            $estado = (string)($r['estado'] ?? 'SIN_ESTADO');
            $agrupados[$key]['estados'][$estado]=($agrupados[$key]['estados'][$estado]??0)+1;
            $estados[$estado]=($estados[$estado]??0)+1;
            if ($detalle) $agrupados[$key]['cuentas'][]=$r;
            if ($estado !== 'OK' || (is_string($coach) && str_starts_with($coach,'EVENT|'))) {
                $alertas[$estado]=($alertas[$estado]??0)+1;
            }
        }
        ksort($agrupados, SORT_STRING);
        $total=count($cuentasIncluidas);
        $suma=array_sum(array_column($agrupados,'instalaciones'));
        if ($suma !== $total) throw new LogicException('Diferencia de conservación jerárquica');
        return [
            'version'=>'0.2.0-consulta',
            'motor_atribucion'=>$opciones['version_atribucion'] ?? '0.1.0-auditoria',
            'nivel'=>$nivel,'id_consultado'=>$filtro,
            'total_instalaciones'=>$total,'suma_nodos'=>$suma,'conservacion'=>true,
            'nodos'=>array_values($agrupados),'estados'=>$estados,'alertas'=>$alertas,
            'fotografias_hc'=>$opciones['fotografias_hc'] ?? [],
            'advertencias'=>[
                'Identidades EVENT| y claves de líder actuales son provisionales hasta certificación Personas/HIC.',
                'La conservación numérica no certifica atribución organizacional ni completitud de fuentes.',
                'Ventas y headcount no se calculan en esta versión: sólo instalaciones atribuidas.',
            ],
        ];
    }
}
