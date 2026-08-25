<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuramos la zona horaria para que el cálculo de minutos sea exacto (Chile)
date_default_timezone_set('America/Santiago'); 

include 'db.php';


// --- 0. MAGIA: ASEGURAR QUE LA TABLA PEDIDOS TENGA RESTAURANT_ID ---
$check_pedidos = $conn->query("SHOW COLUMNS FROM pedidos LIKE 'restaurant_id'");
if ($check_pedidos && $check_pedidos->num_rows == 0) {
    $conn->query("ALTER TABLE pedidos ADD COLUMN restaurant_id INT NOT NULL DEFAULT 1");
}

// --- 1. MAGIA: CREAR TABLA DE CONFIGURACIÓN DE COCINA ---
$conn->query("CREATE TABLE IF NOT EXISTS config_cocina (
    restaurant_id INT PRIMARY KEY,
    minutos_amarillo INT DEFAULT 15,
    minutos_rojo INT DEFAULT 30
)");
// Insertar valores por defecto si no existen
$conn->query("INSERT IGNORE INTO config_cocina (restaurant_id, minutos_amarillo, minutos_rojo) VALUES ($mi_restaurant_id, 15, 30)");

// --- 2. GUARDAR NUEVOS TIEMPOS (Si el chef cambia la configuración) ---
if (isset($_POST['guardar_tiempos'])) {
    $am = (int)$_POST['min_amarillo'];
    $ro = (int)$_POST['min_rojo'];
    $conn->query("UPDATE config_cocina SET minutos_amarillo = $am, minutos_rojo = $ro WHERE restaurant_id = $mi_restaurant_id");
    header("Location: cocina.php");
    exit();
}

// --- 3. MARCAR PEDIDO COMO LISTO ---
if (isset($_POST['marcar_listo'])) {
    $ids_crudos = $_POST['pedidos_ids']; 
    if (!empty($ids_crudos)) {
        $ids_limpios = implode(',', array_map('intval', explode(',', $ids_crudos)));
        $conn->query("UPDATE pedidos SET estado = 2 WHERE id IN ($ids_limpios)");
    }
    header("Location: cocina.php");
    exit();
}

// --- 4. OBTENER CONFIGURACIÓN ACTUAL ---
$config = $conn->query("SELECT * FROM config_cocina WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
$min_amarillo = $config['minutos_amarillo'];
$min_rojo = $config['minutos_rojo'];

// --- 5. OBTENER TICKETS PENDIENTES (ESTADO 1) ---
// SE AGREGÓ p.notas A LA CONSULTA SQL
$sql = "SELECT p.id, p.fecha, m.nombre as nombre_plato, p.notas, p.mesa_id, p.delivery_id,
        t.numero_mesa, d.cliente as nombre_delivery
        FROM pedidos p
        JOIN menu m ON p.menu_id = m.id
        LEFT JOIN mesas t ON p.mesa_id = t.id
        LEFT JOIN registro_delivery d ON p.delivery_id = d.id
        WHERE p.estado = 1 AND m.restaurant_id = $mi_restaurant_id
        ORDER BY p.fecha ASC";

$tickets = [];
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $ticket_key = $row['mesa_id'] ? 'mesa_'.$row['mesa_id'] : 'delivery_'.$row['delivery_id'];
        
        if (!isset($tickets[$ticket_key])) {
            $tickets[$ticket_key] = [
                'origen' => $row['mesa_id'] ? 'Mesa '.$row['numero_mesa'] : '🛵 Delivery: '.htmlspecialchars($row['nombre_delivery']),
                'fecha' => $row['fecha'],
                'platos' => [],
                'ids' => []
            ];
        }
        
        // AHORA GUARDAMOS EL NOMBRE Y LA NOTA EN UN ARREGLO
        $tickets[$ticket_key]['platos'][] = [
            'nombre' => $row['nombre_plato'],
            'notas' => $row['notas']
        ];
        $tickets[$ticket_key]['ids'][] = $row['id'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Pantalla de Cocina (KDS)</title>
    <style>
        body { background-color: #1a1a1a; font-family: 'Segoe UI', sans-serif; margin: 0; color: #fff; }
        
        .nav-hub { background: #000; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; border-bottom: 2px solid #333;}
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 8px; background: #333; color: white; transition: 0.3s; margin-left: 10px;}
        .nav-hub a:hover { background: #555; }
        
        .container { padding: 30px; }

        /* TABLERO DE TICKETS */
        .grid-tickets { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; align-items: start;}
        
        /* DISEÑO DE LA TARJETA (TICKET) */
        .ticket { background: #fff; color: #000; border-radius: 12px; overflow: hidden; box-shadow: 0 5px 15px rgba(0,0,0,0.5); display: flex; flex-direction: column;}
        
        .ticket-header { padding: 15px; text-align: center; border-bottom: 2px dashed #ccc;}
        .ticket-header h2 { margin: 0; font-size: 1.6rem; font-weight: 900;}
        .ticket-tiempo { font-size: 1.1rem; font-weight: bold; margin-top: 5px;}

        /* COLORES DEL SEMÁFORO */
        .verde .ticket-header { background: #32CD32; color: white; }
        .amarillo .ticket-header { background: #FFC107; color: black; }
        .rojo .ticket-header { background: #E53935; color: white; animation: titilar 2s infinite;}

        @keyframes titilar { 0% { opacity: 1; } 50% { opacity: 0.85; } 100% { opacity: 1; } }

        .ticket-body { padding: 15px; font-size: 1.2rem; flex-grow: 1;}
        .ticket-body ul { margin: 0; padding: 0; list-style: none; }
        
        /* Ajuste para que las notas se vean bien debajo del nombre */
        .ticket-body li { padding: 10px 0; border-bottom: 1px solid #eee; display: flex; flex-direction: column; align-items: flex-start;}
        .ticket-body li:last-child { border-bottom: none; }
        
        .plato-titulo { display: flex; align-items: center; font-weight: bold; width: 100%;}
        .plato-titulo::before { content: "▪"; color: #888; margin-right: 10px; font-size: 1.5rem;}
        
        /* NUEVO: ESTILO PARA LA NOTA EN LA COCINA */
        .nota-alerta { 
            display: inline-block; 
            color: #d32f2f; 
            background: #ffebee; 
            padding: 5px 10px; 
            border-radius: 6px; 
            border-left: 4px solid #f44336;
            font-size: 1rem; 
            font-weight: 900; 
            margin-top: 6px; 
            margin-left: 20px; /* Para alinearlo con el texto del plato */
        }

        .btn-listo { background: #014421; color: white; border: none; padding: 15px; font-size: 1.2rem; font-weight: bold; cursor: pointer; transition: 0.2s; text-transform: uppercase;}
        .btn-listo:hover { background: #01331a; }

        /* PANEL DE CONFIGURACIÓN */
        #panel-config { display: none; background: #222; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #444;}
        .input-tiempos { padding: 8px; border-radius: 5px; border: none; font-size: 1rem; width: 60px; text-align: center; margin: 0 10px;}
        .btn-guardar-conf { background: #FF8C00; color: white; border: none; padding: 8px 15px; border-radius: 5px; font-weight: bold; cursor: pointer;}
    </style>
    
    <script>
        // --- AUTO-REFRESCO DE PANTALLA ---
        setTimeout(function() {
            window.location.reload();
        }, 30000); 

        function toggleConfig() {
            var panel = document.getElementById('panel-config');
            panel.style.display = (panel.style.display === 'block') ? 'none' : 'block';
        }
    </script>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.8rem; font-weight: 900;">👨‍🍳 bargaiwe KDS - Cocina</span>
        <div>
            <button onclick="toggleConfig()" style="background: transparent; border: 1px solid #555; color: white; padding: 8px 15px; border-radius: 8px; cursor: pointer; font-size: 1rem;">⚙️ Configurar Tiempos</button>
            <a href="mesas.php">← Volver a Mesas</a>
        </div>
    </div>

    <div class="container">

        <div id="panel-config">
            <form method="POST" style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <span style="color: #FFC107; font-size: 1.2rem;">🟡 Cambiar a Amarillo a los:</span>
                    <input type="number" name="min_amarillo" class="input-tiempos" value="<?= $min_amarillo ?>" min="1"> min.
                    
                    <span style="color: #E53935; font-size: 1.2rem; margin-left: 20px;">🔴 Cambiar a Rojo a los:</span>
                    <input type="number" name="min_rojo" class="input-tiempos" value="<?= $min_rojo ?>" min="2"> min.
                </div>
                <button type="submit" name="guardar_tiempos" class="btn-guardar-conf">💾 Guardar Tiempos</button>
            </form>
        </div>

        <?php if(empty($tickets)): ?>
            <div style="text-align: center; color: #666; margin-top: 100px;">
                <h1 style="font-size: 4rem; margin: 0;">🍻</h1>
                <h2>La cocina está despejada.</h2>
                <p>No hay pedidos pendientes. Tómate un respiro.</p>
            </div>
        <?php else: ?>
            
            <div class="grid-tickets">
                <?php foreach($tickets as $key => $ticket): ?>
                    <?php 
                        $fecha_pedido = strtotime($ticket['fecha']);
                        $minutos_transcurridos = floor((time() - $fecha_pedido) / 60);

                        if ($minutos_transcurridos >= $min_rojo) {
                            $clase_color = 'rojo';
                            $icono = "🔥 ¡URGENTE!";
                        } elseif ($minutos_transcurridos >= $min_amarillo) {
                            $clase_color = 'amarillo';
                            $icono = "⏳ Demorado";
                        } else {
                            $clase_color = 'verde';
                            $icono = "✅ Nuevo";
                        }
                    ?>
                    
                    <div class="ticket <?= $clase_color ?>">
                        <div class="ticket-header">
                            <h2><?= $ticket['origen'] ?></h2>
                            <div class="ticket-tiempo"><?= $icono ?> - Hace <?= $minutos_transcurridos ?> min.</div>
                        </div>
                        
                        <div class="ticket-body">
                            <ul>
                                <?php foreach($ticket['platos'] as $plato): ?>
                                    <li>
                                        <div class="plato-titulo"><?= htmlspecialchars($plato['nombre']) ?></div>
                                        
                                        <?php if(!empty($plato['notas'])): ?>
                                            <span class="nota-alerta">⚠️ <?= htmlspecialchars($plato['notas']) ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        
                        <form method="POST" style="display: flex; flex-direction: column;">
                            <input type="hidden" name="pedidos_ids" value="<?= implode(',', $ticket['ids']) ?>">
                            <button type="submit" name="marcar_listo" class="btn-listo">🔔 Marcar todo como Listo</button>
                        </form>
                    </div>

                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>