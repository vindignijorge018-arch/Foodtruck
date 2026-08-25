<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'db.php';

if (!isset($_SESSION['restaurant_id'])) { header("Location: portal_bargaiwe.php"); exit(); }
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// --- 1. BASE DE DATOS: REGALOS (Lo que ya tenías) ---
$conn->query("CREATE TABLE IF NOT EXISTS promociones (
    id INT AUTO_INCREMENT PRIMARY KEY, restaurant_id INT NOT NULL, 
    meta_venta INT NOT NULL DEFAULT 0, meta_platos INT NOT NULL DEFAULT 0, 
    limite_ocupacion INT NOT NULL DEFAULT 100, estado_promo TINYINT(1) NOT NULL DEFAULT 0
)");
$nuevas_columnas = [
    'tipo_premio' => "VARCHAR(50) DEFAULT 'postre'", 'detalle_premio' => "VARCHAR(100) DEFAULT ''",
    'probabilidad' => "INT NOT NULL DEFAULT 0", /* Cambiado a 0 por seguridad */
    'mensaje_ganador' => "VARCHAR(500) DEFAULT ''" 
];
foreach ($nuevas_columnas as $columna => $definicion) {
    if ($conn->query("SHOW COLUMNS FROM promociones LIKE '$columna'")->num_rows == 0) { 
        $conn->query("ALTER TABLE promociones ADD COLUMN $columna $definicion"); 
    }
}
if ($conn->query("SELECT id FROM promociones WHERE restaurant_id = $mi_restaurant_id")->num_rows == 0) { 
    // MAGIA: Al crear la cuenta, se fuerza a que nazca apagado (estado_promo = 0) y con probabilidad 0
    $conn->query("INSERT INTO promociones (restaurant_id, estado_promo, probabilidad) VALUES ($mi_restaurant_id, 0, 0)"); 
}

// --- 2. BASE DE DATOS: NUEVOS DESCUENTOS MANUALES ---
$conn->query("CREATE TABLE IF NOT EXISTS descuentos_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    nombre VARCHAR(50) NOT NULL,
    porcentaje DECIMAL(5,2) NOT NULL,
    estado TINYINT(1) DEFAULT 1
)");

// --- 3. LÓGICA DE FORMULARIOS (POST Y GET) ---

// A. Guardar Regalo Sorpresa
if (isset($_POST['guardar_promo'])) {
    $meta_venta = (int)$_POST['meta_venta']; $meta_platos = (int)$_POST['meta_platos'];
    $limite_ocupacion = (int)$_POST['limite_ocupacion']; $estado_promo = isset($_POST['estado_promo']) ? 1 : 0;
    $tipo_premio = $conn->real_escape_string($_POST['tipo_premio']); 
    $detalle_premio = $conn->real_escape_string($_POST['detalle_premio']);
    $probabilidad = (int)$_POST['probabilidad']; 
    $mensaje_ganador = $conn->real_escape_string($_POST['mensaje_ganador']);
    
    $conn->query("UPDATE promociones SET meta_venta=$meta_venta, meta_platos=$meta_platos, limite_ocupacion=$limite_ocupacion, estado_promo=$estado_promo, tipo_premio='$tipo_premio', detalle_premio='$detalle_premio', probabilidad=$probabilidad, mensaje_ganador='$mensaje_ganador' WHERE restaurant_id = $mi_restaurant_id");
    header("Location: descuentos.php?exito=regalo"); exit();
}

// B. Crear Descuento Manual
if (isset($_POST['crear_descuento'])) {
    $nombre = $conn->real_escape_string($_POST['nombre_desc']);
    $porcentaje = (float)$_POST['porcentaje_desc'];
    $conn->query("INSERT INTO descuentos_config (restaurant_id, nombre, porcentaje) VALUES ($mi_restaurant_id, '$nombre', $porcentaje)");
    header("Location: descuentos.php?exito=descuento"); exit();
}

// C. Borrar Descuento Manual
if (isset($_GET['borrar_desc'])) {
    $id_borrar = (int)$_GET['borrar_desc'];
    $conn->query("DELETE FROM descuentos_config WHERE id = $id_borrar AND restaurant_id = $mi_restaurant_id");
    header("Location: descuentos.php"); exit();
}

// --- 4. CONSULTAS PARA LA VISTA ---
$promo = $conn->query("SELECT * FROM promociones WHERE restaurant_id = $mi_restaurant_id LIMIT 1")->fetch_assoc();
$lista_descuentos = $conn->query("SELECT * FROM descuentos_config WHERE restaurant_id = $mi_restaurant_id ORDER BY nombre ASC");

$res_promedio = $conn->query("SELECT AVG(total_mesa) as p FROM (SELECT SUM(precio_al_momento) as total_mesa FROM pedidos WHERE estado = 3 AND tipo_pedido = 'mesa' GROUP BY mesa_id) as sub");
$ticket_promedio = $res_promedio->fetch_assoc()['p'] ?? 0;
$res_platos = $conn->query("SELECT AVG(cant) as p FROM (SELECT COUNT(id) as cant FROM pedidos WHERE estado = 3 AND tipo_pedido = 'mesa' GROUP BY mesa_id) as sub");
$platos_promedio = $res_platos->fetch_assoc()['p'] ?? 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Promociones y Descuentos</title>
    <style>
        /* --- MODO OSCURO AUTOMÁTICO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; }
        body.modo-oscuro .card { background: #1e1e1e !important; box-shadow: 0 4px 10px rgba(0,0,0,0.5); border: 1px solid #333; }
        body.modo-oscuro h3, body.modo-oscuro .form-grupo label { color: #ccc !important; border-bottom-color: #333; }
        body.modo-oscuro .dato-destacado { background: #2a2a2a; border-color: #444; }
        body.modo-oscuro .dato-destacado span { color: #aaa; }
        body.modo-oscuro .dato-destacado strong { color: #4caf50; }
        body.modo-oscuro input, body.modo-oscuro select, body.modo-oscuro textarea { background: #333; color: white; border-color: #555; }
        body.modo-oscuro .tabla-desc th { background: #2a2a2a; border-bottom-color: #444; color: #ccc; }
        body.modo-oscuro .tabla-desc td { border-bottom-color: #444; color: #eee; }
        body.modo-oscuro .consejo-box { background: #0d2b42; border-left-color: #2196f3; color: #ccc; }
        body.modo-oscuro .btn-toggle-regalo { background: #3e280d; border-color: #FF8C00; }
        body.modo-oscuro .btn-toggle-regalo:hover { background: #5c3a11; }
        body.modo-oscuro .form-grupo[style*="e8f5e9"] { background: #0d2a11 !important; border-color: #2e7d32 !important; }
        body.modo-oscuro .form-grupo[style*="e8f5e9"] label { color: #4caf50 !important; }
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        .nav-hub { background: #014421; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 10px; background: #8C8C8C; color: white; transition: 0.3s; }
        .container { padding: 40px; max-width: 1100px; margin: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start;}
        .card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 6px 15px rgba(0,0,0,0.05); }
        .card h3 { margin-top: 0; color: #014421; border-bottom: 2px solid #eee; padding-bottom: 10px; font-size: 1.3rem;}
        
        /* Stats & UI */
        .dato-destacado { background: #fdfcf0; border: 1px dashed #ccc; padding: 15px; border-radius: 10px; text-align: center; margin-bottom: 15px;}
        .dato-destacado span { display: block; color: #666; font-size: 0.85rem; font-weight: bold; text-transform: uppercase;}
        .dato-destacado strong { font-size: 1.6rem; color: #014421; }
        
        /* Forms */
        .form-grupo { margin-bottom: 15px; }
        .form-grupo label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.95rem; color: #444;}
        .form-grupo input, .form-grupo select, .form-grupo textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; font-size: 1rem; box-sizing: border-box;}
        .btn-guardar { background: #FF8C00; color: white; border: none; padding: 15px; width: 100%; border-radius: 10px; font-size: 1.1rem; font-weight: bold; cursor: pointer; margin-top: 10px;}
        .btn-crear { background: #32CD32; color: white; border: none; padding: 15px; width: 100%; border-radius: 10px; font-size: 1.1rem; font-weight: bold; cursor: pointer; margin-top: 10px;}
        
        /* Caja de Consejo Cerrable */
        .consejo-box { background: #e3f2fd; border-left: 5px solid #2196f3; padding: 15px; border-radius: 0 10px 10px 0; font-size: 0.9rem; position: relative; margin-bottom: 20px;}
        .btn-cerrar-consejo { position: absolute; top: 5px; right: 10px; background: none; border: none; font-size: 1.2rem; cursor: pointer; color: #555; font-weight: bold;}
        
        /* Tabla Historial */
        .tabla-desc { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .tabla-desc th, .tabla-desc td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        .tabla-desc th { background: #f4f4f4; border-radius: 5px; }
        .btn-borrar { color: white; background: #E53935; text-decoration: none; padding: 5px 10px; border-radius: 5px; font-size: 0.8rem; font-weight: bold;}

        /* Botón Regalo Toggle */
        .btn-toggle-regalo { background: #fff3e0; border: 2px dashed #FF8C00; color: #FF8C00; width: 100%; padding: 15px; border-radius: 10px; font-size: 1.2rem; font-weight: bold; cursor: pointer; margin-top: 20px; transition: 0.3s;}
        .btn-toggle-regalo:hover { background: #ffe0b2; }
        #panel-regalo { display: none; margin-top: 20px; padding-top: 20px; border-top: 2px dashed #eee; animation: fadeIn 0.4s; }
        
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Interruptor Maestro (Toggle Switch) */
        .switch { position: relative; display: inline-block; width: 60px; height: 34px; margin: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 26px; width: 26px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        input:checked + .slider { background-color: #4caf50; }
        input:checked + .slider:before { transform: translateX(26px); }
    </style>
</head>
<body>
    <div class="nav-hub">
        <span style="font-size: 1.6rem; font-weight: 800;">🏷️ Gestor de Descuentos</span>
        <a href="stats.php">← Volver al Dashboard</a>
    </div>
    
    <div class="container">
        <div>
            <?php if(isset($_GET['exito'])): ?>
                <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; text-align:center; font-weight:bold; margin-bottom: 20px;">
                    ✅ Cambios guardados correctamente.
                </div>
            <?php endif; ?>

            <div class="card" style="margin-bottom: 20px;">
                <div style="display: flex; gap: 10px;">
                    <div class="dato-destacado" style="flex: 1;"><span>Ticket Promedio</span><strong>$<?php echo number_format($ticket_promedio, 0, ',', '.'); ?></strong></div>
                    <div class="dato-destacado" style="flex: 1;"><span>Platos por Mesa</span><strong><?php echo number_format($platos_promedio, 1); ?></strong></div>
                </div>
            </div>

            <div class="card">
                <h3>📋 Descuentos Activos</h3>
                <?php if($lista_descuentos->num_rows > 0): ?>
                    <table class="tabla-desc">
                        <tr><th>Nombre</th><th>% Descuento</th><th>Acción</th></tr>
                        <?php while($d = $lista_descuentos->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($d['nombre']); ?></strong></td>
                                <td><?php echo $d['porcentaje']; ?>%</td>
                                <td><a href="?borrar_desc=<?php echo $d['id']; ?>" class="btn-borrar" onclick="return confirm('¿Borrar este descuento?');">Borrar</a></td>
                            </tr>
                        <?php endwhile; ?>
                    </table>
                <?php else: ?>
                    <p style="color: #888; font-style: italic; text-align: center; margin-top: 20px;">No has creado ningún descuento aún.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div>
            <div class="card">
                <h3>➕ Crear Nuevo Descuento</h3>
                <form method="POST">
                    <div class="form-grupo">
                        <label>Nombre del Descuento</label>
                        <input type="text" name="nombre_desc" placeholder="Ej: Estudiante UCN, Convenio Empresa..." required>
                    </div>
                    <div class="form-grupo">
                        <label>Porcentaje a Descontar (%)</label>
                        <input type="number" name="porcentaje_desc" min="1" max="100" placeholder="Ej: 10" required>
                    </div>
                    <button type="submit" name="crear_descuento" class="btn-crear">Crear Descuento</button>
                </form>

                <button class="btn-toggle-regalo" onclick="toggleRegalo()">🎁 Configurar Regalo Sorpresa Oculto</button>

                <div id="panel-regalo">
                    <div class="consejo-box" id="cajaFilosofia">
                        <button class="btn-cerrar-consejo" onclick="cerrarFilosofia()">×</button>
                        <strong>💡 Filosofía del Regalo Sorpresa:</strong><br><br>
                        El cliente nunca debe saber que participa. Si gasta más de la meta, el cajero verá tu mensaje y se lo dirá como un regalo espontáneo de la casa.
                    </div>

                    <h3>⚙️ Reglas de la Sorpresa</h3>
                    <form method="POST">
                        <div class="form-grupo" style="display: flex; align-items: center; justify-content: space-between; background: #e8f5e9; padding: 15px; border-radius: 10px; border: 2px solid #4caf50; margin-bottom: 20px;">
                            <label style="margin:0; font-size:1.2rem; color:#2e7d32; font-weight: 900;">🚀 Motor de Sorpresas (ON/OFF)</label>
                            <label class="switch">
                                <input type="checkbox" name="estado_promo" <?php echo ($promo['estado_promo'] == 1) ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <div class="form-grupo" style="flex: 1;">
                                <label>Premio</label>
                                <select name="tipo_premio">
                                    <option value="postre" <?php echo ($promo['tipo_premio'] == 'postre') ? 'selected' : ''; ?>>🍰 Postre</option>
                                    <option value="descuento" <?php echo ($promo['tipo_premio'] == 'descuento') ? 'selected' : ''; ?>>🏷️ Descuento</option>
                                    <option value="bebida" <?php echo ($promo['tipo_premio'] == 'bebida') ? 'selected' : ''; ?>>🥤 Bebida</option>
                                </select>
                            </div>
                            <div class="form-grupo" style="flex: 1;">
                                <label>Detalle</label>
                                <input type="text" name="detalle_premio" value="<?php echo htmlspecialchars($promo['detalle_premio']); ?>" placeholder="Ej: 10% prox. visita">
                            </div>
                        </div>
                        
                        <div class="form-grupo">
                            <label>💬 Guion para el Cajero</label>
                            <textarea name="mensaje_ganador" rows="2" placeholder="Ej: ¡Hola! Como hoy pidieron bastante, la casa quiere regalarles..."><?php echo htmlspecialchars($promo['mensaje_ganador']); ?></textarea>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div class="form-grupo">
                                <label>Ticket Min. ($)</label>
                                <input type="number" name="meta_venta" value="<?php echo $promo['meta_venta']; ?>">
                            </div>
                            <div class="form-grupo">
                                <label>Platos Min.</label>
                                <input type="number" name="meta_platos" value="<?php echo $promo['meta_platos']; ?>">
                            </div>
                            <div class="form-grupo">
                                <label>Límite Ocup. (%)</label>
                                <input type="number" name="limite_ocupacion" value="<?php echo $promo['limite_ocupacion']; ?>" max="100">
                            </div>
                            <div class="form-grupo">
                                <label>Probabilidad (%)</label>
                                <input type="number" name="probabilidad" value="<?php echo $promo['probabilidad']; ?>" min="1" max="100">
                            </div>
                        </div>
                        
                        <button type="submit" name="guardar_promo" class="btn-guardar">💾 Guardar Sorpresa</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Lógica para abrir/cerrar el panel del regalo
        function toggleRegalo() {
            let panel = document.getElementById('panel-regalo');
            if (panel.style.display === 'block') {
                panel.style.display = 'none';
            } else {
                panel.style.display = 'block';
                // Si la filosofía ya fue cerrada antes, la ocultamos
                if(localStorage.getItem('ocultar_filosofia') === 'true') {
                    document.getElementById('cajaFilosofia').style.display = 'none';
                }
            }
        }

        // Lógica para cerrar el mensaje de filosofía para siempre en este navegador
        function cerrarFilosofia() {
            document.getElementById('cajaFilosofia').style.display = 'none';
            localStorage.setItem('ocultar_filosofia', 'true');
        }
    </script>
    <script>
        // Sincroniza el modo oscuro desde mesas.php
        document.addEventListener('DOMContentLoaded', (event) => {
            if (localStorage.getItem('temaMesas') === 'oscuro') {
                document.body.classList.add('modo-oscuro');
            }
        });
    </script>
</body>
</html>