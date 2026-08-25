<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['restaurant_id'])) { 
    header("Location: ../portal_bargaiwe.php"); 
    exit(); 
}

include 'r_db.php';
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// OBTENER CONFIGURACIÓN
$res_tema = $conn->query("SELECT modo_global, color_pantalla, color_fondo, color_texto FROM config_temas WHERE restaurant_id = $mi_restaurant_id");
$tema = ($res_tema && $res_tema->num_rows > 0) ? $res_tema->fetch_assoc() : ['modo_global' => 'oscuro', 'color_pantalla' => '#32CD32'];
$fondo_actual = !empty($tema['color_fondo']) ? $tema['color_fondo'] : '#0d1117';
$texto_actual = !empty($tema['color_texto']) ? $tema['color_texto'] : '#ffffff';

$config = $conn->query("SELECT pantalla_activa, max_cocinando FROM config_rapida WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
$pantalla_activa = isset($config['pantalla_activa']) ? $config['pantalla_activa'] : 1;
$max_cocinando = isset($config['max_cocinando']) ? $config['max_cocinando'] : 3;

// --- LÓGICA DE DIVISIONES ---
// 1. Obtener TODOS los pedidos en cocina (Estado 2) ordenados por antigüedad
$res_prep = $conn->query("SELECT cliente_nombre, codigo_grupo, MIN(fecha) as fecha_llegada FROM pedidos WHERE estado = 2 AND restaurant_id = $mi_restaurant_id GROUP BY cliente_nombre, codigo_grupo ORDER BY fecha_llegada ASC");
$lista_total_cocina = [];
if($res_prep) { while($r = $res_prep->fetch_assoc()) { $lista_total_cocina[] = $r; } }

// 2. Dividimos el arreglo inteligentemente
$lista_cocinando = array_slice($lista_total_cocina, 0, $max_cocinando); // Los primeros N
$lista_pendientes = array_slice($lista_total_cocina, $max_cocinando); // El resto

// 3. Obtener Listos (Estado 3)
$res_listos = $conn->query("SELECT cliente_nombre, codigo_grupo, MAX(fecha) as fecha_llegada FROM pedidos WHERE estado = 3 AND restaurant_id = $mi_restaurant_id GROUP BY cliente_nombre, codigo_grupo ORDER BY fecha_llegada DESC");
$lista_listos = [];
if($res_listos) { while($r = $res_listos->fetch_assoc()) { $lista_listos[] = $r; } }
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <?php if($pantalla_activa == 1): ?>
        <meta http-equiv="refresh" content="5"> <?php endif; ?>
    <title>Pantalla de Retiro</title>
    <style>
        :root {
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: <?php echo $tema['color_pantalla']; ?>;
        }
        <?php if($tema['modo_global'] === 'claro'): ?>
            body { --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000; }
        <?php elseif($tema['modo_global'] === 'personalizado'): ?>
            body { --bg-body: <?php echo $fondo_actual; ?>; --bg-panel: <?php echo $fondo_actual; ?>; --border: #8b949e; --text: <?php echo $texto_actual; ?>; --text-title: <?php echo $texto_actual; ?>; }
        <?php endif; ?>

        body { background: var(--bg-body); color: var(--text-title); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        
        .header { background: var(--bg-panel); border-bottom: 3px solid var(--accent); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center;}
        .titulo-header { font-size: 1.5rem; font-weight: 900; letter-spacing: 2px; text-transform: uppercase; margin: 0;}
        .btn-volver { background: var(--bg-body); color: var(--text-title); text-decoration: none; padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); font-weight: bold; transition: 0.2s; }
        .btn-volver:hover { border-color: var(--accent); }

        .pantalla-container { display: flex; height: 100%; }
        .columna { flex: 1; padding: 30px; overflow-y: auto; border-right: 2px solid var(--border); }
        .columna:last-child { border-right: none; }
        
        .col-listos { background: rgba(50, 205, 50, 0.02); }

        .titulo-col { font-size: 2rem; text-align: center; font-weight: 900; margin-top: 0; margin-bottom: 30px; text-transform: uppercase;}
        
        /* Tickets */
        .ticket-item { background: var(--bg-panel); border: 2px solid var(--border); padding: 20px; border-radius: 12px; margin-bottom: 15px; text-align: center; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .nombre { font-size: 2.2rem; font-weight: 900; }
        
        .status-cocinando { border-color: #FF8C00; background: rgba(255, 140, 0, 0.05); }
        .status-cocinando .nombre { color: #FF8C00; }
        
        .status-listo { border-color: var(--accent); background: rgba(50, 205, 50, 0.1); border-width: 4px; transform: scale(1.02); }
        .status-listo .nombre { color: var(--accent); font-size: 3rem; }

        .pantalla-apagada { display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; color: #8b949e; text-align: center; }
    </style>
</head>
<body>

    <div class="header">
        <h1 class="titulo-header">ESTADO DE PEDIDOS</h1>
        <a href="r_pedidos.php" class="btn-volver">← Volver al Cajero</a>
    </div>

    <?php if($pantalla_activa == 0): ?>
        <div class="pantalla-apagada">
            <span style="font-size: 5rem;">📺💤</span>
            <h2>Pantalla Desactivada</h2>
            <p>Puedes encenderla desde los ajustes en el Cajero.</p>
        </div>
    <?php else: ?>
        <div class="pantalla-container">
            
            <div class="columna">
                <h2 class="titulo-col" style="color: #8b949e;">⏳ Pendientes</h2>
                <?php foreach($lista_pendientes as $p): ?>
                    <div class="ticket-item">
                        <div class="nombre"><?php echo htmlspecialchars($p['cliente_nombre']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="columna">
                <h2 class="titulo-col" style="color: #FF8C00;">🔥 Cocinando</h2>
                <?php foreach($lista_cocinando as $p): ?>
                    <div class="ticket-item status-cocinando">
                        <div class="nombre"><?php echo htmlspecialchars($p['cliente_nombre']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="columna col-listos">
                <h2 class="titulo-col" style="color: var(--accent);">✔️ Retirar</h2>
                <?php foreach($lista_listos as $p): ?>
                    <div class="ticket-item status-listo">
                        <div class="nombre"><?php echo htmlspecialchars($p['cliente_nombre']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    <?php endif; ?>

</body>
</html>