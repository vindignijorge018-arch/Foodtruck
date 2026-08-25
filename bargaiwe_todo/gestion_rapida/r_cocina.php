<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Seguridad
if (!isset($_SESSION['restaurant_id'])) { 
    header("Location: ../portal_bargaiwe.php"); 
    exit(); 
}

// Configuramos la zona horaria para Chile
date_default_timezone_set('America/Santiago'); 

include 'r_db.php';
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// --- OBTENER TEMA DESDE LA BASE DE DATOS ---
$res_tema = $conn->query("SELECT modo_global, color_cajero FROM config_temas WHERE restaurant_id = $mi_restaurant_id");
$tema = ($res_tema && $res_tema->num_rows > 0) ? $res_tema->fetch_assoc() : ['modo_global' => 'oscuro', 'color_cajero' => '#FF8C00'];

// --- 0. MAGIA: ASEGURAR COLUMNAS VITALES PARA FAST FOOD ---
// 1. Aseguramos la columna 'cantidad'
$check_cantidad = $conn->query("SHOW COLUMNS FROM pedidos LIKE 'cantidad'");
if ($check_cantidad && $check_cantidad->num_rows == 0) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN cantidad INT DEFAULT 1");
}

// 2. Aseguramos la columna 'comentario' (aquí estaba el error actual)
$check_comentario = $conn->query("SHOW COLUMNS FROM pedidos LIKE 'comentario'");
if ($check_comentario && $check_comentario->num_rows == 0) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN comentario VARCHAR(255) DEFAULT ''");
}
// 4. Aseguramos la columna 'fecha_fin' para la pantalla de clientes
$check_fecha_fin = $conn->query("SHOW COLUMNS FROM pedidos LIKE 'fecha_fin'");
if ($check_fecha_fin && $check_fecha_fin->num_rows == 0) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN fecha_fin TIMESTAMP NULL DEFAULT NULL");
}
// 3. Por si acaso, nos aseguramos de que el cliente_nombre sea lo suficientemente largo
$conn->query("ALTER TABLE pedidos MODIFY COLUMN cliente_nombre VARCHAR(150) DEFAULT 'Pendiente'");

// --- 2. GUARDAR NUEVOS TIEMPOS ---
if (isset($_POST['guardar_tiempos'])) {
    $am = (int)$_POST['min_amarillo'];
    $ro = (int)$_POST['min_rojo'];
    $conn->query("UPDATE config_cocina SET minutos_amarillo = $am, minutos_rojo = $ro WHERE restaurant_id = $mi_restaurant_id");
    header("Location: r_cocina.php");
    exit();
}

// --- 3. MARCAR TICKET ENTERO COMO LISTO ---
if (isset($_POST['marcar_listo'])) {
    $codigo_ticket = $conn->real_escape_string($_POST['codigo_grupo']);
    
    // Cambia todo el bloque del ticket a estado 3 (Finalizado/Entregado)
    $conn->query("UPDATE pedidos SET estado = 3, fecha_fin = NOW() WHERE codigo_grupo = '$codigo_ticket' AND restaurant_id = $mi_restaurant_id");
    header("Location: r_cocina.php");
    exit();
}

// --- 4. OBTENER CONFIGURACIÓN ACTUAL ---
$res_config = $conn->query("SELECT * FROM config_cocina WHERE restaurant_id = $mi_restaurant_id");

if ($res_config && $res_config->num_rows > 0) {
    // Si ya tiene configuración, la usamos
    $config = $res_config->fetch_assoc();
    $min_amarillo = $config['minutos_amarillo'];
    $min_rojo = $config['minutos_rojo'];
} else {
    // Si la cuenta es nueva, usamos valores por defecto
    $min_amarillo = 10;
    $min_rojo = 20;
    
    // Y le creamos su fila automáticamente para el futuro
    $conn->query("INSERT IGNORE INTO config_cocina (restaurant_id, minutos_amarillo, minutos_rojo) VALUES ($mi_restaurant_id, 10, 20)");
}

// --- 5. OBTENER TICKETS DE FAST FOOD PENDIENTES (ESTADO 2) ---
// En Fast Food, el cajero arma el carrito (estado 1) y al apretar enviar pasa a estado 2 (Cocina)
$sql = "SELECT p.id, p.fecha, m.nombre as nombre_plato, p.cantidad, p.comentario, p.cliente_nombre, p.codigo_grupo
        FROM pedidos p
        JOIN menu m ON p.menu_id = m.id
        WHERE p.estado = 2 AND p.restaurant_id = $mi_restaurant_id
        ORDER BY p.fecha ASC";

$tickets = [];
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ticket_key = $row['codigo_grupo']; // Agrupamos por el código del ticket
        
        if (!isset($tickets[$ticket_key])) {
            $tickets[$ticket_key] = [
                'cliente' => htmlspecialchars($row['cliente_nombre']),
                'fecha' => $row['fecha'],
                'platos' => [],
                'codigo_grupo' => $ticket_key
            ];
        }
        
        $tickets[$ticket_key]['platos'][] = [
            'cantidad' => $row['cantidad'],
            'nombre' => htmlspecialchars($row['nombre_plato']),
            'comentario' => htmlspecialchars($row['comentario'])
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bargaiwe Fast - KDS (Cocina)</title>
    <style>
        /* Variables de Tema Globales */
        :root {
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: <?php echo $tema['color_cajero']; ?>; 
        }
        
        <?php if($tema['modo_global'] === 'claro'): ?>
        body {
            --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000;
        }
        <?php endif; ?>

        body { background-color: var(--bg-body); font-family: 'Segoe UI', sans-serif; margin: 0; color: var(--text); }
        
        /* NAVEGACIÓN FAST FOOD */
        .nav-hub { background: var(--bg-panel); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border);}
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 8px; background: var(--border); color: var(--text-title); transition: 0.3s; margin-left: 10px; border: 1px solid transparent;}
        .nav-hub a:hover { border-color: var(--accent); color: var(--accent); }
        
        .container { padding: 30px; }

        /* TABLERO DE TICKETS */
        .grid-tickets { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; align-items: start;}
        
        /* DISEÑO DEL TICKET DE PAPEL */
        .ticket { background: var(--bg-panel); color: var(--text-title); border-radius: 12px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.5); display: flex; flex-direction: column; border: 1px solid var(--border);}
        
        .ticket-header { padding: 15px; text-align: center; border-bottom: 2px dashed var(--border);}
        .ticket-header h2 { margin: 0; font-size: 1.8rem; font-weight: 900;}
        .ticket-tiempo { font-size: 1.1rem; font-weight: bold; margin-top: 5px;}

        /* COLORES DEL SEMÁFORO KDS */
        .verde { border-color: #32CD32; }
        .verde .ticket-header { background: rgba(50, 205, 50, 0.1); color: #32CD32; }
        
        .amarillo { border-color: #FFC107; }
        .amarillo .ticket-header { background: rgba(255, 193, 7, 0.1); color: #FFC107; }
        
        .rojo { border-color: #E53935; box-shadow: 0 0 15px rgba(229, 57, 53, 0.5); }
        .rojo .ticket-header { background: rgba(229, 57, 53, 0.2); color: #FF5252; animation: titilar 2s infinite;}

        @keyframes titilar { 0% { opacity: 1; } 50% { opacity: 0.6; } 100% { opacity: 1; } }

        .ticket-body { padding: 15px; font-size: 1.2rem; flex-grow: 1;}
        .ticket-body ul { margin: 0; padding: 0; list-style: none; }
        
        .ticket-body li { padding: 12px 0; border-bottom: 1px solid var(--border); display: flex; flex-direction: column; align-items: flex-start;}
        .ticket-body li:last-child { border-bottom: none; }
        
        .plato-titulo { display: flex; align-items: center; font-weight: bold; width: 100%;}
        .plato-titulo span.cant { color: var(--accent); margin-right: 10px; font-weight: 900;}
        
        .nota-alerta { display: inline-block; color: #FF5252; background: rgba(229, 57, 53, 0.1); padding: 5px 10px; border-radius: 6px; border-left: 4px solid #FF5252; font-size: 1rem; font-weight: bold; margin-top: 8px; margin-left: 30px; }

        /* BOTÓN DESPACHAR */
        .btn-listo { background: var(--accent); color: white; border: none; padding: 15px; font-size: 1.2rem; font-weight: 900; cursor: pointer; transition: 0.2s; text-transform: uppercase; letter-spacing: 1px;}
        .btn-listo:hover { opacity: 0.8; }

        /* PANEL DE CONFIGURACIÓN */
        #panel-config { display: none; background: var(--bg-body); padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 1px solid var(--border);}
        .input-tiempos { padding: 8px; border-radius: 5px; border: 1px solid var(--border); background: var(--bg-panel); color: var(--text-title); font-size: 1rem; width: 60px; text-align: center; margin: 0 10px;}
        .btn-guardar-conf { background: #32CD32; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer;}
    </style>
    
    <script>
        // Auto-refresco cada 20 segundos (Ideal para cocinas de alta demanda)
        setTimeout(function() { window.location.reload(); }, 20000); 

        function toggleConfig() {
            var panel = document.getElementById('panel-config');
            panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
        }
    </script>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.8rem; font-weight: 900; color: var(--accent);">👨‍🍳 Fast KDS - Cocina</span>
        <div>
            <button onclick="toggleConfig()" style="background: transparent; border: 1px solid var(--border); color: var(--text-title); padding: 10px 15px; border-radius: 8px; cursor: pointer; font-size: 1rem;">⚙️ Tiempos</button>
            <a href="r_pedidos.php">← Volver al Cajero</a>
        </div>
    </div>

    <div class="container">

        <div id="panel-config">
            <form method="POST" style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <span style="color: #FFC107; font-size: 1.1rem; font-weight: bold;">🟡 Alerta Amarilla:</span>
                    <input type="number" name="min_amarillo" class="input-tiempos" value="<?= $min_amarillo ?>" min="1"> min.
                    
                    <span style="color: #FF5252; font-size: 1.1rem; font-weight: bold; margin-left: 20px;">🔴 Alerta Roja:</span>
                    <input type="number" name="min_rojo" class="input-tiempos" value="<?= $min_rojo ?>" min="2"> min.
                </div>
                <button type="submit" name="guardar_tiempos" class="btn-guardar-conf">Guardar Tiempos</button>
            </form>
        </div>

        <?php if(empty($tickets)): ?>
            <div style="text-align: center; color: #8b949e; margin-top: 100px;">
                <h1 style="font-size: 5rem; margin: 0; filter: grayscale(100%);">🍔</h1>
                <h2 style="color: white;">Sin pedidos en cola</h2>
                <p>La parrilla está limpia. Esperando nuevos tickets.</p>
            </div>
        <?php else: ?>
            
            <div class="grid-tickets">
                <?php foreach($tickets as $key => $ticket): ?>
                    <?php 
                        $fecha_pedido = strtotime($ticket['fecha']);
                        $minutos_transcurridos = floor((time() - $fecha_pedido) / 60);

                        if ($minutos_transcurridos >= $min_rojo) {
                            $clase_color = 'rojo'; $icono = "🔥 ¡URGENTE!";
                        } elseif ($minutos_transcurridos >= $min_amarillo) {
                            $clase_color = 'amarillo'; $icono = "⏳ Demorado";
                        } else {
                            $clase_color = 'verde'; $icono = "✅ A tiempo";
                        }
                    ?>
                    
                    <div class="ticket <?= $clase_color ?>">
                        <div class="ticket-header">
                            <h2><?= $ticket['cliente'] ?></h2>
                            <div class="ticket-tiempo"><?= $icono ?> - Hace <?= $minutos_transcurridos ?> min.</div>
                        </div>
                        
                        <div class="ticket-body">
                            <ul>
                                <?php foreach($ticket['platos'] as $plato): ?>
                                    <li>
                                        <div class="plato-titulo"><span class="cant"><?= $plato['cantidad'] ?>x</span> <?= $plato['nombre'] ?></div>
                                        
                                        <?php 
                                        // Tijera: Cortamos el texto justo donde empieza el descuento
                                        $partes_comentario = explode('[DSTO:', $plato['comentario']);
                                        // Limpiamos la primera parte (el comentario real) de espacios o líneas "|"
                                        $comentario_limpio = trim(rtrim($partes_comentario[0], '| ')); 
                                        
                                        if(!empty($comentario_limpio)): 
                                        ?>
                                            <span class="nota-alerta">⚠️ <?= htmlspecialchars($comentario_limpio) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        
                        <form method="POST" style="margin: 0; display: flex; flex-direction: column;">
                            <input type="hidden" name="codigo_grupo" value="<?= $ticket['codigo_grupo'] ?>">
                            <button type="submit" name="marcar_listo" class="btn-listo">✔ DESPACHAR</button>
                        </form>
                    </div>

                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>