<?php
/**
 * TotalXpedient | Importador Clusters_BDU v2.0.1 (2026-11-10)
 * Ubicacion prevista: /plataforma/import/import_clusters_bdu.php
 * Requiere: /plataforma/conexion.php y /plataforma/includes/SimpleXLSX.php
 * IMPORTA EXCLUSIVAMENTE la hoja "Clusters". El catalogo se carga con el SQL 02.
 * La unidad de registro es: anio + periodo + numero_periodo + distrito + cluster_hijo.
 * Se admiten multiples cortes en el mismo Excel. Reimportar una clave actualiza ese corte.
 * NO se borran las semanas ni los meses que no aparezcan en el archivo.
 */
declare(strict_types=1);

session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['usuario'])) {
    header('Location: /plataforma/login.php');
    exit;
}
if (strtoupper(trim((string)($_SESSION['rol'] ?? ''))) !== 'ADMIN') {
    http_response_code(403);
    exit('Acceso restringido: se requiere perfil ADMIN.');
}
require_once dirname(__DIR__) . '/conexion.php';
require_once dirname(__DIR__) . '/includes/SimpleXLSX.php';
if (!isset($conexion) || !($conexion instanceof mysqli)) {
    http_response_code(500);
    exit('Conexion mysqli no disponible en conexion.php.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conexion->set_charset('utf8mb4');
ini_set('memory_limit', '512M');
set_time_limit(180);

if (empty($_SESSION['csrf_clusters_bdu'])) {
    $_SESSION['csrf_clusters_bdu'] = bin2hex(random_bytes(32));
}
function cbduEsc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function cbduNorm(string $s): string {
    $s = trim(strtr($s, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N','Ü'=>'U'
    ]));
    return strtoupper(preg_replace('/\s+/u', ' ', $s) ?? $s);
}
function cbduHeader(string $s): string {
    return preg_replace('/[^A-Z0-9]/', '', cbduNorm($s)) ?? '';
}
function cbduDistrito(string $s): string {
    $s = cbduNorm($s);
    $s = preg_replace('/\s*\/\s*/', '/', $s) ?? $s;
    return match ($s) {
        'CANCUN' => 'CANCUN',
        'MERIDA' => 'MERIDA',
        'TUXTLA', 'TUXTLA GUTIERREZ' => 'TUXTLA',
        'VILLAHERMOSA' => 'VILLAHERMOSA',
        'COATZA/MINA', 'COATZA-MINA', 'COATZACOALCOS/MINATITLAN', 'COATZACOALCOS-MINATITLAN' => 'COATZA/MINA',
        default => $s,
    };
}
function cbduText(mixed $v): string {
    return trim((string)($v ?? ''));
}
/** ND y guion => NULL; nunca traducir a cero. Numeros porcentuales => fraccion. */
function cbduNumber(mixed $v, bool $integer=false): int|float|null {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || in_array(cbduNorm($s), ['-', 'N/A', 'ND', 'N.D.', 'NULL'], true)) return null;
    if (is_int($v) || is_float($v)) {
        $n = (float)$v;
    } else {
        $percent = str_contains($s, '%');
        $s = str_replace(['%', ' ', "\xC2\xA0"], '', $s);
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $lastComma = strrpos($s, ',');
            $lastDot = strrpos($s, '.');
            $s = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $s))
                : str_replace(',', '', $s);
        } elseif (preg_match('/^-?\d{1,3}(,\d{3})+$/', $s)) {
            $s = str_replace(',', '', $s);
        } else {
            $s = str_replace(',', '.', $s);
        }
        if (!is_numeric($s)) throw new InvalidArgumentException('Valor numerico no valido: '.cbduText($v));
        $n = (float)$s;
        if ($percent) $n /= 100;
    }
    if (!is_finite($n)) throw new InvalidArgumentException('Valor numerico fuera de rango.');
    if ($integer) {
        if (abs($n - round($n)) > 0.000001 || abs($n) > PHP_INT_MAX) {
            throw new InvalidArgumentException('Se esperaba un entero: '.cbduText($v));
        }
        return (int)round($n);
    }
    return $n;
}
/** Los meses se identifican por nombre o numero, sin usar el nombre del archivo. */
function cbduPeriodo(mixed $tipoValor, mixed $nombreValor): array {
    $tipo = cbduNorm(cbduText($tipoValor));
    $nombre = cbduNorm(cbduText($nombreValor));
    if (!in_array($tipo, ['SEMANAL', 'MENSUAL'], true)) {
        throw new InvalidArgumentException('PERIODO debe ser SEMANAL o MENSUAL.');
    }
    if ($tipo === 'SEMANAL') {
        if (!preg_match('/^(?:(?:SEMANA|SEM|S)\s*)?#?\s*(\d{1,2})$/', $nombre, $m)) {
            throw new InvalidArgumentException('NOMBRE PERIODO semanal debe ser 1-53, #45, S45 o SEMANA 45.');
        }
        $n = (int)$m[1];
        if ($n < 1 || $n > 53) throw new InvalidArgumentException('Numero de semana fuera de 1-53.');
        return ['SEMANAL', $n, 'SEMANA '.$n];
    }
    $meses = [
        'ENERO'=>1, 'FEBRERO'=>2, 'MARZO'=>3, 'ABRIL'=>4, 'MAYO'=>5,
        'JUNIO'=>6, 'JULIO'=>7, 'AGOSTO'=>8, 'SEPTIEMBRE'=>9,
        'OCTUBRE'=>10, 'NOVIEMBRE'=>11, 'DICIEMBRE'=>12
    ];
    $numero = ctype_digit($nombre) ? (int)$nombre : ($meses[$nombre] ?? 0);
    if ($numero < 1 || $numero > 12) {
        throw new InvalidArgumentException('NOMBRE PERIODO mensual no reconocido: '.$nombre);
    }
    $mes = array_search($numero, $meses, true);
    return ['MENSUAL', $numero, (string)$mes];
}
function cbduBindExec(mysqli_stmt $stmt, string $types, array $values): void {
    $refs = [$types];
    foreach ($values as $i => &$value) $refs[] = &$value;
    unset($value);
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
}
function cbduParse(string $archivo): array {
    $libro = \Shuchkin\SimpleXLSX::parse($archivo);
    if (!$libro) {
        throw new RuntimeException('No se pudo abrir el archivo XLSX: '.\Shuchkin\SimpleXLSX::parseError());
    }
    $nombres = $libro->sheetNames();
    $indice = null;
    foreach ($nombres as $i => $nombre) {
        if (cbduHeader((string)$nombre) === 'CLUSTERS') {
            $indice = (int)$i;
            break;
        }
    }
    if ($indice === null) throw new RuntimeException('No se encontro la hoja obligatoria "Clusters".');
    $filas = $libro->rows($indice);
    if (count($filas) <= 1) throw new RuntimeException('La hoja Clusters no contiene filas de datos.');
    $campos = [
        'CLUSTER'=>'cluster_padre',
        'BASEUSUARIOS'=>'base_usuarios',
        'CASASPASADAS'=>'casas_pasadas',
        'PENETRACION'=>'penetracion',
        'ANTIGUEDADANOS'=>'antiguedad_anios',
        'MENSUALCANCELACIONES'=>'cancelaciones_mes',
        'ACUMULADOCANCELACIONES'=>'cancelaciones_acumuladas',
        'ADICIONESNETASULTIMOTRIMESTRE'=>'adiciones_netas_ultimo_trimestre',
        'VELOCIDADDEPENETRACIONANUAL'=>'velocidad_penetracion_anual',
        'CLUSTERHIJO'=>'cluster_hijo',
        'TIPO'=>'tipo', 'DISTRITO'=>'distrito',
        'PERIODO'=>'periodo', 'NOMBREPERIODO'=>'nombre_periodo',
        'ANO'=>'anio', 'ANIO'=>'anio'
    ];
    $indices = [];
    foreach ($filas[0] as $i => $valor) {
        $h = cbduHeader(cbduText($valor));
        if (isset($campos[$h])) $indices[$campos[$h]] = $i;
        if ($h === 'PLAZAMUNICIPIO') $indices['plaza_municipio_qlik'] = $i;
    }
    $obligatorios = array_values(array_unique($campos));
    foreach ($obligatorios as $campo) {
        if (!array_key_exists($campo, $indices)) {
            throw new RuntimeException('Falta una columna requerida en Clusters: '.$campo);
        }
    }
    $datos = [];
    $errores = [];
    $advertencias = [];
    $claves = [];
    $resumen = [];
    for ($i=1; $i<count($filas); $i++) {
        $f = $filas[$i];
        $nf = $i + 1;
        if (!array_filter($f, static fn($v): bool => cbduText($v) !== '')) continue;
        try {
            $get = static fn(string $k): mixed => $f[$indices[$k]] ?? null;
            $padre = cbduText($get('cluster_padre'));
            $hijo = cbduText($get('cluster_hijo'));
            $tipo = cbduNorm(cbduText($get('tipo')));
            $distrito = cbduDistrito(cbduText($get('distrito')));
            [$periodo, $numeroPeriodo, $nombrePeriodo] = cbduPeriodo($get('periodo'), $get('nombre_periodo'));
            $anio = cbduNumber($get('anio'), true);
            if ($anio === null || $anio < 2020 || $anio > 2100) throw new InvalidArgumentException('AÑO invalido.');
            if ($padre === '' || $hijo === '') throw new InvalidArgumentException('Cluster Padre o Hijo vacio.');
            if (strlen($padre) > 200 || strlen($hijo) > 200) throw new InvalidArgumentException('Nombre de cluster supera 200 bytes.');
            if (!in_array($tipo, ['NORMAL','CLUSTER_A'], true)) throw new InvalidArgumentException('Tipo invalido: '.$tipo);
            if (!in_array($distrito, ['CANCUN','COATZA/MINA','MERIDA','TUXTLA','VILLAHERMOSA'], true)) {
                throw new InvalidArgumentException('Distrito desconocido: '.$distrito);
            }
            $u = cbduNumber($get('base_usuarios'), true);
            $c = cbduNumber($get('casas_pasadas'), true);
            $p = cbduNumber($get('penetracion'));
            $a = cbduNumber($get('antiguedad_anios'));
            $canMes = cbduNumber($get('cancelaciones_mes'), true);
            $canAcum = cbduNumber($get('cancelaciones_acumuladas'), true);
            $netas = cbduNumber($get('adiciones_netas_ultimo_trimestre'), true);
            $vel = cbduNumber($get('velocidad_penetracion_anual'));
            if (($u !== null && $u < 0) || ($c !== null && $c < 0)) {
                throw new InvalidArgumentException('Usuarios/Casas Pasadas no pueden ser negativos.');
            }
            if ($p !== null && ($p < 0 || $p > 1)) throw new InvalidArgumentException('Penetracion fuera de 0-1.');
            if ($a !== null && $a < 0) throw new InvalidArgumentException('Antiguedad negativa.');
            $clave = implode('|', [$anio, $periodo, $numeroPeriodo, $distrito, cbduNorm($hijo)]);
            if (isset($claves[$clave])) {
                throw new InvalidArgumentException('Clave duplicada del mismo corte; fila previa '.$claves[$clave]);
            }
            $claves[$clave] = $nf;
            $plaza = array_key_exists('plaza_municipio_qlik', $indices)
                ? cbduText($get('plaza_municipio_qlik')) : '';
            $datos[] = [
                'anio'=>$anio, 'periodo'=>$periodo, 'numero_periodo'=>$numeroPeriodo,
                'nombre_periodo'=>$nombrePeriodo, 'distrito'=>$distrito,
                'cluster_padre'=>$padre, 'cluster_hijo'=>$hijo, 'tipo'=>$tipo,
                'base_usuarios'=>$u, 'casas_pasadas'=>$c, 'penetracion'=>$p,
                'antiguedad_anios'=>$a, 'cancelaciones_mes'=>$canMes,
                'cancelaciones_acumuladas'=>$canAcum,
                'adiciones_netas_ultimo_trimestre'=>$netas,
                'velocidad_penetracion_anual'=>$vel,
                'plaza_municipio_qlik'=>$plaza !== '' ? $plaza : null,
                'fila_origen'=>$nf
            ];
            $rk = "$anio | $periodo $nombrePeriodo | $distrito";
            $resumen[$rk] = ($resumen[$rk] ?? 0) + 1;
            if ($c === 0 && $u !== null && $u > 0) {
                $advertencias[] = "Fila $nf: $hijo con $u usuarios y 0 casas pasadas.";
            } elseif ($c !== null && $c > 0 && $u === null) {
                $advertencias[] = "Fila $nf: $hijo con casas pasadas pero usuarios ND.";
            }
            if ($c !== null && $c > 0 && $u !== null && $p !== null && abs($p-$u/$c)>0.002) {
                $advertencias[] = "Fila $nf: penetracion Qlik difiere mas de 0.2 puntos porcentuales del cociente usuarios/casas.";
            }
            if ($tipo === 'NORMAL' && cbduNorm($padre) !== cbduNorm($hijo)) {
                $advertencias[] = "Fila $nf: NORMAL con padre diferente del hijo.";
            }
        } catch (Throwable $e) {
            $errores[] = "Fila $nf: ".$e->getMessage();
        }
    }
    if (!$datos && !$errores) $errores[] = 'No hay registros de datos.';
    ksort($resumen);
    return [$datos, $errores, $advertencias, $resumen];
}

$salida = '';
$detalle = [];
$resumen = [];
$exito = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['csrf_clusters_bdu'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Token de formulario invalido; vuelve a cargar la pagina.');
        }
        $accion = (string)($_POST['accion'] ?? 'validar');
        if (!in_array($accion, ['validar', 'importar'], true)) throw new RuntimeException('Accion desconocida.');
        $file = $_FILES['archivo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('No se recibio el Excel (verifica upload_max_filesize de PHP).');
        }
        if ((int)$file['size'] > 15 * 1024 * 1024) throw new RuntimeException('Tamano maximo: 15 MB.');
        $nombre = basename((string)$file['name']);
        if (strtolower(pathinfo($nombre, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Solo se admite formato XLSX.');
        }
        if (!is_uploaded_file((string)$file['tmp_name'])) {
            throw new RuntimeException('Archivo no reconocido como carga HTTP.');
        }
        [$datos, $errores, $advertencias, $resumen] = cbduParse((string)$file['tmp_name']);
        if ($errores) {
            $salida = 'Validacion rechazada. No se realizaron modificaciones.';
            $detalle = array_merge($errores, array_slice($advertencias, 0, 12));
        } else {
            // Revisar claves catalogadas, sin cambiar ni insertar en Catalogo.
            $catalogados = [];
            $q = $conexion->query('SELECT distrito, cluster_hijo FROM `Clusters_BDU_Catalogo`');
            while ($r = $q->fetch_assoc()) {
                $catalogados[cbduDistrito((string)$r['distrito']).'|'.cbduNorm((string)$r['cluster_hijo'])] = true;
            }
            $sinCatalogo = [];
            foreach ($datos as $d) {
                $k = $d['distrito'].'|'.cbduNorm($d['cluster_hijo']);
                if (!isset($catalogados[$k])) $sinCatalogo[$k] = true;
            }
            if ($sinCatalogo) {
                $advertencias[] = count($sinCatalogo).' cluster(es) hijo no tienen correspondencia en Catalogo. Completar con INSERT manual.';
                foreach (array_slice(array_keys($sinCatalogo), 0, 10) as $k) {
                    $advertencias[] = 'Sin catalogo: '.$k;
                }
            }
            if ($accion === 'importar') {
                $conexion->begin_transaction();
                try {
                    $sql = "INSERT INTO `Clusters_BDU`
                      (`anio`,`periodo`,`numero_periodo`,`nombre_periodo`,`distrito`,
                       `cluster_padre`,`cluster_hijo`,`tipo`,`base_usuarios`,`casas_pasadas`,
                       `penetracion`,`antiguedad_anios`,`cancelaciones_mes`,`cancelaciones_acumuladas`,
                       `adiciones_netas_ultimo_trimestre`,`velocidad_penetracion_anual`,
                       `plaza_municipio_qlik`,`archivo_origen`,`fila_origen`,`usuario_importacion`)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                      ON DUPLICATE KEY UPDATE
                        `nombre_periodo`=VALUES(`nombre_periodo`),
                        `cluster_padre`=VALUES(`cluster_padre`), `tipo`=VALUES(`tipo`),
                        `base_usuarios`=VALUES(`base_usuarios`), `casas_pasadas`=VALUES(`casas_pasadas`),
                        `penetracion`=VALUES(`penetracion`), `antiguedad_anios`=VALUES(`antiguedad_anios`),
                        `cancelaciones_mes`=VALUES(`cancelaciones_mes`),
                        `cancelaciones_acumuladas`=VALUES(`cancelaciones_acumuladas`),
                        `adiciones_netas_ultimo_trimestre`=VALUES(`adiciones_netas_ultimo_trimestre`),
                        `velocidad_penetracion_anual`=VALUES(`velocidad_penetracion_anual`),
                        `plaza_municipio_qlik`=VALUES(`plaza_municipio_qlik`),
                        `archivo_origen`=VALUES(`archivo_origen`),
                        `fila_origen`=VALUES(`fila_origen`),
                        `usuario_importacion`=VALUES(`usuario_importacion`)";
                    $st = $conexion->prepare($sql);
                    foreach ($datos as $d) {
                        $valores = [
                            $d['anio'], $d['periodo'], $d['numero_periodo'], $d['nombre_periodo'],
                            $d['distrito'], $d['cluster_padre'], $d['cluster_hijo'], $d['tipo'],
                            $d['base_usuarios'], $d['casas_pasadas'], $d['penetracion'], $d['antiguedad_anios'],
                            $d['cancelaciones_mes'], $d['cancelaciones_acumuladas'],
                            $d['adiciones_netas_ultimo_trimestre'], $d['velocidad_penetracion_anual'],
                            $d['plaza_municipio_qlik'], $nombre, $d['fila_origen'],
                            (string)$_SESSION['usuario']
                        ];
                        cbduBindExec($st, 'isisssssiiddiiidssis', $valores);
                    }
                    $st->close();
                    $log = $conexion->prepare('INSERT INTO `Clusters_BDU_Importaciones` (`archivo_origen`,`usuario`,`registros_procesados`,`resumen_periodos`) VALUES (?,?,?,?)');
                    cbduBindExec($log, 'ssis', [
                        $nombre, (string)$_SESSION['usuario'], count($datos),
                        json_encode($resumen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
                    ]);
                    $log->close();
                    $conexion->commit();
                    $salida = 'Importacion confirmada: '.count($datos).' registros procesados (altas o actualizaciones por clave).';
                    $exito = true;
                } catch (Throwable $e) {
                    $conexion->rollback();
                    throw $e;
                }
            } else {
                $salida = 'VALIDACION OK: '.count($datos).' registros aptos. Ningun dato fue modificado.';
                $exito = true;
            }
            $detalle = array_slice($advertencias, 0, 25);
            if (count($advertencias) > 25) $detalle[] = '... y '.(count($advertencias) - 25).' observaciones adicionales.';
        }
    } catch (Throwable $e) {
        $salida = 'No se realizo la importacion: '.$e->getMessage();
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clusters BDU | Importacion semanal y mensual</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f2f6f5;color:#20302b}
main{max-width:900px;margin:50px auto;background:#fff;border-radius:15px;padding:32px;box-shadow:0 7px 28px #132e241a}
h1{font-size:25px;margin:0 0 8px;color:#12563e}p{line-height:1.55;color:#53645d}
label{display:block;font-weight:650;margin:20px 0 8px}input[type=file]{display:block;padding:17px;border:2px dashed #8ab6a4;border-radius:9px;width:100%;box-sizing:border-box}
.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}
button{border:0;border-radius:8px;padding:12px 20px;background:#136b48;color:#fff;font-weight:700;cursor:pointer}
button.secondary{background:#536d62}.notice{padding:15px;border-radius:8px;background:#fff3d7;margin:22px 0}
.notice.good{background:#e2f6e9;color:#124c32}.notice.bad{background:#ffebea;color:#8b2222}
table{border-collapse:collapse;width:100%;margin-top:15px;font-size:14px}td,th{text-align:left;padding:9px;border-bottom:1px solid #e8eeee}
code{background:#e8f4ee;padding:2px 5px;border-radius:4px}.small{font-size:13px}
</style></head><body><main>
<h1>Penetracion de Clusters | Importador v2.0.1</h1>
<p>Importa <strong>solo la hoja Clusters</strong>. Reconoce los campos <code>PERIODO</code>, <code>NOMBRE PERIODO</code> y <code>AÑO</code>. Los cortes SEMANAL y MENSUAL se guardan independientemente; las claves existentes se actualizan sin generar duplicados.</p>
<p class="small">CatalogoClusters se mantiene exclusivamente con SQL. La columna PLAZA (MUNICIPIO) de Clusters se conserva como dato de auditoria; el tablero debe consultar el municipio desde el Catalogo.</p>
<?php if ($salida !== ''): ?>
<div class="notice <?= $exito ? 'good' : 'bad' ?>"><?= cbduEsc($salida) ?></div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= cbduEsc($_SESSION['csrf_clusters_bdu']) ?>">
<label for="archivo">Archivo XLSX de Qlik</label><input id="archivo" name="archivo" type="file" accept=".xlsx" required>
<div class="actions"><button class="secondary" name="accion" value="validar" type="submit">1. Solo validar</button>
<button name="accion" value="importar" type="submit" onclick="return confirm('¿Importar estos cortes a Clusters_BDU? Se actualizaran las claves existentes del mismo corte.');">2. Importar a MySQL</button></div>
</form>
<?php if ($resumen): ?><h2>Resumen por corte</h2><table><thead><tr><th>Año, periodo y distrito</th><th>Filas</th></tr></thead><tbody>
<?php foreach($resumen as $clave => $n): ?><tr><td><?= cbduEsc((string)$clave) ?></td><td><?= (int)$n ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?>
<?php if ($detalle): ?><h2>Observaciones</h2><ul><?php foreach($detalle as $m): ?><li><?= cbduEsc($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
<p class="small">Los guiones de Qlik se guardan como NULL. Si se vuelve a importar un mismo corte, se actualizan sus indicadores. El sistema no borra registros ausentes del XLSX.</p>
</main></body></html>
