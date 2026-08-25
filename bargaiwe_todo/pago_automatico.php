<?php

$json_event = file_get_contents('php://input', true);
$event = json_decode($json_event);


if (!isset($event->type) || !isset($event->data->id)) {
    http_response_code(400);
    die("Acceso denegado. Este archivo es solo para el servidor de pagos.");
}


include 'gestion_restaurante/db.php';


if ($event->type == 'payment') {

    $id_pago_mercado_pago = $event->data->id;


    $estado_pago = 'approved'; 
    $email_cliente = 'admin@turestaurante.com'; 
    
    if ($estado_pago == 'approved') {

        $res = $conn->query("SELECT id, fecha_vencimiento FROM restaurantes WHERE email = '$email_cliente'");
        
        if ($res && $row = $res->fetch_assoc()) {
            $id_rest = $row['id'];
            $fecha_actual = new DateTime($row['fecha_vencimiento']);
            $hoy = new DateTime();

            $base_fecha = ($fecha_actual > $hoy) ? $row['fecha_vencimiento'] : date('Y-m-d');
            

            $nueva_fecha = date('Y-m-d', strtotime($base_fecha . ' + 30 days'));
            
            $conn->query("UPDATE restaurantes SET fecha_vencimiento = '$nueva_fecha' WHERE id = $id_rest");
            

            http_response_code(200);
            echo "Renovación exitosa aplicada.";
        }
    }
} else {
    http_response_code(200);
}
?>