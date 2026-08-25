<?php
session_start();
include 'r_db.php';

// Configuramos la zona horaria para Chile (Antofagasta)
date_default_timezone_set('America/Santiago');

if (!isset($_SESSION['restaurant_id']) || !isset($_GET['ticket'])) {
    die("Acceso denegado o ticket inválido.");
}

$mi_restaurant_id = (int)$_SESSION['restaurant_id'];
$ticket_id = $conn->real_escape_string($_GET['ticket']);

// 1. Obtener la configuración
$conf = $conn->query("SELECT * FROM config_impresora WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
if (!$conf) { 
    $conf = ['titulo_ticket' => 'MI NEGOCIO', 'encabezado_ticket' => '¡Gracias por su compra!', 'imprimir_valor' => 1, 'tamano_letra' => 2]; 
}

$size_css = "12px"; 
if ($conf['tamano_letra'] == 1) $size_css = "10px";
if ($conf['tamano_letra'] == 3) $size_css = "14px";

$sql = "SELECT 
            m.nombre, 
            p.comentario, 
            p.cliente_nombre, 
            p.precio_al_momento, 
            SUM(p.cantidad) as cantidad 
        FROM pedidos p 
        JOIN menu m ON p.menu_id = m.id 
        WHERE p.codigo_grupo = '$ticket_id' AND p.restaurant_id = $mi_restaurant_id
        GROUP BY p.menu_id, m.nombre, p.comentario, p.cliente_nombre, p.precio_al_momento";
        
$res_p = $conn->query($sql);

$items = [];
$total = 0;
$cliente = "Cliente";

if ($res_p) {
    while ($row = $res_p->fetch_assoc()) {
        // 1. Calculamos el subtotal de esta línea
        $row['subtotal_linea'] = $row['cantidad'] * $row['precio_al_momento'];
        
        $items[] = $row;
        
        // 2. Sumamos al total general
        $total += $row['subtotal_linea'];
        
        $cliente = $row['cliente_nombre'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Imprimiendo Ticket...</title>
    <style>
        /* === DISEÑO DE LA PANTALLA (PC) === */
        body { 
            background: #0d1117; 
            font-family: 'Segoe UI', sans-serif; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            min-height: 100vh; 
            margin: 0; 
        }
        
        .panel-controles { 
            background: #161b22; 
            padding: 25px; 
            border-radius: 12px; 
            text-align: center; 
            margin-bottom: 30px; 
            border: 1px solid #30363d; 
            color: white; 
            width: 90%; 
            max-width: 400px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        .btn-volver { 
            background: #238636; 
            color: white; 
            border: none; 
            padding: 15px; 
            font-size: 1.2rem; 
            border-radius: 8px; 
            cursor: pointer; 
            font-weight: bold; 
            width: 100%; 
            margin-top: 15px; 
            text-decoration: none; 
            display: block;
            box-sizing: border-box;
            transition: 0.2s;
        }
        .btn-volver:hover { background: #2ea043; }

        /* Contenedor visual del ticket en pantalla */
        .ticket-papel { 
            background: white; 
            width: 48mm; 
            padding: 0mm 2mm; 
            color: black; 
            font-family: 'Courier New', Courier, monospace; 
            font-size: <?php echo $size_css; ?>; 
            line-height: 1.1; 
        }

        /* Clase para el título que evita el manchón */
        .titulo-negocio {
            font-size: 1.3em;
            font-weight: bold;
            margin-top: 15px; 
            margin-bottom: 2px;
            text-transform: uppercase;
        }

        /* Clases internas del ticket */
        .text-center { text-align: center; }
        .bold { font-weight: bold; }
        .divider { border-top: 1px dashed black; margin: 8px 0; }
        .item-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px; }
        .item-qty { width: 15%; font-weight: bold; }
        .item-name { width: <?php echo $conf['imprimir_valor'] ? '55%' : '85%'; ?>; word-wrap: break-word; }
        .item-price { width: 30%; text-align: right; }

        /* === DISEÑO EXCLUSIVO PARA LA IMPRESORA === */
        @media print {
            @page { margin: 0; size: 58mm auto; }
            body { background: white; display: block; min-height: auto; padding: 0; margin: 0; }
            .panel-controles { display: none !important; }
            .ticket-papel { width: 48mm; padding: 0; box-shadow: none; margin: 0 auto; padding-top: 0px; }
        }
    </style>
</head>
<body onload="iniciarImpresion();">

    <div class="panel-controles">
        <h2 style="margin-top: 0; color: #58a6ff;">🖨️ Enviando a Ticketera...</h2>
        <p style="color: #8b949e; font-size: 0.95rem; margin-bottom: 0;">Si tu navegador está en "Modo Kiosco", esto fue automático.</p>
        <a href="r_pedidos.php?exito=1" class="btn-volver">✅ Volver a la Caja</a>
    </div>

    <div class="ticket-papel">
        
        <div class="text-center" style="font-size: 10px; margin-bottom: 8px;">
            --------------------------------
        </div>

        <div class="text-center titulo-negocio" style="margin-top: 0;">
            <?php echo strtoupper(htmlspecialchars($conf['titulo_ticket'] ?? 'MI NEGOCIO')); ?>
        </div>
        
        <div class="text-center" style="font-size: 0.85em; margin-bottom: 10px;">
             <?php echo date('d/m/Y H:i'); ?>
        </div>
        
        <div class="text-center bold" style="font-size: 1.3em; margin-bottom: 15px; border-bottom: 2px solid black; padding-bottom: 5px;">
             PARA: <?php echo strtoupper(htmlspecialchars($cliente)); ?>
        </div>

        <div class="divider"></div>

        <?php foreach ($items as $item): ?>
            <div class="item-row">
                <div class="item-qty"><?php echo $item['cantidad']; ?>x</div>
                <div class="item-name">
                    <?php echo htmlspecialchars($item['nombre']); ?>
                    <?php if (!empty($item['comentario'])): ?>
                        <div style="font-size: 0.85em; font-style: italic;">* <?php echo htmlspecialchars($item['comentario']); ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($conf['imprimir_valor']): ?>
                    <!-- Aquí imprimimos el subtotal calculado -->
                    <div class="item-price">$<?php echo number_format($item['subtotal_linea'], 0, ',', '.'); ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="divider"></div>

        <?php 
        // Lógica de Ahorro
        $ahorro_total = 0;
        foreach ($items as $item) {
            if (isset($item['descuento_aplicado']) && $item['descuento_aplicado'] > 0) {
                $ahorro_total += $item['descuento_aplicado'];
            }
        }

        if ($ahorro_total > 0 && $conf['imprimir_valor']): ?>
            <div class="item-row" style="color: black; font-style: italic;">
                <div style="width: 50%;">AHORRO:</div>
                <div style="width: 50%; text-align: right;">$ -<?php echo number_format($ahorro_total, 0, ',', '.'); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($conf['imprimir_valor']): ?>
            <div class="item-row bold" style="font-size: 1.1em; margin-top: 5px;">
                <div style="width: 50%;">TOTAL A PAGAR:</div>
                <div style="width: 50%; text-align: right;">$<?php echo number_format($total, 0, ',', '.'); ?></div>
            </div>
            <div class="divider"></div>
        <?php endif; ?>

        <?php if (!empty($conf['mensaje_opcional'])): ?>
            <div class="text-center" style="margin-top: 12px; font-weight: bold; font-size: 1.1em;">
                <?php echo nl2br(htmlspecialchars($conf['mensaje_opcional'])); ?>
            </div>
        <?php endif; ?>

        <div class="text-center" style="margin-top: 15px; margin-bottom: 30px;">
            <?php echo nl2br(htmlspecialchars($conf['encabezado_ticket'] ?? '¡Gracias por su compra!')); ?>
        </div>
    </div>
    
    <script>
        function iniciarImpresion() {
            window.print();
        }
        window.onafterprint = function() {
            window.location.href = 'r_pedidos.php?exito=1';
        };
    </script>
</body>
</html>