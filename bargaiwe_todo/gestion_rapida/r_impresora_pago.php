<?php
function imprimirTicketBargaiwe($ticket_id, $conn, $res_id) {
    // 1. Obtener Configuración
    $conf = $conn->query("SELECT * FROM config_impresora WHERE restaurant_id = $res_id")->fetch_assoc();
    if (!$conf || !$conf['impresora_activa']) return;

    // 2. Obtener Datos del Pedido
    $res_p = $conn->query("SELECT p.*, m.nombre FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.codigo_grupo = '$ticket_id' AND p.restaurant_id = $res_id");
    
    $total = 0;
    $cliente = "";
    $titulo = strtoupper($conf['titulo_ticket'] ?? 'MI NEGOCIO');
    
    // Armamos el texto para la impresora térmica
    $cuerpo = "--------------------------------\n";
    $cuerpo .= "      $titulo\n";
    $cuerpo .= "--------------------------------\n";

    while ($row = $res_p->fetch_assoc()) {
        $cliente = $row['cliente_nombre'];
        $cuerpo .= $row['cantidad'] . "x " . substr($row['nombre'], 0, 20) . "\n";
        if ($conf['imprimir_valor']) {
            $cuerpo .= "      $" . number_format($row['precio_al_momento'], 0, ',', '.') . "\n";
        }
        $total += $row['precio_al_momento'];
    }

    $cuerpo .= "--------------------------------\n";
    $cuerpo .= "PEDIDO: $cliente\n";
    
    if ($conf['imprimir_valor']) {
        $cuerpo .= "TOTAL: $" . number_format($total, 0, ',', '.') . "\n";
    }
    $cuerpo .= "--------------------------------\n";
    
    if (!empty($conf['mensaje_opcional'])) {
        $cuerpo .= "  " . $conf['mensaje_opcional'] . "\n";
    }
    $cuerpo .= "  " . ($conf['encabezado_ticket'] ?? '') . "\n\n\n\n\n";

    // 3. ENVIAR AL HARDWARE (Comando Linux)
    $puerto = $conf['puerto_linux'] ?? '/dev/usb/lp0';
    $comando = "echo " . escapeshellarg($cuerpo) . " > $puerto";
    shell_exec($comando);
}
?>