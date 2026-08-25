<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['restaurant_id'])) { 
    header("Location: ../portal_bargaiwe.php"); 
    exit(); 
}

include 'r_db.php';
verificarPlanPlus(); // Esto bloquea a los usuarios Standard de inmediato
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// --- TEMA DINÁMICO ---
$res_tema = $conn->query("SELECT modo_global, color_cajero FROM config_temas WHERE restaurant_id = $mi_restaurant_id");
$tema = ($res_tema && $res_tema->num_rows > 0) ? $res_tema->fetch_assoc() : ['modo_global' => 'oscuro', 'color_cajero' => '#FF8C00'];

// --- 1. MAGIA: AUTO-CREAR TABLA SI NO EXISTE ---
$conn->query("CREATE TABLE IF NOT EXISTS metas_restaurante (
    restaurant_id INT PRIMARY KEY,
    mostrar_metas INT DEFAULT 0,
    meta_periodo VARCHAR(50) DEFAULT 'diaria',
    meta_dias INT DEFAULT 1,
    act_neta INT DEFAULT 1,
    meta_dinero INT DEFAULT 0,
    act_ventas INT DEFAULT 1,
    meta_ventas INT DEFAULT 0,
    act_total_platos INT DEFAULT 1,
    meta_platos INT DEFAULT 0,
    act_seccion INT DEFAULT 0,
    meta_seccion_nombre VARCHAR(100) DEFAULT '',
    meta_seccion_cant INT DEFAULT 0
)");

// Asegurar que exista un registro base para este local
$conn->query("INSERT IGNORE INTO metas_restaurante (restaurant_id) VALUES ($mi_restaurant_id)");

// --- 2. GUARDAR CONFIGURACIÓN ---
if (isset($_POST['guardar_metas'])) {
    $mostrar = isset($_POST['mostrar_metas']) ? 1 : 0;
    $periodo = $conn->real_escape_string($_POST['meta_periodo']);
    $dinero = (int)$_POST['meta_dinero'];
    $ventas = (int)$_POST['meta_ventas'];
    $platos = (int)$_POST['meta_platos'];

    $sql = "UPDATE metas_restaurante SET 
            mostrar_metas = $mostrar,
            meta_periodo = '$periodo',
            meta_dinero = $dinero,
            meta_ventas = $ventas,
            meta_platos = $platos
            WHERE restaurant_id = $mi_restaurant_id";
            
    $conn->query($sql);
    header("Location: r_stats.php"); // Devuelve a stats al guardar
    exit();
}

// Extraer metas actuales para mostrarlas en el formulario
$metas = $conn->query("SELECT * FROM metas_restaurante WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configurar Metas - Bargaiwe Fast</title>
    <style>
        :root {
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: <?php echo $tema['color_cajero']; ?>; 
            --success: #32CD32; --danger: #E53935;
        }
        <?php if($tema['modo_global'] === 'claro'): ?>
        body { --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000; }
        <?php endif; ?>

        body { background: var(--bg-body); color: var(--text); font-family: 'Segoe UI', sans-serif; margin: 0; padding: 40px; display: flex; justify-content: center; transition: 0.3s;}
        
        .card { background: var(--bg-panel); padding: 30px; border-radius: 15px; border: 1px solid var(--border); width: 100%; max-width: 600px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);}
        
        input, select { width: 100%; padding: 12px; margin: 5px 0 20px 0; border: 1px solid var(--border); border-radius: 8px; box-sizing: border-box; background: var(--bg-body); color: var(--text); font-size: 1rem;}
        input:focus, select:focus { outline: none; border-color: var(--accent); }
        
        .btn-guardar { background: var(--accent); color: white; border: none; padding: 15px; border-radius: 8px; font-weight: bold; width: 100%; cursor: pointer; font-size: 1.1rem; text-transform: uppercase; transition: 0.2s;}
        .btn-guardar:hover { opacity: 0.9; transform: translateY(-2px);}
        
        label { font-weight: bold; color: var(--text-title); font-size: 0.95rem; }
    </style>
</head>
<body>
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid var(--border); padding-bottom: 15px;">
            <h2 style="color: var(--accent); margin: 0;">🎯 Controlador de Metas</h2>
            <a href="r_stats.php" style="color: var(--text); text-decoration: none; font-weight: bold; background: var(--bg-body); padding: 8px 15px; border-radius: 6px; border: 1px solid var(--border);">← Cancelar</a>
        </div>

        <form method="POST">
            <div style="background: rgba(50, 205, 50, 0.05); border: 1px solid var(--success); padding: 15px; border-radius: 8px; margin-bottom: 25px;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; color: var(--text-title); font-size: 1.1rem;">
                    <input type="checkbox" name="mostrar_metas" value="1" <?php echo ($metas['mostrar_metas'] == 1) ? 'checked' : ''; ?> style="width: 25px; height: 25px; accent-color: var(--success); margin: 0; cursor: pointer;">
                    Activar Panel de Metas en Estadísticas
                </label>
                <span style="font-size: 0.85rem; color: #8b949e; display: block; margin-top: 5px; margin-left: 35px;">Si se apaga, las metas se ocultarán de la vista principal.</span>
            </div>

            <label>Periodo de Evaluación:</label>
            <select name="meta_periodo">
                <option value="diaria" <?php echo ($metas['meta_periodo'] == 'diaria') ? 'selected' : ''; ?>>Meta Diaria (Hoy)</option>
                <option value="semanal" <?php echo ($metas['meta_periodo'] == 'semanal') ? 'selected' : ''; ?>>Meta Semanal</option>
                <option value="mensual" <?php echo ($metas['meta_periodo'] == 'mensual') ? 'selected' : ''; ?>>Meta Mensual</option>
            </select>

            <div style="display: flex; gap: 15px;">
                <div style="flex: 1;">
                    <label>Meta: Ganancia Neta ($):</label>
                    <input type="number" name="meta_dinero" value="<?php echo $metas['meta_dinero']; ?>" min="0">
                </div>
                <div style="flex: 1;">
                    <label>Meta: Ventas Brutas ($):</label>
                    <input type="number" name="meta_ventas" value="<?php echo $metas['meta_ventas']; ?>" min="0">
                </div>
            </div>

            <label>Meta: Productos Totales Vendidos (Uds):</label>
            <input type="number" name="meta_platos" value="<?php echo $metas['meta_platos']; ?>" min="0">

            <button type="submit" name="guardar_metas" class="btn-guardar">Guardar y Aplicar</button>
        </form>
    </div>
</body>
</html>