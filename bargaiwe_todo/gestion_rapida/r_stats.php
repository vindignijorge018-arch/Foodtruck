<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['restaurant_id'])) { 
    header("Location: ../portal_bargaiwe.php"); 
    exit(); 
}

include 'r_db.php'; 
verificarPlanPlus(); 

$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// --- OBTENER TEMA DESDE LA BASE DE DATOS ---
$res_tema = $conn->query("SELECT modo_global, color_cajero FROM config_temas WHERE restaurant_id = $mi_restaurant_id");
$tema = ($res_tema && $res_tema->num_rows > 0) ? $res_tema->fetch_assoc() : ['modo_global' => 'oscuro', 'color_cajero' => '#FF8C00'];

// --- 0. CARGAR METAS DEL NEGOCIO ---
$metas = $conn->query("SELECT * FROM metas_restaurante WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
if(!$metas) {
    $metas = [
        'mostrar_metas'=>0, 'meta_periodo'=>'diaria', 'meta_dias'=>1, 
        'act_neta'=>0, 'act_ventas'=>0, 'act_total_platos'=>0, 'act_plato_esp'=>0, 
        'act_seccion'=>0, 'act_postre'=>0, 'act_desc'=>0,
        'meta_dinero'=>0, 'meta_ventas'=>0, 'meta_platos'=>0, 'meta_seccion_nombre'=>'', 'meta_seccion_cant'=>0
    ];
}

$neta_periodo = 0; $ventas_periodo = 0; $platos_periodo = 0; $seccion_cant = 0;
$texto_periodo = "Hoy";

// --- 1. REGISTRAR GASTOS E INGRESOS EXTRA ---
if (isset($_POST['monto'])) {
    $concepto = $conn->real_escape_string($_POST['concepto']);
    $monto = (int)str_replace('.', '', $_POST['monto']);
    if (isset($_POST['agregar_gasto_extra'])) {
        $conn->query("INSERT INTO gastos (restaurant_id, concepto, monto) VALUES ($mi_restaurant_id, '$concepto', $monto)");
        header("Location: r_stats.php?exito_gasto=1"); exit();
    }
    if (isset($_POST['agregar_ingreso_extra'])) {
        $conn->query("INSERT INTO ingresos_extra (restaurant_id, concepto, monto) VALUES ($mi_restaurant_id, '$concepto', $monto)");
        header("Location: r_stats.php?exito_ingreso=1"); exit();
    }
}

// --- 2. LÓGICAS DE REINICIO ---
$resets = [
    'reset_platos' => ["UPDATE pedidos SET stat_platos = 0 WHERE estado >= 3 AND restaurant_id = $mi_restaurant_id"],
    'reset_grafica' => ["UPDATE pedidos SET stat_grafica = 0 WHERE estado >= 3 AND restaurant_id = $mi_restaurant_id", "DELETE FROM gastos WHERE restaurant_id = $mi_restaurant_id", "DELETE FROM ingresos_extra WHERE restaurant_id = $mi_restaurant_id"],
    'reset_horas' => ["UPDATE pedidos SET stat_horas = 0 WHERE estado >= 3 AND restaurant_id = $mi_restaurant_id"]
];
foreach ($resets as $get_key => $queries) {
    if (isset($_GET[$get_key])) {
        foreach ($queries as $q) $conn->query($q);
        header("Location: r_stats.php"); exit();
    }
}

// =========================================================
// ¡CORRECCIÓN!: FILTROS DE TIEMPO (Se calculan ANTES que las consultas)
// =========================================================
$filtro_tiempo = $_GET['rango'] ?? '3meses';
$where_fecha = "p.fecha >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
$select_fecha = "DATE(p.fecha)";
$group_fecha = "DATE(p.fecha)";
$titulo_rango = "Últimos 90 Días";

if ($filtro_tiempo == 'hoy') {
    $where_fecha = "DATE(p.fecha) = CURDATE()";
    $select_fecha = "DATE_FORMAT(p.fecha, '%Y-%m-%d %H:00')"; 
    $group_fecha  = "DATE_FORMAT(p.fecha, '%Y-%m-%d %H:00')"; 
    $titulo_rango = "Hoy (Por Horas)";
} elseif ($filtro_tiempo == 'semana') {
    $where_fecha = "p.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    $titulo_rango = "Últimos 7 Días";
} elseif ($filtro_tiempo == 'mes') {
    $where_fecha = "p.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $titulo_rango = "Últimos 30 Días";
} elseif ($filtro_tiempo == 'ano') {
    $where_fecha = "p.fecha >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
    $select_fecha = "DATE_FORMAT(p.fecha, '%Y-%m')"; 
    $group_fecha  = "DATE_FORMAT(p.fecha, '%Y-%m')"; 
    $titulo_rango = "Último Año (Por Meses)";
}

// =========================================================
// 3. ESTADÍSTICAS POR CAJERO / EMPLEADO (Ahora $where_fecha sí existe)
// =========================================================
$stats_cajeros = [];
$sql_cajeros = "SELECT 
                    IFNULL(e.nombre, 'Cajero Principal') as nombre, 
                    IFNULL(e.color, '#8b949e') as color, 
                    COUNT(DISTINCT p.codigo_grupo) as tickets, 
                    SUM(p.precio_al_momento) as ventas,
                    MIN(p.fecha) as primer_registro
                FROM pedidos p
                LEFT JOIN empleados e ON p.empleado_id = e.id
                WHERE p.estado >= 3 AND p.stat_grafica = 1 AND p.restaurant_id = $mi_restaurant_id AND $where_fecha
                GROUP BY p.empleado_id
                ORDER BY ventas DESC";

if($res = $conn->query($sql_cajeros)) {
    while($row = $res->fetch_assoc()) {
        $stats_cajeros[] = $row;
    }
}

// =========================================================
// 4. OBTENER PRODUCTOS ESTRELLA 
// =========================================================
$ventas_sec = []; $tot_sec = [];
if ($res = $conn->query("SELECT m.seccion, m.nombre, COUNT(p.id) as cant FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.estado >= 3 AND p.stat_platos = 1 AND p.restaurant_id = $mi_restaurant_id GROUP BY m.seccion, m.nombre ORDER BY m.seccion ASC, cant DESC")) {
    while($p = $res->fetch_assoc()) {
        $s = $p['seccion'];
        $tot_sec[$s] = ($tot_sec[$s] ?? 0) + $p['cant'];
        if (!isset($ventas_sec[$s])) $ventas_sec[$s] = [];
        if (count($ventas_sec[$s]) < 3 && count($ventas_sec) <= 6) $ventas_sec[$s][] = $p; 
    }
}

// =========================================================
// 5. GRÁFICA FINANCIERA Y CÁLCULOS
// =========================================================
$datos_dias = []; 
$tots = ['ventas'=>0, 'extra'=>0, 'gasto'=>0, 'costos_prod'=>0];

if ($filtro_tiempo == 'semana' || $filtro_tiempo == 'mes' || $filtro_tiempo == '3meses') {
    $dias_a_restar = ($filtro_tiempo == 'semana') ? 6 : (($filtro_tiempo == 'mes') ? 29 : 89);
    for ($i = $dias_a_restar; $i >= 0; $i--) {
        $fecha_iter = date('Y-m-d', strtotime("-$i days"));
        $datos_dias[$fecha_iter] = ['ventas'=>0, 'extra'=>0, 'gasto'=>0, 'costos_prod'=>0];
    }
} elseif ($filtro_tiempo == 'hoy') {
    for ($i = 0; $i <= 23; $i++) {
        $hora_iter = date('Y-m-d ') . str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
        $datos_dias[$hora_iter] = ['ventas'=>0, 'extra'=>0, 'gasto'=>0, 'costos_prod'=>0];
    }
} elseif ($filtro_tiempo == 'ano') {
     for ($i = 11; $i >= 0; $i--) {
        $mes_iter = date('Y-m', strtotime("first day of -$i month"));
        $datos_dias[$mes_iter] = ['ventas'=>0, 'extra'=>0, 'gasto'=>0, 'costos_prod'=>0];
    }
}

function initDia(&$arr, $dia) {
    if(!isset($arr[$dia])) $arr[$dia] = ['ventas'=>0, 'extra'=>0, 'gasto'=>0, 'costos_prod'=>0];
}

$sql_ventas = "SELECT $select_fecha as dia, SUM(p.precio_al_momento) as t, SUM(IFNULL(m.costo, 0) * p.cantidad) as c 
               FROM pedidos p 
               LEFT JOIN menu m ON p.menu_id = m.id 
               WHERE p.estado >= 3 AND p.stat_grafica = 1 AND p.restaurant_id = $mi_restaurant_id AND $where_fecha 
               GROUP BY $group_fecha";

if($res = $conn->query($sql_ventas)) {
    while($g = $res->fetch_assoc()) {
        initDia($datos_dias, $g['dia']);
        $datos_dias[$g['dia']]['ventas'] += $g['t'];
        $datos_dias[$g['dia']]['costos_prod'] += $g['c'];
        $tots['ventas'] += $g['t'];
        $tots['costos_prod'] += $g['c'];
    }
}

$where_extra = str_replace("p.fecha", "fecha", $where_fecha); 
$select_extra = str_replace("p.fecha", "fecha", $select_fecha);
$group_extra = str_replace("p.fecha", "fecha", $group_fecha);

if($res = $conn->query("SELECT $select_extra as dia, SUM(monto) as t FROM ingresos_extra WHERE restaurant_id = $mi_restaurant_id AND $where_extra GROUP BY $group_extra")) {
    while($g = $res->fetch_assoc()) {
        initDia($datos_dias, $g['dia']);
        $datos_dias[$g['dia']]['extra'] += $g['t'];
        $tots['extra'] += $g['t'];
    }
}

if($res = $conn->query("SELECT $select_extra as dia, SUM(monto) as t FROM gastos WHERE restaurant_id = $mi_restaurant_id AND $where_extra GROUP BY $group_extra")) {
    while($g = $res->fetch_assoc()) {
        initDia($datos_dias, $g['dia']);
        $datos_dias[$g['dia']]['gasto'] += $g['t'];
        $tots['gasto'] += $g['t'];
    }
}

ksort($datos_dias);

$fechas_crudas = array_keys($datos_dias);
$fechas = [];
foreach($fechas_crudas as $f) {
    if ($filtro_tiempo == 'hoy') { $fechas[] = date('H:i', strtotime($f)); } 
    elseif ($filtro_tiempo == 'ano') { $fechas[] = date('m/Y', strtotime($f . '-01')); } 
    else { $fechas[] = date('d/m', strtotime($f)); }
}

$ingresos_ventas = array_column($datos_dias, 'ventas');
$ingresos_extra_graf = array_column($datos_dias, 'extra');
$gastos = array_column($datos_dias, 'gasto');
$costos_graf = array_column($datos_dias, 'costos_prod');

$neta_graf = [];
foreach($datos_dias as $d) {
    $neta_graf[] = $d['ventas'] + $d['extra'] - $d['gasto'] - $d['costos_prod'];
}

$ganancia_neta_hist = $tots['ventas'] + $tots['extra'] - $tots['gasto'] - $tots['costos_prod'];

// =========================================================
// 6. GRÁFICA DE HORAS PICO
// =========================================================
$dias_esp = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
$horas_labels = []; $horas_data = [];

if($res = $conn->query("SELECT DAYOFWEEK(fecha) as dia_num, HOUR(fecha) as hora, COUNT(DISTINCT codigo_grupo) as tot FROM pedidos WHERE estado >= 3 AND stat_horas = 1 AND restaurant_id = $mi_restaurant_id GROUP BY DAYOFWEEK(fecha), HOUR(fecha) ORDER BY dia_num ASC, hora ASC")) {
    while($h = $res->fetch_assoc()) {
        $horas_labels[] = $dias_esp[$h['dia_num'] - 1] . ' ' . str_pad($h['hora'], 2, '0', STR_PAD_LEFT) . ':00';
        $horas_data[] = $h['tot'];
    }
}

if (empty($horas_labels)) {
    $horas_labels[] = "Sin Datos";
    $horas_data[] = 0;
}

function drawProgressBar($titulo, $actual, $meta, $es_dinero = false) {
    $actual = (float)$actual;
    $meta = (float)$meta;
    if($meta <= 0) return "";
    $porcentaje = min(100, round(($actual / $meta) * 100));
    $color = $porcentaje >= 100 ? 'var(--accent)' : 'var(--success)';
    $txt_actual = $es_dinero ? "$".number_format($actual, 0, ',', '.') : $actual;
    $txt_meta = $es_dinero ? "$".number_format($meta, 0, ',', '.') : $meta;
    
    return "
    <div style='margin-bottom: 15px;'>
        <div style='display: flex; justify-content: space-between; margin-bottom: 5px; color: var(--text-title);'>
            <strong>$titulo ($txt_actual / $txt_meta)</strong>
            <span style='color: $color; font-weight: bold;'>$porcentaje%</span>
        </div>
        <div style='width: 100%; height: 12px; border-radius: 10px; background: rgba(255,255,255,0.05); overflow: hidden; border: 1px solid var(--border);'>
            <div style='width: $porcentaje%; background: $color; height: 100%; border-radius: 10px; transition: width 0.5s ease;'></div>
        </div>
    </div>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Estadísticas - Bargaiwe Fast</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: <?php echo $tema['color_cajero']; ?>; 
            --danger: #E53935; --success: #32CD32;
        }
        
        <?php if($tema['modo_global'] === 'claro'): ?>
        body {
            --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000;
        }
        <?php endif; ?>

        body { background: var(--bg-body); color: var(--text); font-family: 'Segoe UI', sans-serif; margin: 0; transition: 0.3s; }
        
        .nav-hub { background: var(--bg-panel); border-bottom: 1px solid var(--border); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: var(--text-title); }
        .nav-hub a { color: white; text-decoration: none; font-weight: bold; background: var(--bg-panel); padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); transition: 0.3s; }
        .nav-hub a:hover { border-color: var(--accent); color: var(--accent); }

        .container { padding: 30px; display: grid; grid-template-columns: 1fr 1fr; gap: 30px; max-width: 1400px; margin: auto;}
        .card { background: var(--bg-panel); padding: 25px; border-radius: 15px; border: 1px solid var(--border); display: flex; flex-direction: column; }
        .card-full { grid-column: span 2; }
        
        h3 { color: var(--accent); margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; font-size: 1.3rem; display: flex; justify-content: space-between; align-items: center;}
        
        .btn-reiniciar { background: rgba(229, 57, 53, 0.1); color: var(--danger); text-decoration: none; padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; border: 1px solid var(--danger); transition: 0.2s; }
        .btn-reiniciar:hover { background: var(--danger); color: white; }

        .seccion-platos { margin-bottom: 20px; }
        .seccion-titulo { font-weight: bold; color: var(--text-title); text-transform: uppercase; margin-bottom: 10px; font-size: 0.9rem; border-bottom: 1px dashed var(--border); padding-bottom: 5px;}
        .plato-item { background: var(--bg-body); padding: 10px 15px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; border-left: 4px solid var(--accent); color: var(--text-title); font-size: 0.95rem;}
        
        .header-finanzas { display: flex; gap: 20px; margin-bottom: 20px; align-items: stretch;}
        .panel-izquierdo { flex-grow: 1; display: flex; flex-direction: column; gap: 20px; width: calc(100% - 320px); }
        
        .resumen-finanzas { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; background: var(--bg-body); padding: 15px; border-radius: 10px; border: 1px dashed var(--border);}
        
        .bloque-fin { text-align: center; border-right: 1px solid var(--border); padding: 10px 5px; cursor: pointer; transition: all 0.2s ease-in-out; border: 1px solid transparent; border-radius: 8px;}
        .bloque-fin:last-child { border-right: 1px solid transparent; }
        .bloque-fin:hover { background: rgba(255,255,255,0.08); transform: scale(1.02); border-color: var(--border); }
        .bloque-apagado { opacity: 0.4; filter: grayscale(1); border-color: transparent !important; }
        
        .bloque-fin span { display: block; font-size: 0.8rem; color: #8b949e; font-weight: bold; text-transform: uppercase;}
        .bloque-fin strong { font-size: 1.4rem; }
        
        .forms-container { display: flex; flex-direction: column; gap: 15px; width: 300px; flex-shrink: 0;}
        .form-fin { padding: 15px; border-radius: 10px; display: flex; flex-direction: column; gap: 10px; background: var(--bg-body); border: 1px solid var(--border);}
        .form-fin h4 { margin: 0; font-size: 0.95rem; color: var(--text-title); text-transform: uppercase; letter-spacing: 1px;}
        .form-fin input { padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-size: 0.9rem; background: var(--bg-panel); color: var(--text);}
        .form-fin input:focus { outline: none; border-color: var(--accent); }
        .form-fin button { color: white; border: none; padding: 10px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.9rem; transition: 0.2s;}
        .form-fin.ingreso button { background: var(--success); }
        .form-fin.gasto button { background: var(--danger); }
        .form-fin button:hover { opacity: 0.8; }
    </style>
    <script>
        if (localStorage.getItem('tema-rapida') === 'claro') { document.body.classList.add('tema-claro'); }
        function formatearMoneda(inputVis) {
            let val = inputVis.value.replace(/[^0-9]/g, '');
            inputVis.nextElementSibling.value = val;
            inputVis.value = val === '' ? '' : new Intl.NumberFormat('es-CL').format(val);
        }
    </script>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.8rem; font-weight: 800; color: var(--accent);">📊 Fast Stats</span>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="r_metas.php" style="border-color: var(--accent); color: var(--text-title); background: transparent;">🎯 Configurar Metas</a>
            <a href="r_costos_plus.php" style="border-color: var(--danger); color: var(--danger); background: transparent;">💰 Control de Costos</a>
            <a href="r_descuentos.php" style="border-color: var(--success); color: var(--success);">🏷️ Descuentos</a>
            <a href="r_pedidos.php">← Volver al Cajero</a>
        </div>
    </div>

    <div class="container">
        
        <?php if(isset($_GET['exito_gasto'])): ?>
            <div style="grid-column: span 2; background: rgba(229, 57, 53, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 15px; border-radius: 8px; text-align:center; font-weight:bold;">📉 Gasto operativo registrado.</div>
        <?php endif; ?>
        <?php if(isset($_GET['exito_ingreso'])): ?>
            <div style="grid-column: span 2; background: rgba(50, 205, 50, 0.1); border: 1px solid var(--success); color: var(--success); padding: 15px; border-radius: 8px; text-align:center; font-weight:bold;">📈 Ingreso extra registrado exitosamente.</div>
        <?php endif; ?>

        <?php if($metas['mostrar_metas']): ?>
        <div class="card card-full" style="border-color: var(--success);">
            <h3 style="color: var(--success);">🎯 Metas <?= $texto_periodo ?></h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <div>
                    <?php 
                        if($metas['act_neta']) echo drawProgressBar("Ganancia Neta", $neta_periodo, $metas['meta_dinero'], true);
                        if($metas['act_ventas']) echo drawProgressBar("Ventas Brutas", $ventas_periodo, $metas['meta_ventas'], true);
                    ?>
                </div>
                <div>
                    <?php 
                        if($metas['act_total_platos']) echo drawProgressBar("Productos Totales", $platos_periodo, $metas['meta_platos']);
                        if($metas['act_seccion']) echo drawProgressBar("Categoría: " . $metas['meta_seccion_nombre'], $seccion_cant, $metas['meta_seccion_cant']);
                    ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card card-full">
            <h3>
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span>📈 Flujo de Caja Total</span>
                    <select onchange="window.location.href='r_stats.php?rango='+this.value" style="padding: 5px 10px; border-radius: 6px; background: var(--bg-body); color: var(--accent); border: 1px solid var(--accent); font-size: 0.9rem; font-weight: bold; cursor: pointer;">
                        <option value="hoy" <?= $filtro_tiempo == 'hoy' ? 'selected' : '' ?>>Hoy (Por Horas)</option>
                        <option value="semana" <?= $filtro_tiempo == 'semana' ? 'selected' : '' ?>>Última Semana</option>
                        <option value="mes" <?= $filtro_tiempo == 'mes' ? 'selected' : '' ?>>Último Mes</option>
                        <option value="3meses" <?= $filtro_tiempo == '3meses' ? 'selected' : '' ?>>Últimos 3 Meses</option>
                        <option value="ano" <?= $filtro_tiempo == 'ano' ? 'selected' : '' ?>>Último Año (Por Meses)</option>
                    </select>
                </div>
                <a href="r_stats.php?reset_grafica=1" class="btn-reiniciar" onclick="return confirm('¿Borrar historial financiero?');">🔄 Reiniciar Todo</a>
            </h3>
            
            <div class="header-finanzas">
                <div class="panel-izquierdo">
                    
                    <div class="resumen-finanzas">
                        <div class="bloque-fin bloque-interactivo" onclick="toggleLineaGrafica(0, this)" title="Clic para ocultar/ver en el gráfico">
                            <span>Ventas (<?= $titulo_rango ?>)</span>
                            <strong style="color: #2E7D32;">$<?= number_format($tots['ventas'], 0, ',', '.') ?></strong>
                        </div>
                        <div class="bloque-fin bloque-interactivo" onclick="toggleLineaGrafica(1, this)" title="Clic para ocultar/ver en el gráfico">
                            <span>Costo Producción</span>
                            <strong style="color: #FFC107;">-$<?= number_format($tots['costos_prod'], 0, ',', '.') ?></strong>
                        </div>
                        <div class="bloque-fin bloque-interactivo" onclick="toggleLineaGrafica(2, this)" title="Clic para ocultar/ver en el gráfico">
                            <span>Ingresos Extra</span>
                            <strong style="color: #2196F3;">$<?= number_format($tots['extra'], 0, ',', '.') ?></strong>
                        </div>
                        <div class="bloque-fin bloque-interactivo" onclick="toggleLineaGrafica(3, this)" title="Clic para ocultar/ver en el gráfico">
                            <span>Gastos Manuales</span>
                            <strong style="color: #E53935;">-$<?= number_format($tots['gasto'], 0, ',', '.') ?></strong>
                        </div>
                        <div class="bloque-fin bloque-interactivo" onclick="toggleLineaGrafica(4, this)" style="background: rgba(255,255,255,0.05); border-radius: 8px; border: 1px dashed var(--border);" title="Clic para ocultar/ver en el gráfico">
                            <span>Ganancia Neta Real</span>
                            <strong id="texto-ganancia-neta" style="color: <?= ($ganancia_neta_hist >= 0) ? '#32CD32' : '#E53935' ?>;">$<?= number_format($ganancia_neta_hist, 0, ',', '.') ?></strong>
                        </div>
                    </div>
                    
                    <div style="position: relative; height: 200px; width: 100%;"><canvas id="graficaIngresos"></canvas></div>
                </div>

                <div class="forms-container">
                    <form method="POST" class="form-fin ingreso">
                        <h4>💰 Registrar Ingreso</h4>
                        <input type="text" name="concepto" placeholder="Ej: Venta de mercadería" required>
                        <div style="display:flex; gap:5px;">
                            <input type="text" oninput="formatearMoneda(this)" placeholder="$ Monto" required style="width: 60%;">
                            <input type="hidden" name="monto">
                            <button type="submit" name="agregar_ingreso_extra" style="width: 40%;">Sumar</button>
                        </div>
                    </form>
                    <form method="POST" class="form-fin gasto">
                        <h4>💸 Registrar Gasto</h4>
                        <input type="text" name="concepto" placeholder="Ej: Luz, Insumos, Gas" required>
                        <div style="display:flex; gap:5px;">
                            <input type="text" oninput="formatearMoneda(this)" placeholder="$ Monto" required style="width: 60%;">
                            <input type="hidden" name="monto">
                            <button type="submit" name="agregar_gasto_extra" style="width: 40%;">Restar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div> 

        <div class="card card-full">
            <h3><span>👥 Rendimiento por Cajero (<?= $titulo_rango ?>)</span></h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">
                <?php if(empty($stats_cajeros)): ?>
                    <p style="color:#8b949e; text-align:center; grid-column: 1/-1; padding: 20px;">No hay ventas registradas en este periodo.</p>
                <?php else: foreach($stats_cajeros as $c): ?>
                    <div style="background: var(--bg-body); padding: 15px; border-radius: 10px; border: 1px solid var(--border); border-left: 5px solid <?= $c['color'] ?>; display: flex; flex-direction: column; gap: 8px;">
                        <h4 style="margin: 0; color: var(--text-title); display: flex; align-items: center; gap: 10px; font-size: 1.1rem; border-bottom: 1px dashed var(--border); padding-bottom: 8px;">
                            <span style="display:inline-block; width:14px; height:14px; border-radius:50%; background:<?= $c['color'] ?>; box-shadow: 0 0 5px <?= $c['color'] ?>;"></span>
                            <?= htmlspecialchars($c['nombre']) ?>
                        </h4>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #8b949e; font-size: 0.9rem;">Ventas Totales:</span>
                            <strong style="color: var(--success); font-size: 1.2rem;">$<?= number_format($c['ventas'], 0, ',', '.') ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #8b949e; font-size: 0.9rem;">Tickets Emitidos:</span>
                            <strong style="color: var(--text-title); font-size: 1.1rem;"><?= $c['tickets'] ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 5px; font-size: 0.8rem;">
                            <span style="color: #8b949e;">Primera venta del rango:</span>
                            <span style="color: var(--text);"><?= $c['primer_registro'] ? date('d/m/Y H:i', strtotime($c['primer_registro'])) : 'N/A' ?></span>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="card">
            <h3><span>🍔 Productos Estrella</span>
                <a href="r_stats.php?reset_platos=1" class="btn-reiniciar" onclick="return confirm('¿Reiniciar contadores de productos?');">🔄 Reiniciar</a></h3>
            <div style="max-height: 400px; overflow-y: auto; padding-right: 10px;">
                <?php if(empty($ventas_sec)): ?>
                    <p style="color:#8b949e; text-align:center; padding: 30px;">Aún no hay ventas registradas.</p>
                <?php else: foreach($ventas_sec as $sec => $platos): ?>
                    <div class="seccion-platos">
                        <div class="seccion-titulo">📁 <?= htmlspecialchars($sec) ?></div>
                        <?php foreach($platos as $pl): ?>
                            <div class="plato-item">
                                <span><?= htmlspecialchars($pl['nombre']) ?> <span style="color:#8b949e; font-size: 0.8rem;">(<?= $pl['cant'] ?> uds)</span></span>
                                <strong style="color: var(--accent);"><?= ($tot_sec[$sec] > 0) ? round(($pl['cant'] / $tot_sec[$sec]) * 100, 1) : 0 ?>%</strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="card">
            <h3><span>⏰ Horarios de Alta Demanda</span>
                <a href="r_stats.php?reset_horas=1" class="btn-reiniciar" onclick="return confirm('¿Reiniciar gráfica de horas?');">🔄 Reiniciar</a></h3>
            <div style="position: relative; height: 350px; width: 100%;"><canvas id="graficaHoras"></canvas></div>
        </div>

    </div> <script>
        const isLightMode = <?php echo ($tema['modo_global'] === 'claro') ? 'true' : 'false'; ?>;
        const chartTextColor = isLightMode ? '#24292f' : '#8b949e';
        const chartGridColor = isLightMode ? '#d0d7de' : '#30363d';

        Chart.defaults.color = chartTextColor;
        Chart.defaults.borderColor = chartGridColor; 

        const totalesMatematica = {
            ventas: <?= (float)$tots['ventas'] ?>,
            costos: <?= (float)$tots['costos_prod'] ?>,
            extra: <?= (float)$tots['extra'] ?>,
            gastos: <?= (float)$tots['gasto'] ?>
        };

        let chartFlujo; 
        const ctxFlujo = document.getElementById('graficaIngresos').getContext('2d');
        chartFlujo = new Chart(ctxFlujo, {
            type: 'line',
            data: {
                labels: <?= json_encode($fechas) ?>,
                datasets: [
                    { label: 'Ventas ($)', data: <?= json_encode($ingresos_ventas) ?>, borderColor: '#2E7D32', backgroundColor: 'rgba(46, 125, 50, 0.1)', borderWidth: 2, tension: 0.3, fill: true, hidden: false }, 
                    { label: 'Costo Producción ($)', data: <?= json_encode($costos_graf) ?>, borderColor: '#FFC107', backgroundColor: 'rgba(255, 193, 7, 0.1)', borderWidth: 2, tension: 0.3, fill: true, hidden: false }, 
                    { label: 'Ingresos Extra ($)', data: <?= json_encode($ingresos_extra_graf) ?>, borderColor: '#2196F3', backgroundColor: 'rgba(33, 150, 243, 0.1)', borderWidth: 2, tension: 0.3, fill: true, hidden: false }, 
                    { label: 'Gastos Manuales ($)', data: <?= json_encode($gastos) ?>, borderColor: '#E53935', backgroundColor: 'rgba(229, 57, 53, 0.1)', borderWidth: 2, tension: 0.3, fill: true, hidden: false }, 
                    { label: 'Ganancia Neta Real ($)', data: <?= json_encode($neta_graf) ?>, borderColor: '#32CD32', backgroundColor: 'rgba(50, 205, 50, 0.1)', borderWidth: 4, tension: 0.3, fill: false, hidden: false } 
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { callback: v => '$' + v.toLocaleString() } } }
            }
        });

        function toggleLineaGrafica(index, elemento_html) {
            if(!chartFlujo) return;
            const isVisible = chartFlujo.isDatasetVisible(index);
            if (isVisible) {
                chartFlujo.hide(index);
                elemento_html.classList.add('bloque-apagado');
            } else {
                chartFlujo.show(index);
                elemento_html.classList.remove('bloque-apagado');
            }
            recalcularGananciaNeta();
        }

        function recalcularGananciaNeta() {
            let nuevaNeta = 0;

            // 1. Recalcular el texto global del recuadro
            if (chartFlujo.isDatasetVisible(0)) nuevaNeta += totalesMatematica.ventas;
            if (chartFlujo.isDatasetVisible(1)) nuevaNeta -= totalesMatematica.costos;
            if (chartFlujo.isDatasetVisible(2)) nuevaNeta += totalesMatematica.extra;
            if (chartFlujo.isDatasetVisible(3)) nuevaNeta -= totalesMatematica.gastos;

            const formateador = new Intl.NumberFormat('es-CL');
            const elementoNeta = document.getElementById('texto-ganancia-neta');
            if (elementoNeta) {
                elementoNeta.innerText = '$' + formateador.format(nuevaNeta);
                elementoNeta.style.color = (nuevaNeta >= 0) ? '#32CD32' : '#E53935';
            }

            // 2. Recalcular LA LÍNEA VERDE del gráfico día por día
            const datosVentas = chartFlujo.data.datasets[0].data;
            const datosCostos = chartFlujo.data.datasets[1].data;
            const datosExtra  = chartFlujo.data.datasets[2].data;
            const datosGastos = chartFlujo.data.datasets[3].data;
            
            let nuevaLineaNeta = [];

            // Recorremos cada día del gráfico para sumar/restar según lo que esté visible
            for (let i = 0; i < datosVentas.length; i++) {
                let puntoNeto = 0;
                
                if (chartFlujo.isDatasetVisible(0)) puntoNeto += parseFloat(datosVentas[i]) || 0;
                if (chartFlujo.isDatasetVisible(1)) puntoNeto -= parseFloat(datosCostos[i]) || 0;
                if (chartFlujo.isDatasetVisible(2)) puntoNeto += parseFloat(datosExtra[i]) || 0;
                if (chartFlujo.isDatasetVisible(3)) puntoNeto -= parseFloat(datosGastos[i]) || 0;
                
                nuevaLineaNeta.push(puntoNeto);
            }

            // Actualizamos la línea Neta (es el dataset número 4) y redibujamos el gráfico
            chartFlujo.data.datasets[4].data = nuevaLineaNeta;
            chartFlujo.update();
        }

        const ctxHoras = document.getElementById('graficaHoras').getContext('2d');
        new Chart(ctxHoras, {
            type: 'bar',
            data: {
                labels: <?= json_encode($horas_labels) ?>,
                datasets: [{ label: 'Tickets Emitidos', data: <?= json_encode($horas_data) ?>, backgroundColor: '#FF8C00', borderRadius: 4 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    </script>
</body>
</html>