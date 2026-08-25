<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';

// Verificación de seguridad
if (!isset($_SESSION['restaurant_id'])) {
    die("Error: Sesión no iniciada.");
}
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// Obtener Configuración de la Impresora
$conf = $conn->query("SELECT * FROM config_impresora WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
if (!$conf) { 
    $conf = ['titulo_ticket' => 'MI NEGOCIO', 'encabezado_ticket' => '¡Gracias por su compra!', 'mensaje_opcional' => '', 'imprimir_valor' => 1]; 
}

// Recibimos qué tipo de ticket es
$mesa_id = isset($_GET['mesa_id']) ? (int)$_GET['mesa_id'] : 0;
$delivery_id = isset($_GET['delivery_id']) ? (int)$_GET['delivery_id'] : 0;

$es_delivery = ($delivery_id > 0);
$items = [];
$total = 0;
$ahorro_total = 0;
$titulo_orden = "";
$info_extra = "";

if ($es_delivery) {
    // LÓGICA PARA TICKET DE DELIVERY
    $titulo_orden = "DELIVERY #" . $delivery_id;
    $res_cli = $conn->query("SELECT * FROM registro_delivery WHERE id = $delivery_id AND restaurant_id = $mi_restaurant_id");
    if ($res_cli && $cli = $res_cli->fetch_assoc()) {
        $info_extra = "<div style='margin: 3px 0; text-align: left;'><strong>Cli:</strong> " . htmlspecialchars($cli['cliente']) . "</div>";
        if (!empty($cli['telefono'])) $info_extra .= "<div style='margin: 3px 0; text-align: left;'><strong>Tel:</strong> " . htmlspecialchars($cli['telefono']) . "</div>";
        if (!empty($cli['direccion'])) $info_extra .= "<div style='margin: 3px 0; text-align: left;'><strong>Dir:</strong> " . htmlspecialchars($cli['direccion']) . "</div>";
    }
    // Añadimos m.precio as precio_original para calcular ahorros
    $sql_items = "SELECT m.nombre, m.precio as precio_original, p.precio_al_momento, p.notas FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.delivery_id = $delivery_id";

} else if ($mesa_id > 0) {
    // LÓGICA PARA TICKET DE MESA
    $res_mesa = $conn->query("SELECT numero_mesa FROM mesas WHERE id = $mesa_id AND restaurant_id = $mi_restaurant_id");
    $num_mesa = ($res_mesa && $row = $res_mesa->fetch_assoc()) ? $row['numero_mesa'] : '?';
    $titulo_orden = "MESA #" . $num_mesa;
    $sql_items = "SELECT m.nombre, m.precio as precio_original, p.precio_al_momento, p.notas FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.mesa_id = $mesa_id AND p.estado IN (1, 2)";
} else {
    die("Error: No se especificó orden a imprimir.");
}

// Ejecutamos la búsqueda de platos
$res_items = $conn->query($sql_items);
if($res_items) {
    while($row = $res_items->fetch_assoc()) {
        $items[] = $row;
        $total += $row['precio_al_momento'];
        if ($row['precio_original'] > $row['precio_al_momento']) {
            $ahorro_total += ($row['precio_original'] - $row['precio_al_momento']);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Imprimiendo <?php echo $titulo_orden; ?></title>
    <style>
        /* === RESET PARA IMPRESORAS TÉRMICAS DE 58mm === */
        @media print { 
            @page { margin: 0; size: 58mm auto; } 
            html, body { margin: 0; padding: 0; width: 58mm; background: white; } 
            .no-imprimir { display: none !important; }
        }
        
        body { 
            font-family: 'Courier New', Courier, monospace; 
            width: 100%; 
            max-width: 58mm; 
            margin: 0 auto; 
            /* El padding empuja el texto para que no se salga del papel, 
               box-sizing evita que el ticket se haga más ancho de 58mm */
            padding: 0 3mm; 
            box-sizing: border-box; 
            color: black;
            background: white;
            font-size: 11px; /* Letra un poco más chica para 58mm */
        }
        
        .centrado { text-align: center; }
        .separador { border-top: 1px dashed black; margin: 8px 0; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 3px 0; vertical-align: top; }
        .precio { text-align: right; white-space: nowrap; }
        .total { font-weight: bold; font-size: 13px; border-top: 1px dashed black; padding-top: 5px; }
        .nota { font-size: 9.5px; font-style: italic; }
    </style>
</head>
<body>

    <div class="centrado" style="font-size: 9px; margin-bottom: 5px;">
        --------------------------------
    </div>

    <div class="centrado">
        <h2 style="margin: 0 0 5px 0; font-size: 16px; text-transform: uppercase;">
            <?php echo htmlspecialchars($conf['titulo_ticket']); ?>
        </h2>
        <p style="margin: 0; font-weight: bold; font-size: 13px;"><?php echo $titulo_orden; ?></p>
        <p style="margin: 2px 0 0 0; font-size: 10px;">Fecha: <?php echo date('d/m/Y H:i'); ?></p>
    </div>

    <div class="separador"></div>

    <?php if(!empty($info_extra)): ?>
        <?php echo $info_extra; ?>
        <div class="separador"></div>
    <?php endif; ?>

    <table>
        <tr>
            <th style="width: 70%;">Cant x Desc.</th>
            <th class="precio" style="width: 30%;">Subt.</th>
        </tr>
        <?php foreach($items as $i): ?>
        <tr>
            <td>
                1x <?php echo htmlspecialchars(substr($i['nombre'], 0, 15)); ?>
                <?php if(!empty($i['notas'])): ?><br><span class="nota">» <?php echo htmlspecialchars($i['notas']); ?></span><?php endif; ?>
            </td>
            <?php if ($conf['imprimir_valor']): ?>
                <td class="precio">$<?php echo number_format($i['precio_al_momento'], 0, ',', '.'); ?></td>
            <?php else: ?>
                <td class="precio">--</td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>

    <div class="separador"></div>

    <?php if ($conf['imprimir_valor']): ?>
        <table>
            <?php if ($ahorro_total > 0): ?>
            <tr>
                <td style="text-align: right; width: 60%; font-style: italic;">AHORRO:</td>
                <td class="precio" style="width: 40%; font-style: italic; color: #333;">-$<?php echo number_format($ahorro_total, 0, ',', '.'); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td class="total" style="text-align: right; width: 60%;">TOTAL:</td>
                <td class="total precio" style="width: 40%;">$<?php echo number_format($total, 0, ',', '.'); ?></td>
            </tr>
        </table>
    <?php endif; ?>

    <?php if (!empty($conf['mensaje_opcional'])): ?>
        <div class="centrado" style="margin-top: 15px; font-weight: bold; font-size: 12px;">
            <?php echo nl2br(htmlspecialchars($conf['mensaje_opcional'])); ?>
        </div>
    <?php endif; ?>

    <div class="centrado" style="margin-top: 10px; margin-bottom: 20px;">
        <p style="margin: 0;"><?php echo nl2br(htmlspecialchars($conf['encabezado_ticket'] ?? '¡Gracias por su preferencia!')); ?></p>
    </div>

    <div class="centrado no-imprimir" style="margin-top: 30px; padding: 10px; border-top: 1px solid #ccc; background: #f9f9f9;">
        <button onclick="window.print()" style="padding: 10px 15px; font-size: 14px; margin-bottom: 5px; cursor: pointer;">🖨️ Imprimir de nuevo</button>
        <button onclick="window.close()" style="padding: 10px 15px; font-size: 14px; cursor: pointer; color: red;">❌ Cerrar</button>
    </div>

    <script>
        // Dispara la impresión automáticamente
        window.onload = function() { window.print(); };
    </script>
</body>
</html>