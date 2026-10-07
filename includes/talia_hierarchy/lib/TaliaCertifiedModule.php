<?php
/** Registro ligero de módulos que ya consumen el resolver canónico. */
final class TaliaCertifiedModule
{
    public const RESOLVER_VERSION = '1.0.0';
    public const MODULES = [
        'ranking_productividad' => [
            'status' => 'CERTIFIED_BASELINE',
            'resolver' => self::RESOLVER_VERSION,
            'rules' => ['HIC','STRUCTURE','EVENT_FIRST','DIRECT_PRIORITY','FALLBACK','COACHES_MATCH','SARGABLE_DATES'],
        ],
        'reai' => [
            'status' => 'PENDING_MIGRATION',
            'resolver' => self::RESOLVER_VERSION,
            'rules' => [],
        ],
    ];
}
