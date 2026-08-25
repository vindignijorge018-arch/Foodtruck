<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'r_db.php';

if (!isset($_SESSION['restaurant_id'])) { header("Location: ../portal_bargaiwe.php"); exit(); }
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// --- 1. INSTALACIÓN AUTOMÁTICA DE TABLAS (Por si este módulo se usa primero) ---
$conn->query("CREATE TABLE IF NOT EXISTS mp_credenciales (
    restaurant_id INT PRIMARY KEY,
    access_token VARCHAR(255) NOT NULL DEFAULT '',
    modo_prueba TINYINT(1) NOT NULL DEFAULT 1
)");

$conn->query("CREATE TABLE IF NOT EXISTS mp_terminales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    nombre_caja VARCHAR(50) NOT NULL,
    pos_id VARCHAR(100) NOT NULL
)");

$conn->query("INSERT IGNORE INTO mp_credenciales (restaurant_id) VALUES ($mi_restaurant_id)");

// --- 2. LÓGICA DE GUARDADO ---
if (isset($_POST['guardar_token'])) {
    $token = $conn->real_escape_string($_POST['access_token']);
    $modo = isset($_POST['modo_prueba']) ? 1 : 0;
    $conn->query("UPDATE mp_credenciales SET access_token = '$token', modo_prueba = $modo WHERE restaurant_id = $mi_restaurant_id");
    header("Location: r_configuracion_pagos.php?exito=1"); exit();
}

if (isset($_POST['agregar_caja'])) {
    $nombre = $conn->real_escape_string($_POST['nombre_caja']);
    $pos_id = $conn->real_escape_string($_POST['pos_id']);
    $conn->query("INSERT INTO mp_terminales (restaurant_id, nombre_caja, pos_id) VALUES ($mi_restaurant_id, '$nombre', '$pos_id')");
    header("Location: r_configuracion_pagos.php?exito=1"); exit();
}

if (isset($_GET['borrar_caja'])) {
    $id_caja = (int)$_GET['borrar_caja'];
    $conn->query("DELETE FROM mp_terminales WHERE id = $id_caja AND restaurant_id = $mi_restaurant_id");
    header("Location: r_configuracion_pagos.php"); exit();
}

// --- 3. CONSULTAS PARA LA VISTA ---
$credenciales = $conn->query("SELECT * FROM mp_credenciales WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
$terminales = $conn->query("SELECT * FROM mp_terminales WHERE restaurant_id = $mi_restaurant_id ORDER BY id ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bargaiwe Fast - Configuración de Pagos</title>
    <style>
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        /* Barra superior con un tono naranja/rojo para diferenciar Comida Rápida */
        .nav-hub { background: #D32F2F; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 10px; background: rgba(0,0,0,0.2); color: white; transition: 0.3s; }
        .nav-hub a:hover { background: rgba(0,0,0,0.4); }
        .container { padding: 40px; max-width: 1000px; margin: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start;}
        
        .card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 6px 15px rgba(0,0,0,0.05); border-top: 5px solid #009EE3; }
        .card h3 { margin-top: 0; color: #009EE3; border-bottom: 2px solid #eee; padding-bottom: 10px; font-size: 1.3rem;}
        
        .form-grupo { margin-bottom: 15px; }
        .form-grupo label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.95rem; color: #444;}
        .form-grupo input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 8px; font-size: 1rem; box-sizing: border-box;}
        
        .btn-azul { background: #009EE3; color: white; border: none; padding: 15px; width: 100%; border-radius: 10px; font-size: 1.1rem; font-weight: bold; cursor: pointer; margin-top: 10px; transition: 0.2s;}
        .btn-azul:hover { background: #008cc9; }
        .btn-verde { background: #32CD32; color: white; border: none; padding: 15px; width: 100%; border-radius: 10px; font-size: 1.1rem; font-weight: bold; cursor: pointer; margin-top: 10px;}
        
        .tabla-cajas { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .tabla-cajas th, .tabla-cajas td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        .tabla-cajas th { background: #f4f4f4; border-radius: 5px; }
        .btn-borrar { color: white; background: #E53935; text-decoration: none; padding: 5px 10px; border-radius: 5px; font-size: 0.8rem; font-weight: bold;}

        /* Toggle Switch */
        .switch { position: relative; display: inline-block; width: 50px; height: 28px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #009EE3; }
        input:checked + .slider:before { transform: translateX(22px); }

        /* --- MODO OSCURO AUTOMÁTICO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; border-top: 3px solid #D32F2F; }
        body.modo-oscuro .card { background: #1e1e1e !important; box-shadow: 0 4px 10px rgba(0,0,0,0.5); border: 1px solid #333; border-top: 5px solid #009EE3; }
        body.modo-oscuro h3, body.modo-oscuro .form-grupo label { color: #ccc !important; border-bottom-color: #333; }
        body.modo-oscuro input { background: #333; color: white; border-color: #555; }
        body.modo-oscuro .tabla-cajas th { background: #2a2a2a; border-bottom-color: #444; color: #ccc; }
        body.modo-oscuro .tabla-cajas td { border-bottom-color: #444; color: #eee; }
        body.modo-oscuro p { color: #aaa; }
    </style>
</head>
<body>
    <div class="nav-hub">
        <span style="font-size: 1.6rem; font-weight: 800;">🍔 Fast Food - Terminales Point</span>
        <a href="r_pedidos.php">← Volver a Pedidos</a>
    </div>

    <div class="container">
        
        <?php if(isset($_GET['exito'])): ?>
            <div style="grid-column: span 2; background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; text-align:center; font-weight:bold; margin-bottom: 10px;">
                ✅ Configuración de cobro guardada correctamente.
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>🔑 1. Credenciales de Mercado Pago</h3>
            <p style="font-size: 0.9rem; color: #666; margin-bottom: 20px;">
                Pega aquí tu <strong>Access Token</strong>. Es compartido con todo el restaurante.
            </p>

            <form method="POST">
                <div class="form-grupo">
                    <label>Access Token</label>
                    <input type="password" name="access_token" value="<?php echo htmlspecialchars($credenciales['access_token']); ?>" placeholder="Ej: APP_USR-123456789..." required>
                </div>
                
                <div class="form-grupo" style="display: flex; justify-content: space-between; align-items: center; background: #f4f4f4; padding: 15px; border-radius: 10px; margin-top: 20px;">
                    <div>
                        <label style="margin: 0;">Modo de Pruebas (Sandbox)</label>
                        <span style="font-size: 0.8rem; color: #888;">Simula pagos sin usar tarjetas reales.</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="modo_prueba" <?php echo ($credenciales['modo_prueba'] == 1) ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <button type="submit" name="guardar_token" class="btn-azul">💾 Guardar Credenciales</button>
            </form>
        </div>

        <div class="card" style="border-top-color: #32CD32;">
            <h3 style="color: #32CD32;">🖥️ 2. Terminales Físicas (Point)</h3>
            <p style="font-size: 0.9rem; color: #666; margin-bottom: 20px;">
                Registra el nombre virtual (POS ID) de la máquina que usas en este Foodtruck.
            </p>

            <form method="POST" style="background: #fafafa; padding: 15px; border-radius: 10px; border: 1px dashed #ccc; margin-bottom: 20px;">
                <div class="form-grupo">
                    <label>Nombre (Ej: Caja Foodtruck)</label>
                    <input type="text" name="nombre_caja" required>
                </div>
                <div class="form-grupo">
                    <label>POS_ID de Mercado Pago</label>
                    <input type="text" name="pos_id" placeholder="Ej: CAJA_RAPIDA_01" required>
                </div>
                <button type="submit" name="agregar_caja" class="btn-verde">➕ Añadir Dispositivo</button>
            </form>

            <?php if($terminales->num_rows > 0): ?>
                <table class="tabla-cajas">
                    <tr><th>Nombre</th><th>POS ID</th><th></th></tr>
                    <?php while($caja = $terminales->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($caja['nombre_caja']); ?></strong></td>
                            <td style="font-family: monospace; color: #009EE3;"><?php echo htmlspecialchars($caja['pos_id']); ?></td>
                            <td style="text-align: right;"><a href="?borrar_caja=<?php echo $caja['id']; ?>" class="btn-borrar" onclick="return confirm('¿Borrar esta máquina?');">✖</a></td>
                        </tr>
                    <?php endwhile; ?>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: #999; font-style: italic; margin-top: 20px;">Sin máquinas registradas.</p>
            <?php endif; ?>
        </div>

    </div>

    <script>
        // Sincroniza el modo oscuro
        document.addEventListener('DOMContentLoaded', (event) => {
            if (localStorage.getItem('temaMesas') === 'oscuro') {
                document.body.classList.add('modo-oscuro');
                document.querySelectorAll('.form-grupo[style*="f4f4f4"]').forEach(el => {
                    el.style.background = '#2a2a2a'; el.style.border = '1px solid #444';
                });
                document.querySelectorAll('form[style*="fafafa"]').forEach(el => {
                    el.style.background = '#1e1e1e'; el.style.borderColor = '#444';
                });
            }
        });
    </script>
</body>
</html>