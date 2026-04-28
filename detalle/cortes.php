<?php
// 1. INCLUIR CONEXIÓN A BASE DE DATOS
// Cambia esto por tu archivo real de conexión
include 'conexion.php'; 

// --- SECCIÓN AJAX: GUARDAR DATOS SIN RECARGAR ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $datos = json_decode(file_get_contents('php://input'), true);
    
    if($datos) {
        $fecha = $datos['fecha'];
        $hora  = $datos['hora'];
        
        foreach($datos['filas'] as $fila) {
            $distrito = mysqli_real_escape_string($conexion, $fila['distrito']);
            $obj_v    = (int)$fila['obj_v'];
            $act_v    = (int)$fila['act_v'];
            $obj_i    = (int)$fila['obj_i'];
            $act_i    = (int)$fila['act_i'];
            
            // Insertar o actualizar si ya existe para esa hora, fecha y distrito
            $query = "INSERT INTO cortes_seguimiento 
                        (fecha, hora_corte, distrito, obj_ventas, ventas, obj_instalaciones, instalaciones) 
                      VALUES ('$fecha', '$hora', '$distrito', $obj_v, $act_v, $obj_i, $act_i)
                      ON DUPLICATE KEY UPDATE 
                        obj_ventas = $obj_v, ventas = $act_v, 
                        obj_instalaciones = $obj_i, instalaciones = $act_i";
            
            mysqli_query($conexion, $query);
        }
        echo json_encode(['status' => 'ok']);
    }
    exit; // Detenemos aquí para que AJAX solo reciba el JSON
}

// --- SECCIÓN VISTA: CARGAR DATOS ---
$fecha_hoy_str = date('d-M-y'); // Ej: 27-Apr-26
$fecha_hoy_db  = date('Y-m-d'); 

$fecha_pasada_str = date('d-M', strtotime('-7 days')); // Ej: 20-Apr
$fecha_pasada_db  = date('Y-m-d', strtotime('-7 days'));

// Obtener la hora seleccionada (por defecto 10:00)
$hora_seleccionada = $_GET['hora'] ?? '10:00';

$distritos = ['CANCÚN', 'COATZA/M', 'MÉRIDA', 'TUXTLA', 'VILLAHERM'];

// Traer datos de HOY a esta HORA
$datos_hoy = [];
$res_hoy = mysqli_query($conexion, "SELECT * FROM cortes_seguimiento WHERE fecha = '$fecha_hoy_db' AND hora_corte = '$hora_seleccionada'");
while($row = mysqli_fetch_assoc($res_hoy)) {
    $datos_hoy[$row['distrito']] = $row;
}

// Traer datos de la SEMANA PASADA a esta HORA
$datos_pasados = [];
$res_pasados = mysqli_query($conexion, "SELECT * FROM cortes_seguimiento WHERE fecha = '$fecha_pasada_db' AND hora_corte = '$hora_seleccionada'");
while($row = mysqli_fetch_assoc($res_pasados)) {
    $datos_pasados[$row['distrito']] = $row;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cortes de Seguimiento</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; background-color: #f4f6fb;}
        .contenedor { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header-cortes { display: flex; justify-content: space-between; align-items: center; font-weight: bold; font-size: 1.2rem; margin-bottom: 15px; }
        
        .table-cortes { width: 100%; border-collapse: collapse; text-align: center; font-size: 0.9rem; }
        .table-cortes th, .table-cortes td { border: 1px solid #b3b3b3; padding: 8px 4px; }
        .table-cortes td:first-child { text-align: left; padding-left: 10px; font-weight: 500; }
        
        .bg-blue { background-color: #38bdf8; color: white; font-weight: bold; }
        .bg-gray { background-color: #e5e7eb; font-weight: bold;}
        
        .input-captura { width: 45px; text-align: center; border: 1px solid #ccc; border-radius: 3px; padding: 4px; font-weight: bold; }
        .input-captura:focus { outline: 2px solid #38bdf8; border-color: transparent;}
        
        .text-red { color: #dc2626; font-weight: bold;}
        .text-green { color: #16a34a; font-weight: bold;}
        
        .btn-guardar { background-color: #2b57a7; color: white; border: none; padding: 10px 20px; font-size: 1rem; border-radius: 5px; cursor: pointer; float: right; margin-top: 15px; font-weight: bold;}
        .btn-guardar:hover { background-color: #1e3f7d; }
        select { padding: 5px; border-radius: 4px; font-weight:bold; cursor: pointer;}
    </style>
</head>
<body>

<div class="contenedor">
    <div class="header-cortes">
        <div style="font-size: 1.4rem;"><?= htmlspecialchars($fecha_hoy_str) ?></div>
        <div>
            CORTE: 
            <select id="selectHora" onchange="cambiarHora()">
                <?php 
                $horas_disponibles = ['10:00', '12:00', '14:00', '16:00', '18:00'];
                foreach($horas_disponibles as $h) {
                    $sel = ($h === $hora_seleccionada) ? 'selected' : '';
                    echo "<option value='$h' $sel>$h HRS</option>";
                }
                ?>
            </select>
        </div>
    </div>

    <table class="table-cortes" id="tablaCortes">
        <thead>
            <tr class="bg-blue">
                <th rowspan="2">DISTRITO</th>
                <th colspan="4">VENTAS</th>
                <th colspan="4">INSTALADAS</th>
                <th rowspan="2">CONV</th>
            </tr>
            <tr class="bg-blue">
                <th>OBJ</th>
                <th><?= htmlspecialchars($fecha_pasada_str) ?></th>
                <th><?= substr($fecha_hoy_str, 0, 6) ?></th> <!-- Muestra solo el dia y mes Ej: 27-Apr -->
                <th>DIF - Sem p</th>
                <th>OBJ</th>
                <th><?= htmlspecialchars($fecha_pasada_str) ?></th>
                <th><?= substr($fecha_hoy_str, 0, 6) ?></th>
                <th>DIF - Sem p</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($distritos as $distrito): 
                // Extraer variables, si no existen en DB ponemos 0
                $obj_v = $datos_hoy[$distrito]['obj_ventas'] ?? 0;
                $act_v = $datos_hoy[$distrito]['ventas'] ?? 0;
                $obj_i = $datos_hoy[$distrito]['obj_instalaciones'] ?? 0;
                $act_i = $datos_hoy[$distrito]['instalaciones'] ?? 0;
                
                $pas_v = $datos_pasados[$distrito]['ventas'] ?? 0;
                $pas_i = $datos_pasados[$distrito]['instalaciones'] ?? 0;
            ?>
            <tr class="fila-distrito">
                <td class="nombre-distrito"><?= htmlspecialchars($distrito) ?></td>
                
                <!-- VENTAS -->
                <td><input type="number" class="input-captura obj-v" value="<?= $obj_v ?>" onkeyup="recalcularTodo()" onchange="recalcularTodo()"></td>
                <td class="v-pasada"><?= $pas_v ?></td>
                <td><input type="number" class="input-captura act-v" value="<?= $act_v ?>" onkeyup="recalcularTodo()" onchange="recalcularTodo()"></td>
                <td class="dif-v">0</td>
                
                <!-- INSTALADAS -->
                <td><input type="number" class="input-captura obj-i" value="<?= $obj_i ?>" onkeyup="recalcularTodo()" onchange="recalcularTodo()"></td>
                <td class="i-pasada"><?= $pas_i ?></td>
                <td><input type="number" class="input-captura act-i" value="<?= $act_i ?>" onkeyup="recalcularTodo()" onchange="recalcularTodo()"></td>
                <td class="dif-i">0</td>
                
                <!-- CONVERSIÓN -->
                <td class="conv">0%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="bg-gray">
                <td>REGIÓN</td>
                <td id="tot-obj-v">0</td>
                <td id="tot-pas-v">0</td>
                <td id="tot-act-v">0</td>
                <td id="tot-dif-v">0</td>
                <td id="tot-obj-i">0</td>
                <td id="tot-pas-i">0</td>
                <td id="tot-act-i">0</td>
                <td id="tot-dif-i">0</td>
                <td id="tot-conv">0%</td>
            </tr>
        </tfoot>
    </table>

    <button class="btn-guardar" onclick="guardarDatos()">Guardar Cambios</button>
    <div style="clear: both;"></div>
</div>

<script>
// --- DATOS PHP A JS ---
const fechaDB = "<?= $fecha_hoy_db ?>";

function cambiarHora() {
    const hora = document.getElementById('selectHora').value;
    window.location.href = '?hora=' + hora;
}

// --- LÓGICA DE CÁLCULO ---
function recalcularTodo() {
    let totObjV = 0, totPasV = 0, totActV = 0;
    let totObjI = 0, totPasI = 0, totActI = 0;

    document.querySelectorAll('.fila-distrito').forEach(fila => {
        // Ventas
        const objV = parseFloat(fila.querySelector('.obj-v').value) || 0;
        const pasV = parseFloat(fila.querySelector('.v-pasada').innerText) || 0;
        const actV = parseFloat(fila.querySelector('.act-v').value) || 0;
        const difV = actV - pasV;
        
        fila.querySelector('.dif-v').innerText = difV;
        fila.querySelector('.dif-v').className = 'dif-v ' + (difV < 0 ? 'text-red' : 'text-green');

        // Instalaciones
        const objI = parseFloat(fila.querySelector('.obj-i').value) || 0;
        const pasI = parseFloat(fila.querySelector('.i-pasada').innerText) || 0;
        const actI = parseFloat(fila.querySelector('.act-i').value) || 0;
        const difI = actI - pasI;
        
        fila.querySelector('.dif-i').innerText = difI;
        fila.querySelector('.dif-i').className = 'dif-i ' + (difI < 0 ? 'text-red' : 'text-green');

        // Conversión
        let conv = 0;
        if (actV > 0) conv = Math.round((actI / actV) * 100);
        
        fila.querySelector('.conv').innerText = conv + '%';
        // Asumiendo que < 30% es rojo, ajústalo a tus necesidades
        fila.querySelector('.conv').className = 'conv ' + (conv < 30 ? 'text-red' : 'text-green');

        // Sumar para Totales
        totObjV += objV; totPasV += pasV; totActV += actV;
        totObjI += objI; totPasI += pasI; totActI += actI;
    });

    // Actualizar Totales Región
    document.getElementById('tot-obj-v').innerText = totObjV;
    document.getElementById('tot-pas-v').innerText = totPasV;
    document.getElementById('tot-act-v').innerText = totActV;
    const regDifV = totActV - totPasV;
    document.getElementById('tot-dif-v').innerText = regDifV;
    document.getElementById('tot-dif-v').className = regDifV < 0 ? 'text-red' : 'text-green';

    document.getElementById('tot-obj-i').innerText = totObjI;
    document.getElementById('tot-pas-i').innerText = totPasI;
    document.getElementById('tot-act-i').innerText = totActI;
    const regDifI = totActI - totPasI;
    document.getElementById('tot-dif-i').innerText = regDifI;
    document.getElementById('tot-dif-i').className = regDifI < 0 ? 'text-red' : 'text-green';

    let regConv = 0;
    if (totActV > 0) regConv = Math.round((totActI / totActV) * 100);
    document.getElementById('tot-conv').innerText = regConv + '%';
    document.getElementById('tot-conv').className = regConv < 30 ? 'text-red' : 'text-green';
}

// --- GUARDAR CON AJAX ---
function guardarDatos() {
    const btn = document.querySelector('.btn-guardar');
    btn.innerText = "Guardando...";
    btn.disabled = true;

    const hora = document.getElementById('selectHora').value;
    const filas = [];

    document.querySelectorAll('.fila-distrito').forEach(fila => {
        filas.push({
            distrito: fila.querySelector('.nombre-distrito').innerText,
            obj_v: fila.querySelector('.obj-v').value || 0,
            act_v: fila.querySelector('.act-v').value || 0,
            obj_i: fila.querySelector('.obj-i').value || 0,
            act_i: fila.querySelector('.act-i').value || 0
        });
    });

    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ fecha: fechaDB, hora: hora, filas: filas })
    })
    .then(response => response.json())
    .then(data => {
        if(data.status === 'ok') {
            btn.innerText = "¡Guardado con éxito!";
            btn.style.backgroundColor = "#16a34a"; // verde
            setTimeout(() => {
                btn.innerText = "Guardar Cambios";
                btn.style.backgroundColor = "#2b57a7"; // azul original
                btn.disabled = false;
            }, 2000);
        }
    })
    .catch(error => {
        alert("Hubo un error al guardar.");
        btn.innerText = "Guardar Cambios";
        btn.disabled = false;
    });
}

// Inicializar cálculos al cargar la página
document.addEventListener('DOMContentLoaded', recalcularTodo);
</script>

</body>
</html>