<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['restaurant_id'])) { 
    header("Location: ../portal_bargaiwe.php"); 
    exit(); 
}

// SOLO DEJAMOS LA CONEXIÓN CORRECTA:
include 'r_db.php'; 
verificarPlanPlus(); // El portero VIP

$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// ... (El resto de tu código)

// --- TEMA DINÁMICO ---
$res_tema = $conn->query("SELECT modo_global, color_cajero FROM config_temas WHERE restaurant_id = $mi_restaurant_id");
$tema = ($res_tema && $res_tema->num_rows > 0) ? $res_tema->fetch_assoc() : ['modo_global' => 'oscuro', 'color_cajero' => '#FF8C00'];

// --- 1. MAGIA: AUTO-CREAR COLUMNA DE COSTOS SI NO EXISTE ---
$check_costo = $conn->query("SHOW COLUMNS FROM menu LIKE 'costo'");
if ($check_costo && $check_costo->num_rows == 0) {
    $conn->query("ALTER TABLE menu ADD COLUMN costo INT DEFAULT 0");
}

// --- 2. GUARDAR COSTOS MASIVAMENTE ---
if (isset($_POST['guardar_costos'])) {
    if(isset($_POST['costos']) && is_array($_POST['costos'])) {
        foreach($_POST['costos'] as $id_plato => $costo_ingresado) {
            $id_s = (int)$id_plato;
            $costo_s = (int)$costo_ingresado;
            $conn->query("UPDATE menu SET costo = $costo_s WHERE id = $id_s AND restaurant_id = $mi_restaurant_id");
        }
    }
    header("Location: r_costos_plus.php?exito=1"); 
    exit();
}

// --- 3. OBTENER DATOS (PLATOS Y DESCUENTOS) ---
$platos_query = $conn->query("SELECT id, seccion, nombre, precio, costo FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion ASC, nombre ASC");
$todos_los_platos = [];
$menu_agrupado = [];
while($row = $platos_query->fetch_assoc()){ 
    $todos_los_platos[] = $row;
    $menu_agrupado[$row['seccion']][] = $row; 
}

$descuentos_query = $conn->query("SELECT * FROM descuentos WHERE restaurant_id = $mi_restaurant_id AND estado = 1");
$descuentos_activos = [];

// Analizamos si algún descuento causa pérdida antes de mostrarlo (Pinta de Rojo)
while($d = $descuentos_query->fetch_assoc()){
    $d['causa_perdida'] = false;
    foreach($todos_los_platos as $p) {
        if($p['costo'] > 0) { // Solo analizamos si le asignaste un costo
            $precio_final = $p['precio'];
            if($d['tipo'] == 'porcentaje') {
                $precio_final = $p['precio'] - ($p['precio'] * ($d['valor']/100));
            } else {
                $precio_final = $p['precio'] - $d['valor'];
            }
            
            // Venta Final - Costo de Compra = ¿Negativo?
            if( ($precio_final - $p['costo']) < 0 ) {
                $d['causa_perdida'] = true;
                break; // Con un solo plato que pierda, la alerta se enciende
            }
        }
    }
    $descuentos_activos[] = $d;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Costos y Márgenes - Bargaiwe Fast</title>
    <style>
        :root {
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: <?php echo $tema['color_cajero']; ?>; 
            --success: #32CD32; --danger: #E53935; --warning: #FFC107;
        }
        <?php if($tema['modo_global'] === 'claro'): ?>
        body { --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000; }
        <?php endif; ?>

        body { background: var(--bg-body); color: var(--text); font-family: 'Segoe UI', sans-serif; margin: 0; transition: 0.3s; }
        
        .nav-hub { background: var(--bg-panel); border-bottom: 1px solid var(--border); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: var(--text-title); }
        .nav-hub a { color: white; text-decoration: none; font-weight: bold; background: var(--bg-panel); padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); transition: 0.3s; }
        .nav-hub a:hover { border-color: var(--accent); color: var(--accent); }

        .container { padding: 30px; display: grid; grid-template-columns: 300px 1fr; gap: 30px; max-width: 1400px; margin: auto; align-items: start;}
        
        .card { background: var(--bg-panel); padding: 25px; border-radius: 15px; border: 1px solid var(--border); }
        h3 { color: var(--accent); margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; font-size: 1.3rem;}

        /* ESTILOS DEL PANEL IZQUIERDO (DESCUENTOS) */
        .btn-descuento { width: 100%; text-align: left; background: var(--bg-body); border: 2px solid var(--success); color: var(--text-title); padding: 15px; border-radius: 8px; margin-bottom: 10px; cursor: pointer; transition: 0.2s; font-weight: bold; font-size: 1.1rem; display: flex; justify-content: space-between; align-items: center;}
        .btn-descuento:hover { transform: scale(1.02); }
        .btn-descuento.peligro { border-color: var(--danger); background: rgba(229, 57, 53, 0.05); }
        .btn-descuento.activo-sim { background: var(--accent); color: white; border-color: var(--accent); }

        /* ESTILOS DEL MENÚ Y TABLA DE MÁRGENES */
        .seccion-folder { background: #010409; padding: 10px 15px; border-radius: 8px; font-weight: bold; color: var(--text-title); text-transform: uppercase; margin: 20px 0 10px 0; border: 1px solid var(--border);}
        
        .grid-plato { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 15px; background: var(--bg-body); padding: 12px 15px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 8px; align-items: center;}
        .grid-header { font-size: 0.8rem; color: #8b949e; font-weight: bold; text-transform: uppercase; padding: 0 15px; margin-bottom: 5px; }
        
        .caja-valor { text-align: right; font-weight: bold; font-family: monospace; font-size: 1.1rem; }
        .caja-valor span { font-size: 0.8rem; color: #8b949e; display: block; }
        
        .input-costo { width: 100px; padding: 8px; border-radius: 6px; border: 1px dashed var(--accent); background: var(--bg-panel); color: var(--text-title); font-size: 1rem; text-align: right; font-weight: bold;}
        .input-costo:focus { outline: none; border-style: solid; }

        .ganancia-positiva { color: var(--success); }
        .ganancia-negativa { color: var(--danger); }

        .btn-flotante { position: sticky; bottom: 20px; background: var(--success); color: white; border: none; padding: 15px 30px; border-radius: 30px; font-weight: bold; font-size: 1.1rem; box-shadow: 0 10px 20px rgba(0,0,0,0.5); cursor: pointer; width: 100%; display: block; text-transform: uppercase; letter-spacing: 1px; transition: 0.3s;}
        .btn-flotante:hover { opacity: 0.9; transform: translateY(-3px); }
    </style>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.8rem; font-weight: 800; color: var(--accent);">💰 Control de Márgenes</span>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="r_stats.php">← Volver a Estadísticas</a>
        </div>
    </div>

    <div class="container">
        
        <div class="card" style="position: sticky; top: 30px;">
            <h3>🛠️ Simulador de Descuentos</h3>
            <p style="font-size: 0.9rem; color: #8b949e; margin-bottom: 20px;">Presiona un descuento para simular cómo afectará tus ganancias. <span style="color: var(--danger); font-weight: bold;">Rojo = Te hace perder dinero.</span></p>

            <button type="button" onclick="restaurarVista()" id="btn_normal" class="btn-descuento activo-sim" style="border-color: var(--border);">
                👁️ Vista Normal (Sin Dcto)
            </button>

            <?php if(empty($descuentos_activos)): ?>
                <p style="color: #8b949e; text-align: center; margin-top: 20px;">No tienes cupones activos.</p>
            <?php else: ?>
                <?php foreach($descuentos_activos as $d): 
                    $clase_peligro = $d['causa_perdida'] ? 'peligro' : '';
                    $icono = $d['causa_perdida'] ? '⚠️' : '✅';
                    $txt_val = ($d['tipo'] == 'porcentaje') ? floatval($d['valor'])."%" : "$".number_format($d['valor'], 0, ',', '.');
                ?>
                    <button type="button" onclick="simularDescuento(this, '<?php echo $d['tipo']; ?>', <?php echo $d['valor']; ?>)" class="btn-descuento <?php echo $clase_peligro; ?>">
                        <span><?php echo htmlspecialchars($d['codigo']); ?></span>
                        <span><?php echo $icono . ' -' . $txt_val; ?></span>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; border: none; padding: 0;">📦 Costos de Producción</h3>
                <?php if(isset($_GET['exito'])): ?>
                    <span style="background: rgba(50, 205, 50, 0.1); color: var(--success); padding: 5px 15px; border-radius: 20px; font-weight: bold; border: 1px solid var(--success);">¡Costos Guardados!</span>
                <?php endif; ?>
            </div>

            <form method="POST">
                
                <div class="grid-plato grid-header">
                    <div>Producto</div>
                    <div style="text-align: right;">Costo Aprox ($)</div>
                    <div style="text-align: right;" id="titulo_venta">Venta Normal</div>
                    <div style="text-align: right;" id="titulo_ganancia">Margen Real</div>
                </div>

                <?php foreach($menu_agrupado as $seccion => $platos): ?>
                    <div class="seccion-folder">📁 <?php echo htmlspecialchars($seccion); ?></div>
                    
                    <?php foreach($platos as $p): 
                        $ganancia = $p['precio'] - $p['costo'];
                        $clase_ganancia = ($ganancia >= 0) ? 'ganancia-positiva' : 'ganancia-negativa';
                    ?>
                        <div class="grid-plato plato-fila" data-precio="<?php echo $p['precio']; ?>">
                            
                            <div style="font-weight: bold; color: var(--text-title);"><?php echo htmlspecialchars($p['nombre']); ?></div>
                            
                            <div style="text-align: right;">
                                <input type="number" name="costos[<?php echo $p['id']; ?>]" value="<?php echo $p['costo']; ?>" class="input-costo" oninput="recalcularFila(this)">
                            </div>
                            
                            <div class="caja-valor caja-venta" style="color: var(--text);">
                                $<?php echo number_format($p['precio'], 0, ',', '.'); ?>
                            </div>
                            
                            <div class="caja-valor caja-ganancia <?php echo $clase_ganancia; ?>">
                                $<?php echo number_format($ganancia, 0, ',', '.'); ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <br><br>
                <button type="submit" name="guardar_costos" class="btn-flotante">💾 Guardar Todos los Costos</button>
            </form>

        </div>
    </div>

    <script>
        // Formateador de moneda para JS
        const formatMoney = (num) => '$' + Math.round(num).toLocaleString('es-CL');

        // Cuando el usuario cambia el costo escribiendo, recalculamos inmediatamente la fila
        function recalcularFila(inputElement) {
            let fila = inputElement.closest('.plato-fila');
            let precio = parseFloat(fila.getAttribute('data-precio'));
            let costo = parseFloat(inputElement.value) || 0;
            
            // Leemos el valor actual de la caja de Venta (por si hay un descuento aplicado)
            let textoVenta = fila.querySelector('.caja-venta').innerText.replace(/[^0-9-]/g, '');
            let ventaActual = parseFloat(textoVenta);

            let ganancia = ventaActual - costo;
            
            let cajaGanancia = fila.querySelector('.caja-ganancia');
            cajaGanancia.innerText = formatMoney(ganancia);
            
            // Colores
            cajaGanancia.classList.remove('ganancia-positiva', 'ganancia-negativa');
            cajaGanancia.classList.add(ganancia >= 0 ? 'ganancia-positiva' : 'ganancia-negativa');
        }

        // SIMULADOR DE DESCUENTOS
        function simularDescuento(btnElement, tipo, valor) {
            // Estilos de los botones
            document.querySelectorAll('.btn-descuento').forEach(b => b.classList.remove('activo-sim'));
            btnElement.classList.add('activo-sim');

            // Cambiar los títulos
            document.getElementById('titulo_venta').innerHTML = 'Venta c/Dcto <span style="color: var(--accent);">Simulado</span>';
            document.getElementById('titulo_ganancia').innerHTML = 'Margen Final';

            // Recorrer todos los platos y aplicar la matemática
            document.querySelectorAll('.plato-fila').forEach(fila => {
                let precioBase = parseFloat(fila.getAttribute('data-precio'));
                let costo = parseFloat(fila.querySelector('.input-costo').value) || 0;
                
                let nuevoPrecio = precioBase;
                if(tipo === 'porcentaje') {
                    nuevoPrecio = precioBase - (precioBase * (valor/100));
                } else {
                    nuevoPrecio = precioBase - valor;
                }

                // Escribir Venta
                fila.querySelector('.caja-venta').innerHTML = formatMoney(nuevoPrecio) + ' <span><del>'+formatMoney(precioBase)+'</del></span>';
                
                // Calcular Ganancia y pintar
                let ganancia = nuevoPrecio - costo;
                let cajaGanancia = fila.querySelector('.caja-ganancia');
                cajaGanancia.innerText = formatMoney(ganancia);
                
                cajaGanancia.classList.remove('ganancia-positiva', 'ganancia-negativa');
                cajaGanancia.classList.add(ganancia >= 0 ? 'ganancia-positiva' : 'ganancia-negativa');
            });
        }

        function restaurarVista() {
            document.querySelectorAll('.btn-descuento').forEach(b => b.classList.remove('activo-sim'));
            document.getElementById('btn_normal').classList.add('activo-sim');

            document.getElementById('titulo_venta').innerText = 'Venta Normal';
            document.getElementById('titulo_ganancia').innerText = 'Margen Real';

            document.querySelectorAll('.plato-fila').forEach(fila => {
                let precioBase = parseFloat(fila.getAttribute('data-precio'));
                let costo = parseFloat(fila.querySelector('.input-costo').value) || 0;
                
                fila.querySelector('.caja-venta').innerText = formatMoney(precioBase);
                
                let ganancia = precioBase - costo;
                let cajaGanancia = fila.querySelector('.caja-ganancia');
                cajaGanancia.innerText = formatMoney(ganancia);
                
                cajaGanancia.classList.remove('ganancia-positiva', 'ganancia-negativa');
                cajaGanancia.classList.add(ganancia >= 0 ? 'ganancia-positiva' : 'ganancia-negativa');
            });
        }
    </script>
</body>
</html>