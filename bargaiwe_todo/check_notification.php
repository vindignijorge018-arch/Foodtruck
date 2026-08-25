<?php
error_reporting(0);
include 'gestion_restaurante/db.php';

$res_soporte = $conn->query("SELECT COUNT(*) as total FROM mensajes_soporte WHERE leido = 0 AND remitente = 'cliente'");
$count_soporte = ($res_soporte) ? (int)$res_soporte->fetch_assoc()['total'] : 0;

$res_index = $conn->query("SELECT COUNT(*) as total FROM mensajes_index WHERE leido = 0");
$count_index = ($res_index) ? (int)$res_index->fetch_assoc()['total'] : 0;

$total_mensajes = $count_soporte + $count_index;

header('Content-Type: application/json');
echo json_encode(['nuevos_mensajes' => $total_mensajes]);
?>