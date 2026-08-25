<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';
// Asegúrate de que $mi_restaurant_id esté definido aquí (probablemente viene de session_start() o db.php en tu sistema)

$mesa_id = isset($_GET['mesa_id']) ? (int)$_GET['mesa_id'] : 0;

if ($mesa_id == 0) { header("Location: mesas.php"); exit(); }

$res_mesa = $conn->query("SELECT numero_mesa FROM mesas WHERE id = $mesa_id AND restaurant_id = $mi_restaurant_id");
if (!$res_mesa || $res_mesa->num_rows == 0) { die("Mesa no encontrada."); }
$numero_mesa = $res_mesa->fetch_assoc()['numero_mesa'];

// =========================================================================
// 1. LÓGICA DE COBRO CON MAQUINITA POINT (INTEGRACIÓN REAL)
// =========================================================================
if (isset($_POST['cobrar_maquina'])) {
    $monto = (int)$_POST['monto_total'];
    $pos_id = trim($_POST['pos_id_maquina']);

    // 1. Obtenemos el token del almacén
    $conf = $conn->query("SELECT access_token FROM mp_credenciales WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
    $token = $conf['access_token'] ?? '';

    // 2. Generamos una "Referencia Externa" (El rastreador)
    // Esto es vital: es una etiqueta invisible para saber qué mesa estamos cobrando cuando MP nos responda.
    $referencia_externa = "REST_" . $mi_restaurant_id . "_MESA_" . $mesa_id . "_TICKET_" . time();

    // 3. Empaquetamos los datos en formato JSON exacto como lo pide Mercado Pago
    $datos_cobro = [
        "amount" => $monto,
        "description" => "Consumo Mesa #" . $numero_mesa,
        "additional_info" => [
            "external_reference" => $referencia_externa,
            "print_on_terminal" => true // Le dice a la maquinita que imprima la boleta física
        ]
    ];
    $carga_json = json_encode($datos_cobro);

    // 4. El Disparo: Configuramos la llamada cURL a los servidores de MP
    // Endpoint oficial para máquinas Point (Payment Intents API)
    $url = "https://api.mercadopago.com/point/integration-api/devices/$pos_id/payment-intents";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $carga_json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $token,
        "Content-Type: application/json"
    ]);

    // Ejecutamos el disparo y capturamos la respuesta
    $respuesta_mp = curl_exec($ch);
    $codigo_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 5. Analizamos si funcionó o falló
    if ($codigo_http == 200 || $codigo_http == 201) {
        // ¡ÉXITO! MP aceptó la orden.
        // Guardamos el rastreador en las notas del pedido para cruzarlo más tarde con el Webhook
        $conn->query("UPDATE pedidos SET notas = CONCAT(notas, ' [MP_REF: $referencia_externa]') WHERE mesa_id = $mesa_id AND estado IN (1, 2, 4)");
        
        header("Location: pago.php?mesa_id=$mesa_id&enviado_mp=1"); 
        exit();
    } else {
        // ERROR: Algo salió mal (Token inválido, Máquina no existe, etc.)
        $error_info = json_decode($respuesta_mp, true);
        die("
            <div style='background:#121212; color:#fff; padding:40px; font-family:sans-serif; height:100vh;'>
                <div style='max-width: 600px; margin: auto; background: #2a2a2a; padding: 30px; border-radius: 10px; border-top: 5px solid #ff4d4d;'>
                    <h2 style='color:#ff4d4d; margin-top:0;'>❌ Falla en la Comunicación</h2>
                    <p>Mercado Pago rechazó la conexión. Revisa que el Access Token y el POS_ID sean correctos.</p>
                    <p><strong>Código HTTP:</strong> $codigo_http</p>
                    <div style='background:#111; padding:15px; border-radius:8px; font-family:monospace; color:#ff8a80; overflow-x:auto;'>
                        " . htmlspecialchars(print_r($error_info, true)) . "
                    </div>
                    <br>
                    <a href='pago.php?mesa_id=$mesa_id' style='background:#009EE3; color:white; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold;'>← Volver a la Caja</a>
                </div>
            </div>
        ");
    }
}

// =========================================================================
// 2. LÓGICA DE COBRO MANUAL / EFECTIVO (LIBERA LA MESA)
// =========================================================================
if (isset($_POST['procesar_pago_manual'])) {
    $metodo_pago = $conn->real_escape_string($_POST['metodo_pago']);
    $conn->query("UPDATE pedidos SET estado = 3 WHERE mesa_id = $mesa_id AND estado IN (1, 2, 4)");
    $conn->query("UPDATE mesas SET estado = 0, usos = usos + 1 WHERE id = $mesa_id");
    header("Location: mesas.php?exito_pago=1"); exit();
}

// =========================================================================
// 3. CONSULTAS PARA DIBUJAR LA BOLETA
// =========================================================================
$sql_cuenta = "SELECT m.nombre, m.precio as precio_base, p.precio_al_momento, p.notas FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.mesa_id = $mesa_id AND p.estado IN (1, 2, 4)";
$res_cuenta = $conn->query($sql_cuenta);

$total_bruto = 0; $subtotal = 0; 
$items = []; $lista_descuentos = [];

if ($res_cuenta) { 
    while($row = $res_cuenta->fetch_assoc()){ 
        $precio_base = (float)$row['precio_base'];
        $precio_final = (float)$row['precio_al_momento'];
        
        $total_bruto += $precio_base;
        $subtotal += $precio_final;
        $items[] = $row; 
        
        if ($precio_base > $precio_final) {
            $ahorro = $precio_base - $precio_final;
            $nombre_desc = "Descuento aplicado";
            if (preg_match('/\[-(.*?)\]/', $row['notas'], $matches)) {
                $nombre_desc = "Desc. " . trim($matches[1]);
            }
            $lista_descuentos[] = [ 'nombre' => $nombre_desc, 'ahorro' => $ahorro ];
        }
    } 
}
$cantidad_platos = count($items);

$res_total_mesas = $conn->query("SELECT COUNT(*) as total FROM mesas WHERE restaurant_id = $mi_restaurant_id");
$total_mesas = $res_total_mesas->fetch_assoc()['total'] ?? 1;
$res_mesas_ocupadas = $conn->query("SELECT COUNT(*) as ocupadas FROM mesas WHERE restaurant_id = $mi_restaurant_id AND estado = 1");
$mesas_ocupadas = $res_mesas_ocupadas->fetch_assoc()['ocupadas'] ?? 0;
$porcentaje_ocupacion = ($total_mesas > 0) ? round(($mesas_ocupadas / $total_mesas) * 100) : 0;

$res_promo = $conn->query("SELECT * FROM promociones WHERE restaurant_id = $mi_restaurant_id LIMIT 1");
if ($res_promo && $res_promo->num_rows > 0) {
    $reglas = $res_promo->fetch_assoc();
    $meta_venta = $reglas['meta_venta']; $meta_platos = $reglas['meta_platos'];
    $limite_ocupacion = $reglas['limite_ocupacion']; $promo_encendida = ($reglas['estado_promo'] == 1);
    $tipo_premio = strtoupper($reglas['tipo_premio']); $detalle_premio = $reglas['detalle_premio'];
    $probabilidad = (int)$reglas['probabilidad']; 
    $mensaje_ganador = isset($reglas['mensaje_ganador']) ? $reglas['mensaje_ganador'] : '';
} else {
    $meta_venta = 999999; $meta_platos = 99; $limite_ocupacion = 0; $promo_encendida = false;
    $tipo_premio = 'N/A'; $detalle_premio = ''; $probabilidad = 0; $mensaje_ganador = '';
}

$cumple_requisitos_base = ($subtotal >= $meta_venta || $cantidad_platos >= $meta_platos);
$cocina_disponible = ($porcentaje_ocupacion <= $limite_ocupacion);
$tuvo_suerte = false;
if ($cumple_requisitos_base && $cocina_disponible && $promo_encendida) {
    if (rand(1, 100) <= $probabilidad) { $tuvo_suerte = true; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Caja Registradora</title>
    <style>
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        .nav-hub { background: #014421; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 10px; background: #8C8C8C; color: white; transition: 0.3s; }
        .container { padding: 40px; max-width: 1000px; margin: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: start;}
        
        /* DISEÑO DE LA BOLETA */
        .ticket-papel { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); position: relative; border-top: 8px dashed #ccc;}
        .ticket-papel h2 { text-align: center; margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 15px; color: #014421;}
        .lista-items { list-style: none; padding: 0; margin: 20px 0; border-bottom: 2px dashed #eee; padding-bottom: 20px;}
        .lista-items li { display: flex; justify-content: space-between; padding: 8px 0; font-size: 1.1rem; border-bottom: 1px solid #ccc;}
        .lista-items li:last-child { border-bottom: none; }
        .resumen-totales { display: flex; flex-direction: column; gap: 10px; font-size: 1.2rem;}
        .gran-total { font-size: 1.8rem; font-weight: 900; color: #014421; padding-top: 15px; margin-top: 10px; display: flex; justify-content: space-between;}
        
        /* PANEL DERECHO */
        .panel-derecho { display: flex; flex-direction: column; gap: 20px; position: sticky; top: 30px;}
        .panel-cobro { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #eee; }
        .panel-cobro h3 { margin-top: 0; color: #333; margin-bottom: 20px; font-size: 1.3rem;}
        .grupo-form { display: flex; flex-direction: column; gap: 10px; }
        .grupo-form label { font-weight: bold; font-size: 1.1rem;}
        .grupo-form select { padding: 15px; font-size: 1.1rem; border-radius: 8px; border: 1px solid #ccc; background: #fff;}
        
        /* BOTONES */
        .btn-mp { background: #009EE3; color: white; border: none; padding: 15px; border-radius: 10px; font-size: 1.2rem; font-weight: bold; cursor: pointer; box-shadow: 0 4px 0 #007BB5; width: 100%; transition: 0.2s;}
        .btn-mp:hover { background: #008cc9; transform: translateY(2px); box-shadow: 0 2px 0 #007BB5;}
        .btn-manual { background: transparent; color: #32CD32; border: 2px solid #32CD32; padding: 15px; border-radius: 10px; font-size: 1.1rem; font-weight: bold; cursor: pointer; width: 100%; margin-top: 15px; transition: 0.2s;}
        .btn-manual:hover { background: #e8f5e9; }
        .btn-cancelar { display: block; background: transparent; color: #d32f2f; text-align: center; text-decoration: none; font-weight: bold; font-size: 1.1rem; padding: 15px; border-radius: 8px; margin-top: 5px;}
        
        /* PROMOS */
        .mini-regalo { display: flex; align-items: center; gap: 15px; padding: 15px; border-radius: 10px; margin-bottom: 20px; border: 2px solid transparent; }
        .regalo-verde { background: #e8f5e9; border-color: #4caf50; color: #2e7d32; }
        .icono-regalo { font-size: 2.5rem; line-height: 1; }
        .lista-descuentos { list-style: none; padding: 0; margin: 0; color: #d32f2f; border-bottom: 2px dashed #eee; padding-bottom: 15px; }

        /* --- MODO OSCURO AUTOMÁTICO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; }
        body.modo-oscuro .ticket-papel { background-color: #b0bec5 !important; color: #111 !important; border-top-color: #78909c !important; }
        body.modo-oscuro .ticket-papel h2 { color: #000 !important; border-bottom-color: #90a4ae !important; }
        body.modo-oscuro .ticket-papel small { color: #444 !important; }
        body.modo-oscuro .lista-items { border-bottom-color: #78909c !important; }
        body.modo-oscuro .lista-items li { border-bottom-color: #90a4ae !important; }
        body.modo-oscuro .gran-total { color: #000 !important; border-top: 2px solid #78909c; }
        body.modo-oscuro .lista-descuentos { border-bottom-color: #78909c !important; color: #b71c1c !important; }
        body.modo-oscuro .panel-cobro { background: #1e1e1e !important; border-color: #333 !important; }
        body.modo-oscuro .panel-cobro h3 { color: #eee !important; }
        body.modo-oscuro .grupo-form label { color: #ccc !important; }
        body.modo-oscuro select { background: #333 !important; color: white !important; border-color: #555 !important; }
        body.modo-oscuro .mini-regalo { background: #0d2a11 !important; border-color: #2e7d32 !important; }
        body.modo-oscuro .mini-regalo strong { color: #4caf50 !important; }
        body.modo-oscuro .mini-regalo span { color: #aaa !important; }
    </style>
</head>
<body>
    <div class="nav-hub"><span style="font-size: 1.6rem; font-weight: 800;">💰 CAJA REGISTRADORA</span><a href="pedido.php?mesa_id=<?php echo $mesa_id; ?>">← Volver a Pedido</a></div>
    <div class="container">
        
        <div class="ticket-papel">
            <h2>Mesa #<?php echo $numero_mesa; ?> <br><small style="color: #888; font-size:0.9rem; font-weight:normal;">Ticket #<?php echo time(); ?></small></h2>
            <ul class="lista-items">
                <?php foreach($items as $i): ?>
                    <li><span><?php echo htmlspecialchars($i['nombre']); ?></span><span style="font-weight: bold;">$<?php echo number_format($i['precio_base'], 0, ',', '.'); ?></span></li>
                <?php endforeach; ?>
                <?php if(empty($items)): ?><li style="color:#888; justify-content:center;">No hay productos en esta mesa.</li><?php endif; ?>
            </ul>

            <?php if(count($lista_descuentos) > 0): ?>
            <ul class="lista-descuentos">
                <?php foreach($lista_descuentos as $desc): ?>
                    <li style="display: flex; justify-content: space-between; padding: 4px 0;">
                        <small>▼ <?php echo htmlspecialchars($desc['nombre']); ?></small>
                        <span style="font-weight: bold;">-$<?php echo number_format($desc['ahorro'], 0, ',', '.'); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <div class="resumen-totales">
                <?php if(count($lista_descuentos) > 0): ?>
                    <div style="display:flex; justify-content:space-between; color:#888; font-size:1rem; margin-top:10px;">
                        <span>Subtotal Bruto:</span><span>$<?php echo number_format($total_bruto, 0, ',', '.'); ?></span>
                    </div>
                <?php endif; ?>
                <div class="linea-total gran-total"><span>TOTAL A PAGAR:</span><span>$<?php echo number_format($subtotal, 0, ',', '.'); ?></span></div>
            </div>
        </div>

        <div class="panel-derecho">
            
            <?php if ($promo_encendida && $cumple_requisitos_base && $cocina_disponible && $tuvo_suerte): ?>
                <div class="mini-regalo regalo-verde">
                    <div class="icono-regalo">🎁</div>
                    <div>
                        <strong style="display: block; font-size: 1.1rem;">¡Premio Activado!</strong>
                        <span style="font-size: 0.9rem;">Dile: "<?php echo htmlspecialchars($mensaje_ganador); ?>"</span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="panel-cobro">
                <h3>💳 Cobro con Maquinita Point</h3>
                
                <?php
                // Buscamos las máquinas registradas
                $res_term = $conn->query("SELECT * FROM mp_terminales WHERE restaurant_id = $mi_restaurant_id");
                
                if ($res_term && $res_term->num_rows > 0): 
                    while($terminal = $res_term->fetch_assoc()):
                ?>
                    <form method="POST" style="margin-bottom: 10px;">
                        <input type="hidden" name="monto_total" value="<?php echo $subtotal; ?>">
                        <input type="hidden" name="pos_id_maquina" value="<?php echo htmlspecialchars($terminal['pos_id']); ?>">
                        <button type="submit" name="cobrar_maquina" class="btn-mp">
                            Enviar $<?php echo number_format($subtotal, 0, ',', '.'); ?> a: <?php echo htmlspecialchars($terminal['nombre_caja']); ?>
                        </button>
                    </form>
                <?php 
                    endwhile;
                else:
                ?>
                    <p style="color: #666; font-size: 0.9rem; text-align: center; background: #fff3e0; padding: 10px; border-radius: 8px; border: 1px dashed #ff8c00;">
                        ⚠️ No hay máquinas registradas. <br>
                        <a href="configuracion_pagos.php" style="color: #009EE3; font-weight: bold;">Configúralas aquí</a>
                    </p>
                <?php endif; ?>

                <hr style="border: 1px dashed #ccc; margin: 25px 0;">

                <form method="POST">
                    <h3 style="font-size: 1.1rem; margin-bottom: 10px; color: #666;">Cobro Manual (Efectivo / Fallo de red)</h3>
                    <div class="grupo-form">
                        <select name="metodo_pago" required>
                            <option value="efectivo">💵 Efectivo Físico</option>
                            <option value="transferencia">📱 Transferencia Bancaria</option>
                            <option value="tarjeta_manual">💳 Tarjeta (Marcado Manual)</option>
                        </select>
                    </div>
                    
                    <button type="submit" name="procesar_pago_manual" class="btn-manual" onclick="return confirm('¿Seguro que deseas registrar el pago manualmente y liberar la mesa?');">
                        Liberar Mesa Manualmente
                    </button>
                    <a href="configuracion_impresora.php?mesa_id=<?php echo $mesa_id; ?>" 
                       style="background: #58a6ff; color: white; text-decoration: none; display: block; text-align: center; padding: 12px; border-radius: 10px; font-weight: bold; margin-top: 10px; transition: 0.2s;">
                        ⚙️ Configurar Ticketera
                    </a>
                    <a href="imprimir_ticket.php?mesa_id=<?php echo $mesa_id; ?>" target="_blank" 
                       style="background: #333; color: white; text-decoration: none; display: block; text-align: center; padding: 12px; border-radius: 10px; font-weight: bold; margin-top: 15px; transition: 0.2s;">
                        🖨️ Imprimir Pre-Cuenta
                    </a>

                    <a href="pedido.php?mesa_id=<?php echo $mesa_id; ?>" class="btn-cancelar">❌ Cancelar y Volver</a>
                </form>
            </div>
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