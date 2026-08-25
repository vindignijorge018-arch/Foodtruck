<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';



// --- 0. LÓGICA: CANCELAR PEDIDO ---
if (isset($_GET['cancelar_id'])) {
    $id_a_borrar = (int)$_GET['cancelar_id'];
    // Limpiamos platos y el registro del cliente
    $conn->query("DELETE FROM pedidos WHERE delivery_id = $id_a_borrar AND restaurant_id = $mi_restaurant_id");
    $conn->query("DELETE FROM registro_delivery WHERE id = $id_a_borrar AND restaurant_id = $mi_restaurant_id");
    header("Location: delivery.php");
    exit();
}

// --- 1. LÓGICA: CREAR EL REGISTRO DE CLIENTE ---
if (isset($_POST['crear_cliente'])) {
    $nombre = $conn->real_escape_string($_POST['nombre']);
    $telefono = $conn->real_escape_string($_POST['telefono']);
    $direccion = $conn->real_escape_string($_POST['direccion']);
    
    $sql_cliente = "INSERT INTO registro_delivery (restaurant_id, cliente, telefono, direccion, estado_pedido) 
                    VALUES ($mi_restaurant_id, '$nombre', '$telefono', '$direccion', 1)";
    
    if ($conn->query($sql_cliente)) {
        $nuevo_id = $conn->insert_id;
        header("Location: parallevar.php?id=" . $nuevo_id);
        exit();
    }
}

$delivery_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cliente = null;

if ($delivery_id > 0) {
    $res_cliente = $conn->query("SELECT * FROM registro_delivery WHERE id = $delivery_id AND restaurant_id = $mi_restaurant_id");
    $cliente = $res_cliente->fetch_assoc();

    if (!$cliente) {
        header("Location: delivery.php");
        exit();
    }

    // --- LÓGICA: AGREGAR PLATO (VERSIÓN CON ADVERTENCIA) ---
// --- 2. LÓGICA: AGREGAR PLATO (INVENTARIO PERMISIVO) ---
    if (isset($_POST['agregar_plato'])) {
        $menu_id = (int)$_POST['menu_id'];
        $notas = $conn->real_escape_string($_POST['notas']);
        
        // 1. Validar Stock (Avisa, pero NO bloquea)
        $falta_stock = false;
        $res_receta = $conn->query("SELECT r.ingrediente_id, r.cantidad_usada, i.stock_actual FROM recetas r JOIN ingredientes i ON r.ingrediente_id = i.id WHERE r.menu_id = $menu_id");
        $items_receta = [];
        while($row = $res_receta->fetch_assoc()) {
            $items_receta[] = $row;
            if ($row['stock_actual'] < $row['cantidad_usada']) { 
                $falta_stock = true; // Falta stock en sistema, pero dejamos continuar
            }
        }

        // 2. Obtener precio
        $res_precio = $conn->query("SELECT precio FROM menu WHERE id = $menu_id");
        $precio = $res_precio->fetch_assoc()['precio'];
        
        // 3. Insertamos el pedido SIEMPRE
        $conn->query("INSERT INTO pedidos (menu_id, delivery_id, estado, notas, precio_al_momento, tipo_pedido, restaurant_id) 
                      VALUES ($menu_id, $delivery_id, 1, '$notas', $precio, 'delivery', $mi_restaurant_id)");
        
        // 4. Descontamos el stock SIEMPRE (puede quedar negativo)
        foreach($items_receta as $ing) {
            $conn->query("UPDATE ingredientes SET stock_actual = stock_actual - {$ing['cantidad_usada']} WHERE id = {$ing['ingrediente_id']}");
        }

        // 5. Redirigimos con aviso si es necesario
        if ($falta_stock) {
            header("Location: parallevar.php?id=$delivery_id&aviso_stock=1");
        } else {
            header("Location: parallevar.php?id=$delivery_id");
        }
        exit();
    }

    // --- 3. LÓGICA: QUITAR PLATO ---
    if (isset($_GET['quitar'])) {
        $id_pedido = (int)$_GET['quitar'];
        $res_p = $conn->query("SELECT menu_id FROM pedidos WHERE id = $id_pedido AND delivery_id = $delivery_id");
        if ($res_p && $row_p = $res_p->fetch_assoc()) {
            $m_id = $row_p['menu_id'];
            $res_receta = $conn->query("SELECT ingrediente_id, cantidad_usada FROM recetas WHERE menu_id = $m_id");
            while($r = $res_receta->fetch_assoc()) {
                $conn->query("UPDATE ingredientes SET stock_actual = stock_actual + {$r['cantidad_usada']} WHERE id = {$r['ingrediente_id']}");
            }
            $conn->query("DELETE FROM pedidos WHERE id = $id_pedido");
        }
        header("Location: parallevar.php?id=$delivery_id");
        exit();
    }

    // --- 4. LÓGICA: APLICAR DESCUENTO A PLATO --- 
    if (isset($_POST['aplicar_descuento'])) {
        $id_pedido = (int)$_POST['id_pedido'];
        $desc_info = explode('|', $_POST['descuento_data']); 
        
        if(count($desc_info) == 3) {
            $porcentaje = (float)$desc_info[1];
            $nombre_desc = $conn->real_escape_string($desc_info[2]);
            
            $res_base = $conn->query("SELECT m.precio, p.notas FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.id = $id_pedido");
            if($row_base = $res_base->fetch_assoc()) {
                $precio_nuevo = $row_base['precio'] - ($row_base['precio'] * ($porcentaje / 100));
                $nueva_nota = $row_base['notas'] . " [-" . $porcentaje . "% " . $nombre_desc . "]";
                
                $conn->query("UPDATE pedidos SET precio_al_momento = $precio_nuevo, notas = '$nueva_nota' WHERE id = $id_pedido AND restaurant_id = $mi_restaurant_id");
            }
        }
        header("Location: parallevar.php?id=$delivery_id");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Nuevo Pedido</title>
    <style>
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        
        /* BARRA NAVEGACIÓN */
        .nav-hub { background: #E53935; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .btn-nav { text-decoration: none; font-weight: bold; padding: 10px 18px; border-radius: 8px; background: rgba(0,0,0,0.2); color: white; transition: 0.3s; border: 1px solid rgba(255,255,255,0.2); }
        .btn-nav:hover { background: rgba(0,0,0,0.4); }

        .container { padding: 30px; max-width: 1400px; margin: auto; }
        .layout { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; align-items: start; }
        
        /* TARJETAS Y FORMULARIOS */
        .card { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #eee; }
        .form-cliente { display: flex; flex-direction: column; gap: 15px; max-width: 500px; margin: 40px auto; }
        .form-cliente input { padding: 15px; border: 1px solid #ccc; border-radius: 10px; font-size: 1rem; outline-color: #E53935; }
        .btn-crear { background: #E53935; color: white; border: none; padding: 15px; border-radius: 10px; font-weight: bold; font-size: 1.1rem; cursor: pointer; }

        /* MENÚ */
        .menu-titulo { background: #FF8C00; color: white; padding: 12px 20px; border-radius: 12px; font-weight: 900; text-transform: uppercase; margin-bottom: 15px; }
        .plato-item { background: #fafafa; border: 1px solid #ddd; padding: 12px; border-radius: 12px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .btn-add { background: #32CD32; color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; }

        /* TICKET / DETALLE */
        .ticket { background: #fff9c4; padding: 20px; border-radius: 15px; border: 3px dashed #fbc02d; position: sticky; top: 20px; }
        .ticket-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(0,0,0,0.05); }
        .ticket-nota { color: #d32f2f; font-size: 0.85rem; font-weight: bold; }
        
        /* EL BOTÓN QUE PEDISTE (Cuadrado rojo, X gris) */
        .btn-quitar { 
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; 
            background: #E53935; color: #ccc; 
            text-decoration: none; font-weight: bold; font-size: 1.2rem; 
            border-radius: 6px; border: none; transition: 0.2s;
        }
        .btn-quitar:hover { background: #b71c1c; color: #fff; }

        .total { font-size: 1.6rem; font-weight: 900; color: #014421; text-align: right; margin-top: 15px; border-top: 2px solid #fbc02d; padding-top: 10px; }
        .btn-enviar { background: #007BFF; color: white; text-decoration: none; display: block; text-align: center; padding: 15px; border-radius: 10px; font-size: 1.2rem; font-weight: 900; margin-top: 20px; box-shadow: 0 4px 0 #0056b3; }
    </style>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.6rem; font-weight: 800;">🛵 <?php echo $delivery_id == 0 ? "Nuevo Envío" : "Pedido #" . $delivery_id; ?></span>
        <?php if ($delivery_id == 0): ?>
            <a href="delivery.php" class="btn-nav">← Volver al Tablero</a>
        <?php else: ?>
            <a href="parallevar.php?cancelar_id=<?php echo $delivery_id; ?>" class="btn-nav" onclick="return confirm('¿Borrar este pedido por completo?');">❌ Cancelar y Borrar</a>
        <?php endif; ?>
    </div>

    <div class="container">
        <?php if ($delivery_id == 0): ?>
            <div class="card">
                <h2 style="text-align: center; color: #E53935; margin-top: 0;">Datos del Cliente</h2>
                <form method="POST" class="form-cliente">
                    <input type="text" name="nombre" placeholder="Nombre del Cliente" required autofocus>
                    <input type="text" name="telefono" placeholder="Teléfono / WhatsApp">
                    <input type="text" name="direccion" placeholder="Dirección de Envío">
                    <button type="submit" name="crear_cliente" class="btn-crear">Continuar al Menú ➡️</button>
                </form>
            </div>
        <?php else: ?>
            <div class="layout">
                <div class="card">
                    <div style="background: #fdfce0; padding: 15px; border-radius: 12px; margin-bottom: 20px; border-left: 5px solid #FF8C00;">
                        <strong>👤 Cliente:</strong> <?php echo htmlspecialchars($cliente['cliente']); ?> | 
                        <strong>📍 Dirección:</strong> <?php echo htmlspecialchars($cliente['direccion']); ?>
                    </div>

                    <?php 
                    $sql_menu = "SELECT * FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion ASC, nombre ASC";
                    $res_menu = $conn->query($sql_menu);
                    $menu_agrupado = [];
                    while($row = $res_menu->fetch_assoc()) { $menu_agrupado[$row['seccion']][] = $row; }
                    
                    foreach($menu_agrupado as $seccion => $platos): ?>
                        <div class="menu-titulo">📁 <?php echo htmlspecialchars($seccion); ?></div>
                        <?php foreach($platos as $plato): ?>
                            <div class="plato-item">
                                <strong><?php echo htmlspecialchars($plato['nombre']); ?></strong>
                                <form method="POST" style="display:flex; gap:10px; align-items:center;">
                                    <span style="font-weight:900;">$<?php echo number_format($plato['precio'], 0, ',', '.'); ?></span>
                                    <input type="hidden" name="menu_id" value="<?php echo $plato['id']; ?>">
                                    <input type="text" name="notas" placeholder="Notas..." style="padding:6px; border-radius:5px; border:1px solid #ddd; width:120px;">
                                    <button type="submit" name="agregar_plato" class="btn-add">➕</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

<div class="ticket">
                    <h3 style="margin-top:0;">🧾 Detalle del Pedido</h3>
                    
                    <?php if (isset($_GET['aviso_stock'])): ?>
                        <div style="background: #FFF3E0; color: #E65100; padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #FFE0B2; font-weight: bold; text-align: center; font-size: 0.95rem;">
                            ⚠️ <strong>Plato agregado</strong>, pero según el sistema no hay stock suficiente. ¡Verifica!
                        </div>
                    <?php endif; ?>
                    <?php 
                    $sql_cuenta = "SELECT p.id, m.nombre, p.precio_al_momento, p.notas FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.delivery_id = $delivery_id";
                    $res_cuenta = $conn->query($sql_cuenta);
                    
                    // --- CARGAR DESCUENTOS PARA EL SELECT ---
                    $descuentos_db = $conn->query("SELECT * FROM descuentos_config WHERE restaurant_id = $mi_restaurant_id");
                    $opciones_desc = "";
                    while($d = $descuentos_db->fetch_assoc()) {
                        $val = $d['id'] . '|' . $d['porcentaje'] . '|' . $d['nombre'];
                        $opciones_desc .= "<option value=\"$val\">{$d['nombre']} (-{$d['porcentaje']}%)</option>";
                    }

                    $total = 0; $tiene = false;
                    while($item = $res_cuenta->fetch_assoc()):
                        $total += $item['precio_al_momento']; $tiene = true;
                    ?>
                        <div class="ticket-item" style="flex-direction: column; gap: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                                <div style="flex:1">
                                    <strong><?php echo htmlspecialchars($item['nombre']); ?></strong>
                                    <?php if($item['notas']): ?><br><span class="ticket-nota">» <?php echo htmlspecialchars($item['notas']); ?></span><?php endif; ?>
                                </div>
                                <div style="font-weight:bold;">
                                    $<?php echo number_format($item['precio_al_momento'], 0, ',', '.'); ?>
                                </div>
                            </div>

                            <div style="display: flex; justify-content: flex-end; align-items: center; gap: 10px; border-top: 1px dashed #eee; padding-top: 8px; width: 100%;">
                                <?php if(!empty($opciones_desc)): ?>
                                <form method="POST" style="display: flex; gap: 5px; align-items: center; margin: 0;">
                                    <input type="hidden" name="id_pedido" value="<?php echo $item['id']; ?>">
                                    <select name="descuento_data" style="padding: 4px; border-radius: 6px; font-size: 0.85rem; border: 1px solid #ccc; background: white;" required>
                                        <option value="">Aplicar Descuento...</option>
                                        <?php echo $opciones_desc; ?>
                                    </select>
                                    <button type="submit" name="aplicar_descuento" style="background: #FF8C00; color: white; border: none; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: bold;">✔</button>
                                </form>
                                <?php endif; ?>
                                
                                <a href="parallevar.php?id=<?php echo $delivery_id; ?>&quitar=<?php echo $item['id']; ?>" class="btn-quitar" onclick="return confirm('¿Quitar este plato?');">×</a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                    
                    <div class="total">Total: $<?php echo number_format($total, 0, ',', '.'); ?></div>
                    
                    <?php if ($tiene): ?>
                        <a href="delivery.php" class="btn-enviar">✅ MANDAR A COCINA</a>
                        
                        <a href="imprimir_ticket.php?delivery_id=<?php echo $delivery_id; ?>" target="_blank" class="btn-enviar" style="background: #333; box-shadow: 0 4px 0 #222; margin-top: 10px;">
                            🖨️ Imprimir Boleta
                        </a>
                        
                    <?php else: ?>
                        <p style="text-align:center; color:#888; font-style:italic; margin-top:20px;">Agregue platos al pedido...</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>