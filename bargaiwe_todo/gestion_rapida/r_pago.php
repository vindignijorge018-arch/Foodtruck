<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
include 'r_db.php';
include 'r_impresora_pago.php';

if (!isset($_SESSION['restaurant_id'])) { header("Location: ../portal_bargaiwe.php"); exit(); }
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// Recibimos el ticket desde la URL
$ticket = isset($_GET['ticket']) ? $conn->real_escape_string($_GET['ticket']) : '';

if (empty($ticket)) { header("Location: r_pedidos.php"); exit(); }

// Buscamos el nombre/número del cliente para este ticket
$res_cliente = $conn->query("SELECT cliente_nombre FROM pedidos WHERE codigo_grupo = '$ticket' AND restaurant_id = $mi_restaurant_id LIMIT 1");
if (!$res_cliente || $res_cliente->num_rows == 0) { die("Ticket no encontrado o ya pagado."); }
$nombre_cliente = $res_cliente->fetch_assoc()['cliente_nombre'];

// =========================================================================
// A) LÓGICA DE COBRO CON MAQUINITA POINT
// =========================================================================
if (isset($_POST['cobrar_maquina'])) {
    $monto = (int)$_POST['monto_total'];
    $pos_id = trim($_POST['pos_id_maquina']);

    $conf = $conn->query("SELECT access_token FROM mp_credenciales WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
    $token = $conf['access_token'] ?? '';

    // ETIQUETA CLAVE: "RAPIDO" y usamos el "$ticket" en vez de ID de mesa
    $referencia_externa = "REST_" . $mi_restaurant_id . "_RAPIDO_" . $ticket . "_TICKET_" . time();

    $datos_cobro = [
        "amount" => $monto,
        "description" => "Pedido Fast Food " . $nombre_cliente,
        "additional_info" => [
            "external_reference" => $referencia_externa,
            "print_on_terminal" => true
        ]
    ];
    $carga_json = json_encode($datos_cobro);

    $url = "https://api.mercadopago.com/point/integration-api/devices/$pos_id/payment-intents";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $carga_json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $token,
        "Content-Type: application/json"
    ]);

    $respuesta_mp = curl_exec($ch);
    $codigo_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($codigo_http == 200 || $codigo_http == 201) {
        // Guardamos el rastreador en las notas
        $conn->query("UPDATE pedidos SET notas = CONCAT(notas, ' [MP_FAST_REF: $referencia_externa]') WHERE codigo_grupo = '$ticket' AND estado = 5");
        header("Location: r_pago.php?ticket=$ticket&enviado_mp=1"); 
        exit();
    } else {
        $error_info = json_decode($respuesta_mp, true);
        die("<div style='background:#121212; color:#fff; padding:40px; font-family:sans-serif; height:100vh;'>
                <div style='max-width: 600px; margin: auto; background: #2a2a2a; padding: 30px; border-radius: 10px; border-top: 5px solid #ff4d4d;'>
                    <h2 style='color:#ff4d4d; margin-top:0;'>❌ Falla de Conexión (Foodtruck)</h2>
                    <p>Revisa tu Access Token y el POS_ID de tu máquina en la configuración.</p>
                    <a href='r_pago.php?ticket=$ticket' style='background:#009EE3; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>← Volver</a>
                </div>
            </div>");
    }
}

// =========================================================================
// B) LÓGICA DE COBRO MANUAL (Efectivo) -> ENVÍA A COCINA
// =========================================================================
if (isset($_POST['procesar_pago_manual'])) {
    // 1. Cambiamos el estado de 5 a 2 (En Cocina)
    $conn->query("UPDATE pedidos SET estado = 2 WHERE codigo_grupo = '$ticket' AND estado = 5 AND restaurant_id = $mi_restaurant_id");
    
    // 2. Consultamos a la base de datos si el dueño activó la impresión
    $conf_print = $conn->query("SELECT impresora_activa FROM config_impresora WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
    
    if ($conf_print && $conf_print['impresora_activa'] == 1) {
        // Si está ACTIVADA, lo mandamos a la pantalla de imprimir el ticket
        header("Location: r_ticket_imprimible.php?ticket=$ticket"); 
    } else {
        // Si está APAGADA, lo mandamos directo de vuelta a la lista de pedidos sin ventanas molestas
        header("Location: r_pedidos.php?exito=1"); 
    }
    exit();
}

// =========================================================================
// C) CANCELAR PEDIDO
// =========================================================================
if (isset($_POST['cancelar_pedido'])) {
    // Borramos el pedido de la base de datos para no acumular basura
    $conn->query("DELETE FROM pedidos WHERE codigo_grupo = '$ticket' AND estado = 5 AND restaurant_id = $mi_restaurant_id");
    header("Location: r_pedidos.php"); exit();
}

// =========================================================================
// 3. CONSULTAS PARA LA BOLETA (Leemos el estado 5)
// =========================================================================
$sql_cuenta = "SELECT m.nombre, p.precio_al_momento, p.notas FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.codigo_grupo = '$ticket' AND p.estado = 5";
$res_cuenta = $conn->query($sql_cuenta);

$subtotal = 0; 
$items = []; 

if ($res_cuenta) { 
    while($row = $res_cuenta->fetch_assoc()){ 
        $subtotal += (float)$row['precio_al_momento'];
        $items[] = $row; 
    } 
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bargaiwe Fast - Pago</title>
    <style>
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        .nav-hub { background: #D32F2F; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 10px; background: rgba(0,0,0,0.2); color: white; transition: 0.3s; }
        .container { padding: 40px; max-width: 1000px; margin: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: start;}
        
        .ticket-papel { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-top: 8px dashed #ccc;}
        .ticket-papel h2 { text-align: center; margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 15px; color: #D32F2F;}
        .lista-items { list-style: none; padding: 0; margin: 20px 0; border-bottom: 2px dashed #eee; padding-bottom: 20px;}
        .lista-items li { display: flex; justify-content: space-between; padding: 8px 0; font-size: 1.1rem; border-bottom: 1px solid #ccc;}
        .gran-total { font-size: 1.8rem; font-weight: 900; color: #D32F2F; padding-top: 15px; display: flex; justify-content: space-between;}
        
        .panel-cobro { background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #eee; }
        .grupo-form select { width: 100%; padding: 15px; font-size: 1.1rem; border-radius: 8px; border: 1px solid #ccc; margin-bottom: 15px;}
        
        .btn-mp { background: #009EE3; color: white; border: none; padding: 15px; border-radius: 10px; font-size: 1.2rem; font-weight: bold; cursor: pointer; box-shadow: 0 4px 0 #007BB5; width: 100%; transition: 0.2s;}
        .btn-manual { background: transparent; color: #D32F2F; border: 2px solid #D32F2F; padding: 15px; border-radius: 10px; font-size: 1.1rem; font-weight: bold; cursor: pointer; width: 100%; transition: 0.2s;}
        .btn-cancelar { background: #f44336; color: white; border: none; padding: 15px; border-radius: 10px; font-size: 1.1rem; font-weight: bold; cursor: pointer; width: 100%; margin-top: 15px;}

        /* MODO OSCURO */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; border-top: 3px solid #D32F2F; }
        body.modo-oscuro .ticket-papel { background-color: #b0bec5 !important; color: #111 !important; border-top-color: #78909c !important; }
        body.modo-oscuro .ticket-papel h2 { color: #000 !important; border-bottom-color: #90a4ae !important; }
        body.modo-oscuro .lista-items li { border-bottom-color: #90a4ae !important; }
        body.modo-oscuro .panel-cobro { background: #1e1e1e !important; border-color: #333 !important; }
        body.modo-oscuro .panel-cobro h3 { color: #eee !important; }
        body.modo-oscuro select { background: #333 !important; color: white !important; border-color: #555 !important; }
    </style>
</head>
<body>
    <div class="nav-hub"><span style="font-size: 1.6rem; font-weight: 800;">🍔 SALA DE PAGO</span></div>
    
    <div class="container">
        <div class="ticket-papel">
            <h2>Pedido: <?php echo htmlspecialchars($nombre_cliente); ?></h2>
            <ul class="lista-items">
                <?php foreach($items as $i): ?>
                    <li>
                        <span><?php echo htmlspecialchars($i['nombre']); ?> <br> <small style="color:#666;"><?php echo htmlspecialchars($i['notas']); ?></small></span>
                        <span style="font-weight: bold;">$<?php echo number_format($i['precio_al_momento'], 0, ',', '.'); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="gran-total"><span>TOTAL:</span><span>$<?php echo number_format($subtotal, 0, ',', '.'); ?></span></div>
        </div>

        <div>
            <?php if(isset($_GET['enviado_mp'])): ?>
                <div style="background: #e1f5fe; border: 2px solid #009EE3; color: #0277bd; padding: 15px; border-radius: 10px; margin-bottom: 20px; text-align: center;">
                    <strong style="font-size: 1.1rem;">📡 ¡Enviado a la Maquinita!</strong><br>
                    El pedido pasará automáticamente a la cocina cuando el cliente acerque la tarjeta.
                </div>
            <?php endif; ?>

            <div class="panel-cobro">
                <h3 style="margin-top:0;">💳 Cobro Electrónico</h3>
                <?php
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
                <?php endwhile; else: ?>
                    <p style="text-align: center; color: #ff8c00;">⚠️ No hay máquinas registradas. <a href="r_configuracion_pagos.php" style="color: #009EE3;">Configúralas aquí</a></p>
                <?php endif; ?>

                <hr style="border: 1px dashed #ccc; margin: 25px 0;">

                <form method="POST">
                    <h3>💵 Cobro en Efectivo</h3>
                    <div class="grupo-form">
                        <select name="metodo_pago" required>
                            <option value="efectivo">Efectivo Físico</option>
                            <option value="transferencia">Transferencia a Cuenta</option>
                        </select>
                    </div>
                    <button type="submit" name="procesar_pago_manual" class="btn-manual">✅ Pagar y Enviar a Cocina</button>
                </form>

                <hr style="border: 1px dashed #ccc; margin: 25px 0;">

                <form method="POST" style="margin-top: 15px;">
                    <button type="submit" name="cancelar_pedido" class="btn-cancelar" onclick="return confirm('¿Seguro que quieres borrar este pedido y cancelarlo?');">❌ Cancelar Pedido</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', (event) => {
            if (localStorage.getItem('temaMesas') === 'oscuro') { document.body.classList.add('modo-oscuro'); }
        });
    </script>
</body>
</html>