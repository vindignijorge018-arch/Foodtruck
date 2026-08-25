<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';
// 🔥 1. Conectamos la configuración maestro
include 'config_sistema.php'; 

// 🔥 2. Lógica para guardar el clic del botón en la BD
if (isset($_POST['toggle_inventario'])) {
    $nuevo_estado = (int)$_POST['toggle_inventario'];
    $conn->query("UPDATE config_restaurante SET inventario_activo = $nuevo_estado WHERE restaurant_id = $mi_restaurant_id");
    header("Location: precios.php?exito_config=1"); 
    exit();
}


// --- LÓGICAS DE COMPRAS E INVENTARIO ---

if (isset($_POST['crear_ingrediente'])) {
    $nombre = $conn->real_escape_string($_POST['nombre']);
    $unidad = $conn->real_escape_string($_POST['unidad']);
    $conn->query("INSERT INTO ingredientes (restaurant_id, nombre, cantidad_comprada, unidad, precio_compra, stock_actual) VALUES ($mi_restaurant_id, '$nombre', 1, '$unidad', 0, 0)");
    header("Location: precios.php"); exit();
}

if (isset($_POST['registrar_compra'])) {
    $id_ing = (int)$_POST['id_ing'];
    $cant_comprada = (float)$_POST['cant_comprada'];
    $precio_pagado = (int)$_POST['precio_pagado_real']; 
    
    // Suma stock y actualiza los "valores por defecto" para la próxima vez
    $conn->query("UPDATE ingredientes SET 
                  cantidad_comprada = $cant_comprada, 
                  precio_compra = $precio_pagado, 
                  stock_actual = stock_actual + $cant_comprada 
                  WHERE id = $id_ing");
                  
    $res_nombre = $conn->query("SELECT nombre FROM ingredientes WHERE id = $id_ing");
    $nombre_ing = $res_nombre->fetch_assoc()['nombre'];
    
    // Se anota el gasto para stats.php
    $concepto = "Compra de Insumo: " . $nombre_ing . " (" . $cant_comprada . ")";
    $conn->query("INSERT INTO gastos (restaurant_id, concepto, monto) VALUES ($mi_restaurant_id, '$concepto', $precio_pagado)");
    
    // Volvemos a la página con un mensaje de ÉXITO
    header("Location: precios.php?exito_compra=1"); exit();
}

if (isset($_GET['del_ing'])) {
    $id_ing = (int)$_GET['del_ing'];
    $conn->query("DELETE FROM ingredientes WHERE id = $id_ing AND restaurant_id = $mi_restaurant_id");
    $conn->query("DELETE FROM recetas WHERE ingrediente_id = $id_ing");
    header("Location: precios.php"); exit();
}

if (isset($_POST['ajustar_stock'])) {
    $id_ing = (int)$_POST['id_ing'];
    $stock_real = (float)$_POST['stock_real'];
    $conn->query("UPDATE ingredientes SET stock_actual = $stock_real WHERE id = $id_ing");
    header("Location: precios.php?exito_ajuste=1"); exit();
}

// --- LÓGICAS DE RECETAS ---
if (isset($_POST['agregar_a_receta'])) {
    $menu_id = (int)$_POST['menu_id'];
    $ingrediente_id = (int)$_POST['ingrediente_id'];
    $cantidad_usada = (float)$_POST['cantidad_usada'];
    $conn->query("INSERT INTO recetas (menu_id, ingrediente_id, cantidad_usada) VALUES ($menu_id, $ingrediente_id, $cantidad_usada)");
    header("Location: precios.php"); exit();
}
if (isset($_GET['del_receta'])) {
    $id_receta = (int)$_GET['del_receta'];
    $conn->query("DELETE FROM recetas WHERE id = $id_receta");
    header("Location: precios.php"); exit();
}
if (isset($_POST['actualizar_precio_venta'])) {
    $menu_id = (int)$_POST['menu_id'];
    $nuevo_precio = (int)$_POST['nuevo_precio_real'];
    $conn->query("UPDATE menu SET precio = $nuevo_precio WHERE id = $menu_id");
    header("Location: precios.php"); exit();
}

// --- OBTENER DATOS PARA MOSTRAR ---
$ingredientes = [];
$res_ing = $conn->query("SELECT * FROM ingredientes WHERE restaurant_id = $mi_restaurant_id ORDER BY nombre ASC");
if($res_ing){
    while($row = $res_ing->fetch_assoc()){
        $costo_por_unidad = ($row['cantidad_comprada'] > 0) ? ($row['precio_compra'] / $row['cantidad_comprada']) : 0;
        $row['costo_unidad'] = $costo_por_unidad;
        // Condición: Menos del 20% de la compra habitual
        $row['alerta'] = ($row['stock_actual'] <= ($row['cantidad_comprada'] * 0.2) && $row['cantidad_comprada'] > 1);
        $ingredientes[$row['id']] = $row;
    }
}

$platos_agrupados = [];
$res_menu = $conn->query("SELECT id, seccion, nombre, precio FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion ASC, nombre ASC");
if($res_menu){
    while($row = $res_menu->fetch_assoc()){
        $menu_id = $row['id'];
        $seccion = $row['seccion'];
        $receta = [];
        $costo_total_plato = 0;
        
        $sql_receta = "SELECT r.id as id_receta, r.cantidad_usada, i.nombre, i.unidad, i.precio_compra, i.cantidad_comprada 
                       FROM recetas r JOIN ingredientes i ON r.ingrediente_id = i.id WHERE r.menu_id = $menu_id";
        $res_rec = $conn->query($sql_receta);
        
        if($res_rec){
            while($ing = $res_rec->fetch_assoc()){
                $costo_x_un = ($ing['cantidad_comprada'] > 0) ? ($ing['precio_compra'] / $ing['cantidad_comprada']) : 0;
                $costo_en_este_plato = $costo_x_un * $ing['cantidad_usada'];
                $costo_total_plato += $costo_en_este_plato;
                $ing['costo_calculado'] = $costo_en_este_plato;
                $receta[] = $ing;
            }
        }
        $row['receta'] = $receta;
        $row['costo_produccion'] = $costo_total_plato;
        $platos_agrupados[$seccion][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Gestión de Costos e Inventario</title>
    <style>
            /* Estilos del Interruptor Maestro */
        .caja-interruptor { background: #f8f9fa; border: 2px dashed #ccc; padding: 20px; border-radius: 10px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; }
        .btn-interruptor { padding: 12px 25px; border-radius: 8px; font-weight: bold; border: none; cursor: pointer; font-size: 1.1rem; transition: 0.3s; }
        .btn-interruptor.on { background: #32CD32; color: white; box-shadow: 0 4px 0 #228B22; }
        .btn-interruptor.off { background: #E53935; color: white; box-shadow: 0 4px 0 #B71C1C; }
        .btn-interruptor:active { transform: translateY(4px); box-shadow: none !important; }
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        .nav-hub { background: #014421; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 10px; background: #8C8C8C; color: white; transition: 0.3s; }
        .nav-hub a:hover { background: #666; }
        
        .container { padding: 30px; max-width: 1250px; margin: auto; }
        
        /* Mensajes de Alerta */
        .alerta-exito { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; border: 1px solid #c3e6cb; margin-bottom: 20px; font-weight: bold; text-align: center; }

        .seccion-caja { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 30px; border-left: 8px solid #014421; }
        .seccion-caja.inv { border-left-color: #FF8C00; }
        .seccion-caja.rec { border-left-color: #32CD32; }
        
        h3 { color: #333; margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px; font-size: 1.4rem; display: flex; align-items: center; gap: 10px;}
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 0.95rem; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #eee; vertical-align: middle; }
        th { background-color: #f9f9f9; color: #555; text-transform: uppercase; font-size: 0.85rem;}
        
        .btn-rojo { background: #ff4d4d; color: white; text-decoration: none; padding: 8px 12px; border-radius: 6px; font-weight: bold; display: flex; align-items: center; justify-content: center;}
        .btn-rojo:hover { background: #cc0000; }
        .btn-verde { background: #32CD32; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 1rem;}
        
        .form-inline { display: flex; gap: 10px; align-items: center; background: #f4f4f4; padding: 15px; border-radius: 10px; margin-bottom: 15px; border: 1px solid #ddd;}
        .form-inline input, .form-inline select { padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 1rem;}
        
        .input-mini { width: 80px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-weight: bold; text-align: center; }
        .input-precio { width: 110px; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-weight: bold; }
        
        .stock-bien { color: #333; font-weight: bold; font-size: 1.2rem; }
        .stock-alerta { color: #d32f2f; font-weight: bold; font-size: 1.2rem; }
        
        .folder { background: #f0f0f0; color: #333; padding: 15px; margin-top: 20px; border-radius: 10px; cursor: pointer; font-weight: bold; display: flex; justify-content: space-between; font-size: 1.1rem; border: 1px solid #ddd;}
        .folder:hover { background: #e2e2e2; }
        .folder-content { display: none; padding: 20px; background: white; border: 1px solid #ddd; border-top: none; border-radius: 0 0 10px 10px; }
        
        .grid-platos { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .card-plato { border: 1px solid #ddd; border-radius: 12px; padding: 15px; background: #fafafa; }
        .plato-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px dashed #ccc; padding-bottom: 10px; }
        
        .info-financiera { background: white; padding: 10px; border-radius: 8px; margin-bottom: 15px; display: flex; justify-content: space-around; text-align: center; border: 1px solid #eee; box-shadow: 0 2px 4px rgba(0,0,0,0.02);}
        .positivo { color: #32CD32; font-weight: bold; }
        .negativo { color: #ff4d4d; font-weight: bold; }
        
        .lista-receta { list-style: none; padding: 0; margin: 0 0 15px 0; font-size: 0.9rem; }
        .lista-receta li { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        /* --- MODO OSCURO AUTOMÁTICO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; }
        body.modo-oscuro .seccion-caja { background: #1e1e1e !important; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        body.modo-oscuro h3, body.modo-oscuro p { color: #ccc !important; border-bottom-color: #333; }
        body.modo-oscuro .caja-interruptor { background: #2a2a2a; border-color: #555; }
        body.modo-oscuro table th { background-color: #2a2a2a; color: #aaa; border-bottom-color: #444; }
        body.modo-oscuro table td { border-bottom-color: #444; }
        body.modo-oscuro .form-inline { background: #2a2a2a; border-color: #444; }
        body.modo-oscuro input, body.modo-oscuro select { background: #333; color: white; border-color: #555; }
        body.modo-oscuro .folder { background: #2a2a2a; border-color: #444; color: #ccc; }
        body.modo-oscuro .folder-content { background: #1e1e1e; border-color: #444; }
        body.modo-oscuro .card-plato { background: #2a2a2a; border-color: #444; }
        body.modo-oscuro .plato-header { border-bottom-color: #444; }
        body.modo-oscuro .info-financiera { background: #111; border-color: #333; }
        body.modo-oscuro .lista-receta li { border-bottom-color: #444; }
        /* Tonos específicos para el modo oscuro en tablas */
        body.modo-oscuro th[style*="e3f2fd"], body.modo-oscuro td[style*="e3f2fd"] { background: #0d2b42 !important; }
        body.modo-oscuro th[style*="fff3e0"], body.modo-oscuro td[style*="fff3e0"] { background: #3e280d !important; }
    </style>
    <script>
        function toggleFolder(id) {
            var el = document.getElementById(id);
            el.style.display = (el.style.display === 'block') ? 'none' : 'block';
        }

        function actualizarPaso(selectElement) {
            let option = selectElement.options[selectElement.selectedIndex];
            let unidad = option.getAttribute('data-unidad');
            let form = selectElement.closest('form');
            let inputCantidad = form.querySelector('.input-cant');
            let labelUnidad = form.querySelector('.label-unidad');
            
            if(unidad) { labelUnidad.textContent = unidad; } else { labelUnidad.textContent = ""; }
            
            if (unidad === 'unidades') { inputCantidad.step = "1"; inputCantidad.value = "1"; }
            else if (unidad === 'gr' || unidad === 'ml') { inputCantidad.step = "50"; inputCantidad.value = "50"; }
            else if (unidad === 'kg' || unidad === 'L') { inputCantidad.step = "0.1"; inputCantidad.value = "0.1"; }
            else { inputCantidad.step = "0.01"; inputCantidad.value = ""; }
        }

        function formatearMoneda(inputVis) {
            let valorLimpio = inputVis.value.replace(/[^0-9]/g, '');
            if (valorLimpio === '') {
                inputVis.value = ''; inputVis.nextElementSibling.value = ''; return;
            }
            inputVis.nextElementSibling.value = valorLimpio;
            inputVis.value = new Intl.NumberFormat(navigator.language).format(valorLimpio);
        }

        window.onload = function() {
            let inputsPrecio = document.querySelectorAll('.input-precio-formato');
            inputsPrecio.forEach(function(input) {
                if(input.value !== '' && input.value != '0') {
                    input.value = new Intl.NumberFormat(navigator.language).format(input.value.replace(/[^0-9]/g, ''));
                } else if(input.value == '0') {
                    input.value = ''; // Si el precio es 0 (nuevo), dejamos limpio para que escriba
                }
            });
        };
    </script>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.6rem; font-weight: 800;">bargaiwe - Control Maestro</span>
        <a href="mesas.php">← Volver a Mesas</a>
    </div>

    <div class="container">
        
        <?php if(isset($_GET['exito_compra'])): ?>
            <div class="alerta-exito">✅ ¡Compra registrada! Stock sumado y gasto enviado a estadísticas.</div>
        <?php endif; ?>
        <?php if(isset($_GET['exito_ajuste'])): ?>
            <div class="alerta-exito">✅ Inventario físico ajustado correctamente.</div>
        <?php endif; ?>

        <div class="seccion-caja">
            <h3><span style="font-size:1.8rem;">1.</span> Catálogo y Compras</h3>
            <p style="color: #666; font-size: 0.95rem;">Crea los insumos que utiliza tu restaurante. Usa los controles para registrar compras y reabastecer rápidamente.</p>
            
            <form method="POST" class="form-inline">
                <input type="text" name="nombre" placeholder="Crear Nuevo Insumo (Ej: Papas)" required style="flex-grow: 1;">
                <select name="unidad" required>
                    <option value="gr">Gramos (gr)</option>
                    <option value="kg">Kilos (kg)</option>
                    <option value="ml">Mililitros (ml)</option>
                    <option value="L">Litros (L)</option>
                    <option value="unidades">Unidades (un)</option>
                </select>
                <button type="submit" name="crear_ingrediente" class="btn-verde">+ Crear en Catálogo</button>
            </form>

            <table>
                <tr>
                    <th>Insumo</th>
                    <th>Último Costo Unitario</th>
                    <th style="background: #e3f2fd; border-radius: 8px 8px 0 0; text-align:center;">🛒 Datos de Compra</th>
                    <th style="text-align:center;">ELIMINAR / AGREGAR</th>
                </tr>
                <?php foreach($ingredientes as $ing): ?>
                <tr>
                    <td><strong style="font-size:1.1rem;"><?php echo htmlspecialchars($ing['nombre']); ?></strong></td>
                    <td style="color: #555;">$<?php echo round($ing['costo_unidad'], 2); ?> / <?php echo $ing['unidad']; ?></td>
                    
                    <td colspan="2" style="background: #e3f2fd; text-align:center; border-radius: 8px;">
                        <form method="POST" style="display:flex; gap:10px; margin:0; justify-content: center; align-items: center; padding: 5px;">
                            <input type="hidden" name="id_ing" value="<?php echo $ing['id']; ?>">
                            
                            <input type="number" step="0.01" name="cant_comprada" value="<?php echo ($ing['cantidad_comprada'] > 0 ? $ing['cantidad_comprada'] : ''); ?>" placeholder="Cant." required class="input-mini">
                            <span style="color:#555; width:30px; text-align:left;"><?php echo $ing['unidad']; ?></span>
                            
                            <input type="text" value="<?php echo $ing['precio_compra']; ?>" oninput="formatearMoneda(this)" placeholder="$ Pagado" required class="input-precio input-precio-formato">
                            <input type="hidden" name="precio_pagado_real" value="<?php echo $ing['precio_compra']; ?>">
                            
                            <div style="display:flex; gap:5px; margin-left: 15px; border-left: 2px solid #ccc; padding-left: 15px; align-items: center;">
                                <a href="precios.php?del_ing=<?php echo $ing['id']; ?>" class="btn-rojo" onclick="return confirm('¿Borrar definitivamente del catálogo?');" title="Eliminar Insumo">✖</a>
                                <button type="submit" name="registrar_compra" style="background:#32CD32; color:white; border:none; border-radius:6px; cursor:pointer; padding:6px 12px; font-weight:bold; font-size: 1.2rem;" title="Sumar esta compra al inventario">➕</button>
                            </div>
                            
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="seccion-caja inv">
            <h3><span style="font-size:1.8rem; color:#FF8C00;">2.</span> Inventario en Tiempo Real</h3>
            <div class="caja-interruptor">
                <div>
                    <h4 style="margin: 0 0 5px 0;">Control de Descuento Automático</h4>
                    <p style="margin: 0; color: #666; font-size: 0.9rem;">
                        <?php if($inventario_activo == 1): ?>
                            El sistema está <strong>restando ingredientes</strong> con cada pedido.
                        <?php else: ?>
                            El sistema está <strong>congelado</strong>. No se descontarán ingredientes.
                        <?php endif; ?>
                    </p>
                </div>
                <form method="POST" style="margin:0;">
                    <?php if($inventario_activo == 1): ?>
                        <button type="submit" name="toggle_inventario" value="0" class="btn-interruptor off">⏸️ CONGELAR STOCK</button>
                    <?php else: ?>
                        <button type="submit" name="toggle_inventario" value="1" class="btn-interruptor on">▶️ ACTIVAR DESCUENTO</button>
                    <?php endif; ?>
                </form>
            </div>
            <p style="color: #666; font-size: 0.95rem;">Revisa cuánto stock te queda. Si algo se daña, ajusta el número real sin afectar tus finanzas.</p>
            
            <table>
                <tr>
                    <th>Insumo</th>
                    <th>Estado</th>
                    <th>Stock Actual en Bodega</th>
                    <th style="background: #fff3e0; border-radius: 8px 8px 0 0; text-align:center;">⚖️ Ajustar Merma</th>
                </tr>
                <?php foreach($ingredientes as $ing): 
                    $clase_stock = $ing['alerta'] ? 'stock-alerta' : 'stock-bien';
                    $texto_estado = $ing['alerta'] ? '⚠️ Bajo Stock' : '✅ Normal';
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($ing['nombre']); ?></strong></td>
                    <td style="color: #666; font-size: 0.9rem; font-weight:bold;"><?php echo $texto_estado; ?></td>
                    
                    <td>
                        <span class="<?php echo $clase_stock; ?>"><?php echo $ing['stock_actual']; ?></span> <?php echo $ing['unidad']; ?>
                        <?php if($inventario_activo == 0): ?>
                            <span style="background: #eee; color: #888; font-size: 0.75rem; padding: 2px 6px; border-radius: 4px; margin-left: 8px;">❄️ Pausado</span>
                        <?php endif; ?>
                    </td>

                    <td style="background: #fff3e0; text-align:center; border-radius: 8px;">
                        <form method="POST" style="display:flex; gap:10px; margin:0; justify-content: center; padding: 5px;">
                            <input type="hidden" name="id_ing" value="<?php echo $ing['id']; ?>">
                            <input type="number" step="0.01" name="stock_real" placeholder="Stock Real Físico" required class="input-mini">
                            <button type="submit" name="ajustar_stock" style="background:#FF8C00; color:white; border:none; border-radius:6px; cursor:pointer; padding:8px 15px; font-weight:bold;">Fijar Stock</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="seccion-caja rec">
            <h3><span style="font-size:1.8rem; color:#32CD32;">3.</span> Menú y Recetas</h3>
            <p style="color: #666; font-size: 0.95rem;">Asigna los insumos a tus platos. El sistema calculará el costo basándose en tu última compra.</p>
            
            <?php foreach($platos_agrupados as $seccion => $platos): ?>
                <div class="folder" onclick="toggleFolder('f-<?php echo md5($seccion); ?>')">
                    <span>📁 <?php echo strtoupper($seccion); ?></span>
                    <span>▼</span>
                </div>
                
                <div id="f-<?php echo md5($seccion); ?>" class="folder-content">
                    <div class="grid-platos">
                        <?php foreach($platos as $plato): 
                            $ganancia = $plato['precio'] - $plato['costo_produccion'];
                            $clase_ganancia = ($ganancia >= 0) ? 'positivo' : 'negativo';
                        ?>
                        <div class="card-plato">
                            <div class="plato-header">
                                <strong style="font-size: 1.2rem;"><?php echo htmlspecialchars($plato['nombre']); ?></strong>
                                
                                <form method="POST" style="display:flex; gap:5px;">
                                    <input type="hidden" name="menu_id" value="<?php echo $plato['id']; ?>">
                                    <input type="text" value="<?php echo $plato['precio']; ?>" oninput="formatearMoneda(this)" class="input-precio-formato" style="width: 80px; padding: 4px; border-radius:4px; border: 1px solid #ccc; font-weight:bold; text-align: center;" title="Precio de Venta">
                                    <input type="hidden" name="nuevo_precio_real" value="<?php echo $plato['precio']; ?>">
                                    <button type="submit" name="actualizar_precio_venta" style="background:#007BFF; color:white; border:none; border-radius:4px; cursor:pointer;">💾</button>
                                </form>
                            </div>

                            <div class="info-financiera">
                                <div><small style="color:#888;">Costo Receta</small><br><strong>$<?php echo number_format($plato['costo_produccion'], 0, ',', '.'); ?></strong></div>
                                <div><small style="color:#888;">Venta</small><br><strong>$<?php echo number_format($plato['precio'], 0, ',', '.'); ?></strong></div>
                                <div><small style="color:#888;">Ganancia</small><br><strong class="<?php echo $clase_ganancia; ?>">$<?php echo number_format($ganancia, 0, ',', '.'); ?></strong></div>
                            </div>

                            <ul class="lista-receta">
                                <?php foreach($plato['receta'] as $r): ?>
                                <li>
                                    <span><?php echo $r['cantidad_usada'] . ' ' . $r['unidad'] . ' ' . $r['nombre']; ?></span>
                                    <span>$<?php echo number_format($r['costo_calculado'], 0, ',', '.'); ?> 
                                        <a href="precios.php?del_receta=<?php echo $r['id_receta']; ?>" style="color:red; text-decoration:none; margin-left:5px;">✖</a>
                                    </span>
                                </li>
                                <?php endforeach; ?>
                            </ul>

                            <form method="POST" style="display:flex; gap:5px; align-items:center;">
                                <input type="hidden" name="menu_id" value="<?php echo $plato['id']; ?>">
                                <select name="ingrediente_id" required style="flex-grow: 1;" onchange="actualizarPaso(this)">
                                    <option value="" data-unidad="">-- Insumo --</option>
                                    <?php foreach($ingredientes as $ing): ?>
                                        <option value="<?php echo $ing['id']; ?>" data-unidad="<?php echo $ing['unidad']; ?>">
                                            <?php echo htmlspecialchars($ing['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" step="0.01" name="cantidad_usada" class="input-cant" placeholder="Cant." required style="width: 60px;">
                                <span class="label-unidad" style="color: #666; font-size: 0.85rem; width: 30px; font-weight: bold;"></span>
                                <button type="submit" name="agregar_a_receta" style="background:#32CD32; color:white; border:none; border-radius:4px; font-weight:bold; cursor:pointer; padding:6px 10px;">+</button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
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