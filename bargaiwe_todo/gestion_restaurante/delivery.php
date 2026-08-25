<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';



// --- 1. LÓGICA PARA MOVER LAS TARJETAS (CAMBIAR ESTADO) ---
if (isset($_GET['accion']) && isset($_GET['id'])) {
    $id_delivery = (int)$_GET['id'];
    $nuevo_estado = (int)$_GET['accion']; 
    
    // Actualizamos el estado de la tarjeta
    $conn->query("UPDATE registro_delivery SET estado_pedido = $nuevo_estado WHERE id = $id_delivery");
    
    // Si se archiva (4), marcamos los platos como historial (estado 3)
    if ($nuevo_estado == 4) {
        $conn->query("UPDATE pedidos SET estado = 3 WHERE delivery_id = $id_delivery");
    }
    
    header("Location: delivery.php");
    exit();
}

// --- 2. OBTENER LOS PEDIDOS ACTIVOS CON SUS TOTALES Y PLATOS ---
$sql = "SELECT d.*, 
        (SELECT SUM(precio_al_momento) FROM pedidos WHERE delivery_id = d.id) as total_calculado,
        (SELECT GROUP_CONCAT(m.nombre SEPARATOR ', ') FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.delivery_id = d.id) as resumen_platos
        FROM registro_delivery d 
        WHERE d.estado_pedido IN (1, 2, 3) AND d.restaurant_id = $mi_restaurant_id
        ORDER BY d.id DESC";

$resultado = $conn->query($sql);

$columnas = [1 => [], 2 => [], 3 => []];
if ($resultado) {
    while ($row = $resultado->fetch_assoc()) {
        $estado = (int)$row['estado_pedido'];
        if (isset($columnas[$estado])) {
            $columnas[$estado][] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Tablero de Delivery</title>
    <style>
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        .nav-hub { background: #E53935; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 8px 15px; border-radius: 8px; background: rgba(0,0,0,0.2); color: white; transition: 0.3s; margin-left: 10px;}
        
        .btn-nuevo { background: #fff !important; color: #E53935 !important; }

        .tablero { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; padding: 20px; height: 85vh; }
        .columna { background: #f4f4f4; border-radius: 15px; padding: 15px; display: flex; flex-direction: column; gap: 15px; border: 1px solid #ddd; overflow-y: auto;}
        .col-titulo { font-size: 1.1rem; font-weight: 900; text-align: center; margin: 0; padding-bottom: 10px; border-bottom: 3px solid #ccc; text-transform: uppercase;}

        /* Tarjetas */
        .tarjeta { background: white; border-radius: 12px; padding: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-left: 5px solid #ccc; }
        .t-1 { border-left-color: #FF8C00; } /* Cocina */
        .t-2 { border-left-color: #007BFF; } /* Camino */
        .t-3 { border-left-color: #32CD32; } /* Entregado */

        .cliente-n { font-size: 1.1rem; font-weight: 900; margin: 0; }
        .cliente-d { font-size: 0.85rem; color: #666; margin: 5px 0; }
        .platos { background: #fffdf0; padding: 8px; border-radius: 6px; font-size: 0.85rem; font-style: italic; border: 1px dashed #ccc; margin: 10px 0; }
        .total { font-size: 1.2rem; font-weight: 900; color: #014421; text-align: right; }

        .btn-a { text-decoration: none; display: block; text-align: center; padding: 8px; border-radius: 6px; color: white; font-weight: bold; margin-top: 10px; font-size: 0.9rem; }
        .btn-azul { background: #007BFF; }
        .btn-verde { background: #32CD32; }
        .btn-gris { background: #607D8B; }
        .btn-editar { background: #eee; color: #333; font-size: 0.7rem; padding: 4px; display: inline-block; margin-top: 0; }
    </style>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.6rem; font-weight: 800;">Tablero de Delivery</span>
        <div>
            <a href="parallevar.php" class="btn-nuevo">➕ NUEVO PEDIDO</a>
            <a href="mesas.php">← Volver a Mesas</a>
        </div>
    </div>

    <div class="tablero">
        
        <div class="columna">
            <h3 class="col-titulo" style="color: #FF8C00; border-color: #FF8C00;">👨‍🍳 Cocina (<?php echo count($columnas[1]); ?>)</h3>
            <?php foreach($columnas[1] as $p): ?>
                <div class="tarjeta t-1">
                    <p class="cliente-n">#<?php echo $p['id']; ?> - <?php echo htmlspecialchars($p['cliente']); ?></p>
                    <p class="cliente-d">📍 <?php echo htmlspecialchars($p['direccion']); ?></p>
                    <div class="platos">🍔 <?php echo htmlspecialchars($p['resumen_platos'] ?: 'Sin platos...'); ?></div>
                    <div class="total">$<?php echo number_format($p['total_calculado'] ?? 0, 0, ',', '.'); ?></div>
                    
                    <a href="parallevar.php?id=<?php echo $p['id']; ?>" class="btn-a btn-editar">✏️ Editar Pedido</a>
                    <a href="delivery.php?accion=2&id=<?php echo $p['id']; ?>" class="btn-a btn-azul">🛵 Despachar Pedido</a>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="columna">
            <h3 class="col-titulo" style="color: #007BFF; border-color: #007BFF;">🛵 En Camino (<?php echo count($columnas[2]); ?>)</h3>
            <?php foreach($columnas[2] as $p): ?>
                <div class="tarjeta t-2">
                    <p class="cliente-n"><?php echo htmlspecialchars($p['cliente']); ?></p>
                    <p class="cliente-d">📞 <?php echo htmlspecialchars($p['telefono']); ?></p>
                    <div class="total">$<?php echo number_format($p['total_calculado'] ?? 0, 0, ',', '.'); ?></div>
                    <a href="delivery.php?accion=3&id=<?php echo $p['id']; ?>" class="btn-a btn-verde">✅ Marcar Entregado</a>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="columna">
            <h3 class="col-titulo" style="color: #32CD32; border-color: #32CD32;">💰 Por Cobrar (<?php echo count($columnas[3]); ?>)</h3>
            <?php foreach($columnas[3] as $p): ?>
                <div class="tarjeta t-3">
                    <p class="cliente-n"><?php echo htmlspecialchars($p['cliente']); ?></p>
                    <div class="total">$<?php echo number_format($p['total_calculado'] ?? 0, 0, ',', '.'); ?></div>
                    <a href="delivery.php?accion=4&id=<?php echo $p['id']; ?>" class="btn-a btn-gris" onclick="return confirm('¿Confirmas que se recibió el dinero?');">📦 Archivar y Cobrar</a>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

</body>
</html>