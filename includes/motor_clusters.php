<?php
/**
 * TalIA / TotalXpedient | MOTOR CLUSTERS v1.7.0 | 12-nov-2026
 * Ubicación de despliegue: /plataforma/includes/motor_clusters.php
 * Autoridad única de metas de penetración y velocidad anual POR ANTIGÜEDAD.
 * Lectura/calculo puro: no modifica Clusters_BDU ni su catálogo.
 *
 * Reglas por defecto (porcentaje anual para velocidad):
 *  Edad [0,12m): Pen 0 -> 15% lineal; vel 7% constante.
 *  Edad [12,24m): Pen 15 -> 19.9% lineal; vel 7 -> 4.9% lineal.
 *  Edad [24,60m): Pen 20 -> 30% lineal; vel 5 -> 3% lineal.
 *  Edad >=60m: Pen 30%; vel 3% constantes.
 *  * A partir de 24m se clasifica como Maduro; al límite hay un paso
 *    29.9 -> 30% en Pen y 4.9 -> 5.0% en velocidad, definidos por la directiva.
 *  El consolidado general se obtiene como combinación ponderada de metas por antigüedad.
 * Se interpola por HIJO al corte Qlik; los consolidados ponderan cada meta por HP.
 */
declare(strict_types=1);

final class MotorClusters
{
    public const VERSION = '1.7.0-tipo-cluster-y-metas-ajustadas';
    public const DEFAULTS = [
        'a_0_12_pen' => 15.0, 'a_0_12_vel' => 7.0,
        'a_12_24_pen' => 19.9, 'a_12_24_vel' => 4.9,
        'm_24_60_pen' => 20.0, 'm_24_60_vel' => 5.0,
        'm_60_plus_pen' => 30.0, 'm_60_plus_vel' => 3.0,
    ];
    private const ALLOWED_REQUEST = [
        'a_0_12_pen','a_0_12_vel','a_12_24_pen','a_12_24_vel',
        'm_24_60_pen','m_24_60_vel','m_60_plus_pen','m_60_plus_vel',
    ];
    public const EDADES = [
        'A_0_12' => 'De 0 a menos de 1 año',
        'A_12_24' => 'De 1 a menos de 2 años',
        'M_24_60' => 'De 2 a menos de 5 años',
        'M_60_PLUS' => 'De 5 años en adelante',
    ];

    /** Parámetros explícitos; jamás se recortan o corrigen silenciosamente. */
    public static function parametros(?array $inputs = null): array
    {
        $out = self::DEFAULTS;
        foreach (self::ALLOWED_REQUEST as $key) {
            if (!array_key_exists($key, $inputs ?? [])) continue;
            $value = $inputs[$key];
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Formato inválido para '.$key);
            }
            $s = trim((string)$value);
            if ($s === '' || strlen($s)>16 || !preg_match('/^(?:\d{1,3})(?:\.\d{1,3})?$/D', $s)) {
                throw new InvalidArgumentException('Captura numérica inválida para '.$key.'. Usa punto decimal, por ejemplo 29.9.');
            }
            $f = (float)$s;
            if (!is_finite($f) || $f<0 || $f>100) {
                throw new InvalidArgumentException('El valor de '.$key.' debe estar entre 0 y 100%.');
            }
            $out[$key] = $f;
        }
        // Objetivos de penetración siempre iguales o mayores conforme madura el clúster.
        if ($out['a_0_12_pen'] > $out['a_12_24_pen'] ||
            $out['a_12_24_pen'] > $out['m_24_60_pen'] ||
            $out['m_24_60_pen'] > $out['m_60_plus_pen']) {
            throw new InvalidArgumentException('Penetración incongruente: A al año ≤ A a 2 años ≤ Maduro a 2 años ≤ Maduro a 5 años.');
        }
        // En cada etapa la velocidad se mantiene o disminuye según antigüedad.
        if ($out['a_0_12_vel'] < $out['a_12_24_vel'] || $out['m_24_60_vel'] < $out['m_60_plus_vel']) {
            throw new InvalidArgumentException('Velocidad incongruente: A al año ≥ A a 2 años y Maduro a 2 años ≥ Maduro a 5 años.');
        }
        // Se respeta el paso expresamente solicitado de 4.9% a 5.0% a los 24 meses;
        // se bloquean saltos ascendentes mayores a 0.1 pp al cambiar de segmento.
        if ($out['m_24_60_vel'] > $out['a_12_24_vel'] + 0.100001) {
            throw new InvalidArgumentException('Velocidad incongruente al pasar de A a Maduro: la meta a 2 años no puede aumentar más de 0.1 pp (default 4.9% → 5.0%).');
        }
        return $out;
    }

    /** Antigüedad histórica: fecha certificada del catálogo o edad Qlik del corte. */
    public static function edadMeses(array $row, string $fechaCorte): ?float
    {
        $f = trim((string)($row['fecha_liberacion'] ?? ''));
        if ($f !== '' && $f !== '0000-00-00') {
            $alta = DateTimeImmutable::createFromFormat('!Y-m-d',$f);
            $corte = DateTimeImmutable::createFromFormat('!Y-m-d',$fechaCorte);
            if (!$alta || !$corte || $alta->format('Y-m-d')!==$f || $corte->format('Y-m-d')!==$fechaCorte || $alta>$corte) {
                return null; // No sustituir silenciosamente una fecha confirmada inconsistente.
            }
            $delta = $alta->diff($corte);
            return $delta->y*12.0 + $delta->m + $delta->d/30.4375;
        }
        $age = $row['antiguedad_anios'] ?? null;
        return ($age !== null && is_numeric($age) && (float)$age>=0 && is_finite((float)$age))
            ? 12.0*(float)$age : null;
    }

    public static function etapa(?float $meses): ?string
    {
        if ($meses===null || !is_finite($meses) || $meses<0) return null;
        if ($meses<12.0) return 'A_0_12';
        if ($meses<24.0) return 'A_12_24';
        if ($meses<60.0) return 'M_24_60';
        return 'M_60_PLUS';
    }

    public static function tipoGestion(?float $meses, string $tipoQlik): string
    {
        $etapa=self::etapa($meses);
        if ($etapa!==null) return str_starts_with($etapa,'A_') ? 'CLUSTER_A' : 'NORMAL';
        return in_array($tipoQlik,['NORMAL','CLUSTER_A'],true) ? $tipoQlik : 'SIN_CLASIFICAR';
    }

    private static function interpolar(float $edad, float $inicio, float $fin, float $base, float $cima): float
    {
        $p = max(0.0,min(1.0,($edad-$inicio)/($fin-$inicio)));
        return $base + ($cima-$base)*$p;
    }

    /** Devuelve ambas metas individualizadas, calculadas sobre meses del hijo. */
    public static function objetivo(?float $meses, string $tipoQlik, array $params): array
    {
        $stage = self::etapa($meses);
        $pen = null; $vel = null;
        if ($stage==='A_0_12') {
            // Misma regla histórica del tablero: alcance proporcional a 20% al cumplir 12 meses.
            $pen=self::interpolar((float)$meses,0,12,0,(float)$params['a_0_12_pen']);
            $vel=(float)$params['a_0_12_vel'];
        } elseif ($stage==='A_12_24') {
            $pen=self::interpolar((float)$meses,12,24,(float)$params['a_0_12_pen'],(float)$params['a_12_24_pen']);
            $vel=self::interpolar((float)$meses,12,24,(float)$params['a_0_12_vel'],(float)$params['a_12_24_vel']);
        } elseif ($stage==='M_24_60') {
            $pen=self::interpolar((float)$meses,24,60,(float)$params['m_24_60_pen'],(float)$params['m_60_plus_pen']);
            $vel=self::interpolar((float)$meses,24,60,(float)$params['m_24_60_vel'],(float)$params['m_60_plus_vel']);
        } elseif ($stage==='M_60_PLUS') {
            $pen=(float)$params['m_60_plus_pen'];
            $vel=(float)$params['m_60_plus_vel'];
        }
        return [
            'etapa'=>$stage,
            'etiqueta'=>$stage===null?'Sin antigüedad validada':self::EDADES[$stage],
            'tipo_gestion'=>self::tipoGestion($meses,$tipoQlik),
            'pen'=>$pen, 'vel'=>$vel,
        ];
    }

    /** Completa filas sin acceder a BD ni alterar los valores originales de Qlik. */
    public static function enriquecer(array $rows, string $fechaCorte, array $params): array
    {
        foreach ($rows as &$row) {
            $months=self::edadMeses($row,$fechaCorte);
            $target=self::objetivo($months,(string)($row['tipo']??''),$params);
            $row['edad_meses_gestion']=$months;
            $row['edad_gestion']=$months===null?null:$months/12.0;
            $row['tipo_gestion']=$target['tipo_gestion'];
            $row['etapa_gestion']=$target['etapa'];
            $row['etapa_etiqueta']=$target['etiqueta'];
            $row['meta_pen_gestion']=$target['pen'];
            $row['meta_vel_gestion']=$target['vel'];
            $row['tipo_q_gestion_distinto']=(string)($row['tipo']??'')!==$target['tipo_gestion'];
        }
        unset($row);
        return $rows;
    }

    /** Faltantes sin compensar: techo del déficit por hijo, mínimo cero. */
    public static function faltaSuscriptores(int $hp, int $usuarios, ?float $meta): ?int
    {
        if ($hp<=0 || $meta===null) return null;
        return max(0,(int)ceil($hp*$meta/100.0 - $usuarios - 1e-9));
    }
}
