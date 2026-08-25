<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';
include 'config_sistema.php';

// 1 = Inventario descontando normal | 0 = Inventario CONGELADO (Stock infinito)
$inventario_activo = 0;

// Si no hay sesión activa, la creamos usando los datos que vienen en el QR (r y t)
if (!isset($_SESSION['rest_id_movil'])) {
    if (isset($_GET['r']) && isset($_GET['t'])) {
        $r_url = (int)$_GET['r'];
        $t_url = $_GET['t'];
        
        // Validamos que el token sea legítimo (la misma clave que en mesas.php)
        $clave_secreta = "Bargaiwe_Secreto_2026"; 
        $token_esperado = hash('sha256', $r_url . $clave_secreta);
        
        if ($t_url === $token_esperado) {
            // El token es correcto, "logueamos" al celular
            $_SESSION['rest_id_movil'] = $r_url;
        } else {
            die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>⛔ Token Inválido</h2><p>Acceso denegado.</p></div>");
        }
    } else {
        die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>⛔ Error de Sesión</h2><p>Escanea el QR de nuevo.</p></div>");
    }
}
$mi_restaurant_id = (int)$_SESSION['rest_id_movil'];

$mesa_id = isset($_GET['mesa_id']) ? (int)$_GET['mesa_id'] : 0;
if ($mesa_id == 0) { header("Location: mesa_qr.php"); exit(); }

$res_mesa = $conn->query("SELECT numero_mesa FROM mesas WHERE id = $mesa_id AND restaurant_id = $mi_restaurant_id");
if (!$res_mesa || $res_mesa->num_rows == 0) { die("Mesa inválida."); }
$numero_mesa = $res_mesa->fetch_assoc()['numero_mesa'];

// 2. SEGUNDO: Procesar las acciones de los botones

// --- LÓGICA: AGREGAR PLATO (Botón +) ---
if (isset($_POST['agregar_plato'])) {
    $menu_id = (int)$_POST['menu_id'];
    $notas = $conn->real_escape_string($_POST['notas']); 
    
    $res_precio = $conn->query("SELECT precio FROM menu WHERE id = $menu_id AND restaurant_id = $mi_restaurant_id");
    
    if ($row = $res_precio->fetch_assoc()) {
        $precio = $row['precio'];
        // CAMBIO: Se inserta en Estado 0 (Carrito QR)
        $conn->query("INSERT INTO pedidos (menu_id, mesa_id, estado, notas, precio_al_momento, tipo_pedido, restaurant_id) 
                      VALUES ($menu_id, $mesa_id, 0, '$notas', $precio, 'local', $mi_restaurant_id)");
        
        if ($inventario_activo == 1) {
            $res_receta = $conn->query("SELECT ingrediente_id, cantidad_usada FROM recetas WHERE menu_id = $menu_id");
            while($ing = $res_receta->fetch_assoc()) {
                $conn->query("UPDATE ingredientes SET stock_actual = stock_actual - {$ing['cantidad_usada']} WHERE id = {$ing['ingrediente_id']}");
            }
        }
    } 
    header("Location: pedido_qr.php?mesa_id=$mesa_id&r=$mi_restaurant_id&t=" . $_GET['t']);
    exit();
}

// --- NUEVA LÓGICA: ENVIAR A COCINA DESDE EL QR ---
if (isset($_POST['enviar_cocina_qr'])) {
    // Pasa los platos del carrito (0) a la cocina (1)
    $conn->query("UPDATE pedidos SET estado = 1 WHERE mesa_id = $mesa_id AND estado = 0 AND restaurant_id = $mi_restaurant_id");
    header("Location: pedido_qr.php?mesa_id=$mesa_id&r=$mi_restaurant_id&t=" . $_GET['t'] . "&envio=ok");
    exit();
}

// --- LÓGICA: CANCELAR PLATO ESPECÍFICO (Botón X en el resumen) ---
if (isset($_POST['cancelar_item'])) {
    $id_a_borrar = (int)$_POST['id_a_borrar'];
    
    $res_m = $conn->query("SELECT menu_id FROM pedidos WHERE id = $id_a_borrar AND restaurant_id = $mi_restaurant_id");
    if ($res_m && $row_m = $res_m->fetch_assoc()) {
        $m_id = $row_m['menu_id'];
        
        if ($inventario_activo == 1) {
            $res_receta = $conn->query("SELECT ingrediente_id, cantidad_usada FROM recetas WHERE menu_id = $m_id");
            while($ing = $res_receta->fetch_assoc()) {
                $conn->query("UPDATE ingredientes SET stock_actual = stock_actual + {$ing['cantidad_usada']} WHERE id = {$ing['ingrediente_id']}");
            }
        }
        $conn->query("DELETE FROM pedidos WHERE id = $id_a_borrar AND restaurant_id = $mi_restaurant_id");
    }
    header("Location: pedido_qr.php?mesa_id=$mesa_id&r=$mi_restaurant_id&t=" . $_GET['t']); exit();
}

// --- LÓGICA: QUITAR ÚLTIMO PLATO (Botón Menos en la lista) ---
if (isset($_POST['quitar_plato'])) {
    $menu_id = (int)$_POST['menu_id'];
    
    // CAMBIO: Solo quita platos que aún no se han enviado a cocina (estado = 0)
    $res_ultimo = $conn->query("SELECT id FROM pedidos WHERE menu_id = $menu_id AND mesa_id = $mesa_id AND estado = 0 AND restaurant_id = $mi_restaurant_id ORDER BY id DESC LIMIT 1");
    
    if ($res_ultimo && $row_ultimo = $res_ultimo->fetch_assoc()) {
        $id_a_borrar = $row_ultimo['id'];
        
        if ($inventario_activo == 1) {
            $res_receta = $conn->query("SELECT ingrediente_id, cantidad_usada FROM recetas WHERE menu_id = $menu_id");
            while($ing = $res_receta->fetch_assoc()) {
                $conn->query("UPDATE ingredientes SET stock_actual = stock_actual + {$ing['cantidad_usada']} WHERE id = {$ing['ingrediente_id']}");
            }
        }
        $conn->query("DELETE FROM pedidos WHERE id = $id_a_borrar");
    }
    header("Location: pedido_qr.php?mesa_id=$mesa_id&r=$mi_restaurant_id&t=" . $_GET['t']); exit();
}

// 3. TERCERO: Consultar datos para mostrar en pantalla
// CAMBIO: Incluimos el estado 0 para que sume el carrito
$res_cuenta = $conn->query("SELECT COUNT(*) as cant, SUM(precio_al_momento) as total FROM pedidos WHERE mesa_id = $mesa_id AND estado IN (0, 1, 2) AND restaurant_id = $mi_restaurant_id");
$data_cuenta = $res_cuenta->fetch_assoc();
$total_cuenta = $data_cuenta['total'] ?? 0;
$cantidad_items = $data_cuenta['cant'] ?? 0;
// --- NUEVA LÓGICA: MARCAR PLATO COMO ENTREGADO DESDE EL CELULAR ---
if (isset($_POST['marcar_entregado'])) {
    $id_pedido = (int)$_POST['id_pedido'];
    // Pasamos de estado 2 (Listo en cocina) a estado 4 (Entregado al cliente)
    $conn->query("UPDATE pedidos SET estado = 4 WHERE id = $id_pedido AND restaurant_id = $mi_restaurant_id");
    header("Location: pedido_qr.php?mesa_id=$mesa_id&r=$mi_restaurant_id&t=" . $_GET['t']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mesa <?php echo $numero_mesa; ?></title>
    <style>
        body { background-color: #f4f4f4; font-family: 'Segoe UI', sans-serif; margin: 0; padding-bottom: 100px; }
        .header { background: #E53935; color: white; padding: 15px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .btn-back { background: rgba(255,255,255,0.2); color: white; text-decoration: none; padding: 8px 12px; border-radius: 8px; font-weight: bold; }
        
        .categoria { background: #333; color: #fff; padding: 10px 15px; font-weight: bold; text-transform: uppercase; font-size: 0.9rem; margin-top: 10px; }
        .plato-card { background: white; border-bottom: 1px solid #eee; padding: 12px 15px; display: flex; flex-direction: column; gap: 10px; }
        
        .plato-fila-principal { display: flex; justify-content: space-between; align-items: center; }
        .plato-nombre { font-weight: bold; font-size: 1.1rem; color: #333; }
        
        .controles-pedido { display: flex; align-items: center; gap: 10px; }
        .input-notas { width: 100px; padding: 10px; border: 1px solid #ccc; border-radius: 8px; font-size: 0.9rem; background: #fafafa; }
        
        .btn-accion { border: none; width: 45px; height: 45px; border-radius: 10px; font-size: 1.5rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; color: white; }
        .btn-menos { background: #888; box-shadow: 0 4px 0 #666; }
        .btn-mas { background: #32CD32; box-shadow: 0 4px 0 #228B22; }
        .btn-accion:active { transform: translateY(3px); box-shadow: none; }

        .barra-pago { position: fixed; bottom: 0; width: 100%; background: white; padding: 15px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 -3px 15px rgba(0,0,0,0.1); box-sizing: border-box; z-index: 100; }
        .total-monto { font-size: 1.5rem; font-weight: 900; color: #014421; }
        .btn-confirmar { background: #007BFF; color: white; text-decoration: none; padding: 12px 20px; border-radius: 10px; font-weight: bold; font-size: 1.1rem; box-shadow: 0 4px 0 #0056b3; }
    </style>
</head>
<body>

<div class="header">
    <a href="mesa_qr.php" class="btn-back">◀ Volver</a>
    <span style="font-weight: 900; font-size: 1.2rem;">MESA <?php echo $numero_mesa; ?></span>
    <span style="background: white; color: #E53935; padding: 3px 10px; border-radius: 15px; font-weight: bold;"><?php echo $cantidad_items; ?></span>
</div>

<div class="container">
    <div style="background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin: 20px 0; border-left: 5px solid #007BFF;">
        <h3 style="margin: 0 0 10px 0; font-size: 1rem; color: #555;">📋 Pedido en curso:</h3>
        <?php 
        // CAMBIO: Ahora consultamos todos los platos de la mesa que no estén pagados (estado != 3)
        $sql_actual = "SELECT p.id, m.nombre, p.notas, p.estado FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.mesa_id = $mesa_id AND p.estado != 3 AND p.restaurant_id = $mi_restaurant_id";
        $res_actual = $conn->query($sql_actual);
        if ($res_actual && $res_actual->num_rows > 0): ?>
            <ul style="list-style: none; padding: 0; margin: 0;">
                <?php while($item = $res_actual->fetch_assoc()): 
                    // LÓGICA DE SEMÁFORO
                    if ($item['estado'] == 0) {
                        $color_estado = '#E53935'; $titulo_estado = '🔴 Carrito';
                    } elseif ($item['estado'] == 1) {
                        $color_estado = '#FF8C00'; $titulo_estado = '👨‍🍳 Cocinando'; 
                    } elseif ($item['estado'] == 2) {
                        $color_estado = '#FFC107'; $titulo_estado = '🔔 ¡LISTO!'; 
                    } elseif ($item['estado'] == 4) {
                        $color_estado = '#32CD32'; $titulo_estado = '✅ Entregado'; 
                    } else {
                        $color_estado = '#ccc'; $titulo_estado = '';
                    }
                ?>
                    <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #eee;">
                        
                        <div style="width: 12px; height: 12px; border-radius: 50%; background-color: <?php echo $color_estado; ?>; margin-right: 10px; flex-shrink: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.3);"></div>
                        
                        <span style="flex: 1;">
                            <strong style="font-size: 1.1rem;"><?php echo htmlspecialchars($item['nombre']); ?></strong>
                            <br><span style="font-size: 0.8rem; color: #666; font-weight: bold;"><?php echo $titulo_estado; ?></span>
                            <?php if($item['notas']): ?><br><span style="color:#d32f2f; font-weight: bold; font-size: 0.9rem;">» <?php echo htmlspecialchars($item['notas']); ?></span><?php endif; ?>
                        </span>
                        
                        <?php if ($item['estado'] == 0): ?>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="id_a_borrar" value="<?php echo $item['id']; ?>">
                                <button type="submit" name="cancelar_item" style="background: #ff4444; color: white; border: none; border-radius: 8px; padding: 10px 15px; font-weight: bold; font-size: 1.1rem; cursor: pointer; box-shadow: 0 3px 0 #cc0000;">✕</button>
                            </form>
                        <?php elseif ($item['estado'] == 2): ?>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="id_pedido" value="<?php echo $item['id']; ?>">
                                <button type="submit" name="marcar_entregado" style="background: #32CD32; color: white; border: none; border-radius: 8px; padding: 10px; font-weight: bold; font-size: 0.9rem; cursor: pointer; box-shadow: 0 3px 0 #228B22;">✔️ Entregar</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php else: ?>
            <p style="color: #999; font-style: italic; margin: 0;">No hay platos anotados todavía.</p>
        <?php endif; ?>
    </div>

    <?php
    $sql_menu = "SELECT * FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion ASC, nombre ASC";
    $res_menu = $conn->query($sql_menu);
    $current_sec = "";
    while($p = $res_menu->fetch_assoc()): 
        if($current_sec != $p['seccion']){
            $current_sec = $p['seccion'];
            echo "<div class='categoria'>" . htmlspecialchars($current_sec) . "</div>";
        }
    ?>
        <div class="plato-card">
            <div class="plato-fila-principal">
                <span class="plato-nombre"><?php echo htmlspecialchars($p['nombre']); ?> <br> <small style="color:#777;">$<?php echo number_format($p['precio'], 0, ',', '.'); ?></small></span>
                
                <div class="controles-pedido">
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="menu_id" value="<?php echo $p['id']; ?>">
                        <button type="submit" name="quitar_plato" class="btn-accion btn-menos">➖</button>
                    </form>
                    
                    <form method="POST" style="display: flex; gap: 8px; align-items: center; margin:0;">
                        <input type="hidden" name="menu_id" value="<?php echo $p['id']; ?>">
                        <input type="text" name="notas" placeholder="Nota..." class="input-notas">
                        <button type="submit" name="agregar_plato" class="btn-accion btn-mas">➕</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endwhile; ?>
</div>

<div class="barra-pago">
    <div>
        <small style="color:#888; font-weight:bold;">TOTAL CUENTA</small><br>
        <span class="total-monto">$<?php echo number_format($total_cuenta, 0, ',', '.'); ?></span>
    </div>
    <form method="POST" style="margin: 0;">
    <button type="submit" name="enviar_cocina_qr" class="btn-confirmar" style="border: none; cursor: pointer;">
        Enviar a Cocina ✅
    </button>
</form>
</div>

</body>
</html>