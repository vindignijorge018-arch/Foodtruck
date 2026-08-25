<?php
// datos_dubo.php - El "Espía" de Datos para la IA
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

// Para pruebas, usamos la sesión actual. 
// A futuro, esto recibirá un ID por URL o correrá automático.
if (!isset($mi_restaurant_id) || $mi_restaurant_id == 0) {
    die(json_encode(["error" => "No hay restaurante seleccionado"]));
}

$dias_analisis = 21; // Analizamos las últimas 3 semanas
$paquete_edubo = [
    "restaurante_id" => $mi_restaurant_id,
    "periodo_dias" => $dias_analisis,
    "fecha_generacion" => date('Y-m-d H:i:s'),
    "menu_y_stock" => [],
    "eficiencia_mesas" => [],
    "afluencia" => [],
    "gastos_extra" => [],
    "metas_progreso" => []
];

// --- 1. MENÚ Y DEAD INVENTORY (Ley de Hick) ---
// A. Contar cuántos platos hay por categoría (Para evitar menús inflados)
$res_secciones = $conn->query("SELECT seccion, COUNT(id) as cantidad_platos FROM menu WHERE restaurant_id = $mi_restaurant_id GROUP BY seccion");
$paquete_edubo['menu_y_stock']['volumen_categorias'] = [];
if($res_secciones) {
    while($row = $res_secciones->fetch_assoc()) {
        $paquete_edubo['menu_y_stock']['volumen_categorias'][$row['seccion']] = (int)$row['cantidad_platos'];
    }
}

// B. Platos "Muertos" (0 o 1 venta en 21 días)
$sql_dead = "SELECT m.nombre, m.seccion, COUNT(p.id) as ventas 
             FROM menu m 
             LEFT JOIN pedidos p ON m.id = p.menu_id AND p.fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_analisis DAY) AND p.estado = 3
             WHERE m.restaurant_id = $mi_restaurant_id 
             GROUP BY m.id 
             HAVING ventas <= 1";
$res_dead = $conn->query($sql_dead);
$paquete_edubo['menu_y_stock']['platos_muertos'] = [];
if($res_dead) {
    while($row = $res_dead->fetch_assoc()) {
        $paquete_edubo['menu_y_stock']['platos_muertos'][] = $row['nombre'] . " (" . $row['seccion'] . ")";
    }
}

// --- 2. EFICIENCIA DE MESAS (Zonas frías) ---
$sql_mesas = "SELECT numero_mesa, usos FROM mesas WHERE restaurant_id = $mi_restaurant_id";
$res_mesas = $conn->query($sql_mesas);
$total_usos = 0;
$mesas_data = [];
if($res_mesas) {
    while($row = $res_mesas->fetch_assoc()) {
        $mesas_data[$row['numero_mesa']] = (int)$row['usos'];
        $total_usos += (int)$row['usos'];
    }
}
// Calcular porcentaje real por mesa
foreach($mesas_data as $num => $usos) {
    $porcentaje = ($total_usos > 0) ? round(($usos / $total_usos) * 100, 1) : 0;
    $paquete_edubo['eficiencia_mesas']["Mesa_$num"] = $porcentaje . "%";
}

// --- 3. AFLUENCIA (Picos y Valles) ---
// Qué día de la semana viene más gente
$sql_dias = "SELECT DAYNAME(fecha) as dia, COUNT(id) as visitas 
             FROM pedidos 
             WHERE estado = 3 AND fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_analisis DAY)
             GROUP BY dia ORDER BY visitas DESC";
$res_dias = $conn->query($sql_dias);
if($res_dias) {
    while($row = $res_dias->fetch_assoc()) {
        $paquete_edubo['afluencia']['visitas_por_dia'][$row['dia']] = (int)$row['visitas'];
    }
}

// --- 4. ESTRUCTURA DE GASTOS EXTRA ---
$sql_gastos = "SELECT concepto, SUM(monto) as total_gasto 
               FROM gastos 
               WHERE restaurant_id = $mi_restaurant_id AND fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_analisis DAY)
               GROUP BY concepto";
$res_gastos = $conn->query($sql_gastos);
if($res_gastos) {
    while($row = $res_gastos->fetch_assoc()) {
        $paquete_edubo['gastos_extra'][$row['concepto']] = (int)$row['total_gasto'];
    }
}

// --- 5. CUMPLIMIENTO DE METAS ---
$metas = $conn->query("SELECT * FROM metas_restaurante WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
if($metas) {
    $paquete_edubo['metas_progreso']['meta_dinero_activa'] = (bool)$metas['act_neta'];
    $paquete_edubo['metas_progreso']['meta_dinero_objetivo'] = (int)$metas['meta_dinero'];
    // Aquí puedes agregar la lógica de $neta_periodo que ya tienes en stats.php para ver cuánto falta
}

// --- SALIDA FINAL EN FORMATO JSON ---
header('Content-Type: application/json; charset=utf-8');
echo json_encode($paquete_edubo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>