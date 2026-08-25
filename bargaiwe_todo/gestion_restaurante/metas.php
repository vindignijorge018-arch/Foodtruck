<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';


// 1. AUTO-CREAR LA TABLA SI NO EXISTE
$query_tabla = "CREATE TABLE IF NOT EXISTS metas_restaurante (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    meta_dinero INT NOT NULL DEFAULT 100000,
    meta_platos INT NOT NULL DEFAULT 10,
    mostrar_metas TINYINT(1) NOT NULL DEFAULT 1
)";
$conn->query($query_tabla);

// 2. MAGIA: AUTO-AGREGAR COLUMNAS NUEVAS SI NO EXISTEN
$nuevas_columnas = [
    'meta_periodo' => "VARCHAR(20) DEFAULT 'diaria'", // diaria, semanal, mensual, personalizada
    'meta_dias' => "INT NOT NULL DEFAULT 1",          // Cantidad de días si es personalizada
    'meta_ventas' => 'INT NOT NULL DEFAULT 0',
    'meta_plato_id' => 'INT NOT NULL DEFAULT 0',
    'meta_plato_cant' => 'INT NOT NULL DEFAULT 0',
    'meta_seccion_nombre' => 'VARCHAR(100) DEFAULT ""',
    'meta_seccion_cant' => 'INT NOT NULL DEFAULT 0',
    'meta_postre_gasto' => 'INT NOT NULL DEFAULT 0', 
    'meta_desc_cant' => 'INT NOT NULL DEFAULT 0',
    // Interruptores (0 = apagado, 1 = encendido)
    'act_neta' => 'TINYINT(1) DEFAULT 1',
    'act_ventas' => 'TINYINT(1) DEFAULT 0',
    'act_total_platos' => 'TINYINT(1) DEFAULT 1',
    'act_plato_esp' => 'TINYINT(1) DEFAULT 0',
    'act_seccion' => 'TINYINT(1) DEFAULT 0',
    'act_postre' => 'TINYINT(1) DEFAULT 0',
    'act_desc' => 'TINYINT(1) DEFAULT 0'
];

foreach ($nuevas_columnas as $columna => $definicion) {
    $check = $conn->query("SHOW COLUMNS FROM metas_restaurante LIKE '$columna'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE metas_restaurante ADD COLUMN $columna $definicion");
    }
}

// 3. CREAR VALORES POR DEFECTO SI ESTÁ VACÍO
$res_check = $conn->query("SELECT * FROM metas_restaurante WHERE restaurant_id = $mi_restaurant_id");
if ($res_check->num_rows == 0) {
    $conn->query("INSERT INTO metas_restaurante (restaurant_id) VALUES ($mi_restaurant_id)");
}

// 4. GUARDAR LOS CAMBIOS
$mensaje = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $mostrar = isset($_POST['mostrar_metas']) ? 1 : 0;
    
    // Interruptores
    $act_neta = isset($_POST['act_neta']) ? 1 : 0;
    $act_ventas = isset($_POST['act_ventas']) ? 1 : 0;
    $act_total_platos = isset($_POST['act_total_platos']) ? 1 : 0;
    $act_plato_esp = isset($_POST['act_plato_esp']) ? 1 : 0;
    $act_seccion = isset($_POST['act_seccion']) ? 1 : 0;
    $act_postre = isset($_POST['act_postre']) ? 1 : 0;
    $act_desc = isset($_POST['act_desc']) ? 1 : 0;

    // Valores
    $meta_periodo = $conn->real_escape_string($_POST['meta_periodo']);
    $meta_dias = (int)$_POST['meta_dias'];
    $meta_dinero = (int)$_POST['meta_dinero'];
    $meta_ventas = (int)$_POST['meta_ventas'];
    $meta_platos = (int)$_POST['meta_platos'];
    $meta_plato_id = (int)$_POST['meta_plato_id'];
    $meta_plato_cant = (int)$_POST['meta_plato_cant'];
    $meta_seccion_nombre = $conn->real_escape_string($_POST['meta_seccion_nombre']);
    $meta_seccion_cant = (int)$_POST['meta_seccion_cant'];
    $meta_postre_gasto = (int)$_POST['meta_postre_gasto'];
    $meta_desc_cant = (int)$_POST['meta_desc_cant'];

    $sql_update = "UPDATE metas_restaurante SET 
        mostrar_metas = $mostrar, act_neta = $act_neta, act_ventas = $act_ventas, act_total_platos = $act_total_platos, 
        act_plato_esp = $act_plato_esp, act_seccion = $act_seccion, act_postre = $act_postre, act_desc = $act_desc,
        meta_periodo = '$meta_periodo', meta_dias = $meta_dias,
        meta_dinero = $meta_dinero, meta_ventas = $meta_ventas, meta_platos = $meta_platos, 
        meta_plato_id = $meta_plato_id, meta_plato_cant = $meta_plato_cant, meta_seccion_nombre = '$meta_seccion_nombre', 
        meta_seccion_cant = $meta_seccion_cant, meta_postre_gasto = $meta_postre_gasto, meta_desc_cant = $meta_desc_cant
        WHERE restaurant_id = $mi_restaurant_id";
    
    $conn->query($sql_update);
    $mensaje = "<div class='alerta-exito'>✅ Metas configuradas correctamente.</div>";
}

// 5. OBTENER DATOS ACTUALES
$datos = $conn->query("SELECT * FROM metas_restaurante WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();

// Consultas para los selectores (dropdowns)
$platos_menu = $conn->query("SELECT id, nombre, seccion FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion, nombre");
$secciones_menu = $conn->query("SELECT DISTINCT seccion FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>bargaiwe - Estrategia de Metas</title>
    <style>
        /* --- MODO OSCURO AUTOMÁTICO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; }
        body.modo-oscuro .card { background: #1e1e1e !important; box-shadow: 0 4px 10px rgba(0,0,0,0.5); border: 1px solid #333; }
        body.modo-oscuro h3, body.modo-oscuro h4 { color: #ccc !important; border-bottom-color: #333; }
        body.modo-oscuro .card[style*="e8f5e9"] { background: #0d2a11 !important; border-color: #2e7d32 !important; }
        body.modo-oscuro .card[style*="e8f5e9"] strong { color: #4caf50 !important; }
        body.modo-oscuro .card[style*="e8f5e9"] div { color: #aaa !important; }
        body.modo-oscuro .opcion-bloque { border-color: #444; }
        body.modo-oscuro .switch-container { background: #222; }
        body.modo-oscuro .switch-container:hover { background: #2a2a2a; }
        body.modo-oscuro .config-area { background: #1e1e1e; border-top-color: #444; }
        body.modo-oscuro .config-area label, body.modo-oscuro .card label { color: #aaa !important; }
        body.modo-oscuro input, body.modo-oscuro select { background: #333; color: white; border-color: #555; }
        body.modo-oscuro #div_dias_pers { border-top-color: #444 !important; }
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        .nav-hub { background: #014421; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .nav-hub a { text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 10px; background: #8C8C8C; color: white; transition: 0.3s; }
        .container { padding: 40px 20px; max-width: 800px; margin: auto; }
        
        .card { background: white; padding: 30px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px;}
        h3 { color: #014421; margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px;}
        
        /* Contenedores de opciones (Fatiga Cero) */
        .opcion-bloque { border: 1px solid #eee; border-radius: 12px; margin-bottom: 15px; overflow: hidden; }
        .switch-container { display: flex; justify-content: space-between; align-items: center; background: #fafafa; padding: 15px 20px; cursor: pointer;}
        .switch-container:hover { background: #f0f0f0; }
        .switch-container strong { font-size: 1.05rem; display: flex; align-items: center; gap: 10px;}
        
        /* El Toggle Switch */
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; pointer-events: none;}
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #32CD32; }
        input:checked + .slider:before { transform: translateX(20px); }

        /* Contenido oculto */
        .config-area { padding: 20px; background: white; border-top: 1px solid #eee; display: none; }
        .config-area label { display: block; font-weight: bold; margin-bottom: 8px; font-size: 0.9rem; color: #555;}
        input[type="number"], select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; margin-bottom: 10px; box-sizing: border-box;}
        select { background-color: #fff; cursor: pointer; }
        
        .btn-guardar { background: #FF8C00; color: white; border: none; padding: 15px; border-radius: 10px; font-weight: bold; font-size: 1.1rem; cursor: pointer; width: 100%; transition: 0.3s; box-shadow: 0 4px 10px rgba(255, 140, 0, 0.3); position: sticky; bottom: 20px;}
        .alerta-exito { background: #e8f5e9; color: #2e7d32; padding: 15px; border-radius: 10px; text-align: center; font-weight: bold; margin-bottom: 20px; border: 1px solid #c8e6c9;}
    </style>
    <script>
        function toggleConfig(id, checkboxId) {
            var checkbox = document.getElementById(checkboxId);
            checkbox.checked = !checkbox.checked;
            document.getElementById(id).style.display = checkbox.checked ? 'block' : 'none';
        }

        // Mostrar el campo de "días" solo si eligen la opción personalizada
        function checkPeriodo() {
            var selector = document.getElementById('selector_periodo');
            var divDias = document.getElementById('div_dias_pers');
            if(selector.value === 'personalizada') {
                divDias.style.display = 'block';
            } else {
                divDias.style.display = 'none';
            }
        }

        window.onload = function() {
            var areas = ['neta', 'ventas', 'total_platos', 'plato_esp', 'seccion', 'postre', 'desc'];
            areas.forEach(function(area) {
                var isChecked = document.getElementById('chk_' + area).checked;
                document.getElementById('config_' + area).style.display = isChecked ? 'block' : 'none';
            });
            checkPeriodo(); // Revisar el selector de tiempo al cargar
        }
    </script>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.6rem; font-weight: 800;">bargaiwe - Panel de Estrategias</span>
        <a href="stats.php" style="background: #007BFF;">← Volver al Dashboard</a>
    </div>

    <div class="container">
        <?= $mensaje ?>

        <form method="POST">
            <div class="card" style="background: #e8f5e9; border: 2px solid #4caf50;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="font-size: 1.2rem; color: #2e7d32;">🎯 Habilitar Sistema de Metas</strong>
                        <div style="font-size: 0.9rem; color: #555; margin-top: 5px;">Muestra las barras de progreso en la pantalla principal.</div>
                    </div>
                    <label class="switch" style="pointer-events: auto;">
                        <input type="checkbox" name="mostrar_metas" <?= ($datos['mostrar_metas'] == 1) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>

            <h4 style="color: #666; margin-bottom: 10px;">⏳ PERIODO DE EVALUACIÓN</h4>
            <div class="card" style="padding: 20px;">
                <label style="display: block; font-weight: bold; margin-bottom: 8px; font-size: 0.9rem; color: #555;">¿Para cuánto tiempo son estas metas?</label>
                <select name="meta_periodo" id="selector_periodo" onchange="checkPeriodo()">
                    <option value="diaria" <?= ($datos['meta_periodo'] == 'diaria') ? 'selected' : '' ?>>☀️ Diaria (El progreso se reinicia cada día)</option>
                    <option value="semanal" <?= ($datos['meta_periodo'] == 'semanal') ? 'selected' : '' ?>>📅 Semanal (El progreso dura 7 días)</option>
                    <option value="mensual" <?= ($datos['meta_periodo'] == 'mensual') ? 'selected' : '' ?>>🗓️ Mensual (El progreso dura 30 días)</option>
                    <option value="personalizada" <?= ($datos['meta_periodo'] == 'personalizada') ? 'selected' : '' ?>>⚙️ Personalizada...</option>
                </select>
                
                <div id="div_dias_pers" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ccc;">
                    <label style="display: block; font-weight: bold; margin-bottom: 8px; font-size: 0.9rem; color: #555;">¿Cuántos días durará la meta?</label>
                    <input type="number" name="meta_dias" value="<?= $datos['meta_dias'] ?>" min="1" placeholder="Ej: 14">
                </div>
            </div>

            <h4 style="color: #666; margin-bottom: 10px;">💰 METAS FINANCIERAS</h4>
            
            <div class="opcion-bloque">
                <div class="switch-container" onclick="toggleConfig('config_neta', 'chk_neta')">
                    <strong>Ganancia Neta (Global)</strong>
                    <label class="switch"><input type="checkbox" name="act_neta" id="chk_neta" <?= ($datos['act_neta'] == 1) ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                <div id="config_neta" class="config-area">
                    <label>¿Cuánto dinero esperas ganar en este periodo (restando gastos)? ($)</label>
                    <input type="number" name="meta_dinero" value="<?= $datos['meta_dinero'] ?>">
                </div>
            </div>

            <div class="opcion-bloque">
                <div class="switch-container" onclick="toggleConfig('config_ventas', 'chk_ventas')">
                    <strong>Ganancia Solo Ventas (Bruto)</strong>
                    <label class="switch"><input type="checkbox" name="act_ventas" id="chk_ventas" <?= ($datos['act_ventas'] == 1) ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                <div id="config_ventas" class="config-area">
                    <label>¿Cuánto esperas recaudar solo vendiendo platos (sin contar ingresos extra)? ($)</label>
                    <input type="number" name="meta_ventas" value="<?= $datos['meta_ventas'] ?>">
                </div>
            </div>

            <h4 style="color: #666; margin-top: 30px; margin-bottom: 10px;">🍽️ METAS DE VOLUMEN Y MENÚ</h4>

            <div class="opcion-bloque">
                <div class="switch-container" onclick="toggleConfig('config_total_platos', 'chk_total_platos')">
                    <strong>Total de Platos Vendidos</strong>
                    <label class="switch"><input type="checkbox" name="act_total_platos" id="chk_total_platos" <?= ($datos['act_total_platos'] == 1) ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                <div id="config_total_platos" class="config-area">
                    <label>Meta total de unidades a vender en este periodo</label>
                    <input type="number" name="meta_platos" value="<?= $datos['meta_platos'] ?>">
                </div>
            </div>

            <div class="opcion-bloque">
                <div class="switch-container" onclick="toggleConfig('config_plato_esp', 'chk_plato_esp')">
                    <strong>Impulsar un Plato Específico</strong>
                    <label class="switch"><input type="checkbox" name="act_plato_esp" id="chk_plato_esp" <?= ($datos['act_plato_esp'] == 1) ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                <div id="config_plato_esp" class="config-area">
                    <label>¿Qué plato quieres incentivar?</label>
                    <select name="meta_plato_id">
                        <option value="0">-- Selecciona un producto --</option>
                        <?php while($p = $platos_menu->fetch_assoc()): ?>
                            <option value="<?= $p['id'] ?>" <?= ($datos['meta_plato_id'] == $p['id']) ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($p['seccion']) ?>] <?= htmlspecialchars($p['nombre']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <label style="margin-top: 10px;">¿Cuántos quieres vender en este periodo?</label>
                    <input type="number" name="meta_plato_cant" value="<?= $datos['meta_plato_cant'] ?>">
                </div>
            </div>

            <div class="opcion-bloque">
                <div class="switch-container" onclick="toggleConfig('config_seccion', 'chk_seccion')">
                    <strong>Impulsar una Carpeta/Categoría</strong>
                    <label class="switch"><input type="checkbox" name="act_seccion" id="chk_seccion" <?= ($datos['act_seccion'] == 1) ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                <div id="config_seccion" class="config-area">
                    <label>¿Qué categoría quieres incentivar?</label>
                    <select name="meta_seccion_nombre">
                        <option value="">-- Selecciona una categoría --</option>
                        <?php while($s = $secciones_menu->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($s['seccion']) ?>" <?= ($datos['meta_seccion_nombre'] == $s['seccion']) ? 'selected' : '' ?>>
                                📁 <?= htmlspecialchars($s['seccion']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <label style="margin-top: 10px;">¿Cuántos artículos de esta categoría quieres vender?</label>
                    <input type="number" name="meta_seccion_cant" value="<?= $datos['meta_seccion_cant'] ?>">
                </div>
            </div>

            <h4 style="color: #666; margin-top: 30px; margin-bottom: 10px;">🎁 METAS DE PROMOCIÓN</h4>

            <div class="opcion-bloque">
                <div class="switch-container" onclick="toggleConfig('config_postre', 'chk_postre')">
                    <strong>Estrategia: Postre de Regalo</strong>
                    <label class="switch"><input type="checkbox" name="act_postre" id="chk_postre" <?= ($datos['act_postre'] == 1) ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                <div id="config_postre" class="config-area">
                    <label>Condición: Si el ticket de una mesa supera los ($)</label>
                    <input type="number" name="meta_postre_gasto" value="<?= $datos['meta_postre_gasto'] ?>" placeholder="Ej: 40000">
                    <small style="color: #888;">El sistema avisará al garzón que esta mesa se ganó un postre.</small>
                </div>
            </div>

            <div class="opcion-bloque">
                <div class="switch-container" onclick="toggleConfig('config_desc', 'chk_desc')">
                    <strong>Estrategia: Uso de Descuentos</strong>
                    <label class="switch"><input type="checkbox" name="act_desc" id="chk_desc" <?= ($datos['act_desc'] == 1) ? 'checked' : '' ?>><span class="slider"></span></label>
                </div>
                <div id="config_desc" class="config-area">
                    <label>¿Cuántos descuentos quieres entregar en este periodo?</label>
                    <input type="number" name="meta_desc_cant" value="<?= $datos['meta_desc_cant'] ?>" placeholder="Ej: 10">
                </div>
            </div>

            <button type="submit" class="btn-guardar">💾 Guardar Estrategia de Metas</button>
        </form>
    </div>
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