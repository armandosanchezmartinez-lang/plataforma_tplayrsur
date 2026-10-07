<?php
interface TaliaHierarchyResolverInterface
{
    public function version(): string;
    public function normalizeIdentity(string $value): string;
    public function sqlLideresActivosCte(string $cteName = 'lideres_activos'): string;
    public function sqlDateRangeCondition(string $alias, string $startDate, string $endDate, array $mysqlDays = []): string;
    public function sqlAttributedSalesExpr(string $periodo, string $directAlias, string $directColumn, string $fallbackAlias, string $fallbackColumn): string;
    public function sqlFolioMatch(string $installationAlias, string $personAlias): string;
    public function sqlCanonicalFolioExpr(string $folioExpr): string;
    public function sqlCoachStructurePredicate(string $hcAlias, string $coachAlias): string;
    public function sqlLeaderEventPredicate(string $installationAlias, string $leaderAlias): string;
    public function sqlCoachEventPredicate(string $installationAlias, string $coach): string;
    public function sqlHcCanonicalIdentityPredicate(string $hcAlias, string $canonicalFolioExpr): string;
}
