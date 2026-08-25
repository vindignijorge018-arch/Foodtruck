<?php
// config_sistema.php

// --- 1. CONFIGURACIONES GLOBALES DEL SAAS ---
define('SISTEMA_NOMBRE', 'Bargaiwe SaaS');
define('VERSION', '1.0.3');

// --- 2. INTERRUPTOR MAESTRO DE INVENTARIO ---
// 1 = Inventario descontando normal (Bodega activa)
// 0 = Inventario CONGELADO (Stock infinito, no descuenta nada)
// --- 2. INTERRUPTOR MAESTRO DE INVENTARIO (Desde la BD) ---
$inventario_activo = 0; // Por defecto apagado por seguridad

// Verificamos si existe la conexión y sabemos de qué restaurante hablamos
if (isset($conn) && isset($mi_restaurant_id)) {
    $res_config = $conn->query("SELECT inventario_activo FROM config_restaurante WHERE restaurant_id = $mi_restaurant_id");
    if ($res_config && $row_config = $res_config->fetch_assoc()) {
        $inventario_activo = (int)$row_config['inventario_activo'];
    }
}
// --- 3. LÓGICA DE SUSCRIPCIÓN Y CORTESÍA ---
// (Supongamos que ya obtuviste estos datos de la base de datos del cliente)
$fecha_vence_db = "2026-03-29"; // Venció hoy
$hoy = new DateTime();
$vencimiento = new DateTime($fecha_vence_db);

$aviso_cortesia = "";
$sistema_bloqueado = false;

// Verificamos si ya venció
if ($hoy > $vencimiento) {
    $diferencia = $vencimiento->diff($hoy);
    $dias_pasados = $diferencia->days;
    
    $dias_cortesia_total = 4;
    $quedan = $dias_cortesia_total - $dias_pasados;

    if ($quedan > 0) {
        $aviso_cortesia = "⚠️ Tu suscripción venció. Tienes $quedan días de cortesía para regularizar.";
    } else {
        $sistema_bloqueado = true;
    }
}
?>