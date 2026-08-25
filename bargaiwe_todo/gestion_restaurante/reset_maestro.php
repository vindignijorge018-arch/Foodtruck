<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

// 1. SEGURIDAD SAAS: ID Dinámico y validación estricta
if (!isset($_SESSION['restaurant_id']) || empty($_SESSION['restaurant_id'])) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>⛔ Acceso Denegado</h2><p>Debes iniciar sesión primero.</p></div>");
}
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// --- 2. LÓGICA DE BORRADO (Solo se activa si se envía el formulario POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validación de la palabra de seguridad
    if (!isset($_POST['palabra_seguridad']) || strtoupper(trim($_POST['palabra_seguridad'])) !== 'BORRAR') {
        $error_msg = "Palabra de seguridad incorrecta. No se borró nada.";
    } else {
        // Tablas operativas reales de tu sistema
        $tablas_propias = [
            'pedidos', 
            'menu', 
            'mesas', 
            'registro_delivery', 
            'objetos_mapa', 
            'pisos_personalizados', 
            'ingredientes', 
            'gastos', 
            'ingresos_extra',
            'mapa_config'
        ];

        // Ejecutar con transacciones
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $conn->begin_transaction();

        try {
            foreach ($tablas_propias as $tabla) {
                $stmt = $conn->prepare("DELETE FROM $tabla WHERE restaurant_id = ?");
                $stmt->bind_param("i", $mi_restaurant_id);
                $stmt->execute();
            }

            // Limpieza manual de la tabla puente (recetas)
            $conn->query("DELETE FROM recetas WHERE menu_id NOT IN (SELECT id FROM menu) OR ingrediente_id NOT IN (SELECT id FROM ingredientes)");

            $conn->commit();
            $exito = true;

        } catch (Exception $e) {
            $conn->rollback();
            die("Error crítico de base de datos. Se revirtieron los cambios. Detalles: " . $e->getMessage());
        }

        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Reinicio Maestro</title>
    <style>
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card-peligro { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border-top: 8px solid #E53935; text-align: center; max-width: 500px; }
        h1 { color: #E53935; font-size: 2.5rem; margin-top: 0; }
        .input-seguridad { width: 100%; padding: 15px; font-size: 1.2rem; text-align: center; border: 2px dashed #E53935; border-radius: 8px; margin: 20px 0; box-sizing: border-box; font-weight: bold;}
        .btn-borrar { background: #E53935; color: white; border: none; padding: 15px 30px; font-size: 1.2rem; font-weight: bold; border-radius: 8px; cursor: pointer; width: 100%; transition: 0.3s; }
        .btn-borrar:hover { background: #b71c1c; }
        .btn-cancelar { display: block; margin-top: 20px; color: #666; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

    <?php if (isset($exito) && $exito): ?>
        <div class="card-peligro" style="border-top-color: #32CD32;">
            <h1 style="color: #32CD32;">✨ REINICIO COMPLETADO</h1>
            <p>Toda la data operativa de tu restaurante ha sido eliminada con éxito. Las configuraciones y tus metas se han mantenido intactas.</p>
            <a href="mesas.php" class="btn-borrar" style="background: #32CD32;">Volver al Tablero</a>
        </div>
    <?php else: ?>
        <div class="card-peligro">
            <h1>⚠️ ZONA DE PELIGRO</h1>
            <h2>Restaurante ID: <?php echo $mi_restaurant_id; ?></h2>
            <p style="color: #666; font-size: 1.1rem; line-height: 1.5;">Estás a punto de borrar todo tu menú, inventario, mesas configuradas, historial de gastos y estadística de ventas.</p>
            <p style="font-weight: bold;">Esta acción no se puede deshacer.</p>

            <?php if (isset($error_msg)): ?>
                <div style="background: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-weight: bold;">
                    <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <label style="display: block; font-size: 0.9rem; color: #555;">Escribe la palabra <strong>BORRAR</strong> para confirmar:</label>
                <input type="text" name="palabra_seguridad" class="input-seguridad" placeholder="Escribe BORRAR aquí" required autocomplete="off">
                <button type="submit" class="btn-borrar">EJECUTAR REINICIO MAESTRO</button>
            </form>

            <a href="mesas.php" class="btn-cancelar">← Mejor me arrepiento (Volver)</a>
        </div>
    <?php endif; ?>

</body>
</html>