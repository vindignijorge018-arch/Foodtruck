<?php
session_start();
include 'gestion_restaurante/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $conn->query("CREATE TABLE IF NOT EXISTS mensajes_index (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        correo VARCHAR(100) NOT NULL,
        asunto VARCHAR(150) NOT NULL,
        mensaje TEXT NOT NULL,
        fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        leido TINYINT(1) DEFAULT 0
    )");

    $nombre = $conn->real_escape_string($_POST['nombre']);
    $correo = $conn->real_escape_string($_POST['correo']);
    $asunto = $conn->real_escape_string($_POST['asunto']);
    $mensaje = $conn->real_escape_string($_POST['mensaje']);

    $sql = "INSERT INTO mensajes_index (nombre, correo, asunto, mensaje) VALUES ('$nombre', '$correo', '$asunto', '$mensaje')";
    
    if ($conn->query($sql)) {
        header("Location: index.html?contacto=exito#contacto");
        exit();
    } else {
        echo "Error: " . $conn->error;
    }
} else {
    header("Location: index.html");
    exit();
}
?>