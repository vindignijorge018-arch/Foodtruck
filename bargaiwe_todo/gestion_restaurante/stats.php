<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// En las primeras líneas de stats.php, justo después de incluir db.php:
include 'db.php';
verificarPlanPlus(); // El portero que expulsa a los Estándar


// --- 0. CARGAR METAS DEL NEGOCIO DESDE LA BASE DE DATOS ---
$metas = $conn->query("SELECT * FROM metas_restaurante WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
if(!$metas) {
    $metas = ['mostrar_metas'=>0, 'meta_periodo'=>'diaria', 'meta_dias'=>1, 'act_neta'=>0, 'act_ventas'=>0, 'act_total_platos'=>0, 'act_plato_esp'=>0, 'act_seccion'=>0, 'act_postre'=>0, 'act_desc'=>0];
}

$dias_intervalo = 1;
if($metas['meta_periodo'] == 'semanal') $dias_intervalo = 7;
if($metas['meta_periodo'] == 'mensual') $dias_intervalo = 30;
if($metas['meta_periodo'] == 'personalizada') $dias_intervalo = max(1, $metas['meta_dias']);

$texto_periodo = "Hoy";
if($metas['meta_periodo'] == 'semanal') $texto_periodo = "de la Semana";
if($metas['meta_periodo'] == 'mensual') $texto_periodo = "del Mes";
if($metas['meta_periodo'] == 'personalizada') $texto_periodo = "({$dias_intervalo} días)";

// --- 1. REGISTRAR GASTOS E INGRESOS EXTRA ---
if (isset($_POST['monto'])) {
    $concepto = $conn->real_escape_string($_POST['concepto']);
    $monto = (int)$_POST['monto'];
    if (isset($_POST['agregar_gasto_extra'])) {
        $conn->query("INSERT INTO gastos (restaurant_id, concepto, monto) VALUES ($mi_restaurant_id, '$concepto', $monto)");
        header("Location: stats.php?exito_gasto=1"); exit();
    }
    if (isset($_POST['agregar_ingreso_extra'])) {
        $conn->query("INSERT INTO ingresos_extra (restaurant_id, concepto, monto) VALUES ($mi_restaurant_id, '$concepto', $monto)");
        header("Location: stats.php?exito_ingreso=1"); exit();
    }
}

// --- LÓGICAS DE REINICIO ---
$resets = [
    'reset_mesas' => ["UPDATE mesas SET usos = 0 WHERE restaurant_id = $mi_restaurant_id"],
    'reset_platos' => ["UPDATE pedidos SET stat_platos = 0 WHERE estado = 3"],
    'reset_grafica' => ["UPDATE pedidos SET stat_grafica = 0 WHERE estado = 3", "DELETE FROM gastos WHERE restaurant_id = $mi_restaurant_id", "DELETE FROM ingresos_extra WHERE restaurant_id = $mi_restaurant_id"],
    'reset_horas' => ["UPDATE pedidos SET stat_horas = 0 WHERE estado = 3"]
];
foreach ($resets as $get_key => $queries) {
    if (isset($_GET[$get_key])) {
        foreach ($queries as $q) $conn->query($q);
        header("Location: stats.php"); exit();
    }
}

// 2. OBTENER TOP 10 MESAS
$mesas_top = [];
$tot_usos = ($r = $conn->query("SELECT SUM(usos) as t FROM mesas WHERE restaurant_id = $mi_restaurant_id")) ? $r->fetch_assoc()['t'] ?? 0 : 0;
if ($res = $conn->query("SELECT numero_mesa, usos FROM mesas WHERE restaurant_id = $mi_restaurant_id ORDER BY usos DESC LIMIT 10")) {
    while($m = $res->fetch_assoc()) {
        $mesas_top[] = ['numero' => $m['numero_mesa'], 'usos' => $m['usos'], 'porcentaje' => ($tot_usos > 0) ? round(($m['usos'] / $tot_usos) * 100, 1) : 0];
    }
}

// 3. OBTENER PLATOS ESTRELLA 
$ventas_sec = []; $tot_sec = [];
if ($res = $conn->query("SELECT m.seccion, m.nombre, COUNT(p.id) as cant FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.estado = 3 AND p.stat_platos = 1 AND m.restaurant_id = $mi_restaurant_id GROUP BY m.seccion, m.nombre ORDER BY m.seccion ASC, cant DESC")) {
    while($p = $res->fetch_assoc()) {
        $s = $p['seccion'];
        $tot_sec[$s] = ($tot_sec[$s] ?? 0) + $p['cant'];
        if (!isset($ventas_sec[$s])) $ventas_sec[$s] = [];
        if (count($ventas_sec[$s]) < 2 && count($ventas_sec) <= 4) $ventas_sec[$s][] = $p;
    }
}

// 4. GRÁFICA FINANCIERA Y CÁLCULOS
$datos_dias = []; $tots = ['mesa'=>0, 'delivery'=>0, 'extra'=>0, 'gasto'=>0];

// NUEVO: Asegurar que exista al menos el día de hoy con $0 para que la gráfica siempre se dibuje
$hoy = date('Y-m-d');
$datos_dias[$hoy] = ['mesa'=>0, 'delivery'=>0, 'extra'=>0, 'gasto'=>0];

function addData(&$arr, $dia, $tipo, $monto, &$tots) {
    // Si el pedido es del QR ('local'), lo sumamos a la columna de 'mesa'
    if ($tipo == 'local') $tipo = 'mesa';
    
    if(!isset($arr[$dia])) $arr[$dia] = ['mesa'=>0, 'delivery'=>0, 'extra'=>0, 'gasto'=>0];
    
    // Verificamos que el tipo exista en los totales para evitar el Warning
    if(isset($tots[$tipo])) {
        $arr[$dia][$tipo] += $monto; 
        $tots[$tipo] += $monto;
    }
}

if($res = $conn->query("SELECT DATE(p.fecha) as dia, p.tipo_pedido, SUM(p.precio_al_momento) as t FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.estado = 3 AND p.stat_grafica = 1 AND m.restaurant_id = $mi_restaurant_id AND p.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY DATE(p.fecha), p.tipo_pedido"))
    while($g = $res->fetch_assoc()) addData($datos_dias, $g['dia'], $g['tipo_pedido'], $g['t'], $tots);

if($res = $conn->query("SELECT DATE(fecha) as dia, SUM(monto) as t FROM ingresos_extra WHERE restaurant_id = $mi_restaurant_id AND fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY DATE(fecha)"))
    while($g = $res->fetch_assoc()) addData($datos_dias, $g['dia'], 'extra', $g['t'], $tots);

if($res = $conn->query("SELECT DATE(fecha) as dia, SUM(monto) as t FROM gastos WHERE restaurant_id = $mi_restaurant_id AND fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) GROUP BY DATE(fecha)"))
    while($g = $res->fetch_assoc()) addData($datos_dias, $g['dia'], 'gasto', $g['t'], $tots);

ksort($datos_dias);
$fechas = array_keys($datos_dias);
$ingresos_mesa = array_column($datos_dias, 'mesa');
$ingresos_delivery = array_column($datos_dias, 'delivery');
$ingresos_extra_graf = array_column($datos_dias, 'extra');
$gastos = array_column($datos_dias, 'gasto');

// AQUÍ CREAMOS LA VARIABLE QUE TE FALTABA PARA EL HTML
$ganancia_neta_hist = $tots['mesa'] + $tots['delivery'] + $tots['extra'] - $tots['gasto'];

// 5. GRÁFICA DE DÍAS Y HORAS PICO
$dias_esp = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$horas_labels = []; $horas_data = [];
if($res = $conn->query("SELECT DAYOFWEEK(fecha) as dia_num, HOUR(fecha) as hora, COUNT(DISTINCT CONCAT(DATE(fecha), '-', COALESCE(mesa_id, 0), '-', COALESCE(delivery_id, 0))) as tot FROM pedidos WHERE estado = 3 AND stat_horas = 1 GROUP BY DAYOFWEEK(fecha), HOUR(fecha) ORDER BY dia_num ASC, hora ASC")) {
    while($h = $res->fetch_assoc()) {
        $horas_labels[] = $dias_esp[$h['dia_num'] - 1] . ' ' . str_pad($h['hora'], 2, '0', STR_PAD_LEFT) . ':00';
        $horas_data[] = $h['tot'];
    }
}

// NUEVO: Asegurar gráfica inferior aunque no haya datos
if (empty($horas_labels)) {
    $horas_labels[] = "Sin Datos";
    $horas_data[] = 0;
}

// --- 6. CÁLCULO ESPECÍFICO PARA LAS METAS ACTIVAS ---
$ingresos_periodo = 0; $gastos_periodo = 0;
if($metas['act_neta'] || $metas['act_ventas']) {
    $res = $conn->query("SELECT SUM(precio_al_momento) as t FROM pedidos WHERE estado = 3 AND stat_grafica = 1 AND fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_intervalo DAY)");
    $ingresos_periodo += ($res->fetch_assoc()['t'] ?? 0);
    
    $res = $conn->query("SELECT SUM(monto) as t FROM ingresos_extra WHERE restaurant_id = $mi_restaurant_id AND fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_intervalo DAY)");
    $ingresos_periodo += ($res->fetch_assoc()['t'] ?? 0);

    $res = $conn->query("SELECT SUM(monto) as t FROM gastos WHERE restaurant_id = $mi_restaurant_id AND fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_intervalo DAY)");
    $gastos_periodo = ($res->fetch_assoc()['t'] ?? 0);
}
$neta_periodo = $ingresos_periodo - $gastos_periodo;
$ventas_periodo = $conn->query("SELECT SUM(precio_al_momento) as t FROM pedidos WHERE estado = 3 AND stat_grafica = 1 AND fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_intervalo DAY)")->fetch_assoc()['t'] ?? 0;

$platos_periodo = 0;
if($metas['act_total_platos']) {
    $platos_periodo = $conn->query("SELECT COUNT(id) as t FROM pedidos WHERE estado = 3 AND stat_platos = 1 AND fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_intervalo DAY)")->fetch_assoc()['t'] ?? 0;
}

$plato_esp_cant = 0; $plato_esp_nombre = "Plato Objetivo";
if($metas['act_plato_esp'] && $metas['meta_plato_id'] > 0) {
    $p_id = $metas['meta_plato_id'];
    $plato_esp_cant = $conn->query("SELECT COUNT(id) as t FROM pedidos WHERE menu_id = $p_id AND estado = 3 AND fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_intervalo DAY)")->fetch_assoc()['t'] ?? 0;
    $plato_esp_nombre = $conn->query("SELECT nombre FROM menu WHERE id = $p_id")->fetch_assoc()['nombre'] ?? "Plato";
}

$seccion_cant = 0;
if($metas['act_seccion'] && !empty($metas['meta_seccion_nombre'])) {
    $s_nom = $conn->real_escape_string($metas['meta_seccion_nombre']);
    $seccion_cant = $conn->query("SELECT COUNT(p.id) as t FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE m.seccion = '$s_nom' AND p.estado = 3 AND p.fecha >= DATE_SUB(CURDATE(), INTERVAL $dias_intervalo DAY)")->fetch_assoc()['t'] ?? 0;
}

function drawProgressBar($titulo, $actual, $meta, $es_dinero = false) {
    // Forzamos a que sean números (float) para que PHP no colapse si llegan vacíos
    $actual = (float)$actual;
    $meta = (float)$meta;
    
    if($meta <= 0) return "";
    $porcentaje = min(100, round(($actual / $meta) * 100));
    $color = $porcentaje >= 100 ? '#FFD700' : '#4caf50';
    $txt_actual = $es_dinero ? "$".number_format($actual, 0, ',', '.') : $actual;
    $txt_meta = $es_dinero ? "$".number_format($meta, 0, ',', '.') : $meta;
    
    return "
    <div style='margin-bottom: 15px;'>
        <div style='display: flex; justify-content: space-between; margin-bottom: 5px;'>
            <strong>$titulo ($txt_actual / $txt_meta)</strong>
            <span style='color: #2e7d32; font-weight: bold;'>$porcentaje%</span>
        </div>
        <div class='barra-progreso-bg' style='width: 100%; height: 12px; border-radius: 10px; background: rgba(0,0,0,0.05);'>
            <div class='barra-progreso-fill' style='width: $porcentaje%; background: $color; border-radius: 10px; transition: width 0.5s ease;'></div>
        </div>
    </div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Estadísticas</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        .nav-hub { background: #014421; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 10px; background: #8C8C8C; color: white; transition: 0.3s; }
        .nav-hub a:hover { background: #666; }
        .container { padding: 30px; display: grid; grid-template-columns: 1fr 1fr; gap: 30px; max-width: 1400px; margin: auto;}
        .card { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); display: flex; flex-direction: column; }
        .card-full { grid-column: span 2; }
        h3 { color: #014421; margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 20px; font-size: 1.3rem; display: flex; justify-content: space-between; align-items: center;}
        .btn-reiniciar { background: white; color: #ff4d4d; text-decoration: none; padding: 5px 10px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; border: 1px solid #ff4d4d; transition: 0.2s; }
        .btn-reiniciar:hover { background: #ffebee; transform: scale(1.05); }
        .fila-mesa { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f9f9f9; }
        .barra-progreso-bg { background: #eee; border-radius: 10px; height: 8px; width: 100px; overflow: hidden; }
        .barra-progreso-fill { background: #32CD32; height: 100%; transition: width 0.5s ease; }
        .seccion-platos { margin-bottom: 20px; }
        .seccion-titulo { font-weight: bold; color: #FF8C00; text-transform: uppercase; margin-bottom: 10px; font-size: 0.9rem;}
        .plato-item { background: #fafafa; padding: 10px 15px; border-radius: 10px; margin-bottom: 8px; display: flex; justify-content: space-between; border-left: 4px solid #014421; }
        .header-finanzas { display: flex; gap: 20px; margin-bottom: 20px; align-items: stretch;}
        .panel-izquierdo { flex-grow: 1; display: flex; flex-direction: column; gap: 20px; width: calc(100% - 320px); }
        .resumen-finanzas { display: grid; grid-template-columns: repeat(5, 1fr); background: #fdfcf0; padding: 15px; border-radius: 10px; border: 1px dashed #ccc;}
        .bloque-fin { text-align: center; border-right: 1px solid #ddd; padding: 0 10px;}
        .bloque-fin:last-child { border-right: none; }
        .bloque-fin span { display: block; font-size: 0.8rem; color: #666; font-weight: bold; text-transform: uppercase;}
        .bloque-fin strong { font-size: 1.3rem; }
        .forms-container { display: flex; flex-direction: column; gap: 10px; width: 300px; flex-shrink: 0;}
        .form-fin { padding: 12px; border-radius: 10px; display: flex; flex-direction: column; gap: 8px; }
        .form-fin.ingreso { background: #e8f5e9; border: 1px solid #a5d6a7; }
        .form-fin.gasto { background: #ffebee; border: 1px solid #ffcdd2; }
        .form-fin h4 { margin: 0; font-size: 0.9rem; }
        .form-fin.ingreso h4 { color: #2e7d32; }
        .form-fin.gasto h4 { color: #c62828; }
        .form-fin input { padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.85rem;}
        .form-fin button { color: white; border: none; padding: 6px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 0.85rem;}
        .form-fin.ingreso button { background: #4caf50; }
        .form-fin.gasto button { background: #f44336; }
        /* --- MODO OSCURO AUTOMÁTICO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; }
        body.modo-oscuro .card { background: #1e1e1e !important; box-shadow: 0 4px 10px rgba(0,0,0,0.5); border: 1px solid #333; }
        body.modo-oscuro .resumen-finanzas { background: #2a2a2a; border-color: #444; }
        body.modo-oscuro .bloque-fin { border-color: #444; }
        body.modo-oscuro .form-fin { background: #2a2a2a; border-color: #444; }
        body.modo-oscuro h3, body.modo-oscuro .bloque-fin span, body.modo-oscuro .form-fin h4 { color: #ccc !important; }
        body.modo-oscuro input { background: #333; color: white; border: 1px solid #555; }
        body.modo-oscuro .plato-item { background: #2a2a2a; border-left-color: #32CD32; color: #ddd; }
    </style>
    <script>
        function formatearMoneda(inputVis) {
            let val = inputVis.value.replace(/[^0-9]/g, '');
            inputVis.nextElementSibling.value = val;
            inputVis.value = val === '' ? '' : new Intl.NumberFormat(navigator.language).format(val);
        }
    </script>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.6rem; font-weight: 800;">bargaiwe - Dashboard Financiero</span>
        <div style="display: flex; gap: 15px; align-items: center;">
            <a href="metas.php" style="background: #FF8C00; color: white;">⚙️ Metas</a>
            <a href="descuentos.php" style="background: #007BFF; color: white;">⚙️ Descuentos</a>
            <a href="mesas.php" style="background: #8C8C8C; color: white;">← Volver a Mesas</a>
        </div>
    </div>

    <div class="container">
        
        <?php if(isset($_GET['exito_gasto'])): ?>
            <div style="grid-column: span 2; background: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; text-align:center; font-weight:bold;">📉 Gasto operativo registrado.</div>
        <?php endif; ?>
        <?php if(isset($_GET['exito_ingreso'])): ?>
            <div style="grid-column: span 2; background: #e8f5e9; color: #2e7d32; padding: 10px; border-radius: 8px; text-align:center; font-weight:bold;">📈 Ingreso extra registrado exitosamente.</div>
        <?php endif; ?>

       <?php if($metas['mostrar_metas']): ?>
       <div class="card card-full" style="background: #e8f5e9; border: 2px dashed #4caf50; padding: 25px;">
            <h3 style="border-bottom: none; margin-bottom: 20px; color: #2e7d32;">🎯 Metas <?= $texto_periodo ?></h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                
                <div>
                    <?php 
                        if($metas['act_neta']) echo drawProgressBar("Ganancia Neta", $neta_periodo, $metas['meta_dinero'], true);
                        if($metas['act_ventas']) echo drawProgressBar("Ventas Brutas", $ventas_periodo, $metas['meta_ventas'], true);
                        if($metas['act_desc']) echo drawProgressBar("Descuentos Otorgados", 0, $metas['meta_desc_cant']);
                    ?>
                </div>

                <div>
                    <?php 
                        if($metas['act_total_platos']) echo drawProgressBar("Platos Totales", $platos_periodo, $metas['meta_platos']);
                        if($metas['act_plato_esp']) echo drawProgressBar("Impulso: " . $plato_esp_nombre, $plato_esp_cant, $metas['meta_plato_cant']);
                        if($metas['act_seccion']) echo drawProgressBar("Categoría: " . $metas['meta_seccion_nombre'], $seccion_cant, $metas['meta_seccion_cant']);
                    ?>
                </div>

            </div>
            <?php if($metas['act_postre']): ?>
                <div style="margin-top: 10px; text-align: center; font-size: 0.9rem; color: #555; background: rgba(255,255,255,0.5); padding: 8px; border-radius: 8px;">
                    🎁 <strong>Postre de Regalo Activado:</strong> Las mesas que gasten más de $<?= number_format($metas['meta_postre_gasto'], 0, ',', '.') ?> recibirán notificación.
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card card-full">
            <h3>
                <span>📈 Flujo de Caja Total (Últimos 90 Días)</span>
                <a href="stats.php?reset_grafica=1" class="btn-reiniciar" onclick="return confirm('¿Borrar historial?');">🔄 Reiniciar Todo</a>
            </h3>
            
            <div class="header-finanzas">
                <div class="panel-izquierdo">
                    <div class="resumen-finanzas">
                        <div class="bloque-fin"><span>Mesas</span><strong style="color: #32CD32;">$<?= number_format($tots['mesa'], 0, ',', '.') ?></strong></div>
                        <div class="bloque-fin"><span>Delivery</span><strong style="color: #007BFF;">$<?= number_format($tots['delivery'], 0, ',', '.') ?></strong></div>
                        <div class="bloque-fin"><span>Ing. Extra</span><strong style="color: #FFC107;">$<?= number_format($tots['extra'], 0, ',', '.') ?></strong></div>
                        <div class="bloque-fin"><span>Gastos</span><strong style="color: #ff4d4d;">-$<?= number_format($tots['gasto'], 0, ',', '.') ?></strong></div>
                        <div class="bloque-fin" style="background: rgba(0,0,0,0.03); border-radius: 8px;">
                            <span>Ganancia Neta</span>
                            <strong style="color: <?= ($ganancia_neta_hist >= 0) ? '#32CD32' : '#ff4d4d' ?>;">$<?= number_format($ganancia_neta_hist, 0, ',', '.') ?></strong>
                        </div>
                    </div>
                    <div style="position: relative; height: 200px; width: 100%;"><canvas id="graficaIngresos"></canvas></div>
                </div>

                <div class="forms-container">
                    <form method="POST" class="form-fin ingreso">
                        <h4>💰 Registrar Ingreso Extra</h4>
                        <input type="text" name="concepto" placeholder="Ej: Arriendo local, Venta poleras" required>
                        <div style="display:flex; gap:5px;">
                            <input type="text" oninput="formatearMoneda(this)" placeholder="$ Monto" required style="width: 60%;">
                            <input type="hidden" name="monto"><button type="submit" name="agregar_ingreso_extra" style="width: 40%;">Sumar</button>
                        </div>
                    </form>
                    <form method="POST" class="form-fin gasto">
                        <h4>💸 Registrar Gasto</h4>
                        <input type="text" name="concepto" placeholder="Ej: Luz, Arriendo, Plato roto" required>
                        <div style="display:flex; gap:5px;">
                            <input type="text" oninput="formatearMoneda(this)" placeholder="$ Monto" required style="width: 60%;">
                            <input type="hidden" name="monto"><button type="submit" name="agregar_gasto_extra" style="width: 40%;">Restar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card">
            <h3><span>Top 10 Mesas <span style="font-size: 0.8rem; color:#888;">(<?= $tot_usos ?> usos)</span></span>
                <a href="stats.php?reset_mesas=1" class="btn-reiniciar" onclick="return confirm('¿Reiniciar uso de mesas?');">🔄 Reiniciar</a></h3>
            <?php if(empty($mesas_top)): ?>
                <p style="color:#888; text-align:center; margin: auto;">Aún no hay mesas finalizadas.</p>
            <?php else: foreach($mesas_top as $m): ?>
                <div class="fila-mesa">
                    <strong style="width: 80px;">Mesa <?= $m['numero'] ?></strong>
                    <span style="color:#666; font-size: 0.9rem; width: 50px;"><?= $m['usos'] ?> usos</span>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div class="barra-progreso-bg"><div class="barra-progreso-fill" style="width: <?= $m['porcentaje'] ?>%;"></div></div>
                        <strong style="color:#014421; width:45px; text-align:right;"><?= $m['porcentaje'] ?>%</strong>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card">
            <h3><span>Platos Estrella <span style="font-size: 0.8rem; color:#888;">(Por categoría)</span></span>
                <a href="stats.php?reset_platos=1" class="btn-reiniciar" onclick="return confirm('¿Reiniciar estadísticas?');">🔄 Reiniciar</a></h3>
            <?php if(empty($ventas_sec)): ?>
                <p style="color:#888; text-align:center; margin: auto;">Aún no hay platos vendidos registrados.</p>
            <?php else: foreach($ventas_sec as $sec => $platos): ?>
                <div class="seccion-platos">
                    <div class="seccion-titulo">📁 <?= $sec ?></div>
                    <?php foreach($platos as $pl): ?>
                        <div class="plato-item">
                            <span><?= htmlspecialchars($pl['nombre']) ?> <small style="color:#888;">(<?= $pl['cant'] ?> uds)</small></span>
                            <strong style="color:#014421;"><?= ($tot_sec[$sec] > 0) ? round(($pl['cant'] / $tot_sec[$sec]) * 100, 1) : 0 ?>%</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="card card-full">
            <h3><span>⏰ Días y Horas de Mayor Afluencia</span>
                <a href="stats.php?reset_horas=1" class="btn-reiniciar" onclick="return confirm('¿Reiniciar gráfica?');">🔄 Reiniciar</a></h3>
            <div style="position: relative; height: 250px; width: 100%;"><canvas id="graficaHoras"></canvas></div>
        </div>

    </div>

    <script>
        // MODIFICADO: Quitamos la condición de que deba tener datos para que siempre dibuje al menos el día actual
        const configChart = (id, type, data, options) => {
            new Chart(document.getElementById(id).getContext('2d'), { type, data, options });
        };

        configChart('graficaIngresos', 'line', {
            labels: <?= json_encode($fechas) ?>,
            datasets: [
                { label: 'Mesas ($)', data: <?= json_encode($ingresos_mesa) ?>, borderColor: '#32CD32', backgroundColor: 'rgba(50, 205, 50, 0.1)', borderWidth: 2, tension: 0.3, fill: true },
                { label: 'Delivery ($)', data: <?= json_encode($ingresos_delivery) ?>, borderColor: '#007BFF', backgroundColor: 'rgba(0, 123, 255, 0.1)', borderWidth: 2, tension: 0.3, fill: true },
                { label: 'Extras ($)', data: <?= json_encode($ingresos_extra_graf) ?>, borderColor: '#FFC107', backgroundColor: 'rgba(255, 193, 7, 0.1)', borderWidth: 2, tension: 0.3, fill: true },
                { label: 'Gastos Tot. ($)', data: <?= json_encode($gastos) ?>, borderColor: '#ff4d4d', backgroundColor: 'rgba(255, 77, 77, 0.1)', borderWidth: 2, tension: 0.3, fill: true }
            ]
        }, { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() } } } });

        configChart('graficaHoras', 'bar', {
            labels: <?= json_encode($horas_labels) ?>,
            datasets: [{ label: 'Grupos Atendidos', data: <?= json_encode($horas_data) ?>, backgroundColor: '#FF8C00', borderRadius: 5 }]
        }, { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } });
    </script>
    <script>
        // Sincroniza el modo oscuro desde mesas.php
        document.addEventListener('DOMContentLoaded', (event) => {
            if (localStorage.getItem('temaMesas') === 'oscuro') {
                document.body.classList.add('modo-oscuro');
                // Opcional: Actualizar el color de la grilla de Chart.js si se ve muy clara
                Chart.defaults.color = '#ccc';
                Chart.defaults.scale.grid.color = '#333';
            }
        });
    </script>
</body>
</html>