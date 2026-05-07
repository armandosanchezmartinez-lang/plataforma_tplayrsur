<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

$posiciones = [];
$db_ok = false;

// Intentar conectar — si falla, el form igual se muestra
if (file_exists('conexion.php')) {
    include 'conexion.php';
    if (isset($conexion) && $conexion) {
        $db_ok = true;
        // Cargar posiciones solo si la tabla existe
        $chk_tabla = mysqli_query($conexion, "SHOW TABLES LIKE 'posiciones'");
        if ($chk_tabla && mysqli_num_rows($chk_tabla) > 0) {
            $res = mysqli_query($conexion, "SELECT id, nombre FROM posiciones ORDER BY nombre ASC");
            if ($res) while ($p = mysqli_fetch_assoc($res)) $posiciones[] = $p;
        }
    }
}

$mensaje = '';
$tipo    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_ok) {
    $username          = trim($_POST['username']          ?? '');
    $password          = trim($_POST['password']          ?? '');
    $password_confirm  = trim($_POST['password_confirm']  ?? '');
    $email             = trim($_POST['email']             ?? '');
    $numero_talento_gs = trim($_POST['numero_talento_gs'] ?? '');
    $id_posicion       = intval($_POST['id_posicion']     ?? 0);

    $errores = [];
    if (!$username)                              $errores[] = 'El username es requerido';
    if (strlen($password) < 8)                   $errores[] = 'La contraseña debe tener al menos 8 caracteres';
    if ($password !== $password_confirm)          $errores[] = 'Las contraseñas no coinciden';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'Email inválido';

    if (empty($errores)) {
        $chk = mysqli_prepare($conexion, "SELECT id FROM usuarios WHERE username = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, 's', $username);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        if (mysqli_stmt_num_rows($chk) > 0) $errores[] = "El username '$username' ya existe";
        mysqli_stmt_close($chk);
    }

    if (empty($errores)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = mysqli_prepare($conexion,
            "INSERT INTO usuarios (username, password, email, numero_talento_gs, id_posicion, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        mysqli_stmt_bind_param($stmt, 'ssssi', $username, $hash, $email, $numero_talento_gs, $id_posicion);
        if (mysqli_stmt_execute($stmt)) {
            $mensaje = "Usuario <strong>" . htmlspecialchars($username) . "</strong> registrado correctamente.";
            $tipo    = 'success';
            // Limpiar POST para no re-poblar
            $_POST = [];
        } else {
            $mensaje = 'Error al insertar: ' . mysqli_stmt_error($stmt);
            $tipo    = 'error';
        }
        mysqli_stmt_close($stmt);
    } else {
        $mensaje = implode('<br>', array_map(fn($e) => "· $e", $errores));
        $tipo    = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registrar Usuario</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg:       #0d1117;
    --surface:  #161b22;
    --surface2: #1c2330;
    --border:   #30363d;
    --blue:     #2f81f7;
    --blue-glow:#2f81f740;
    --green:    #3fb950;
    --red:      #f85149;
    --yellow:   #e3b341;
    --text:     #e6edf3;
    --text2:    #8b949e;
    --text3:    #484f58;
    --accent:   #d2a8ff;
}
body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 32px 16px;
}
body::before {
    content: '';
    position: fixed; inset: 0;
    background:
        radial-gradient(ellipse 60% 40% at 15% 10%, #2f81f714 0%, transparent 60%),
        radial-gradient(ellipse 40% 30% at 85% 85%, #d2a8ff0c 0%, transparent 60%);
    pointer-events: none;
}
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    width: 100%; max-width: 560px;
    padding: 40px 40px 36px;
    position: relative;
    box-shadow: 0 0 0 1px #ffffff06, 0 24px 64px #00000055;
}
.card::before {
    content: '';
    position: absolute;
    top: 0; left: 24px; right: 24px; height: 1px;
    background: linear-gradient(90deg, transparent, var(--blue), var(--accent), transparent);
}
.card-label {
    font-family: 'Syne', sans-serif;
    font-size: 0.62rem; font-weight: 700;
    letter-spacing: 0.2em; text-transform: uppercase;
    color: var(--blue); margin-bottom: 8px;
}
.card-title {
    font-family: 'Syne', sans-serif;
    font-size: 1.55rem; font-weight: 800;
    color: var(--text); line-height: 1.1; margin-bottom: 6px;
}
.card-sub { font-size: 0.8rem; color: var(--text2); margin-bottom: 28px; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.col-span-2 { grid-column: span 2; }
.divider { grid-column: span 2; height: 1px; background: var(--border); margin: 2px 0; }

.field { display: flex; flex-direction: column; gap: 5px; }
.field label { font-size: 0.7rem; font-weight: 500; color: var(--text2); text-transform: uppercase; letter-spacing: 0.08em; }

.field input,
.field select {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 13px;
    font-size: 0.875rem;
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
    width: 100%;
    appearance: none; -webkit-appearance: none;
}
.field input::placeholder { color: var(--text3); }
.field input:focus, .field select:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px var(--blue-glow);
}

.select-wrap { position: relative; }
.select-wrap::after {
    content: '▾'; position: absolute;
    right: 12px; top: 50%; transform: translateY(-50%);
    color: var(--text2); font-size: 0.75rem; pointer-events: none;
}
.select-wrap select { padding-right: 32px; cursor: pointer; }
.select-wrap select option { background: #1c2330; }

.pwd-bar-wrap { height: 3px; background: var(--border); border-radius: 2px; margin-top: 5px; overflow: hidden; }
.pwd-bar { height: 100%; width: 0; border-radius: 2px; transition: width 0.3s, background 0.3s; }
.field-hint { font-size: 0.7rem; color: var(--text3); margin-top: 2px; min-height: 16px; }

.msg { border-radius: 8px; padding: 12px 16px; font-size: 0.82rem; line-height: 1.7; margin-bottom: 20px; border: 1px solid; }
.msg.success { background: #3fb95012; border-color: #3fb95035; color: var(--green); }
.msg.error   { background: #f8514912; border-color: #f8514935; color: var(--red); }

.btn-submit {
    width: 100%; padding: 13px; border: none; border-radius: 8px;
    background: var(--blue); color: #fff;
    font-family: 'Syne', sans-serif; font-size: 0.9rem; font-weight: 700;
    letter-spacing: 0.04em; cursor: pointer; margin-top: 22px;
    transition: background 0.2s, box-shadow 0.2s, transform 0.1s;
}
.btn-submit:hover { background: #388bfd; box-shadow: 0 0 24px var(--blue-glow); }
.btn-submit:active { transform: scale(0.99); }

.btn-back {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 0.78rem; color: var(--text2);
    text-decoration: none; margin-top: 16px;
    transition: color 0.2s;
}
.btn-back:hover { color: var(--text); }

.warn-banner {
    background: #e3b34115; border: 1px solid #e3b34130;
    border-radius: 8px; padding: 10px 14px;
    font-size: 0.78rem; color: var(--yellow);
    margin-bottom: 20px;
}

@media (max-width: 500px) {
    .card { padding: 24px 18px 22px; }
    .form-grid { grid-template-columns: 1fr; }
    .col-span-2, .divider { grid-column: span 1; }
}
</style>
</head>
<body>
<div class="card">
    <div class="card-label">Gestión de accesos</div>
    <div class="card-title">Registrar usuario</div>
    <div class="card-sub">La contraseña se almacena con hash bcrypt seguro.</div>

    <?php if (!$db_ok): ?>
    <div class="warn-banner">⚠️ Sin conexión a la base de datos. Verifica <code>conexion.php</code>.</div>
    <?php endif; ?>

    <?php if ($mensaje): ?>
    <div class="msg <?= $tipo ?>"><?= $mensaje ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" novalidate>
        <div class="form-grid">

            <div class="field col-span-2">
                <label for="username">Username *</label>
                <input type="text" id="username" name="username"
                       placeholder="ej. juan.perez"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>

            <div class="field col-span-2">
                <label for="email">Correo electrónico *</label>
                <input type="email" id="email" name="email"
                       placeholder="ej. juan@regionsur.com.mx"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="divider"></div>

            <div class="field col-span-2">
                <label for="password">Contraseña *</label>
                <input type="password" id="password" name="password"
                       placeholder="Mínimo 8 caracteres"
                       oninput="checkStrength(this.value)">
                <div class="pwd-bar-wrap"><div class="pwd-bar" id="pwdBar"></div></div>
                <div class="field-hint" id="pwdHint">Usa mayúsculas, números y símbolos</div>
            </div>

            <div class="field col-span-2">
                <label for="password_confirm">Confirmar contraseña *</label>
                <input type="password" id="password_confirm" name="password_confirm"
                       placeholder="Repite la contraseña"
                       oninput="checkMatch()">
                <div class="field-hint" id="matchHint"></div>
            </div>

            <div class="divider"></div>

            <div class="field col-span-2">
                <label for="id_posicion">Posición</label>
                <div class="select-wrap">
                    <select id="id_posicion" name="id_posicion">
                        <option value="0">Sin posición</option>
                        <?php foreach ($posiciones as $pos):
                            $s = (intval($_POST['id_posicion'] ?? 0) === intval($pos['id'])) ? 'selected' : '';
                        ?>
                        <option value="<?= $pos['id'] ?>" <?= $s ?>><?= htmlspecialchars($pos['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field col-span-2">
                <label for="numero_talento_gs">Número de talento GS</label>
                <input type="text" id="numero_talento_gs" name="numero_talento_gs"
                       placeholder="ej. 10045"
                       value="<?= htmlspecialchars($_POST['numero_talento_gs'] ?? '') ?>">
            </div>

        </div>

        <button type="submit" class="btn-submit" <?= !$db_ok ? 'disabled' : '' ?>>
            Crear usuario →
        </button>
    </form>

    <a href="index.php" class="btn-back">← Volver al dashboard</a>
</div>

<script>
function checkStrength(pwd) {
    const bar = document.getElementById('pwdBar');
    const hint = document.getElementById('pwdHint');
    let score = 0;
    if (pwd.length >= 8)          score++;
    if (/[A-Z]/.test(pwd))        score++;
    if (/[0-9]/.test(pwd))        score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;
    const cfg = [
        { w:'0%',   bg:'',          t:'Usa mayúsculas, números y símbolos' },
        { w:'25%',  bg:'#f85149',   t:'Muy débil' },
        { w:'50%',  bg:'#e3b341',   t:'Moderada' },
        { w:'75%',  bg:'#3fb950',   t:'Buena' },
        { w:'100%', bg:'#2f81f7',   t:'💪 Muy fuerte' },
    ];
    const c = pwd.length ? cfg[score] || cfg[1] : cfg[0];
    bar.style.width = c.w;
    bar.style.background = c.bg;
    hint.textContent = c.t;
    hint.style.color = c.bg || 'var(--text3)';
    checkMatch();
}
function checkMatch() {
    const pwd  = document.getElementById('password').value;
    const conf = document.getElementById('password_confirm').value;
    const hint = document.getElementById('matchHint');
    if (!conf) { hint.textContent = ''; return; }
    if (pwd === conf) {
        hint.textContent = '✓ Las contraseñas coinciden';
        hint.style.color = 'var(--green)';
    } else {
        hint.textContent = '✗ No coinciden';
        hint.style.color = 'var(--red)';
    }
}
</script>
</body>
</html>
