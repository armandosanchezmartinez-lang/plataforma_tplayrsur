<?php
/** DTO inmutable del corte que consume un módulo TalIA certificado. */
final class TaliaHierarchyContext
{
    public string $periodo;
    public string $fechaInicioBase;
    public string $fechaFinBase;
    public string $fechaInicioActual;
    public string $fechaFinActual;
    public int $hcAnioBase;
    public int $hcSemanaBase;
    public int $hcAnioActual;
    public int $hcSemanaActual;
    public array $mysqlDays;

    public function __construct(array $v)
    {
        $this->periodo = (string)$v['periodo'];
        $this->fechaInicioBase = (string)$v['fecha_inicio_base'];
        $this->fechaFinBase = (string)$v['fecha_fin_base'];
        $this->fechaInicioActual = (string)$v['fecha_inicio_actual'];
        $this->fechaFinActual = (string)$v['fecha_fin_actual'];
        $this->hcAnioBase = (int)$v['hc_anio_base'];
        $this->hcSemanaBase = (int)$v['hc_semana_base'];
        $this->hcAnioActual = (int)$v['hc_anio_actual'];
        $this->hcSemanaActual = (int)$v['hc_semana_actual'];
        $this->mysqlDays = array_values($v['mysql_days'] ?? []);
    }
}
