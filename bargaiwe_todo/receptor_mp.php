<?php
include 'db.php';

$input_bruto = file_get_contents("php://input");
$notificacion = json_decode($input_bruto, true);

if (isset($notificacion['action']) && $notificacion['action'] == 'payment.created') {
    $id_pago = $notificacion['data']['id'];


    $res_conf = $conn->query("SELECT access_token FROM mp_credenciales LIMIT 1");
    $master_token = $res_conf->fetch_assoc()['access_token'];


    $url = "" . $id_pago;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $master_token]);
    $detalles = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($detalles['status']) && $detalles['status'] == 'approved') {
        $referencia = $detalles['external_reference']; 
        $partes = explode("_", $referencia);
        
        $rest_id = (int)$partes[1];
        $tipo = $partes[2];
        $id_obj = (int)$partes[3];

        if ($tipo == 'MESA') {
            $conn->query("UPDATE pedidos SET estado = 3 WHERE mesa_id = $id_obj AND restaurant_id = $rest_id");
            $conn->query("UPDATE mesas SET estado = 0 WHERE id = $id_obj");
        } else {

            $conn->query("UPDATE pedidos SET estado = 2 WHERE codigo_grupo = '$id_obj' AND restaurant_id = $rest_id");
        }

        // 4. GUARDAR EN AUDITORÍA
        $monto = $detalles['transaction_amount'];
        $metodo = $detalles['payment_method_id'];
        $fecha = date("Y-m-d H:i:s");
        
        $stmt = $conn->prepare("INSERT INTO auditoria_pagos (restaurant_id, pago_id_mp, monto, metodo_pago, estado, referencia_interna, fecha_pago) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ref_texto = "$tipo #$id_obj";
        $stmt->bind_param("isdssss", $rest_id, $id_pago, $monto, $metodo, $detalles['status'], $ref_texto, $fecha);
        $stmt->execute();
    }
}
http_response_code(200);
?>