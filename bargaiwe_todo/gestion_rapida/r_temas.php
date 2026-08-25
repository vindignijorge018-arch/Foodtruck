<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['restaurant_id'])) { 
    header("Location: ../portal_bargaiwe.php"); 
    exit(); 
}

include 'r_db.php';
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// --- 0. MAGIA: CREAR TABLA Y ASEGURAR COLUMNAS NUEVAS ---
$conn->query("CREATE TABLE IF NOT EXISTS config_temas (
    restaurant_id INT PRIMARY KEY,
    modo_global VARCHAR(15) DEFAULT 'oscuro',
    color_cajero VARCHAR(20) DEFAULT '#FF8C00',
    color_cocina VARCHAR(20) DEFAULT '#FF8C00',
    color_pantalla VARCHAR(20) DEFAULT '#32CD32',
    color_general VARCHAR(20) DEFAULT '#FF8C00'
)");

// Agregamos las nuevas columnas de Fondo y Texto si no existen
$check_fondo = $conn->query("SHOW COLUMNS FROM config_temas LIKE 'color_fondo'");
if ($check_fondo && $check_fondo->num_rows == 0) {
    $conn->query("ALTER TABLE config_temas ADD COLUMN color_fondo VARCHAR(20) DEFAULT '#0d1117'");
    $conn->query("ALTER TABLE config_temas ADD COLUMN color_texto VARCHAR(20) DEFAULT '#ffffff'");
    // Estiramos la columna modo_global a 20 caracteres para que quepa "personalizado"
}
$conn->query("ALTER TABLE config_temas MODIFY COLUMN modo_global VARCHAR(20) DEFAULT 'oscuro'");
$conn->query("INSERT IGNORE INTO config_temas (restaurant_id) VALUES ($mi_restaurant_id)");

// --- 1. GUARDAR NUEVOS COLORES ---
if (isset($_POST['guardar_temas'])) {
    $modo = $conn->real_escape_string($_POST['modo_global']);
    $c_cajero = $conn->real_escape_string($_POST['color_cajero']);
    $c_cocina = $conn->real_escape_string($_POST['color_cocina']);
    $c_pantalla = $conn->real_escape_string($_POST['color_pantalla']);
    $c_general = $conn->real_escape_string($_POST['color_general']);
    
    $c_fondo = $conn->real_escape_string($_POST['color_fondo']);
    $c_texto = $conn->real_escape_string($_POST['color_texto']);

    $sql_update = "UPDATE config_temas SET 
                    modo_global = '$modo', 
                    color_cajero = '$c_cajero', 
                    color_cocina = '$c_cocina', 
                    color_pantalla = '$c_pantalla', 
                    color_general = '$c_general',
                    color_fondo = '$c_fondo',
                    color_texto = '$c_texto'
                   WHERE restaurant_id = $mi_restaurant_id";
    
    $conn->query($sql_update);
    header("Location: r_temas.php?exito=1");
    exit();
}

// --- 2. OBTENER CONFIGURACIÓN ACTUAL ---
$res_tema = $conn->query("SELECT * FROM config_temas WHERE restaurant_id = $mi_restaurant_id");
$tema = $res_tema->fetch_assoc();

// Variables de seguridad si es la primera vez que carga y las columnas estaban vacías
$fondo_actual = !empty($tema['color_fondo']) ? $tema['color_fondo'] : '#0d1117';
$texto_actual = !empty($tema['color_texto']) ? $tema['color_texto'] : '#ffffff';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Personalización - Bargaiwe Fast</title>
    <style>
        /* MOTOR CSS INTELIGENTE */
        :root {
            /* Modo Oscuro (Por Defecto) */
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: <?php echo $tema['color_general']; ?>; 
            --success: #32CD32;
        }
        
        <?php if($tema['modo_global'] === 'claro'): ?>
        body {
            /* Modo Claro Fijo */
            --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000;
        }
        <?php elseif($tema['modo_global'] === 'personalizado'): ?>
        body {
            /* Modo Personalizado desde la BD */
            --bg-body: <?php echo $fondo_actual; ?>; 
            --bg-panel: <?php echo $fondo_actual; ?>; /* En personalizado, usamos el mismo fondo para simplificar */
            --border: #8b949e; 
            --text: <?php echo $texto_actual; ?>; 
            --text-title: <?php echo $texto_actual; ?>;
        }
        <?php endif; ?>

        body { background: var(--bg-body); color: var(--text-title); font-family: 'Segoe UI', sans-serif; margin: 0; transition: background 0.3s, color 0.3s; }
        
        .nav-hub { background: var(--bg-panel); border-bottom: 1px solid var(--border); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: var(--text-title); }
        .nav-hub a { color: var(--text-title); text-decoration: none; font-weight: bold; background: var(--bg-panel); padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); transition: 0.3s; }
        .nav-hub a:hover { border-color: var(--accent); color: var(--accent); }

        .container { padding: 40px; max-width: 800px; margin: auto; }
        .card { background: var(--bg-panel); padding: 30px; border-radius: 15px; border: 1px solid var(--border); margin-bottom: 20px;}
        h3 { color: var(--accent); margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 10px; font-size: 1.4rem; }

        .form-group { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; padding: 15px; background: var(--bg-body); border-radius: 10px; border: 1px solid var(--border);}
        .form-group label { font-weight: bold; color: var(--text-title); font-size: 1.1rem;}
        .form-group p { margin: 5px 0 0 0; font-size: 0.85rem; color: var(--text); opacity: 0.8;}
        
        select { padding: 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-panel); color: var(--text-title); font-size: 1rem; outline: none; cursor: pointer; font-weight: bold;}
        input[type="color"] { border: none; width: 60px; height: 50px; border-radius: 8px; cursor: pointer; background: transparent; padding: 0;}
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: 2px solid var(--border); border-radius: 8px; }

        .btn-guardar { background: var(--accent); color: white; border: none; padding: 15px; border-radius: 8px; font-weight: bold; width: 100%; cursor: pointer; font-size: 1.2rem; transition: 0.2s; text-transform: uppercase; letter-spacing: 1px;}
        .btn-guardar:hover { opacity: 0.8; transform: translateY(-2px); }
        
        .alerta-exito { background: rgba(50, 205, 50, 0.1); border: 1px solid var(--success); color: var(--success); padding: 15px; border-radius: 8px; text-align: center; font-weight: bold; margin-bottom: 20px; }
    </style>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.8rem; font-weight: 800; color: var(--accent);">🎨 Configuración Visual</span>
        <a href="r_pedidos.php">← Volver al Cajero</a>
    </div>

    <div class="container">
        <?php if(isset($_GET['exito'])): ?>
            <div class="alerta-exito">✅ ¡Tus colores personalizados han sido aplicados!</div>
        <?php endif; ?>

        <form method="POST">
            
            <div class="card">
                <h3>🌓 Modo y Colores Base del Sistema</h3>
                
                <div class="form-group">
                    <div>
                        <label>Configuración General</label>
                        <p>Elige un preajuste o crea el tuyo.</p>
                    </div>
                    <select name="modo_global" id="modo_global" onchange="sincronizarModo()">
                        <option value="oscuro" <?php if($tema['modo_global'] == 'oscuro') echo 'selected'; ?>>🌙 Modo Oscuro</option>
                        <option value="claro" <?php if($tema['modo_global'] == 'claro') echo 'selected'; ?>>☀️ Modo Claro</option>
                        <option value="personalizado" <?php if($tema['modo_global'] == 'personalizado') echo 'selected'; ?>>🎨 Personalizado</option>
                    </select>
                </div>

                <div class="form-group">
                    <div>
                        <label>⬛ Color de Fondo Principal</label>
                        <p>El fondo de las pantallas.</p>
                    </div>
                    <input type="color" name="color_fondo" id="color_fondo" value="<?php echo $fondo_actual; ?>" oninput="forzarPersonalizado()">
                </div>

                <div class="form-group">
                    <div>
                        <label>🔤 Color de las Letras (Textos)</label>
                        <p>Asegúrate de que contraste con el fondo.</p>
                    </div>
                    <input type="color" name="color_texto" id="color_texto" value="<?php echo $texto_actual; ?>" oninput="forzarPersonalizado()">
                </div>
            </div>

            <div class="card">
                <h3>🖌️ Colores de Acento por Pantalla</h3>
                <p style="color: var(--text); font-size: 0.9rem; margin-top: -10px; margin-bottom: 20px;">
                    Estos colores decoran botones, bordes y detalles importantes.
                </p>

                <div class="form-group">
                    <div>
                        <label>🖥️ Color del Cajero</label>
                        <p>Para la toma de pedidos (`r_pedidos.php`).</p>
                    </div>
                    <input type="color" name="color_cajero" value="<?php echo $tema['color_cajero']; ?>" oninput="forzarPersonalizado()">
                </div>

                <div class="form-group">
                    <div>
                        <label>👨‍🍳 Color de la Cocina</label>
                        <p>Para la pantalla de los cocineros (`r_cocina.php`).</p>
                    </div>
                    <input type="color" name="color_cocina" value="<?php echo $tema['color_cocina']; ?>" oninput="forzarPersonalizado()">
                </div>

                <div class="form-group">
                    <div>
                        <label>📺 Color de la Pantalla de Clientes</label>
                        <p>La pantalla pública de retiros (`r_pantalla.php`).</p>
                    </div>
                    <input type="color" name="color_pantalla" value="<?php echo $tema['color_pantalla']; ?>" oninput="forzarPersonalizado()">
                </div>

                <div class="form-group">
                    <div>
                        <label>⚙️ Color General</label>
                        <p>Para Menú, Descuentos y Estadísticas.</p>
                    </div>
                    <input type="color" name="color_general" value="<?php echo $tema['color_general']; ?>" oninput="forzarPersonalizado()">
                </div>
            </div>

            <button type="submit" name="guardar_temas" class="btn-guardar">💾 Aplicar y Guardar Cambios</button>
        </form>
    </div>

    <script>
        const modoSelect = document.getElementById('modo_global');
        const inputFondo = document.getElementById('color_fondo');
        const inputTexto = document.getElementById('color_texto');

        // Función 1: Si cambio la lista, cambiar automáticamente los colores en las paletas
        function sincronizarModo() {
            if (modoSelect.value === 'claro') {
                inputFondo.value = '#f0f2f5'; // Gris/Blanco claro
                inputTexto.value = '#000000'; // Negro puro
            } 
            else if (modoSelect.value === 'oscuro') {
                inputFondo.value = '#0d1117'; // Azul marino muy oscuro
                inputTexto.value = '#ffffff'; // Blanco puro
            }
        }

        // Función 2: Si el usuario toca CUALQUIER color a mano, la lista salta a Personalizado
        function forzarPersonalizado() {
            modoSelect.value = 'personalizado';
        }
    </script>
</body>
</html>