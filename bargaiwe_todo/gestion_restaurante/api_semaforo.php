<?php
session_start();
include 'db.php'; // Usa tu archivo de conexión real (puede ser r_db.php si estás en el código nuevo)

if (!isset($_SESSION['restaurant_id'])) { die(json_encode(['error' => 'No autorizado'])); }
$rest_id = (int)$_SESSION['restaurant_id'];

// Consultamos todas las mesas ocupadas y contamos en qué estado están sus pedidos
// estado = 1 (En cocina), estado = 2 (Listo en cocina), estado = 4 (Entregado al cliente)
$sql = "SELECT m.id as mesa_id,
        SUM(CASE WHEN p.estado = 1 THEN 1 ELSE 0 END) as cocinando,
        SUM(CASE WHEN p.estado = 2 THEN 1 ELSE 0 END) as listos
        FROM mesas m
        LEFT JOIN pedidos p ON m.id = p.mesa_id
        WHERE m.restaurant_id = $rest_id AND m.estado = 1
        GROUP BY m.id";

$res = $conn->query($sql);
$semaforos = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $color = '#32CD32'; // 🟢 Verde por defecto (Todo entregado o sin pedidos)
        
        if ($row['cocinando'] > 0) {
            $color = '#E53935'; // 🔴 Rojo (La cocina está preparando algo)
        } elseif ($row['listos'] > 0) {
            $color = '#FFC107'; // 🟡 Amarillo (La cocina terminó, esperando al mesero)
        }
        
        $semaforos[] = [
            'mesa_id' => $row['mesa_id'],
            'color' => $color
        ];
    }
}

// Devolvemos la información en formato JSON para que JavaScript la lea
header('Content-Type: application/json');
echo json_encode($semaforos);
?>