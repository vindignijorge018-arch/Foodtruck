<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';

if (!isset($_SESSION['restaurant_id'])) {
    die("Acceso no autorizado. Por favor inicia sesión.");
}
$mi_restaurant_id = $_SESSION['restaurant_id'];
$piso_actual = isset($_GET['piso']) ? (int)$_GET['piso'] : 1;

// --- 1. PREPARACIÓN SEGURA DE LA BD ---
$columnas_mesas = [
    'pos_x' => 'INT DEFAULT 0', 'pos_y' => 'INT DEFAULT 0',
    'ancho' => 'INT DEFAULT 70', 'alto' => 'INT DEFAULT 70',
    'piso' => 'INT DEFAULT 1'
];
foreach ($columnas_mesas as $columna => $definicion) {
    $check_col = $conn->query("SHOW COLUMNS FROM mesas LIKE '$columna'");
    if ($check_col && $check_col->num_rows == 0) {
        $conn->query("ALTER TABLE mesas ADD COLUMN $columna $definicion");
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS objetos_mapa (
    id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id INT, nombre VARCHAR(50), color VARCHAR(20),
    pos_x INT DEFAULT 0, pos_y INT DEFAULT 0, ancho INT DEFAULT 140, alto INT DEFAULT 70,
    es_zona INT DEFAULT 0, piso INT DEFAULT 1, opacidad INT DEFAULT 0
)");
$cols_obj = ['es_zona' => 'INT DEFAULT 0', 'piso' => 'INT DEFAULT 1', 'opacidad' => 'INT DEFAULT 0'];
foreach ($cols_obj as $col => $def) {
    $check = $conn->query("SHOW COLUMNS FROM objetos_mapa LIKE '$col'");
    if ($check && $check->num_rows == 0) $conn->query("ALTER TABLE objetos_mapa ADD COLUMN $col $def");
}

$conn->query("CREATE TABLE IF NOT EXISTS mapa_config (
    restaurant_id INT PRIMARY KEY, color_fondo VARCHAR(20) DEFAULT '#242b30', 
    snap_grid INT DEFAULT 1, colision INT DEFAULT 0, zoom INT DEFAULT 1, max_pisos INT DEFAULT 3
)");
$cols_conf = ['colision' => 'INT DEFAULT 0', 'zoom' => 'INT DEFAULT 1', 'max_pisos' => 'INT DEFAULT 3'];
foreach ($cols_conf as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM mapa_config LIKE '$col'");
    if ($chk && $chk->num_rows == 0) $conn->query("ALTER TABLE mapa_config ADD COLUMN $col $def");
}
$conn->query("INSERT IGNORE INTO mapa_config (restaurant_id, color_fondo, snap_grid, colision, zoom, max_pisos) VALUES ($mi_restaurant_id, '#242b30', 1, 0, 1, 3)");


// --- 2. LÓGICA DE GUARDADO INVISIBLE (AJAX) ---
if (isset($_POST['action'])) {
    
    // 1. GUARDAR MOVIMIENTO O TAMAÑO DE MESA
    if ($_POST['action'] == 'guardar_mesa') {
        $id = (int)$_POST['id']; $x = (int)$_POST['x']; $y = (int)$_POST['y']; 
        $w = (int)$_POST['ancho']; $h = (int)$_POST['alto'];
        $conn->query("UPDATE mesas SET pos_x=$x, pos_y=$y, ancho=$w, alto=$h WHERE id=$id AND restaurant_id=$mi_restaurant_id");
        exit("OK");
    }
    
    // 2. GUARDAR MOVIMIENTO O TAMAÑO DE ZONA/OBJETO
    if ($_POST['action'] == 'guardar_objeto') {
        $id = (int)$_POST['id']; $x = (int)$_POST['x']; $y = (int)$_POST['y']; 
        $w = (int)$_POST['ancho']; $h = (int)$_POST['alto'];
        $conn->query("UPDATE objetos_mapa SET pos_x=$x, pos_y=$y, ancho=$w, alto=$h WHERE id=$id AND restaurant_id=$mi_restaurant_id");
        exit("OK");
    }

    // 3. MOVER ELEMENTO DE PISO O AL INVENTARIO (Piso 0)
    if ($_POST['action'] == 'mover_piso') {
        $id = (int)$_POST['id']; $piso_dest = (int)$_POST['piso']; $tipo = $_POST['tipo'];
        if ($tipo == 'mesa') {
            $conn->query("UPDATE mesas SET piso=$piso_dest WHERE id=$id AND restaurant_id=$mi_restaurant_id");
        } else {
            $conn->query("UPDATE objetos_mapa SET piso=$piso_dest WHERE id=$id AND restaurant_id=$mi_restaurant_id");
        }
        exit("OK");
    }

    // 4. CREAR UNA NUEVA ZONA U OBJETO
    if ($_POST['action'] == 'crear_objeto') {
        $nombre = $conn->real_escape_string($_POST['nombre']);
        $color = $conn->real_escape_string($_POST['color']);
        $opacidad = (int)$_POST['opacidad'];
        $conn->query("INSERT INTO objetos_mapa (restaurant_id, nombre, color, opacidad, piso) VALUES ($mi_restaurant_id, '$nombre', '$color', $opacidad, $piso_actual)");
        exit("OK");
    }

    // 5. GUARDAR CONFIGURACIÓN DE MAPA (Color de fondo y Colisión)
    if ($_POST['action'] == 'guardar_config') {
        $color = $conn->real_escape_string($_POST['color_fondo']);
        $colision = (int)$_POST['colision'];
        $conn->query("UPDATE mapa_config SET color_fondo='$color', colision=$colision WHERE restaurant_id=$mi_restaurant_id");
        exit("OK");
    }

    // 6. AÑADIR PISO NUEVO
    if ($_POST['action'] == 'agregar_piso') {
        $conn->query("UPDATE mapa_config SET max_pisos = max_pisos + 1 WHERE restaurant_id=$mi_restaurant_id");
        exit("OK");
    }

    // 7. BORRAR PISO (Envía todo al inventario piso 0)
    if ($_POST['action'] == 'borrar_piso') {
        $piso_borrar = (int)$_POST['piso'];
        $conn->query("UPDATE mesas SET piso = 0 WHERE piso = $piso_borrar AND restaurant_id = $mi_restaurant_id");
        $conn->query("UPDATE objetos_mapa SET piso = 0 WHERE piso = $piso_borrar AND restaurant_id = $mi_restaurant_id");
        $conn->query("UPDATE mapa_config SET max_pisos = GREATEST(1, max_pisos - 1) WHERE restaurant_id=$mi_restaurant_id");
        exit("OK");
    }

    // 8. REINICIAR POSICIONES AL ORIGEN
    if ($_POST['action'] == 'reiniciar_posiciones') {
        $piso_reiniciar = isset($_POST['piso']) ? (int)$_POST['piso'] : $piso_actual;
        $current_x = 0; $current_y = 140; $max_width = 1470; $altura_fila_actual = 0; 
        
        $mesas = $conn->query("SELECT id, ancho, alto FROM mesas WHERE restaurant_id=$mi_restaurant_id AND piso=$piso_reiniciar ORDER BY numero_mesa ASC");
        while($m = $mesas->fetch_assoc()) {
            $w = $m['ancho'] > 0 ? $m['ancho'] : 70;
            $h = $m['alto'] > 0 ? $m['alto'] : 70;
            if ($current_x + $w > $max_width && $current_x > 0) {
                $current_x = 0; $current_y += $altura_fila_actual; $altura_fila_actual = 0; 
            }
            $id = $m['id'];
            $conn->query("UPDATE mesas SET pos_x=$current_x, pos_y=$current_y WHERE id=$id");
            $current_x += $w; 
            if ($h > $altura_fila_actual) $altura_fila_actual = $h; 
        }
        
        $current_x = 0; $current_y += $altura_fila_actual + 70; $altura_fila_actual = 0;
        $objetos = $conn->query("SELECT id, ancho, alto FROM objetos_mapa WHERE restaurant_id=$mi_restaurant_id AND piso=$piso_reiniciar");
        while($o = $objetos->fetch_assoc()) {
            $w = $o['ancho'] > 0 ? $o['ancho'] : 140;
            $h = $o['alto'] > 0 ? $o['alto'] : 70;
            if ($current_x + $w > $max_width && $current_x > 0) {
                $current_x = 0; $current_y += $altura_fila_actual; $altura_fila_actual = 0;
            }
            $id = $o['id'];
            $conn->query("UPDATE objetos_mapa SET pos_x=$current_x, pos_y=$current_y WHERE id=$id");
            $current_x += $w;
            if ($h > $altura_fila_actual) $altura_fila_actual = $h;
        }
        exit("OK");
    }
}

// --- 3. CARGAR DATOS PARA RENDERIZAR LA PÁGINA ---
$res_mesas = $conn->query("SELECT * FROM mesas WHERE restaurant_id = $mi_restaurant_id AND piso = $piso_actual ORDER BY numero_mesa ASC");
$res_objetos = $conn->query("SELECT * FROM objetos_mapa WHERE restaurant_id = $mi_restaurant_id AND piso = $piso_actual");
$config_mapa = $conn->query("SELECT * FROM mapa_config WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();

// Cargar Inventario (Piso 0)
$res_inv_mesas = $conn->query("SELECT * FROM mesas WHERE restaurant_id = $mi_restaurant_id AND piso = 0 ORDER BY numero_mesa ASC");
$res_inv_obj = $conn->query("SELECT * FROM objetos_mapa WHERE restaurant_id = $mi_restaurant_id AND piso = 0");

// --- CONFIGURACIÓN DE LIENZO ÚNICO (22x9 casillas) ---
$mapa_w = 1540; 
$mapa_h = 630;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Creador de Mapa - bargaiwe</title>
    <style>
        body { background-color: #1a1e21; color: #ecf0f1; font-family: 'Segoe UI', sans-serif; margin: 0; padding: 20px; padding-bottom: 120px; box-sizing: border-box; }
        
        .toolbar { display: flex; justify-content: space-between; align-items: center; background: #2c3e50; padding: 12px 20px; border-radius: 12px; margin-bottom: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.4); gap: 10px; flex-wrap: wrap; position: sticky; top: 0; z-index: 2000; }
        .toolbar-seccion { display: flex; align-items: center; gap: 8px; background: #34495e; padding: 8px 12px; border-radius: 8px; border: 1px solid #455a64; }
        .btn-volver { background: #555; color: white; padding: 8px 15px; text-decoration: none; border-radius: 8px; font-weight: bold; border: 1px solid #333; white-space: nowrap;}
        .btn-reiniciar { background: #E53935; color: white; padding: 8px 15px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; white-space: nowrap; transition: 0.2s;}
        .btn-reiniciar:hover { background: #b71c1c; }
        .btn-toggle { background: #555; color: white; border: none; padding: 6px 10px; border-radius: 5px; cursor: pointer; font-weight: bold; transition: 0.2s; font-size: 0.85rem;}
        .btn-toggle.activo { background: #FFC107; color: #000; }
        .input-color-mapa { width: 30px; height: 30px; border: none; cursor: pointer; background: transparent; border-radius: 5px; padding: 0;}
        select, input[type="text"] { padding: 6px; border-radius: 5px; border: 1px solid #555; background: #1a1e21; color: white; font-weight: bold;}
        .btn-accion { background: #32CD32; color: black; font-weight: bold; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; font-size: 0.85rem;}
        .piso-tabs { display: flex; align-items: center; gap: 5px; background: #1a1e21; padding: 5px; border-radius: 8px;}
        .piso-tabs a { text-decoration: none; color: white; padding: 5px 15px; border-radius: 5px; font-weight: bold; background: #555; font-size: 0.9rem;}
        .piso-tabs a.activo { background: #007BFF; }
        .btn-piso-extra { background: #4a555c; color: white; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-weight: bold;}
        .btn-piso-extra:hover { background: #607d8b; }
        .btn-piso-borrar { background: #E53935; color: white; border: none; padding: 5px 10px; border-radius: 5px; cursor: pointer; font-weight: bold;}

        /* PERÍMETRO ESTÁTICO ÚNICO (1540x630px) */
        .mapa-wrapper { 
            width: <?php echo $mapa_w; ?>px; 
            height: <?php echo $mapa_h; ?>px; 
            max-width: 100%;
            margin: 0 auto; 
            border: 2px dashed #4a555c; 
            border-radius: 15px; 
            position: relative; 
            background-color: <?php echo $config_mapa['color_fondo']; ?>; 
            box-shadow: inset 0 0 20px rgba(0,0,0,0.5); 
            overflow: auto; 
        }
        
        .marca-agua { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 35rem; font-weight: 900; color: rgba(255,255,255,0.03); pointer-events: none; z-index: 0; user-select: none; line-height: 1;}

        #lienzo-mapa {
            position: absolute; 
            top: 0; left: 0;
            width: <?php echo $mapa_w; ?>px; 
            height: <?php echo $mapa_h; ?>px;
            /* Líneas oscuras al 50% para destacar en cualquier fondo */
            background-image: 
                linear-gradient(rgba(0,0,0,0.5) 1px, transparent 1px), 
                linear-gradient(90deg, rgba(0,0,0,0.5) 1px, transparent 1px);
            background-size: 70px 70px;
            z-index: 1; 
        }

        .item-drag { position: absolute; display: flex; justify-content: center; align-items: center; font-weight: bold; border-radius: 8px; cursor: grab; user-select: none; box-shadow: 0 5px 10px rgba(0,0,0,0.5); border: 2px solid transparent; text-align: center; transition: box-shadow 0.2s; box-sizing: border-box; }
        .item-drag:active { cursor: grabbing; z-index: 1000 !important; }
        .seleccionado { border: 3px solid #00E5FF !important; box-shadow: 0 0 25px rgba(0, 229, 255, 0.8) !important; z-index: 500 !important;}

        .mesa { flex-direction: column; z-index: 100; }
        .mesa-libre { background: #2d3436; border-color: #32CD32; color: #32CD32; }
        .mesa-ocupada { background: #4a3b2c; border-color: #FF8C00; color: #FF8C00; }
        
        .objeto-mapa { color: white; text-shadow: 1px 1px 2px black; font-size: 1.2rem; z-index: 50;}

        .control-dim { display: flex; align-items: center; background: #1a1e21; border-radius: 4px; border: 1px solid #555;}
        .btn-dim { background: #555; color: white; border: none; padding: 2px 10px; cursor: pointer; font-size: 1rem;}
        .btn-dim:hover:not(:disabled) { background: #007BFF; }
        .valor-dim { width: 25px; text-align: center; font-weight: bold; font-size: 1rem; }
        #toast { position: fixed; bottom: 20px; right: 20px; background: #32CD32; color: #000; padding: 10px 20px; border-radius: 8px; font-weight: bold; opacity: 0; transition: 0.3s; pointer-events: none; z-index: 2000;}
        
        /* DISEÑO DE INVENTARIO Y MODALES */
        #inventario-bar { position: fixed; bottom: 0; left: 0; width: 100%; height: 100px; background: #1a252f; border-top: 3px solid #00E5FF; display: flex; align-items: center; padding: 0 20px; gap: 15px; overflow-x: auto; z-index: 3000; box-shadow: 0 -5px 15px rgba(0,0,0,0.5); box-sizing: border-box;}
        .btn-inv-mesa { background: #32CD32; color: black; border: 2px solid white; border-radius: 8px; width: 50px; height: 50px; font-weight: 900; font-size: 1.2rem; cursor: pointer; flex-shrink: 0; transition: 0.2s;}
        .btn-inv-mesa:hover { transform: scale(1.1); box-shadow: 0 0 10px #32CD32; }
        .btn-inv-obj { background: #007BFF; color: white; border: 2px solid white; border-radius: 8px; padding: 10px 15px; font-weight: bold; cursor: pointer; flex-shrink: 0; transition: 0.2s;}
        .btn-inv-obj:hover { transform: scale(1.1); box-shadow: 0 0 10px #007BFF; }

        .modal-ui { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; z-index: 4000; backdrop-filter: blur(3px);}
        .modal-caja { background: #2c3e50; padding: 30px; border-radius: 15px; width: 400px; text-align: center; color: white; border: 2px solid #4a555c;}
    </style>
</head>
<body>

    <div class="marca-agua"><?php echo $piso_actual; ?></div>

    <div class="toolbar" id="zona-toolbar">
        <div class="toolbar-seccion" style="background: transparent; border: none; padding: 0;">
            <div class="piso-tabs">
                <?php for($i = 1; $i <= $config_mapa['max_pisos']; $i++): ?>
                    <a href="?piso=<?php echo $i; ?>" class="<?php echo $piso_actual==$i?'activo':'';?>">Piso <?php echo $i; ?></a>
                <?php endfor; ?>
                <button onclick="document.getElementById('modalAddPiso').style.display='flex'" class="btn-piso-extra" title="Agregar nuevo piso">+</button>
                <?php if($config_mapa['max_pisos'] > 1): ?>
                    <button onclick="document.getElementById('modalDelPiso').style.display='flex'" class="btn-piso-borrar" title="Borrar último piso">-</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="toolbar-seccion">
            <input type="color" id="mapBgColor" class="input-color-mapa" value="<?php echo $config_mapa['color_fondo']; ?>" title="Color Fondo" onchange="guardarConfigMapa()">
            
            <button id="btn-colision" class="btn-toggle <?php echo ($config_mapa['colision'] == 1) ? 'activo' : ''; ?>" onclick="toggleConfig('colision')">
                <?php echo ($config_mapa['colision'] == 1) ? '🛡️ No Interponer: ON' : '🛡️ No Interponer: OFF'; ?>
            </button>

            <div style="display: flex; align-items: center; gap: 5px; margin-left: 10px; background: #555; padding: 4px 10px; border-radius: 5px;">
                <span style="font-size: 0.8rem; color: #FFC107; font-weight: bold;">📐 Cuadrícula:</span>
                <span style="color: white; font-size: 0.85rem; font-weight: bold;">22×9 (198 cuadros)</span>
            </div>
        </div>

        <div class="toolbar-seccion" id="menu-edicion" style="opacity: 0.4; pointer-events: none;">
            <span style="font-size: 0.8rem; color: #FFC107; font-weight: bold;">[Edición]</span>
            <div style="display:flex; align-items:center; gap: 5px;">
                <span style="font-size: 0.8rem;">↔</span>
                <div class="control-dim"><button class="btn-dim" onclick="cambiarDimension('ancho', -1)">◀</button><span class="valor-dim" id="val-ancho">-</span><button class="btn-dim" onclick="cambiarDimension('ancho', 1)">▶</button></div>
                <span style="font-size: 0.8rem; margin-left:5px;">↕</span>
                <div class="control-dim"><button class="btn-dim" onclick="cambiarDimension('alto', -1)">◀</button><span class="valor-dim" id="val-alto">-</span><button class="btn-dim" onclick="cambiarDimension('alto', 1)">▶</button></div>
            </div>
            
            <select id="select-mover-piso" onchange="moverElementoPiso(this.value)" style="margin-left: 10px;">
                <option value="">Mover a...</option>
                <?php for($i = 1; $i <= $config_mapa['max_pisos']; $i++): ?>
                    <option value="<?php echo $i; ?>">Piso <?php echo $i; ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="toolbar-seccion" style="background: transparent; border: none; padding: 0;">
            <form class="form-objeto" id="formObjeto" style="display: flex; align-items: center; gap: 8px;">
                <input type="text" id="objNombre" placeholder="Nombre área..." required>
                <input type="color" id="objColor" value="#007BFF">
                <div style="display: flex; flex-direction: column; align-items: center;">
                    <span style="font-size:0.7rem; color:#aaa;" id="txtOpacidad">Transparencia: 0%</span>
                    <input type="range" id="objOpacidad" min="0" max="100" value="0" style="width: 80px;" oninput="document.getElementById('txtOpacidad').innerText = 'Transparencia: ' + this.value + '%'">
                </div>
                <button type="submit" class="btn-accion">➕</button>
            </form>
            
            <button class="btn-reiniciar" onclick="reiniciarTodo()">🔄 Reiniciar</button>
            <a href="mesas.php" class="btn-volver">⬅ Volver</a>
        </div>
    </div>

    <div class="mapa-wrapper" id="wrapper">
        <div id="lienzo-mapa">
            <?php if($res_objetos && $res_objetos->num_rows > 0): while($obj = $res_objetos->fetch_assoc()): 
                $hex = ltrim($obj['color'], '#');
                if(strlen($hex) == 6) {
                    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
                } else {
                    $r = 255; $g = 255; $b = 255; 
                }
                $opacidad_porcentaje = isset($obj['opacidad']) ? $obj['opacidad'] : 0;
                $alpha = 1 - ($opacidad_porcentaje / 100);
                
                $fondo_rgba = "rgba($r, $g, $b, $alpha)";
                $borde_css = "3px solid #" . $hex; 
            ?>
                <div class="item-drag objeto-mapa" data-tipo="objeto" data-id="<?php echo $obj['id']; ?>" 
                     style="left: <?php echo $obj['pos_x']; ?>px; top: <?php echo $obj['pos_y']; ?>px; 
                            width: <?php echo $obj['ancho']; ?>px; height: <?php echo $obj['alto']; ?>px; 
                            background-color: <?php echo $fondo_rgba; ?>; 
                            border: <?php echo $borde_css; ?>;">
                    <?php echo htmlspecialchars($obj['nombre']); ?>
                </div>
            <?php endwhile; endif; ?>

            <?php 
            if($res_mesas && $res_mesas->num_rows > 0): 
                $contador = 0;
                while($m = $res_mesas->fetch_assoc()): 
                    $clase = (isset($m['estado']) && $m['estado'] == 1) ? 'mesa-ocupada' : 'mesa-libre';
                    $px = $m['pos_x'] == 0 ? ($contador * 70) : $m['pos_x'];
                    $py = $m['pos_y'] == 0 ? 0 : $m['pos_y'];
                    $contador++;
            ?>
                <div class="item-drag mesa <?php echo $clase; ?>" data-tipo="mesa" data-id="<?php echo $m['id']; ?>" 
                     style="left: <?php echo $px; ?>px; top: <?php echo $py; ?>px; width: <?php echo $m['ancho']; ?>px; height: <?php echo $m['alto']; ?>px;">
                    <span style="font-size: 1.5em; pointer-events: none;"><?php echo $m['numero_mesa']; ?></span>
                </div>
            <?php endwhile; endif; ?>
        </div>
    </div>

    <div id="toast">💾 Guardado</div>

    <div id="inventario-bar">
        <span style="color:#00E5FF; font-weight:bold; font-size:1.2rem;">📦 Cajón:</span>
        <span style="color:#aaa; font-size:0.8rem; margin-right: 10px;">(Arrastra elementos aquí para guardarlos)</span>
        
        <?php if($res_inv_mesas && $res_inv_mesas->num_rows > 0): while($im = $res_inv_mesas->fetch_assoc()): ?>
            <button onclick="sacarDelInventario(<?php echo $im['id']; ?>, 'mesa')" class="btn-inv-mesa" title="Click para enviar al Piso Actual"><?php echo $im['numero_mesa']; ?></button>
        <?php endwhile; endif; ?>
        
        <?php if($res_inv_obj && $res_inv_obj->num_rows > 0): while($io = $res_inv_obj->fetch_assoc()): ?>
            <button onclick="sacarDelInventario(<?php echo $io['id']; ?>, 'objeto')" class="btn-inv-obj" title="Click para enviar al Piso Actual"><?php echo htmlspecialchars($io['nombre']); ?></button>
        <?php endwhile; endif; ?>
    </div>

    <div id="modalAddPiso" class="modal-ui">
        <div class="modal-caja">
            <h2 style="color: #32CD32; margin-top: 0;">➕ Añadir Piso</h2>
            <p>¿Quieres agregar un piso nuevo a tu restaurante?</p>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button onclick="accionSeguraPiso('agregar_piso', null)" style="flex:1; background:#32CD32; border:none; padding:10px; border-radius:8px; font-weight:bold; cursor:pointer;">Sí, agregar</button>
                <button onclick="document.getElementById('modalAddPiso').style.display='none'" style="flex:1; background:#555; color:white; border:none; padding:10px; border-radius:8px; font-weight:bold; cursor:pointer;">Cancelar</button>
            </div>
        </div>
    </div>

    <div id="modalDelPiso" class="modal-ui">
        <div class="modal-caja">
            <h2 style="color: #E53935; margin-top: 0;">➖ Borrar Piso</h2>
            <p>Se borrará el último piso.<br><b style="color:#FFC107;">¡Tranquilo! Las mesas y zonas que estén ahí se guardarán en tu Cajón de Inventario.</b></p>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button onclick="accionSeguraPiso('borrar_piso', <?php echo $config_mapa['max_pisos']; ?>)" style="flex:1; background:#E53935; color:white; border:none; padding:10px; border-radius:8px; font-weight:bold; cursor:pointer;">Sí, borrar</button>
                <button onclick="document.getElementById('modalDelPiso').style.display='none'" style="flex:1; background:#555; color:white; border:none; padding:10px; border-radius:8px; font-weight:bold; cursor:pointer;">Cancelar</button>
            </div>
        </div>
    </div>

    <div id="modalReiniciar" class="modal-ui">
        <div class="modal-caja">
            <h2 style="color: #E53935; margin-top: 0;">🔄 Reiniciar Posiciones</h2>
            <p>Esto atraerá todas las mesas y zonas de este piso a la esquina superior izquierda.</p>
            <div style="display:flex; gap:10px; margin-top:20px;">
                <button onclick="accionSeguraPiso('reiniciar_posiciones', null)" style="flex:1; background:#E53935; color:white; border:none; padding:10px; border-radius:8px; font-weight:bold; cursor:pointer;">Sí, reiniciar</button>
                <button onclick="document.getElementById('modalReiniciar').style.display='none'" style="flex:1; background:#555; color:white; border:none; padding:10px; border-radius:8px; font-weight:bold; cursor:pointer;">Cancelar</button>
            </div>
        </div>
    </div>

    <script>
        const wrapper = document.getElementById('wrapper');
        const lienzo = document.getElementById('lienzo-mapa');
        const unidadBase = 70; 
        const MAX_W = <?php echo $mapa_w; ?>;
        const MAX_H = <?php echo $mapa_h; ?>;
        let itemSeleccionado = null; 
        
        let config = {
            colision: <?php echo $config_mapa['colision']; ?>
        };

        // --- FUNCIONES SEGURAS DE PISO E INVENTARIO ---
        // --- FUNCIONES SEGURAS DE PISO E INVENTARIO ---
        function accionSeguraPiso(accion, numero_piso) {
            let fd = new FormData();
            fd.append('action', accion);
            
            // CAMBIO: Le enviamos el piso actual obligatoriamente al servidor
            fd.append('piso', numero_piso ? numero_piso : <?php echo $piso_actual; ?>);
            
            // CAMBIO: Usamos window.location.href para que no borre el "?piso=X" de la URL
            fetch(window.location.href, { method: 'POST', body: fd }).then(() => {
                if (accion === 'borrar_piso') {
                    let redir = (<?php echo $piso_actual; ?> == numero_piso) ? numero_piso - 1 : <?php echo $piso_actual; ?>;
                    window.location.href = '?piso=' + redir;
                } else {
                    location.reload();
                }
            });
        }

        function sacarDelInventario(id, tipo) {
            let fd = new FormData();
            fd.append('action', 'mover_piso');
            fd.append('id', id);
            fd.append('tipo', tipo);
            fd.append('piso', <?php echo $piso_actual; ?>); 
            fetch(window.location.pathname, { method: 'POST', body: fd }).then(() => location.reload());
        }

        function reiniciarTodo() {
            document.getElementById('modalReiniciar').style.display = 'flex';
        }

        function toggleConfig(tipo) {
            config[tipo] = config[tipo] === 1 ? 0 : 1;
            let btn = document.getElementById('btn-colision');
            if (config[tipo] === 1) {
                btn.classList.add('activo'); btn.innerText = '🛡️ No Interponer: ON';
            } else {
                btn.classList.remove('activo'); btn.innerText = '🛡️ No Interponer: OFF';
            }
            guardarConfigMapa();
        }

        function guardarConfigMapa() {
            let color = document.getElementById('mapBgColor').value;
            wrapper.style.backgroundColor = color; 
            let fd = new FormData();
            fd.append('action', 'guardar_config');
            fd.append('color_fondo', color);
            fd.append('colision', config.colision);
            fetch(window.location.pathname, { method: 'POST', body: fd });
        }

        document.getElementById('formObjeto').addEventListener('submit', function(e) {
            e.preventDefault();
            let fd = new FormData();
            fd.append('action', 'crear_objeto');
            fd.append('nombre', document.getElementById('objNombre').value);
            fd.append('color', document.getElementById('objColor').value);
            fd.append('opacidad', document.getElementById('objOpacidad').value);
            fetch(window.location.pathname, { method: 'POST', body: fd }).then(() => location.reload()); 
        });

        document.addEventListener('pointerdown', (e) => {
            if (e.target.closest('#zona-toolbar') || e.target.closest('#inventario-bar')) return;
            let target = e.target.closest('.item-drag');
            let menuEdicion = document.getElementById('menu-edicion');
            
            if (!target) {
                if (itemSeleccionado) itemSeleccionado.classList.remove('seleccionado');
                itemSeleccionado = null;
                menuEdicion.style.opacity = '0.4'; menuEdicion.style.pointerEvents = 'none';
                document.getElementById('select-mover-piso').value = "";
                return;
            }

            if (itemSeleccionado) itemSeleccionado.classList.remove('seleccionado');
            itemSeleccionado = target;
            itemSeleccionado.classList.add('seleccionado');
            
            let curW = parseInt(itemSeleccionado.style.width) || unidadBase;
            let curH = parseInt(itemSeleccionado.style.height) || unidadBase;
            document.getElementById('val-ancho').innerText = Math.max(1, Math.round(curW / unidadBase));
            document.getElementById('val-alto').innerText = Math.max(1, Math.round(curH / unidadBase));

            menuEdicion.style.opacity = '1'; menuEdicion.style.pointerEvents = 'auto';
        });

        function cambiarDimension(eje, delta) {
            if (!itemSeleccionado) return;
            let span = document.getElementById(eje === 'ancho' ? 'val-ancho' : 'val-alto');
            let nuevasUnidades = parseInt(span.innerText) + delta;
            if (nuevasUnidades < 1) nuevasUnidades = 1; 
            span.innerText = nuevasUnidades;
            
            let pixeles = nuevasUnidades * unidadBase;
            let oldW = itemSeleccionado.style.width; let oldH = itemSeleccionado.style.height;
            
            if (eje === 'ancho') itemSeleccionado.style.width = pixeles + 'px';
            else itemSeleccionado.style.height = pixeles + 'px';

            if (config.colision === 1 && hayColision(itemSeleccionado)) {
                itemSeleccionado.style.width = oldW; itemSeleccionado.style.height = oldH;
                span.innerText = nuevasUnidades - delta; 
                alert("⚠️ Colisión con otra mesa.");
                return;
            }
            guardarEnBD(itemSeleccionado);
        }

        function moverElementoPiso(nuevoPiso) {
            if (!itemSeleccionado || !nuevoPiso) return;
            let fd = new FormData();
            fd.append('action', 'mover_piso');
            fd.append('id', itemSeleccionado.dataset.id);
            fd.append('tipo', itemSeleccionado.dataset.tipo);
            fd.append('piso', nuevoPiso);
            fetch(window.location.pathname, { method: 'POST', body: fd }).then(() => location.reload());
        }

        function hayColision(elemento) {
            let rect1 = elemento.getBoundingClientRect();
            if (elemento.style.backgroundColor.includes('rgba') && !elemento.style.backgroundColor.includes(', 1)')) return false;

            let items = document.querySelectorAll('.item-drag');
            for (let item of items) {
                if (item === elemento) continue;
                if (item.style.backgroundColor.includes('rgba') && !item.style.backgroundColor.includes(', 1)')) continue;

                let rect2 = item.getBoundingClientRect();
                if (rect1.left + 2 < rect2.right - 2 && rect1.right - 2 > rect2.left + 2 &&
                    rect1.top + 2 < rect2.bottom - 2 && rect1.bottom - 2 > rect2.top + 2) {
                    return true;
                }
            }
            return false;
        }

        // --- MOTOR DE ARRASTRE BLINDADO ---
        let dragItem = null; let offsetX = 0; let offsetY = 0;
        let posOriginal = {x: 0, y: 0}; 

        document.addEventListener('pointerdown', (e) => {
            if (e.target.closest('#zona-toolbar') || e.target.closest('#inventario-bar')) return;
            let target = e.target.closest('.item-drag');
            if (!target) return;
            
            dragItem = target;
            let rect = dragItem.getBoundingClientRect();
            
            posOriginal.x = dragItem.style.left;
            posOriginal.y = dragItem.style.top;

            offsetX = e.clientX - rect.left;
            offsetY = e.clientY - rect.top;
            dragItem.setPointerCapture(e.pointerId);
        });

        document.addEventListener('pointermove', (e) => {
            if (!dragItem) return;
            let lienzoRect = lienzo.getBoundingClientRect();
            
            let nuevoX = e.clientX - lienzoRect.left - offsetX;
            let nuevoY = e.clientY - lienzoRect.top - offsetY;

            // FORZAR ALINEAMIENTO A 70px
            nuevoX = Math.round(nuevoX / unidadBase) * unidadBase;
            nuevoY = Math.round(nuevoY / unidadBase) * unidadBase;

            let itemW = parseInt(dragItem.style.width) || unidadBase;
            let itemH = parseInt(dragItem.style.height) || unidadBase;
            
            if (nuevoX < 0) nuevoX = 0;
            if (nuevoY < 0) nuevoY = 0;
            if (nuevoX > MAX_W - itemW) nuevoX = MAX_W - itemW;
            // No limitamos "Y" por abajo durante el movimiento, para permitir soltar en el cajón

            dragItem.style.left = `${nuevoX}px`;
            dragItem.style.top = `${nuevoY}px`;
        });

        document.addEventListener('pointerup', (e) => {
            if (!dragItem) return;
            dragItem.releasePointerCapture(e.pointerId);
            
            // INVENTARIO: Si se soltó en la barra negra (los últimos 100px)
            if (e.clientY > window.innerHeight - 100) {
                let fd = new FormData();
                fd.append('action', 'mover_piso');
                fd.append('id', dragItem.dataset.id);
                fd.append('tipo', dragItem.dataset.tipo);
                fd.append('piso', 0); // Piso 0 = Inventario
                fetch(window.location.pathname, { method: 'POST', body: fd }).then(() => location.reload());
                dragItem = null;
                return;
            }
            
            // Si lo soltó dentro, verificamos que no rebase el alto máximo
            let itemH = parseInt(dragItem.style.height) || unidadBase;
            let finalY = parseInt(dragItem.style.top);
            if (finalY > MAX_H - itemH) {
                dragItem.style.top = `${MAX_H - itemH}px`;
            }

            if (config.colision === 1 && hayColision(dragItem)) {
                dragItem.style.left = posOriginal.x;
                dragItem.style.top = posOriginal.y;
                let t = document.getElementById('toast');
                t.innerText = "⚠️ Colisión evitada";
                t.style.background = "#FFC107";
                t.style.opacity = 1; setTimeout(() => t.style.opacity = 0, 1500);
            } else {
                guardarEnBD(dragItem);
            }
            dragItem = null;
        });

        function guardarEnBD(elemento) {
            let fd = new FormData();
            fd.append('id', elemento.dataset.id);
            fd.append('x', parseInt(elemento.style.left));
            fd.append('y', parseInt(elemento.style.top));
            fd.append('ancho', parseInt(elemento.style.width));
            fd.append('alto', parseInt(elemento.style.height));
            fd.append('action', elemento.dataset.tipo === 'mesa' ? 'guardar_mesa' : 'guardar_objeto');

            fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(r => r.text())
            .then(d => {
                if(d.trim() === "OK") {
                    let t = document.getElementById('toast');
                    t.innerText = "💾 Guardado";
                    t.style.background = "#32CD32";
                    t.style.opacity = 1; setTimeout(() => t.style.opacity = 0, 1500);
                }
            });
        }
    </script>
</body>
</html>