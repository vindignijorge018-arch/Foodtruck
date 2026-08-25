<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';

if (!isset($_SESSION['restaurant_id'])) {
    die("Acceso no autorizado. Por favor inicia sesión.");
}
$mi_restaurant_id = $_SESSION['restaurant_id'];


$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";


$ip_nitro = "192.168.5.54"; 
$ruta_base = "$protocolo://$ip_nitro/bargaiwe/gestion_restaurante/pedido_qr.php";


$clave_secreta = "Bargaiwe_Secreto_2026"; 
$token_restaurante = hash('sha256', $mi_restaurant_id . $clave_secreta);


$url_para_qr = $ruta_base . "?r=" . $mi_restaurant_id . "&t=" . $token_restaurante;

if (isset($_GET['cambiar_vista'])) {
    $_SESSION['vista_mesas'] = $_GET['cambiar_vista'];
    header("Location: mesas.php"); 
    exit();
}
$vista_actual = isset($_SESSION['vista_mesas']) ? $_SESSION['vista_mesas'] : 'clasica';


if (isset($_POST['debug_mesa_num'])) {
    $y = (int)$_POST['debug_mesa_num'];
    $res_x = $conn->query("SELECT MAX(numero_mesa) as max_x FROM mesas WHERE restaurant_id = $mi_restaurant_id");
    $x = ($res_x && $res_x->num_rows > 0) ? (int)$res_x->fetch_assoc()['max_x'] : 0;
    
    $res_y = $conn->query("SELECT id, estado FROM mesas WHERE restaurant_id = $mi_restaurant_id AND numero_mesa = $y");
    
    if ($res_y && $res_y->num_rows > 0) {
        $mesa_data = $res_y->fetch_assoc();
        $id_mesa = $mesa_data['id'];
        $conn->query("DELETE FROM pedidos WHERE mesa_id = $id_mesa AND estado IN (1, 2)");
        $conn->query("UPDATE mesas SET estado = 0 WHERE id = $id_mesa");
        header("Location: mesas.php?debug_exito=$y");
        exit();
    } else {
        if ($y <= $x && $y > 0) {
            $conn->query("DELETE p FROM pedidos p JOIN mesas m ON p.mesa_id = m.id WHERE m.numero_mesa = $y AND m.restaurant_id = $mi_restaurant_id");
            $conn->query("UPDATE mesas SET estado = 0 WHERE numero_mesa = $y AND restaurant_id = $mi_restaurant_id");
            header("Location: mesas.php?debug_error_code=$y");
            exit();
        } else {
            header("Location: mesas.php?debug_no_existe=$y");
            exit();
        }
    }
}


$conn->query("CREATE TABLE IF NOT EXISTS mapa_config (
    restaurant_id INT PRIMARY KEY, color_fondo VARCHAR(20) DEFAULT '#242b30', 
    snap_grid INT DEFAULT 1, colision INT DEFAULT 0, zoom INT DEFAULT 1, max_pisos INT DEFAULT 3
)");
$conn->query("INSERT IGNORE INTO mapa_config (restaurant_id, max_pisos, color_fondo, zoom) VALUES ($mi_restaurant_id, 3, '#f4f4f4', 1)");

$config = $conn->query("SELECT max_pisos, color_fondo, zoom FROM mapa_config WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
$max_pisos = $config['max_pisos'] ? $config['max_pisos'] : 3;
$bg_color_global = isset($config['color_fondo']) ? $config['color_fondo'] : '#f4f4f4';


$tipo_mapa = (isset($config['zoom']) && in_array((int)$config['zoom'], [1,2,3])) ? (int)$config['zoom'] : 1;
$mapa_cfg = [
    1 => ['w' => 1540, 'h' => 630,  'scale' => 1.0], 
    2 => ['w' => 2100, 'h' => 840,  'scale' => 0.75], 
    3 => ['w' => 3080, 'h' => 1260, 'scale' => 0.5]   
];
$mapa_w = $mapa_cfg[$tipo_mapa]['w'];
$mapa_h = $mapa_cfg[$tipo_mapa]['h'];
$mapa_scale = $mapa_cfg[$tipo_mapa]['scale'];


$visual_w = $mapa_w * $mapa_scale;
$visual_h = $mapa_h * $mapa_scale;

$conn->query("CREATE TABLE IF NOT EXISTS pisos_personalizados (
    restaurant_id INT, piso_num INT, color VARCHAR(20) DEFAULT '#e8ecef',
    PRIMARY KEY(restaurant_id, piso_num)
)");

if (isset($_POST['cambiar_color'])) {
    $p_num = (int)$_POST['piso_num'];
    $color = $conn->real_escape_string($_POST['color_fondo']);
    $conn->query("INSERT INTO pisos_personalizados (restaurant_id, piso_num, color) VALUES ($mi_restaurant_id, $p_num, '$color') ON DUPLICATE KEY UPDATE color = '$color'");
    header("Location: mesas.php"); exit();
}

$conn->query("UPDATE mesas SET estado = 1 WHERE id IN (SELECT DISTINCT mesa_id FROM pedidos WHERE estado IN (1, 2) AND mesa_id IS NOT NULL)");

if (isset($_GET['ocupar_mesa'])) {
    $id_mesa = (int)$_GET['ocupar_mesa'];
    $conn->query("UPDATE mesas SET estado = 1 WHERE id = $id_mesa");
    header("Location: mesas.php"); exit();
}

if (isset($_GET['agregar_mesa']) && isset($_GET['piso'])) {
    $piso_destino = (int)$_GET['piso'];
    
   
    $res_numeros = $conn->query("SELECT numero_mesa FROM mesas WHERE restaurant_id = $mi_restaurant_id ORDER BY numero_mesa ASC");
    $ocupados = [];
    if ($res_numeros) {
        while($row = $res_numeros->fetch_assoc()) {
            $ocupados[] = (int)$row['numero_mesa'];
        }
    }
    
    
    $nueva_mesa = 1;
    while(in_array($nueva_mesa, $ocupados)) {
        $nueva_mesa++;
    }
    
    $conn->query("INSERT INTO mesas (restaurant_id, numero_mesa, estado, usos, piso, pos_x, pos_y) VALUES ($mi_restaurant_id, $nueva_mesa, 0, 0, $piso_destino, 0, 0)");
    header("Location: mesas.php"); exit();
}

if (isset($_GET['eliminar_mesa']) && isset($_GET['piso'])) {
    $piso_borrar = (int)$_GET['piso'];
    $res_ultima = $conn->query("SELECT id, estado FROM mesas WHERE restaurant_id = $mi_restaurant_id AND piso = $piso_borrar ORDER BY numero_mesa DESC LIMIT 1");
    if ($res_ultima && $res_ultima->num_rows > 0) {
        $ultima_mesa = $res_ultima->fetch_assoc();
        if ($ultima_mesa['estado'] == 0) {
            $id_borrar = $ultima_mesa['id'];
            $conn->query("DELETE FROM mesas WHERE id = $id_borrar");
        } else {
            header("Location: mesas.php?error_borrar=1"); exit();
        }
    }
    header("Location: mesas.php"); exit();
}

if (isset($_POST['traer_mesa_num']) && isset($_POST['piso_destino'])) {
    $num_mesa_mover = (int)$_POST['traer_mesa_num'];
    $piso_destino = (int)$_POST['piso_destino'];
    $check_mesa = $conn->query("SELECT id FROM mesas WHERE numero_mesa = $num_mesa_mover AND restaurant_id = $mi_restaurant_id");
    if ($check_mesa && $check_mesa->num_rows > 0) {
        $conn->query("UPDATE mesas SET piso = $piso_destino, pos_x = 0, pos_y = 0 WHERE numero_mesa = $num_mesa_mover AND restaurant_id = $mi_restaurant_id");
        header("Location: mesas.php?exito_mover=1");
    } else {
        header("Location: mesas.php?error_mover=1");
    }
    exit();
}

$res_config = $conn->query("SELECT modulo_delivery FROM configuracion_restaurante LIMIT 1");
$delivery_activado = ($res_config && $res_config->num_rows > 0) ? $res_config->fetch_assoc()['modulo_delivery'] : 0;

$deliveries_pendientes = 0;
$res_delivery_pend = $conn->query("SELECT COUNT(id) as pendientes FROM registro_delivery WHERE estado_pedido < 3 AND restaurant_id = $mi_restaurant_id");
if ($res_delivery_pend) { $deliveries_pendientes = $res_delivery_pend->fetch_assoc()['pendientes']; }

$pisos_data = [];
for ($i = 1; $i <= $max_pisos; $i++) {
    $pisos_data[$i] = ['nombre' => "Piso $i", 'color' => '#e8ecef', 'mesas' => [], 'objetos' => []];
}

$res_colores = $conn->query("SELECT piso_num, color FROM pisos_personalizados WHERE restaurant_id = $mi_restaurant_id");
if($res_colores) {
    while($c = $res_colores->fetch_assoc()) {
        if(isset($pisos_data[$c['piso_num']])) { $pisos_data[$c['piso_num']]['color'] = $c['color']; }
    }
}

$res_mesas = $conn->query("SELECT * FROM mesas WHERE restaurant_id = $mi_restaurant_id ORDER BY numero_mesa ASC");
if ($res_mesas) {
    while($m = $res_mesas->fetch_assoc()){
        $piso_num = $m['piso'] > 0 ? $m['piso'] : 1; 
        if (!isset($pisos_data[$piso_num])) { $pisos_data[$piso_num] = ['nombre' => "Piso $piso_num", 'color' => '#e8ecef', 'mesas' => [], 'objetos' => []]; }
        $pisos_data[$piso_num]['mesas'][] = $m;
    }
}

$res_objetos = $conn->query("SELECT * FROM objetos_mapa WHERE restaurant_id = $mi_restaurant_id");
if ($res_objetos) {
    while($obj = $res_objetos->fetch_assoc()){
        $piso_num = $obj['piso'] > 0 ? $obj['piso'] : 1;
        if (!isset($pisos_data[$piso_num])) { $pisos_data[$piso_num] = ['nombre' => "Piso $piso_num", 'color' => '#e8ecef', 'mesas' => [], 'objetos' => []]; }
        $pisos_data[$piso_num]['objetos'][] = $obj;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Gestión de Mesas</title>
    <style>
        /* --- AJUSTES DEL MAPA VISUAL PARA EL MODO OSCURO --- */
        body.modo-oscuro .contenedor-mapa-interno { 
            
            background-color: #222629 !important; 
            border-color: #444 !important;
            box-shadow: inset 0 0 15px rgba(0,0,0,0.8) !important;
        }
        
        body.modo-oscuro .contenedor-mapa-interno > div {
            
            background-image: 
                linear-gradient(rgba(255,255,255,0.05) 1px, transparent 1px), 
                linear-gradient(90deg, rgba(255,255,255,0.05) 1px, transparent 1px) !important;
        }

        body.modo-oscuro .mesa-libre { 
            
            background-color: #1e3a1e !important; 
            border-color: #32CD32 !important; 
        }
        
        body.modo-oscuro .mesa-ocupada { 
            
            background-color: #3e280d !important; 
            border-color: #FF8C00 !important; 
        }
        
        body.modo-oscuro .carrusel-slide { background-color: #222629 !important; }
        body.modo-oscuro .seccion-header { background: #1a1d20 !important; border: 1px solid #333 !important; }
        /* --- ESTILOS DEL MODO OSCURO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; }
        body.modo-oscuro .seccion-header { background: rgba(30, 30, 30, 0.9); box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        body.modo-oscuro .seccion-header h2 { color: #fff; }
        body.modo-oscuro .mesa { background: #1e1e1e; color: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        body.modo-oscuro .modal-content { background: #1e1e1e; color: #fff; }
        body.modo-oscuro .modal-content h3, body.modo-oscuro .modal-content p { color: #ccc !important; }
        
        body { background-color: <?php echo htmlspecialchars($bg_color_global); ?>; font-family: 'Segoe UI', sans-serif; margin: 0; padding-bottom: 20px;}
        
        
        .container { padding: 20px 30px; margin: auto; max-width: 1110px; }
        
        .btn-header-fijo { display: inline-block; color: white; text-decoration: none; padding: 10px 15px; border-radius: 8px; font-weight: bold; font-size: 1rem; transition: 0.2s; box-sizing: border-box; cursor: pointer; }
        .btn-header-fijo:hover { opacity: 0.9; transform: scale(1.05); }

        .nav-hub { background: #014421; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); position: sticky; top: 0; z-index: 200;}
        .nav-buttons { display: flex; gap: 10px; align-items: center;}
        .nav-buttons a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 10px; color: white; transition: 0.3s; }
        .btn-delivery { background: #E53935; border: 1px solid #c62828; }
        .btn-stats { background: #FF8C00; }
        .btn-cocina { background: #32CD32; }
        .btn-menu { background: #555; }
        .nav-buttons a:hover { opacity: 0.8; }

        .btn-con-notificacion { position: relative; display: inline-flex; align-items: center; }
        .notificacion-azul { position: absolute; top: -8px; right: -8px; background-color: #007BFF; color: white; border-radius: 50%; width: 22px; height: 22px; font-size: 0.85rem; font-weight: bold; display: flex; justify-content: center; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.3); animation: latido 2s infinite; }
        @keyframes latido { 0% { transform: scale(1); } 50% { transform: scale(1.15); } 100% { transform: scale(1); } }

        .container { padding: 40px 30px; margin: auto; max-width: 85%; }
        
        .carrusel-contenedor { position: relative; overflow: hidden; width: 100%; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .carrusel-track { display: flex; flex-wrap: nowrap; transition: transform 0.4s ease-in-out; }
        .carrusel-slide { flex: 0 0 100%; box-sizing: border-box; padding: 40px; min-height: 60vh; transition: background-color 0.3s;}
        
        .carrusel-btn { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.6); color: white; border: none; font-size: 2.5rem; width: 60px; height: 60px; display: flex; justify-content: center; align-items: center; cursor: pointer; z-index: 10; border-radius: 50%; transition: 0.2s; backdrop-filter: blur(5px); }
        .carrusel-btn:hover { background: rgba(0,0,0,0.9); transform: translateY(-50%) scale(1.1); }
        .btn-prev { left: 20px; }
        .btn-next { right: 20px; }

        .seccion-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; background: rgba(255,255,255,0.9); padding: 15px 25px; border-radius: 12px; backdrop-filter: blur(5px); box-shadow: 0 4px 10px rgba(0,0,0,0.05);}
        .seccion-header h2 { margin: 0; color: #333; font-size: 1.8rem; display: flex; align-items: center; gap: 10px;}
        .seccion-controles { display: flex; gap: 10px; align-items: center; }
        
        .color-picker-form { display: flex; align-items: center; background: #fff; padding: 5px; border-radius: 8px; border: 1px solid #ccc; transition: 0.2s;}
        .color-picker-form:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .color-picker-form input[type="color"] { border: none; background: transparent; width: 35px; height: 35px; cursor: pointer; padding: 0;}

        .grid-mesas { display: grid; grid-template-columns: repeat(7, 1fr); gap: 20px; justify-content: center; }
        
        .mesa { 
            background: white; 
            border-radius: 15px; 
            padding: 20px; 
            text-align: center; 
            color: #333; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.08); 
            transition: 0.2s; 
            border: 4px solid transparent; 
            width: 100%; 
            box-sizing: border-box;
            aspect-ratio: 1 / 1; 
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .mesa:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.15); }
        .mesa-libre { border-color: #32CD32; }
        .mesa-libre h2 { color: #32CD32; }
        .mesa-ocupada { border-color: #FF8C00; background-color: #fff8e1; }
        .mesa-ocupada h2 { color: #FF8C00; }
        .mesa-numero { font-size: 2.2rem; margin: 0; font-weight: 900; }
        .mesa-estado { font-size: 0.9rem; font-weight: bold; margin-top: 5px; text-transform: uppercase; }

        .btn-accion-mesa { display: inline-block; color: white; text-decoration: none; padding: 0.6em 1em; border-radius: 8px; font-weight: bold; font-size: 0.85rem; transition: 0.2s; box-sizing: border-box; cursor: pointer; }
        .btn-accion-mesa:hover { opacity: 0.9; transform: scale(1.05); }
        
        .btn-creador-mapas { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); background: #777; color: white; padding: 15px 30px; border-radius: 50px; text-decoration: none; font-weight: 900; font-size: 1.1rem; box-shadow: 0 5px 15px rgba(0,0,0,0.3); transition: 0.3s; z-index: 1000;}
        .btn-creador-mapas:hover { background: #555; transform: translateX(-50%) scale(1.05); }

        .btn-debug-flotante { position: fixed; bottom: 30px; right: 30px; background: #E53935; color: white; border: none; padding: 15px 25px; border-radius: 50px; font-weight: 900; font-size: 1.1rem; box-shadow: 0 5px 15px rgba(229, 57, 53, 0.4); cursor: pointer; transition: 0.2s; z-index: 1000;}
        .btn-debug-flotante:hover { transform: scale(1.05); background: #c62828;}
        
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); display: flex; justify-content: center; align-items: center; z-index: 2000; backdrop-filter: blur(5px);}
        .modal-content { background: white; padding: 30px; border-radius: 15px; width: 400px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.2);}
        .modal-content input { width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #ccc; font-size: 1.2rem; text-align: center; box-sizing: border-box; margin-bottom: 15px;}
        .alerta-debug { background: #fff3cd; color: #856404; padding: 20px; border-radius: 10px; margin-bottom: 25px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 15px;}
        .btn-aceptar { background: #333; color: white; padding: 10px 30px; text-decoration: none; border-radius: 8px; font-weight: bold;}
    </style>
    
    <script>
        let indiceActual = 0;
        const totalSlides = <?php echo count($pisos_data); ?>;
        let track;

        function actualizarCarrusel(animar = true) {
            track = document.getElementById('track-secciones');
            if(!track || totalSlides === 0) return;
            if (indiceActual >= totalSlides) indiceActual = Math.max(0, totalSlides - 1);
            track.style.transition = animar ? 'transform 0.4s ease-in-out' : 'none';
            track.style.transform = `translateX(-${indiceActual * 100}%)`;
            sessionStorage.setItem('seccionActiva', indiceActual);
            
            let btnPrev = document.getElementById('btnPrev');
            let btnNext = document.getElementById('btnNext');
            if(btnPrev) btnPrev.style.display = (indiceActual === 0) ? 'none' : 'flex';
            if(btnNext) btnNext.style.display = (indiceActual === totalSlides - 1 || totalSlides === 0) ? 'none' : 'flex';
        }

        function mover(direccion) {
            indiceActual += direccion;
            if (indiceActual < 0) indiceActual = 0;
            if (indiceActual >= totalSlides) indiceActual = totalSlides - 1;
            actualizarCarrusel(true);
        }

        function abrirModalDebug() { document.getElementById('modalDebug').style.display = 'flex'; }
        function cerrarModalDebug() { document.getElementById('modalDebug').style.display = 'none'; }
        
        function abrirModalTraerMesa(piso) { 
            document.getElementById('inputPisoDestino').value = piso;
            document.getElementById('modalTraerMesa').style.display = 'flex'; 
        }
        function cerrarModalTraerMesa() { document.getElementById('modalTraerMesa').style.display = 'none'; }

        function abrirModalEliminar(piso) {
            document.getElementById('btnConfirmarEliminar').href = "mesas.php?eliminar_mesa=1&piso=" + piso;
            document.getElementById('modalEliminarMesa').style.display = 'flex';
        }
        function cerrarModalEliminar() { 
            document.getElementById('modalEliminarMesa').style.display = 'none'; 
        }

        window.addEventListener('beforeunload', function() { sessionStorage.setItem('scrollMesas', window.scrollY); });

        window.onload = function() {
            let slideGuardado = sessionStorage.getItem('seccionActiva');
            if (slideGuardado !== null && slideGuardado < totalSlides) { indiceActual = parseInt(slideGuardado); }
            actualizarCarrusel(false);
            
            let scrollGuardado = sessionStorage.getItem('scrollMesas');
            if (scrollGuardado) { window.scrollTo(0, parseInt(scrollGuardado)); sessionStorage.removeItem('scrollMesas'); }

            let mapaScrollTop = sessionStorage.getItem('mapaScrollTop');
            let mapaScrollLeft = sessionStorage.getItem('mapaScrollLeft');
            if (mapaScrollTop !== null || mapaScrollLeft !== null) {
                setTimeout(() => {
                    let contenedoresMapa = document.querySelectorAll('.contenedor-mapa-interno');
                    contenedoresMapa.forEach(mapa => {
                        mapa.scrollTop = parseInt(mapaScrollTop || 0);
                        mapa.scrollLeft = parseInt(mapaScrollLeft || 0);
                    });
                }, 100);
            }
        }
    </script>
</head>
<body>

<div class="nav-hub">
    <span style="font-size: 1.6rem; font-weight: 900;">bargaiwe</span>
    <div class="nav-buttons">
        <?php if ($vista_actual == 'clasica'): ?>
            <a href="?cambiar_vista=mapa" style="background: #9C27B0;">🗺️ Ver Mapa Visual</a>
        <?php else: ?>
            <a href="?cambiar_vista=clasica" style="background: #607D8B;">🔲 Ver Cuadrícula Clásica</a>
        <?php endif; ?>
        
        <?php if ($delivery_activado == 1): ?>
            <a href="delivery.php" class="btn-delivery btn-con-notificacion">
                🛵 Delivery
                <?php if($deliveries_pendientes > 0): ?>
                    <span class="notificacion-azul"><?php echo $deliveries_pendientes; ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
        
        <a href="precios.php" title="Gestión de Precios" style="background:#007BFF;">🏷️</a>
        
        <?php if (isset($_SESSION['nivel_plan']) && $_SESSION['nivel_plan'] === 'plus'): ?>
            <a href="stats.php" class="btn-stats">📈 Stats.</a>
        <?php endif; ?>
        
        <a href="cocina.php" class="btn-cocina">👨‍🍳 Cocina</a>
        <a href="menu.php" class="btn-menu">⚙️ Config Menú</a>
        <a href="configuracion_pagos.php" style="background: #009EE3; color: white;">💳 Maquinitas</a>
        <a href="configuracion_impresora.php" style="background: #58a6ff; color: white;">🖨️ Ticketera</a>
        <?php if (isset($_SESSION['nivel_plan']) && $_SESSION['nivel_plan'] === 'plus'): ?>
            <a href="javascript:void(0)" onclick="mostrarQR('General', '0')" style="background: #444; color: white;">📱 Qr</a>
        <?php endif; ?>
        
        <a href="javascript:void(0)" onclick="toggleModoOscuro()" id="btnTema" style="background: #222; color: white;" title="Cambiar Tema">🌙</a>
    </div>
</div>

<a href="mesa_visual.php" class="btn-creador-mapas">🗺️ Creador de Mapas</a>
<button class="btn-debug-flotante" onclick="abrirModalDebug()">🐞 Debug Mesa</button>

<div id="modalQR" class="modal-overlay" style="display:none;">
    <div class="modal-content">
        <h3 id="qrTitulo" style="color:#444; margin-top:0;">📱 Conectar</h3>
        <p style="color: #666; font-size: 0.9rem;">Escanea el código para el mesero.</p>
        <div style="background: white; padding: 10px; display: inline-block; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
            <img id="qrImagen" src="" alt="Cargando..." style="width:250px; height:250px; display:block;">
        </div>
        <button onclick="document.getElementById('modalQR').style.display='none'" style="background: #ccc; margin-top:15px; padding: 12px; border:none; border-radius: 8px; width: 100%; font-weight: bold; cursor:pointer; color: #333;">Cerrar</button>
    </div>
</div>

<script>
function mostrarQR(numeroMesa, idMesa) {
    const rutaBase = "<?php echo $ruta_base; ?>";
    const restId = "<?php echo $mi_restaurant_id; ?>";
    const token = "<?php echo $token_restaurante; ?>";
    const urlFinal = rutaBase + "?mesa_id=" + idMesa + "&r=" + restId + "&t=" + token;
    
    document.getElementById('qrTitulo').innerText = "📱 Mesa " + numeroMesa;
    document.getElementById('qrImagen').src = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" + encodeURIComponent(urlFinal);
    document.getElementById('modalQR').style.display = 'flex';
}
</script>

<script>
function mostrarQR(numeroMesa, idMesa) {
    const rutaBase = "<?php echo $ruta_base; ?>";
    const restId = "<?php echo $mi_restaurant_id; ?>";
    const token = "<?php echo $token_restaurante; ?>";
    
    
    const urlFinal = rutaBase + "?mesa_id=" + idMesa + "&r=" + restId + "&t=" + token;
    
    document.getElementById('tituloMesaQR').innerText = "📱 Mesa " + numeroMesa;
    document.getElementById('imgQR').src = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" + encodeURIComponent(urlFinal);
    document.getElementById('modalQR').style.display = 'flex';
}
</script>

<div id="modalDebug" class="modal-overlay" style="display:none;">
    <div class="modal-content">
        <h3 style="color:#E53935; margin-top:0;">🐞 Forzar Liberación</h3>
        <form method="POST">
            <input type="number" name="debug_mesa_num" required min="1" placeholder="Ej: 4">
            <div style="display: flex; gap: 10px;">
                <button type="submit" style="background: #E53935; color: white; padding: 12px; border:none; border-radius: 8px; flex: 1; font-weight: bold; cursor:pointer;">Liberar</button>
                <button type="button" onclick="cerrarModalDebug()" style="background: #ccc; padding: 12px; border:none; border-radius: 8px; flex: 1; font-weight: bold; cursor:pointer; color: #333;">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<div id="modalTraerMesa" class="modal-overlay" style="display:none;">
    <div class="modal-content">
        <h3 style="color:#9C27B0; margin-top:0;">📥 Traer Mesa Aquí</h3>
        <p style="color: #666; font-size: 0.9rem;">Escribe el número de la mesa que quieres traer a este piso.</p>
        <form method="POST">
            <input type="hidden" name="piso_destino" id="inputPisoDestino" value="">
            <input type="number" name="traer_mesa_num" required min="1" placeholder="Nº de Mesa (Ej: 15)">
            <div style="display: flex; gap: 10px;">
                <button type="submit" style="background: #9C27B0; color: white; padding: 12px; border:none; border-radius: 8px; flex: 1; font-weight: bold; cursor:pointer;">Traer Mesa</button>
                <button type="button" onclick="cerrarModalTraerMesa()" style="background: #ccc; padding: 12px; border:none; border-radius: 8px; flex: 1; font-weight: bold; cursor:pointer; color: #333;">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<div id="modalEliminarMesa" class="modal-overlay" style="display:none;">
    <div class="modal-content">
        <h3 style="color:#E53935; margin-top:0;">🗑️ Eliminar Mesa</h3>
        <p style="color: #666; font-size: 0.9rem;">¿Estás seguro de que quieres eliminar la mesa con el número más alto de este piso?</p>
        <div style="display: flex; gap: 10px; margin-top: 20px;">
            <a id="btnConfirmarEliminar" href="#" style="background: #E53935; color: white; padding: 12px; border-radius: 8px; flex: 1; font-weight: bold; text-decoration: none; display: flex; justify-content: center; align-items: center;">Sí, eliminar</a>
            <button type="button" onclick="cerrarModalEliminar()" style="background: #ccc; padding: 12px; border:none; border-radius: 8px; flex: 1; font-weight: bold; cursor:pointer; color: #333;">Cancelar</button>
        </div>
    </div>
</div>

<div id="mainContainer" class="container">
    
    <?php if(isset($_GET['debug_error_code'])): ?>
        <div class="alerta-debug error">
            <strong>⚠️ ERROR DETECTADO</strong>
            <span>Mesa forzada.</span>
            <a href="mesas.php" class="btn-aceptar">Aceptar</a>
        </div>
    <?php endif; ?>
    
    <?php if(isset($_GET['error_mover'])): ?>
        <div style="background: #ffebee; color: #c62828; padding: 15px; border-radius: 10px; margin-bottom: 20px; text-align: center; font-weight: bold; border: 1px solid #ef9a9a;">
            ⚠️ No se encontró ninguna mesa con ese número.
        </div>
    <?php endif; ?>

    <div class="carrusel-contenedor">
        <button id="btnPrev" class="carrusel-btn btn-prev" onclick="mover(-1)" style="display: none;">◀</button>
        <button id="btnNext" class="carrusel-btn btn-next" onclick="mover(1)" style="display: none;">▶</button>

        <div class="carrusel-track" id="track-secciones">
            <script>
                // --- LÓGICA DEL MODO OSCURO ---
                function toggleModoOscuro() {

                    document.body.classList.toggle('modo-oscuro');
    
                    let esOscuro = document.body.classList.contains('modo-oscuro');
    
                    localStorage.setItem('temaMesas', esOscuro ? 'oscuro' : 'claro');
    
                    document.getElementById('btnTema').innerText = esOscuro ? '☀️' : '🌙';
                    document.getElementById('btnTema').style.background = esOscuro ? '#f1c40f' : '#222';
                    document.getElementById('btnTema').style.color = esOscuro ? '#000' : '#fff';
                }
                document.addEventListener('DOMContentLoaded', (event) => {
                    if (localStorage.getItem('temaMesas') === 'oscuro') {
                        document.body.classList.add('modo-oscuro');
                        document.getElementById('btnTema').innerText = '☀️';
                        document.getElementById('btnTema').style.background = '#f1c40f';
                        document.getElementById('btnTema').style.color = '#000';
                    }
                });
                let slideRapido = sessionStorage.getItem('seccionActiva');
                if (slideRapido !== null) {
                    document.getElementById('track-secciones').style.transition = 'none';
                    document.getElementById('track-secciones').style.transform = `translateX(-${slideRapido * 100}%)`;
                }
            </script>

            <?php foreach($pisos_data as $piso_num => $piso): ?>
                <div class="carrusel-slide" style="background-color: <?php echo htmlspecialchars($piso['color']); ?>;">
                    
                    <div class="seccion-header">
                        <h2>📍 <?php echo htmlspecialchars($piso['nombre']); ?></h2>
                        <div class="seccion-controles">
                            <a href="javascript:void(0)" onclick="abrirModalTraerMesa(<?php echo $piso_num; ?>)" class="btn-header-fijo" style="background: #9C27B0;">📥 Traer Mesa Aquí</a>
                            <a href="mesas.php?agregar_mesa=1&piso=<?php echo $piso_num; ?>" class="btn-header-fijo" style="background: #007BFF;">➕ Añadir</a>
                            <?php if (count($piso['mesas']) > 0): ?>
                                <button type="button" class="btn-header-fijo" style="background: #E53935; border: none;" title="Eliminar última mesa de este piso" onclick="abrirModalEliminar(<?php echo $piso_num; ?>)">➖</button>
                            <?php endif; ?>
                        </div>
                    </div> 

                    <?php if ($vista_actual == 'clasica'): ?>
                        <div class="grid-mesas">
                            <?php foreach($piso['mesas'] as $m): ?>
                                <?php 
                                    $clase = ($m['estado'] == 1) ? 'mesa-ocupada' : 'mesa-libre';
                                    $texto_estado = ($m['estado'] == 1) ? 'Ocupada' : 'Libre';
                                ?>
                                <div class="mesa <?php echo $clase; ?>" style="position: relative;">
                                     <div id="semaforo-<?php echo $m['id']; ?>" 
                                     class="luz-semaforo" 
                                     data-mesa="<?php echo $m['id']; ?>" 
                                     style="position: absolute; top: 15px; right: 15px; width: 22px; height: 22px; border-radius: 50%; background-color: #32CD32; box-shadow: 0 2px 5px rgba(0,0,0,0.3); transition: 0.5s; z-index: 10;">
                                </div>

                                <h2 class="mesa-numero"><?php echo $m['numero_mesa']; ?></h2>
                                <div class="mesa-estado"><?php echo $texto_estado; ?></div>
    
                                <div style="margin-top: 15px; display: flex; flex-direction: column; gap: 8px;">
                                    <?php if ($m['estado'] == 0): ?>
                                        <a href="mesas.php?ocupar_mesa=<?php echo $m['id']; ?>" class="btn-accion-mesa" style="background:#32CD32; width: 100%;">Ocupar Mesa</a>
                                   <?php else: ?>
                                        <a href="pedido.php?mesa_id=<?php echo $m['id']; ?>" class="btn-accion-mesa" style="background:#FF8C00; width: 100%;">Ver Pedido</a>
                                    <?php endif; ?>
                                    <button onclick="mostrarQR('<?php echo $m['numero_mesa']; ?>', '<?php echo $m['id']; ?>')" class="btn-accion-mesa" style="background: #444; border:none; width: 100%;">Generar QR 📱</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>

                        <div class="contenedor-mapa-interno" onscroll="sessionStorage.setItem('mapaScrollTop', this.scrollTop); sessionStorage.setItem('mapaScrollLeft', this.scrollLeft);" style="position: relative; width: <?php echo $visual_w; ?>px; max-width: 100%; height: <?php echo $visual_h; ?>px; margin: 0 auto; overflow: hidden; background-color: <?php echo htmlspecialchars($piso['color']); ?>; border-radius: 15px; border: 2px solid rgba(0,0,0,0.1); box-shadow: inset 0 0 15px rgba(0,0,0,0.2);">
                            
                            <div style="position: absolute; top: 0; left: 0; width: <?php echo $mapa_w; ?>px; height: <?php echo $mapa_h; ?>px; background-image: linear-gradient(rgba(128,128,128,0.3) 1px, transparent 1px), linear-gradient(90deg, rgba(128,128,128,0.3) 1px, transparent 1px); background-size: 70px 70px; transform: scale(<?php echo $mapa_scale; ?>); transform-origin: top left;">
                                
                                <?php foreach($piso['objetos'] as $obj): 
                                    $hex = ltrim($obj['color'], '#');
                                    $r = 255; $g = 255; $b = 255;
                                    if(strlen($hex) == 6) { $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2)); }
                                    $alpha = 1 - (($obj['opacidad'] ?? 0) / 100);
                                    $fondo_rgba = "rgba($r, $g, $b, $alpha)";
                                ?>
                                    <div style="position: absolute; left: <?php echo $obj['pos_x']; ?>px; top: <?php echo $obj['pos_y']; ?>px; width: <?php echo $obj['ancho']; ?>px; height: <?php echo $obj['alto']; ?>px; background-color: <?php echo $fondo_rgba; ?>; border: 3px solid #<?php echo $hex; ?>; color: #333; display: flex; justify-content: center; align-items: center; font-weight: bold; font-size: 1.2rem; pointer-events: none; text-shadow: 1px 1px 2px rgba(255,255,255,0.8);">
                                        <?php echo htmlspecialchars($obj['nombre']); ?>
                                    </div>
                                <?php endforeach; ?>

                                <?php 
                                $contador = 0;
                                foreach($piso['mesas'] as $m): 
                                    $color_mesa = ($m['estado'] == 1) ? '#FF8C00' : '#32CD32'; 
                                    $link_accion = ($m['estado'] == 1) ? "pedido.php?mesa_id=".$m['id'] : "mesas.php?ocupar_mesa=".$m['id'];
                                    
                                    if ($m['pos_x'] == 0 && $m['pos_y'] == 0) {
                                        $px = ($contador % 7) * 110; 
                                        $py = floor($contador / 7) * 110; 
                                    } else {
                                        $px = $m['pos_x'];
                                        $py = $m['pos_y'];
                                    }
                                    $contador++;
                                ?>
                                    <a href="<?php echo $link_accion; ?>" 
                                       style="position: absolute; left: <?php echo $px; ?>px; top: <?php echo $py; ?>px; width: <?php echo $m['ancho']; ?>px; height: <?php echo $m['alto']; ?>px; background-color: <?php echo $color_mesa; ?>; border: 3px solid white; border-radius: 8px; color: white; display: flex; justify-content: center; align-items: center; font-size: 1.8rem; font-weight: 900; text-decoration: none; box-shadow: 0 4px 10px rgba(0,0,0,0.3); transition: transform 0.2s, box-shadow 0.2s; box-sizing: border-box;"
                                       onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 8px 15px rgba(0,0,0,0.4)';"
                                       onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 4px 10px rgba(0,0,0,0.3)';">
                                        <div id="semaforo-<?php echo $m['id']; ?>" class="luz-semaforo" data-mesa="<?php echo $m['id']; ?>" style="position: absolute; top: 5px; right: 5px; width: 15px; height: 15px; border-radius: 50%; background-color: #32CD32; border: 2px solid white; transition: 0.5s;"></div>
                                        <?php echo $m['numero_mesa']; ?>
                                    </a>
                                <?php endforeach; ?>

                            </div>
                        </div>
                    <?php endif; ?>

                </div> 
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
function mostrarQR(numeroMesa, idMesa) {
    const rutaBase = "<?php echo $ruta_base; ?>"; 
    const restId = "<?php echo $mi_restaurant_id; ?>";
    const token = "<?php echo $token_restaurante; ?>";
    
    const urlFinal = rutaBase + "?mesa_id=" + idMesa + "&r=" + restId + "&t=" + token;
    
    document.getElementById('qrTitulo').innerText = "📱 Mesa " + numeroMesa;
    document.getElementById('qrImagen').src = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" + encodeURIComponent(urlFinal);
    
    document.getElementById('modalQR').style.display = 'flex';
}
</script>
<script>
setInterval(() => {
    fetch('api_semaforo.php')
    .then(response => response.json())
    .then(datos => {
        datos.forEach(mesa => {
            let luz = document.getElementById('semaforo-' + mesa.mesa_id);
            if (luz) {
                luz.style.backgroundColor = mesa.color;
                if(mesa.color === '#FFC107') {
                    luz.style.boxShadow = "0 0 15px #FFC107";
                } else {
                    luz.style.boxShadow = "0 2px 5px rgba(0,0,0,0.3)";
                }
            }
        });
    })
    .catch(error => console.error("Error en radar:", error));
}, 2000);
</script>
<script src="../sesion_infinita.js"></script>
</body>
</html>