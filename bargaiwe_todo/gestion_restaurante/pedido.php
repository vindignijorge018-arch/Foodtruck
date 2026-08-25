<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';
// 1 = Inventario descontando normal | 0 = Inventario CONGELADO (Stock infinito)
$inventario_activo = 0;


// --- 0. INICIALIZACIÓN (CRÍTICO PARA EVITAR ERRORES) ---
// Capturamos los IDs de la URL para saber si es Mesa o Delivery
$delivery_id = isset($_GET['delivery_id']) ? (int)$_GET['delivery_id'] : 0;
$mesa_id     = isset($_GET['mesa_id'])     ? (int)$_GET['mesa_id']     : 0;

// Definimos los parámetros para las redirecciones automáticas
$params = ($delivery_id > 0) ? "delivery_id=$delivery_id" : "mesa_id=$mesa_id";


// --- LÓGICA: AGREGAR PLATO (VERSIÓN CON ADVERTENCIA) ---
// --- 1. LÓGICA AGREGAR PLATO (INVENTARIO PERMISIVO) ---
if (isset($_POST['agregar_plato'])) {
    $menu_id = (int)$_POST['menu_id'];
    $notas = $conn->real_escape_string($_POST['notas']);

    // Validar stock (Avisa, pero NO bloquea)
    $falta_stock = false;
    $res_receta = $conn->query("SELECT r.ingrediente_id, r.cantidad_usada, i.stock_actual FROM recetas r JOIN ingredientes i ON r.ingrediente_id = i.id WHERE r.menu_id = $menu_id");
    $receta = [];
    while ($row = $res_receta->fetch_assoc()) {
        $receta[] = $row;
        if ($row['stock_actual'] < $row['cantidad_usada']) { 
            $falta_stock = true; // Marcamos que falta stock en sistema, pero dejamos continuar
        }
    }

    $res_precio = $conn->query("SELECT precio FROM menu WHERE id = $menu_id");
    $precio = $res_precio->fetch_assoc()['precio'];

    $columna = ($delivery_id > 0) ? "delivery_id" : "mesa_id";
    $valor_id = ($delivery_id > 0) ? $delivery_id : $mesa_id;
    $tipo = ($delivery_id > 0) ? 'delivery' : 'mesa';

    // Insertamos el plato en ESTADO 0 (Carrito/Rojo, aún no enviado a cocina)
    $conn->query("INSERT INTO pedidos ($columna, menu_id, estado, notas, precio_al_momento, tipo_pedido, restaurant_id) 
                  VALUES ($valor_id, $menu_id, 0, '$notas', $precio, '$tipo', $mi_restaurant_id)");

    // Descontamos el stock (puede quedar en números negativos, lo que ayuda a cuadrar la caja)
    foreach ($receta as $ing) {
        $conn->query("UPDATE ingredientes SET stock_actual = stock_actual - {$ing['cantidad_usada']} WHERE id = {$ing['ingrediente_id']}");
    }

    if ($falta_stock) {
        header("Location: pedido.php?$params&aviso_stock=1");
    } else {
        header("Location: pedido.php?$params");
    }
    exit();
}

// --- 2. QUITAR PLATO (BLINDADO Y SÚPER RÁPIDO) ---
if (isset($_GET['quitar'])) {
    $id_pedido = (int)$_GET['quitar'];
    
    // Buscamos el plato para poder devolver el stock
    $res_ped = $conn->query("SELECT menu_id FROM pedidos WHERE id = $id_pedido");
    
    if ($res_ped && $res_ped->num_rows > 0) {
        $row = $res_ped->fetch_assoc();
        $menu_id_del = $row['menu_id'];
        
        // Borramos el plato directamente por su ID (cero fallos)
        $conn->query("DELETE FROM pedidos WHERE id = $id_pedido");

        // Devolvemos el stock al almacén
        $res_receta = $conn->query("SELECT ingrediente_id, cantidad_usada FROM recetas WHERE menu_id = $menu_id_del");
        if ($res_receta) {
            while ($r = $res_receta->fetch_assoc()) {
                $conn->query("UPDATE ingredientes SET stock_actual = stock_actual + {$r['cantidad_usada']} WHERE id = {$r['ingrediente_id']}");
            }
        }
    }
    
    // Redirección inmediata a la misma mesa
    $url_regreso = ($delivery_id > 0) ? "pedido.php?delivery_id=$delivery_id" : "pedido.php?mesa_id=$mesa_id";
    header("Location: " . $url_regreso);
    exit();
}

// --- 3. TÍTULO DE PANTALLA ---
$titulo_pantalla = "";
if ($mesa_id > 0) {
    $res_mesa = $conn->query("SELECT numero_mesa FROM mesas WHERE id = $mesa_id");
    $row_m = $res_mesa->fetch_assoc();
    $titulo_pantalla = "Mesa " . ($row_m['numero_mesa'] ?? $mesa_id);
} else {
    $titulo_pantalla = "Delivery #" . $delivery_id;
}

// --- 4. LIBERAR MESA VACÍA (BLINDADO) --- 
 if (isset($_POST['liberar_vacia']) && $mesa_id > 0) { 
     // 1. Ponemos la mesa en color verde (libre)
     $conn->query("UPDATE mesas SET estado = 0 WHERE id = $mesa_id"); 
     // 2. Por seguridad, borramos cualquier pedido que haya quedado atascado sin cobrarse
     $conn->query("DELETE FROM pedidos WHERE mesa_id = $mesa_id AND estado != 3");
     
     header("Location: mesas.php"); 
     exit(); 
 }
// --- 4.5 ENVIAR A COCINA (De Carrito a Cocina) ---
 if (isset($_POST['enviar_cocina'])) {
     $m_id = (int)$_POST['mesa_id_oculto'];
     $d_id = (int)$_POST['delivery_id_oculto'];
     $filtro_donde = ($d_id > 0) ? "delivery_id = $d_id" : "mesa_id = $m_id";
     
     // Actualizamos de 🔴 Carrito (0) a 🟢 En cocina (1)
     $conn->query("UPDATE pedidos SET estado = 1 WHERE $filtro_donde AND estado = 0");
     
     $url_regreso = ($d_id > 0) ? 'delivery.php' : 'mesas.php';
     header("Location: " . $url_regreso);
     exit();
 }

 // --- 5. APLICAR DESCUENTO A PLATO ESPECÍFICO --- 
 if (isset($_POST['aplicar_descuento'])) {
     $id_pedido = (int)$_POST['id_pedido'];
     $desc_info = explode('|', $_POST['descuento_data']); // Separamos id|porcentaje|nombre
     
     if(count($desc_info) == 3) {
         $porcentaje = (float)$desc_info[1];
         $nombre_desc = $conn->real_escape_string($desc_info[2]);
         
         // 1. Buscamos el precio original del plato en el menú
         $res_base = $conn->query("SELECT m.precio, p.notas FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.id = $id_pedido");
         if($row_base = $res_base->fetch_assoc()) {
             // 2. Calculamos el nuevo precio
             $precio_nuevo = $row_base['precio'] - ($row_base['precio'] * ($porcentaje / 100));
             // 3. Añadimos una nota automática para que el chef/cajero sepa por qué bajó el precio
             $nueva_nota = $row_base['notas'] . " [-" . $porcentaje . "% " . $nombre_desc . "]";
             
             // 4. Actualizamos la fila en la base de datos
             $conn->query("UPDATE pedidos SET precio_al_momento = $precio_nuevo, notas = '$nueva_nota' WHERE id = $id_pedido AND restaurant_id = $mi_restaurant_id");
         }
     }
     header("Location: pedido.php?$params");
     exit();
 }

// --- 6. MARCAR PLATO COMO ENTREGADO (DE AMARILLO A VERDE) ---
 if (isset($_POST['marcar_entregado'])) {
     $id_pedido = (int)$_POST['id_pedido'];
     // Pasamos de estado 2 (Listo en cocina) a estado 4 (Entregado al cliente)
     $conn->query("UPDATE pedidos SET estado = 4 WHERE id = $id_pedido AND restaurant_id = $mi_restaurant_id");
     header("Location: pedido.php?$params");
     exit();
 }
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>bargaiwe - <?php echo $titulo_pantalla; ?></title>
    <style>
        /* --- MODO OSCURO AUTOMÁTICO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; }
        body.modo-oscuro .card { background: #1e1e1e !important; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        body.modo-oscuro .ticket { background: #1e1e1e !important; border-color: #fbc02d; }
        body.modo-oscuro .ticket h3 { color: #fbc02d; border-bottom-color: #fbc02d; }
        body.modo-oscuro .total { color: #32CD32; border-top-color: #fbc02d; }
        
        /* Ajuste de carpetas y platos */
        body.modo-oscuro .menu-titulo { background: #2a2a2a; border: 1px solid #FF8C00; color: #FF8C00; }
        body.modo-oscuro .plato-item { background: #222; border-color: #444; }
        body.modo-oscuro .plato-info h4 { color: #ddd; }
        body.modo-oscuro .form-add input[type="text"] { background: #333; color: white; border-color: #555; }
        
        /* Ajuste de la cuenta/ticket */
        body.modo-oscuro .ticket-item { border-bottom-color: #444; }
        body.modo-oscuro .ticket-item-nota { background: #4a1919; color: #ff8a80; }
        body.modo-oscuro select { background: #333 !important; color: white; border-color: #555 !important; }
        body {
            background-color: #FDFCF0;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            color: #333;
        }

        .nav-hub {
            background: #014421;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .nav-hub a {
            text-decoration: none;
            font-weight: bold;
            padding: 8px 15px;
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.2);
            color: white;
            transition: 0.3s;
        }

        .nav-hub a:hover {
            background: rgba(0, 0, 0, 0.4);
        }

        .container {
            padding: 30px;
            max-width: 1400px;
            margin: auto;
        }

        .layout-pedido {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            align-items: start;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .menu-seccion {
            margin-bottom: 15px;
        }

        .menu-titulo {
            background: #FF8C00;
            color: white;
            padding: 15px 20px;
            border-radius: 12px;
            font-weight: 900;
            text-transform: uppercase;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .platos-container {
            display: none;
            padding-top: 10px;
        }

        .plato-item {
            background: #fafafa;
            border: 1px solid #ddd;
            padding: 10px 15px;
            border-radius: 10px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .plato-info h4 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 900;
        }

        .form-add {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .plato-precio {
            font-weight: 900;
            color: #014421;
            font-size: 1.15rem;
        }

        .form-add input[type="text"] {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 6px;
            width: 150px;
        }

        .btn-add {
            background: #32CD32;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
        }

        .ticket {
            background: #fff9c4;
            padding: 20px;
            border-radius: 15px;
            border: 3px dashed #fbc02d;
            position: sticky;
            top: 20px;
        }

        .ticket h3 {
            margin-top: 0;
            border-bottom: 2px solid #fbc02d;
            padding-bottom: 10px;
            font-weight: 900;
        }

        .ticket-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .ticket-item-nota {
            color: #d32f2f;
            font-size: 0.8rem;
            font-weight: bold;
            background: #ffebee;
            padding: 2px 6px;
            border-radius: 4px;
        }

        .btn-del {
            color: #d32f2f;
            text-decoration: none;
            font-weight: bold;
            font-size: 1.2rem;
            margin-left: 10px;
        }

        .total {
            font-size: 1.5rem;
            font-weight: 900;
            color: #014421;
            margin-top: 15px;
            text-align: right;
            border-top: 2px solid #fbc02d;
            padding-top: 10px;
        }

        .btn-ir-caja {
            background: #007BFF;
            color: white;
            text-decoration: none;
            display: block;
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            font-size: 1.2rem;
            font-weight: 900;
            margin-top: 20px;
        }
    </style>
</head>

<body>

    <div class="nav-hub">
        <span style="font-size: 1.6rem; font-weight: 800;">📝 <?php echo $titulo_pantalla; ?></span>
        <a href="<?php echo ($delivery_id > 0) ? 'delivery.php' : 'mesas.php'; ?>">← Volver</a>
    </div>

    <div class="container">
        <div class="layout-pedido">

            <div class="card">
                <?php if (isset($_GET['aviso_stock'])): ?>
                    <div style="background: #FFF3E0; color: #E65100; padding: 12px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #FFE0B2; font-weight: bold; text-align: center; font-size: 0.95rem;">
                        ⚠️ <strong>El plato se agregó al pedido</strong>, pero según el sistema no hay stock suficiente. ¡Verifica físicamente!
                    </div>
                <?php endif; ?>

                <?php
                // --- Lógica de Menú (Agrupado por sección) ---
                $sql_menu = "SELECT * FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion ASC, nombre ASC";
                $res_menu = $conn->query($sql_menu);
                $menu_agrupado = [];
                while ($row = $res_menu->fetch_assoc()) {
                    $menu_agrupado[$row['seccion']][] = $row;
                }

                foreach ($menu_agrupado as $seccion => $platos):
                    $id_carpeta = 'cat_' . md5($seccion);
                ?>
                    <div class="menu-seccion">
                        <div class="menu-titulo" onclick="toggleCarpeta('<?php echo $id_carpeta; ?>')">
                            <div>📁 <?php echo htmlspecialchars($seccion); ?></div>
                            <span>VER ▼</span>
                        </div>
                        <div class="platos-container" id="<?php echo $id_carpeta; ?>">
                            <?php foreach ($platos as $plato): ?>
                                <div class="plato-item">
                                    <div class="plato-info">
                                        <h4><?php echo htmlspecialchars($plato['nombre']); ?></h4>
                                    </div>
                                    <form method="POST" class="form-add">
                                        <span class="plato-precio">$<?php echo number_format($plato['precio'], 0, ',', '.'); ?></span>
                                        <input type="hidden" name="menu_id" value="<?php echo $plato['id']; ?>">
                                        <input type="text" name="notas" placeholder="Notas...">
                                        <button type="submit" name="agregar_plato" class="btn-add">➕</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ticket">
                <h3>🧾 Cuenta: <?php echo $titulo_pantalla; ?></h3>
                <?php
                 // ESTA ES LA LÍNEA QUE SE HABÍA BORRADO
                 $filtro_ticket = ($delivery_id > 0) ? "p.delivery_id = $delivery_id" : "p.mesa_id = $mesa_id"; 
                 $sql_cuenta = "SELECT p.id, m.nombre, p.precio_al_momento, p.notas, p.estado
                                FROM pedidos p JOIN menu m ON p.menu_id = m.id  
                                WHERE $filtro_ticket AND p.estado != 3"; 
                 $res_cuenta = $conn->query($sql_cuenta); 
                 
                 // Descuentos
                 $descuentos_db = $conn->query("SELECT * FROM descuentos_config WHERE restaurant_id = $mi_restaurant_id");
                 $opciones_desc = "";
                 while($d = $descuentos_db->fetch_assoc()) {
                     $val = $d['id'] . '|' . $d['porcentaje'] . '|' . $d['nombre'];
                     $opciones_desc .= "<option value=\"$val\">{$d['nombre']} (-{$d['porcentaje']}%)</option>";
                 }

                 $total = 0; 
                 $tiene_items = false; 
                 $faltan_entregar = false; // NUESTRO DETECTOR

                 // AQUÍ EMPIEZA LA LÓGICA DE TU SEMÁFORO (EN EL LUGAR CORRECTO)
                 while ($item = $res_cuenta->fetch_assoc()): 
                    // Si el plato está en cocina (1) o listo (2), marcamos la alerta
                     if ($item['estado'] == 1 || $item['estado'] == 2) {
                         $faltan_entregar = true;
                     }
                     $total += $item['precio_al_momento']; 
                     $tiene_items = true; 
                 ?> 
                     <div class="ticket-item" style="flex-direction: column; gap: 8px;"> 
                         <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                             <?php 
                                 if ($item['estado'] == 0) {
                                     $color_estado = '#E53935'; $titulo_estado = '🔴 Carrito (Falta enviar)';
                                 } elseif ($item['estado'] == 1) {
                                     $color_estado = '#FF8C00'; $titulo_estado = '👨‍🍳 Cocinando...'; 
                                 } elseif ($item['estado'] == 2) {
                                     $color_estado = '#FFC107'; $titulo_estado = '🔔 ¡LISTO! Esperando entrega'; 
                                 } elseif ($item['estado'] == 4) {
                                     $color_estado = '#32CD32'; $titulo_estado = '✅ Entregado'; 
                                 } else {
                                     $color_estado = '#ccc'; $titulo_estado = 'Desconocido';
                                 }
                             ?>
                             <div style="flex: 1; display: flex; align-items: center; gap: 10px;"> 
                                 <div title="<?php echo $titulo_estado; ?>" style="width: 14px; height: 14px; border-radius: 3px; background-color: <?php echo $color_estado; ?>; flex-shrink: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></div>
                                 <div>
                                     <strong><?php echo htmlspecialchars($item['nombre']); ?></strong> 
                                     <?php if (!empty($item['notas'])): ?> 
                                         <br><span class="ticket-item-nota"><?php echo htmlspecialchars($item['notas']); ?></span> 
                                     <?php endif; ?> 
                                 </div>
                             </div>
                             
                             <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px;">
                                 <div style="font-weight: 900; color: #014421; font-size: 1.1rem;"> 
                                     $<?php echo number_format($item['precio_al_momento'], 0, ',', '.'); ?> 
                                 </div>
                                 
                                 <?php if ($item['estado'] == 2): ?>
                                     <form method="POST" style="margin: 0;">
                                         <input type="hidden" name="id_pedido" value="<?php echo $item['id']; ?>">
                                         <button type="submit" name="marcar_entregado" style="background: #32CD32; color: white; border: none; padding: 4px 8px; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 0.8rem; box-shadow: 0 2px 5px rgba(50,205,50,0.4); transition: 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">✔️ Entregar</button>
                                     </form>
                                 <?php endif; ?>
                             </div>
                         </div>
                         
                         <div style="display: flex; justify-content: flex-end; align-items: center; gap: 15px; border-top: 1px dashed #eee; padding-top: 8px;">
                             
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
                             
                             <a href="pedido.php?<?php echo $params; ?>&quitar=<?php echo $item['id']; ?>"  
                                class="btn-del"  
                                title="Quitar plato"
                                style="color: #d32f2f; text-decoration: none; font-weight: bold; font-size: 1.6rem; line-height: 1; padding: 2px 8px; border-radius: 6px; transition: 0.2s;"
                                onmouseover="this.style.background='#ffebee'"
                                onmouseout="this.style.background='transparent'"> 
                                × 
                             </a>
                         </div>
                     </div> 
                 <?php endwhile; ?>
                 <?php if ($faltan_entregar): ?>
                     <div style="background: #FFF3E0; color: #E65100; padding: 12px; border-radius: 8px; margin-top: 15px; border: 1px dashed #FFB74D; font-size: 0.95rem; font-weight: bold; text-align: center;">
                         ⚠️ Faltan platos por entregar (Están en cocina o listos).
                     </div>
                 <?php endif; ?>

                <div class="total">Total: $<?php echo number_format($total, 0, ',', '.'); ?></div>

                <?php if ($tiene_items): ?>
                    <div style="display: flex; gap: 10px; margin-top: 25px;">
                        
                        <form method="POST" style="flex: 1; margin: 0;">
                            <input type="hidden" name="mesa_id_oculto" value="<?php echo $mesa_id; ?>">
                            <input type="hidden" name="delivery_id_oculto" value="<?php echo $delivery_id; ?>">
                            
                            <button type="submit" name="enviar_cocina" 
                               style="width: 100%; height: 100%; background: #32CD32; color: white; border: none; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 15px 5px; border-radius: 10px; font-weight: 900; line-height: 1.2; box-shadow: 0 4px 10px rgba(50, 205, 50, 0.3); cursor: pointer; transition: 0.2s;"
                               onmouseover="this.style.transform='scale(1.03)'"
                               onmouseout="this.style.transform='scale(1)'">
                                <span style="font-size: 1.4rem; margin-bottom: 5px;">👨‍🍳</span>
                                <span style="font-size: 0.95rem; text-align: center;">Enviar a Cocina</span>
                            </button>
                        </form>

                        <a href="<?php echo ($delivery_id > 0) ? 'pago_delivery.php?id=' . $delivery_id : 'pago.php?mesa_id=' . $mesa_id; ?>" 
                           style="flex: 1; background: #007BFF; color: white; text-decoration: none; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 15px 5px; border-radius: 10px; font-weight: 900; line-height: 1.2; box-shadow: 0 4px 10px rgba(0, 123, 255, 0.3); transition: 0.2s;">
                            <span style="font-size: 1.4rem; margin-bottom: 5px;">💳</span>
                            <span style="font-size: 0.95rem; text-align: center;">Pagar Cuenta</span>
                        </a>
                        
                    </div>

                <?php elseif ($mesa_id > 0): ?>
                    <form method="POST" style="margin-top: 20px;">
                        <button type="submit" name="liberar_vacia" 
                                style="background: #E53935; color: white; border: none; padding: 15px; width: 100%; border-radius: 10px; font-size: 1.2rem; font-weight: 900; cursor: pointer; box-shadow: 0 4px 10px rgba(229, 57, 53, 0.3); transition: 0.2s;"
                                onmouseover="this.style.transform='scale(1.03)'"
                                onmouseout="this.style.transform='scale(1)'">
                            🔄 Liberar Mesa Vacía
                        </button>
                    </form>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <script>
        function toggleCarpeta(id) {
            let carpeta = document.getElementById(id);
            if (carpeta.style.display === 'block') {
                carpeta.style.display = 'none';
                sessionStorage.removeItem('abierto_' + id); // Borramos de memoria si se cierra
            } else {
                carpeta.style.display = 'block';
                sessionStorage.setItem('abierto_' + id, 'true'); // Guardamos en memoria si se abre
            }
        }

        // Al cargar la página de nuevo (por ejemplo, al agregar plato)
        window.addEventListener('DOMContentLoaded', (event) => {
            let contenedores = document.querySelectorAll('.platos-container');
            contenedores.forEach(contenedor => {
                // Si la memoria dice que estaba abierta, la forzamos a mostrarse
                if (sessionStorage.getItem('abierto_' + contenedor.id) === 'true') {
                    contenedor.style.display = 'block';
                }
            });
        });
    </script>
    <script>
        // Sincroniza el modo oscuro desde mesas.php
        document.addEventListener('DOMContentLoaded', (event) => {
            if (localStorage.getItem('temaMesas') === 'oscuro') {
                document.body.classList.add('modo-oscuro');
            }
        });
    </script>
</body>

</html>