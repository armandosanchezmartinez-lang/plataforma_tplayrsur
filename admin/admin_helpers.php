<?php
function admin_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function admin_generate_temp_password($length = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@$#';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) { $password .= $chars[random_int(0, $max)]; }
    return $password;
}
function admin_allowed_roles() {
    return ['admin','director_regional','director_distrital','lider','coach','vendedor'];
}
function admin_role_label($rol) {
    $labels = [
        'admin'=>'ADMIN',
        'director_regional'=>'DIRECTOR REGIONAL',
        'director_distrital'=>'DIRECTOR DISTRITAL',
        'lider'=>'LÍDER',
        'coach'=>'COACH',
        'vendedor'=>'VENDEDOR'
    ];
    return $labels[$rol] ?? strtoupper((string)$rol);
}
?>
