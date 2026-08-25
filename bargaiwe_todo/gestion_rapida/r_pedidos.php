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

// =========================================================
// LÓGICA DE PANTALLA Y PEDIDOS LISTOS (MODALES)
// =========================================================

// 1. Entregar un solo pedido desde el Modal
if (isset($_POST['entregar_ticket'])) {
    $codigo_ticket = $conn->real_escape_string($_POST['codigo_ticket']);
    $conn->query("UPDATE pedidos SET estado = 4 WHERE codigo_grupo = '$codigo_ticket' AND restaurant_id = $mi_restaurant_id");
    header("Location: r_pedidos.php"); exit();
}

// 2. Limpiar todos los pedidos listos de golpe
if (isset($_POST['limpiar_todos_listos'])) {
    $conn->query("UPDATE pedidos SET estado = 4 WHERE estado = 3 AND restaurant_id = $mi_restaurant_id");
    header("Location: r_pedidos.php"); exit();
}

// 3. Guardar ajustes de la TV
if (isset($_POST['guardar_pantalla'])) {
    $p_activa = isset($_POST['pantalla_activa']) ? 1 : 0;
    $max_coc = (int)$_POST['max_cocinando'];
    $lim_emerg = (int)$_POST['limite_emergencia'];
    
    // Si el cajero apaga la TV, limpiamos la lista para que no se acumule basura
    if ($p_activa == 0) {
        $conn->query("UPDATE pedidos SET estado = 4 WHERE estado = 3 AND restaurant_id = $mi_restaurant_id");
    }
    
    $conn->query("UPDATE config_rapida SET pantalla_activa = $p_activa, max_cocinando = $max_coc, limite_emergencia = $lim_emerg WHERE restaurant_id = $mi_restaurant_id");
    header("Location: r_pedidos.php"); exit(); 
}

// 4. Obtener pedidos listos para mostrarlos en el Modal
$pedidos_listos = [];
$res_listos_modal = $conn->query("SELECT codigo_grupo, cliente_nombre FROM pedidos WHERE estado = 3 AND restaurant_id = $mi_restaurant_id GROUP BY codigo_grupo, cliente_nombre");
if ($res_listos_modal) {
    while($row = $res_listos_modal->fetch_assoc()) {
        $pedidos_listos[] = $row;
    }
}
// =========================================================
// 0. OBTENER TEMA DESDE LA BASE DE DATOS
// =========================================================
$res_tema = $conn->query("SELECT modo_global, color_cajero FROM config_temas WHERE restaurant_id = $mi_restaurant_id");
$tema = ($res_tema && $res_tema->num_rows > 0) ? $res_tema->fetch_assoc() : ['modo_global' => 'oscuro', 'color_cajero' => '#FF8C00'];

// =========================================================
// 1. CONFIGURACIÓN EXCLUSIVA DE COMIDA RÁPIDA (BBDD)
// =========================================================
$conn->query("CREATE TABLE IF NOT EXISTS config_rapida (
    restaurant_id INT PRIMARY KEY,
    modo_ticket VARCHAR(20) DEFAULT 'numero',
    contador_ticket INT DEFAULT 1,
    ticket_max INT DEFAULT 99,
    pantalla_activa INT DEFAULT 1,
    max_cocinando INT DEFAULT 3,
    limite_emergencia INT DEFAULT 20
)");
$conn->query("INSERT IGNORE INTO config_rapida (restaurant_id) VALUES ($mi_restaurant_id)");

// Forzamos columnas por si es una instalación vieja
$cols = ['ticket_max' => '99', 'pantalla_activa' => '1', 'max_cocinando' => '3', 'limite_emergencia' => '20'];
foreach($cols as $col => $def) {
    $chk = $conn->query("SHOW COLUMNS FROM config_rapida LIKE '$col'");
    if ($chk && $chk->num_rows == 0) $conn->query("ALTER TABLE config_rapida ADD COLUMN $col INT DEFAULT $def");
}

$config = $conn->query("SELECT * FROM config_rapida WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();

// Columnas vitales para los pedidos
$check_grupo = $conn->query("SHOW COLUMNS FROM pedidos LIKE 'codigo_grupo'");
if ($check_grupo && $check_grupo->num_rows == 0) { $conn->query("ALTER TABLE pedidos ADD COLUMN codigo_grupo VARCHAR(50) DEFAULT NULL"); }

$check_comentario = $conn->query("SHOW COLUMNS FROM pedidos LIKE 'comentario'");
if ($check_comentario && $check_comentario->num_rows == 0) { $conn->query("ALTER TABLE pedidos ADD COLUMN comentario VARCHAR(255) DEFAULT ''"); }


// =========================================================
// 2. LÓGICAS DE GESTIÓN Y GUARDADO (REDIRECCIONES)
// =========================================================

// --- A) Guardar Ajustes Generales ---
if (isset($_POST['guardar_config'])) {
    $modo = $conn->real_escape_string($_POST['modo_ticket']);
    $inicio = (int)$_POST['numero_inicio'];
    $maximo = (int)$_POST['ticket_max'];
    
    if($maximo <= $inicio) $maximo = $inicio + 50; 
    $conn->query("UPDATE config_rapida SET modo_ticket = '$modo', contador_ticket = $inicio, ticket_max = $maximo WHERE restaurant_id = $mi_restaurant_id");
    header("Location: r_pedidos.php"); exit();
}

// --- B) Guardar Ajustes de Pantalla ---
if (isset($_POST['guardar_pantalla'])) {
    $p_activa = isset($_POST['pantalla_activa']) ? 1 : 0;
    $max_coc = (int)$_POST['max_cocinando'];
    $lim_emerg = (int)$_POST['limite_emergencia'];
    
    $conn->query("UPDATE config_rapida SET pantalla_activa = $p_activa, max_cocinando = $max_coc, limite_emergencia = $lim_emerg WHERE restaurant_id = $mi_restaurant_id");
    header("Location: r_pedidos.php"); exit(); 
}

// --- C) Añadir Plato al Ticket (Con Descuento Inteligente) ---
if (!isset($_SESSION['ticket_actual'])) { $_SESSION['ticket_actual'] = 'FAST_' . time() . rand(100,999); }
$ticket_grupo = $_SESSION['ticket_actual'];

if (isset($_POST['agregar_al_carrito'])) {
    $menu_id = isset($_POST['menu_id']) ? (int)$_POST['menu_id'] : 0;
    $cantidad = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 1;
    $comentario = isset($_POST['comentario']) ? $conn->real_escape_string(trim($_POST['comentario'])) : '';
    $ticket_grupo_post = $_SESSION['ticket_actual']; 

    if ($menu_id > 0) {
        $precio_base = $conn->query("SELECT precio FROM menu WHERE id = $menu_id")->fetch_assoc()['precio'];
        $precio_final = $precio_base;

        if (!empty($_POST['cupon_aplicado'])) {
            $cod_cupon = $conn->real_escape_string($_POST['cupon_aplicado']);
            $res_c = $conn->query("SELECT * FROM descuentos WHERE codigo = '$cod_cupon' AND restaurant_id = $mi_restaurant_id AND estado = 1");
            
            if ($res_c && $res_c->num_rows > 0) {
                $d = $res_c->fetch_assoc();
                if ($d['tipo'] == 'porcentaje') { $precio_final = $precio_base - ($precio_base * (floatval($d['valor']) / 100)); } 
                else { $precio_final = $precio_base - floatval($d['valor']); }
                
                if ($precio_final < 0) $precio_final = 0; 
                $comentario = empty($comentario) ? "[DSTO: $cod_cupon]" : $comentario . " | [DSTO: $cod_cupon]";
            }
        }

        // Capturamos el ID del empleado seleccionado (0 si no hay ninguno)
        $id_cajero_actual = isset($_SESSION['emp_id']) ? (int)$_SESSION['emp_id'] : 0;

        $sql = "INSERT INTO pedidos (restaurant_id, menu_id, cantidad, comentario, estado, codigo_grupo, precio_al_momento, empleado_id) 
                VALUES ($mi_restaurant_id, $menu_id, $cantidad, '$comentario', 1, '$ticket_grupo_post', $precio_final, $id_cajero_actual)";
        $conn->query($sql);
    }
    header("Location: r_pedidos.php"); exit();
}

// --- D) Borrar Item del Ticket ---
if (isset($_GET['borrar_item'])) {
    $id_borrar = (int)$_GET['borrar_item'];
    $conn->query("DELETE FROM pedidos WHERE id = $id_borrar AND restaurant_id = $mi_restaurant_id AND estado = 1");
    header("Location: r_pedidos.php"); exit();
}

// --- E) Enviar el Ticket DIRECTO A COCINA (Sin recargar) ---
if (isset($_POST['enviar_a_cocina'])) {
    if ($config['modo_ticket'] == 'numero') {
        $num_actual = $config['contador_ticket'];
        $nombre_final = "#" . $num_actual; 
        
        $sig = $num_actual + 1;
        $max = isset($config['ticket_max']) ? $config['ticket_max'] : 99;
        if ($sig > $max) { $sig = 1; }
        
        $conn->query("UPDATE config_rapida SET contador_ticket = $sig WHERE restaurant_id = $mi_restaurant_id");
    } else {
        $nombre_final = isset($_POST['nombre_oculto']) && !empty(trim($_POST['nombre_oculto'])) ? $conn->real_escape_string($_POST['nombre_oculto']) : 'Cliente'; 
    }

    $conn->query("UPDATE pedidos SET estado = 2, cliente_nombre = '$nombre_final', fecha = NOW() WHERE restaurant_id = $mi_restaurant_id AND codigo_grupo = '$ticket_grupo' AND estado = 1");
    
    // Guardamos el código y limpiamos la sesión
    $ticket_a_pagar = $ticket_grupo;
    unset($_SESSION['ticket_actual']);
    
    // Imprimimos el código del ticket para que JavaScript lo lea
    echo $ticket_a_pagar; 
    exit();
}

// =========================================================
// X. GESTIÓN DE EMPLEADOS (CAJEROS)
// =========================================================
$conn->query("CREATE TABLE IF NOT EXISTS empleados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT,
    nombre VARCHAR(50),
    color VARCHAR(20) DEFAULT '#009EE3'
)");

// Añadir columna a pedidos para las estadísticas de ventas por cajero
$check_emp = $conn->query("SHOW COLUMNS FROM pedidos LIKE 'empleado_id'");
if ($check_emp && $check_emp->num_rows == 0) { 
    $conn->query("ALTER TABLE pedidos ADD COLUMN empleado_id INT DEFAULT 0"); 
}

if (isset($_POST['accion_empleado'])) {
    $accion = $_POST['accion_empleado'];
    
    if ($accion == 'crear') {
        $nom = $conn->real_escape_string($_POST['emp_nombre']);
        $col = $conn->real_escape_string($_POST['emp_color']);
        
        // Verificar si el color ya existe para otro empleado
        $check_col = $conn->query("SELECT id FROM empleados WHERE color = '$col' AND restaurant_id = $mi_restaurant_id");
        if($check_col && $check_col->num_rows > 0) {
            // Si existe, generamos un color hexadecimal aleatorio único
            $col = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
        }
        
        $conn->query("INSERT INTO empleados (restaurant_id, nombre, color) VALUES ($mi_restaurant_id, '$nom', '$col')");
        
    } elseif ($accion == 'editar') {
        // Solo editamos el nombre, bloqueamos la edición del color
        $e_id = (int)$_POST['emp_id'];
        $nom = $conn->real_escape_string($_POST['emp_nombre']);
        $conn->query("UPDATE empleados SET nombre='$nom' WHERE id=$e_id AND restaurant_id=$mi_restaurant_id");
        
        if(isset($_SESSION['emp_id']) && $_SESSION['emp_id'] == $e_id) {
            $_SESSION['emp_nombre'] = $nom;
        }
        
    } elseif ($accion == 'eliminar') {
        $e_id = (int)$_POST['emp_id'];
        $conn->query("DELETE FROM empleados WHERE id=$e_id AND restaurant_id=$mi_restaurant_id");
        if(isset($_SESSION['emp_id']) && $_SESSION['emp_id'] == $e_id) {
            unset($_SESSION['emp_id'], $_SESSION['emp_nombre'], $_SESSION['emp_color']);
        }
        
    } elseif ($accion == 'seleccionar') {
        $_SESSION['emp_id'] = (int)$_POST['emp_id'];
        $_SESSION['emp_nombre'] = $_POST['emp_nombre'];
        $_SESSION['emp_color'] = $_POST['emp_color'];
    }
    header("Location: r_pedidos.php"); exit();
}

// Obtener lista de empleados para el Modal
$res_emp = $conn->query("SELECT * FROM empleados WHERE restaurant_id = $mi_restaurant_id");
$empleados = [];
if($res_emp) { while($row = $res_emp->fetch_assoc()) { $empleados[] = $row; } }

// Variables del empleado actual para mostrar en pantalla
$emp_actual_color = isset($_SESSION['emp_color']) ? $_SESSION['emp_color'] : '#8b949e';
$emp_actual_nombre = isset($_SESSION['emp_nombre']) ? $_SESSION['emp_nombre'] : 'Seleccionar Empleado';

    // Variables del empleado actual para mostrar en pantalla
$emp_actual_color = isset($_SESSION['emp_color']) ? $_SESSION['emp_color'] : '#8b949e';
$emp_actual_nombre = isset($_SESSION['emp_nombre']) ? $_SESSION['emp_nombre'] : 'Seleccionar Empleado';

// =========================================================
// 3. CONSULTAS PARA PINTAR LA PANTALLA (HTML)
// =========================================================

// --- Descuentos Activos ---
$res_desc_activos = $conn->query("SELECT * FROM descuentos WHERE restaurant_id = $mi_restaurant_id AND estado = 1");

// =========================================================
// 3. CONSULTAS PARA PINTAR LA PANTALLA (HTML)
// =========================================================

// --- Descuentos Activos ---
$res_desc_activos = $conn->query("SELECT * FROM descuentos WHERE restaurant_id = $mi_restaurant_id AND estado = 1");
$lista_cupones = [];
if($res_desc_activos) { while($row = $res_desc_activos->fetch_assoc()) { $lista_cupones[] = $row; } }
$cantidad_cupones = count($lista_cupones);

// --- Categorías Dinámicas ---
$res_cat = $conn->query("SELECT DISTINCT seccion FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion");
$categorias = [];
if($res_cat) { while($c = $res_cat->fetch_assoc()) { if(!empty($c['seccion'])) $categorias[] = $c['seccion']; } }

$categoria_actual = isset($_GET['cat']) ? $conn->real_escape_string($_GET['cat']) : 'Todos';

// --- Filtrar Menú ---
if ($categoria_actual === 'Todos') {
    $menu = $conn->query("SELECT * FROM menu WHERE restaurant_id = $mi_restaurant_id AND disponibilidad = 1 ORDER BY seccion, nombre");
} else {
    $menu = $conn->query("SELECT * FROM menu WHERE restaurant_id = $mi_restaurant_id AND seccion = '$categoria_actual' AND disponibilidad = 1 ORDER BY nombre");
}

// --- Ticket Actual ---
$carrito = $conn->query("SELECT p.*, m.nombre as plato_nombre, m.precio as precio_base FROM pedidos p JOIN menu m ON p.menu_id = m.id WHERE p.restaurant_id = $mi_restaurant_id AND p.codigo_grupo = '$ticket_grupo' AND p.estado = 1");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Punto de Venta - Bargaiwe Fast</title>
    <style>
        .notif-dot { display: inline-block; width: 12px; height: 12px; background: #E53935; border-radius: 50%; box-shadow: 0 0 8px #E53935; margin-left: 5px; animation: pulse 1.5s infinite;}
        
        /* Diseño de los Botones de Categorías */
        .filtros-categoria {
            display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 10px;
            scrollbar-width: thin; scrollbar-color: var(--accent) var(--bg-body);
        }
        .btn-cat { background: var(--bg-body); color: var(--text); border: 1px solid var(--border); padding: 10px 20px; border-radius: 30px; text-decoration: none; font-weight: bold; white-space: nowrap; transition: 0.2s; }
        .btn-cat:hover { border-color: var(--accent); color: var(--accent); }
        .btn-cat.activo { background: var(--accent); color: white; border-color: var(--accent); }
        
        /* Variables de Tema Globales desde la BD */
        :root {
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: <?php echo $tema['color_cajero']; ?>; 
            --danger: #E53935; --success: #32CD32;
        }
        
        <?php if($tema['modo_global'] === 'claro'): ?>
        /* Si en la BD dice claro, sobreescribimos los colores directamente */
        body {
            --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000;
        }
        <?php endif; ?>

        body { background: var(--bg-body); color: var(--text); font-family: 'Segoe UI', sans-serif; margin: 0; display: flex; flex-direction: column; height: 100vh; box-sizing: border-box; }
        
        /* Navegación Superior */
        .top-nav { background: var(--bg-panel); border-bottom: 1px solid var(--border); padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .nav-links { display: flex; gap: 10px; }
        .btn-nav { background: var(--bg-body); color: var(--text-title); text-decoration: none; padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); font-weight: bold; transition: 0.2s; }
        .btn-nav:hover { border-color: var(--accent); color: var(--accent); }
        .btn-config { background: transparent; border: none; font-size: 1.5rem; cursor: pointer; transition: 0.3s; }
        .btn-config:hover { transform: rotate(90deg); }

        /* Estructura Principal */
        .main-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; padding: 20px; flex-grow: 1; overflow: hidden; }
        .panel { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; overflow: hidden; }
        
        /* Menú Izquierda */
        .grid-menu { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); 
            gap: 15px; 
            overflow-y: auto; 
            padding-right: 10px;
            flex: 1; 
            min-height: 0; 
            padding-bottom: 20px; 
    
    /* ¡La magia para que no se estiren cuando hay pocos! */
            align-content: start; 
        }
        .plato-card { background: var(--bg-body); border: 1px solid var(--border); border-radius: 8px; padding: 15px; display: flex; flex-direction: column; }
        .plato-card h3 { 
            margin: 0 0 8px 0; 
            color: var(--text-title); 
            font-size: 1rem;            /* Bajamos un pelín el tamaño para nombres largos */
            line-height: 1.2;
            text-transform: uppercase;
            display: -webkit-box;
            -webkit-line-clamp: 2;      /* Corta el texto en la 2da línea con puntos (...) */
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 2.5rem;         /* Espacio reservado para 2 líneas siempre */
            word-break: break-word;     /* Evita que palabras largas rompan el botón */
        }
        .plato-precio { color: var(--success); font-weight: 900; font-size: 1.2rem; margin-bottom: 15px; }
        input[type="text"], input[type="number"], select { width: 100%; padding: 10px; background: var(--bg-body); color: var(--text); border: 1px solid var(--border); border-radius: 6px; margin-bottom: 10px; box-sizing: border-box; }
        .btn-add { background: var(--border); color: var(--text-title); border: none; padding: 10px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s; margin-top: auto; }
        .btn-add:hover { background: var(--success); color: white; }

        /* Ticket Derecha */
        .ticket-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 10px; }
        .ticket-header h2 { margin: 0; color: var(--accent); font-size: 1.8rem; display: flex; align-items: center; gap: 10px;}
        .btn-reset { background: var(--danger); color: white; border: none; border-radius: 6px; padding: 5px 10px; cursor: pointer; font-size: 0.9rem; font-weight: bold; }
        .btn-reset:hover { background: #ff5252; }
        
        .lista-ticket { flex-grow: 1; overflow-y: auto; margin-bottom: 20px; }
        .carrito-item { background: var(--bg-body); border: 1px solid var(--border); padding: 12px; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;}
        .btn-borrar-item { color: var(--danger); text-decoration: none; font-weight: bold; font-size: 1.2rem; padding: 0 10px;}
        
        .ticket-total { border-top: 2px dashed var(--border); padding-top: 15px; }
        .btn-enviar { background: var(--accent); color: white; border: none; padding: 20px; width: 100%; border-radius: 8px; font-weight: 900; font-size: 1.3rem; cursor: pointer; transition: 0.2s; text-transform: uppercase; }
        .btn-enviar:hover { background: #ff9d2e; transform: scale(1.02); }

        /* Modal Configuración */
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; z-index: 2000; backdrop-filter: blur(5px); }
        .modal-content { background: var(--bg-panel); border: 1px solid var(--border); padding: 30px; border-radius: 15px; width: 100%; max-width: 450px; position: relative; }
        .btn-close { position: absolute; top: 20px; right: 20px; background: transparent; border: none; color: var(--text); font-size: 1.5rem; cursor: pointer; }
        .btn-close:hover { color: var(--danger); }
        .link-menu { display: block; background: var(--bg-body); color: var(--text-title); text-decoration: none; padding: 15px; margin-bottom: 10px; border-radius: 8px; border: 1px solid var(--border); font-weight: bold; text-align: center; }
        .link-menu:hover { border-color: var(--accent); }
</style>
</head>
<body> <?php 
    // --- SISTEMA DE EMERGENCIA ANTI-ACUMULACIÓN ---
    $res_listos = $conn->query("SELECT COUNT(DISTINCT codigo_grupo) as cant FROM pedidos WHERE estado = 3 AND restaurant_id = $mi_restaurant_id");
    $cant_listos = $res_listos ? $res_listos->fetch_assoc()['cant'] : 0;

    $config_pantalla = $conn->query("SELECT pantalla_activa, limite_emergencia, max_cocinando FROM config_rapida WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
    $pantalla_on = isset($config_pantalla['pantalla_activa']) ? $config_pantalla['pantalla_activa'] : 1;
    $limite_emergencia = isset($config_pantalla['limite_emergencia']) ? $config_pantalla['limite_emergencia'] : 20;

    // Si se llega al límite y la pantalla está encendida, se apaga sola
    if ($cant_listos >= $limite_emergencia && $pantalla_on == 1) {
        $conn->query("UPDATE config_rapida SET pantalla_activa = 0 WHERE restaurant_id = $mi_restaurant_id");
        $pantalla_on = 0; // Actualizamos la variable para el HTML
    }
    ?>

    <?php 
    // 1. Contamos cuántos pedidos están listos (Estado 3)
    $res_listos = $conn->query("SELECT COUNT(DISTINCT codigo_grupo) as cant FROM pedidos WHERE estado = 3 AND restaurant_id = $mi_restaurant_id");
    $cant_listos = $res_listos ? $res_listos->fetch_assoc()['cant'] : 0;

    // --- 1. NUEVAS COLUMNAS PARA LA PANTALLA ---
    $check_emergencia = $conn->query("SHOW COLUMNS FROM config_rapida LIKE 'limite_emergencia'");
    if ($check_emergencia && $check_emergencia->num_rows == 0) {
    $conn->query("ALTER TABLE config_rapida ADD COLUMN limite_emergencia INT DEFAULT 10");
}

// --- 2. GUARDAR CONFIGURACIÓN DE LA PANTALLA ---
if (isset($_POST['guardar_pantalla'])) {
    $p_activa = isset($_POST['pantalla_activa']) ? 1 : 0;
    $max_coc = (int)$_POST['max_cocinando'];
    $lim_emerg = (int)$_POST['limite_emergencia'];
    
    $conn->query("UPDATE config_rapida SET pantalla_activa = $p_activa, max_cocinando = $max_coc, limite_emergencia = $lim_emerg WHERE restaurant_id = $mi_restaurant_id");
    header("Location: r_pedidos.php"); exit();
}

// --- 3. SISTEMA DE EMERGENCIA ANTI-ACUMULACIÓN ---
// Contamos cuántos pedidos están listos (Estado 3)
$res_listos = $conn->query("SELECT COUNT(DISTINCT codigo_grupo) as cant FROM pedidos WHERE estado = 3 AND restaurant_id = $mi_restaurant_id");
$cant_listos = $res_listos ? $res_listos->fetch_assoc()['cant'] : 0;

$config_pantalla = $conn->query("SELECT pantalla_activa, limite_emergencia, max_cocinando FROM config_rapida WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
$pantalla_on = isset($config_pantalla['pantalla_activa']) ? $config_pantalla['pantalla_activa'] : 1;
$limite_emergencia = isset($config_pantalla['limite_emergencia']) ? $config_pantalla['limite_emergencia'] : 10;

// Si se llega al límite y la pantalla está encendida, se apaga sola
if ($cant_listos >= $limite_emergencia && $pantalla_on == 1) {
    $conn->query("UPDATE config_rapida SET pantalla_activa = 0 WHERE restaurant_id = $mi_restaurant_id");
    $pantalla_on = 0; // Actualizamos la variable para el HTML
}

    // 3. Consultamos el estado actual de la pantalla para darle color al interruptor
    $config_pantalla = $conn->query("SELECT pantalla_activa FROM config_rapida WHERE restaurant_id = $mi_restaurant_id")->fetch_assoc();
    $pantalla_on = isset($config_pantalla['pantalla_activa']) ? $config_pantalla['pantalla_activa'] : 1;

    $color_pantalla = ($pantalla_on == 1) ? 'var(--success)' : 'var(--danger)';
    $texto_pantalla = ($pantalla_on == 1) ? '📺 Pantalla (ON)' : '📺 Pantalla (OFF)';
    ?>
    
    <div class="top-nav">
        <div style="display: flex; align-items: center; gap: 15px;">
            <h2 style="margin:0; color: var(--accent);">🍔 FastVentas</h2>
            
            <button onclick="document.getElementById('modalEmpleados').style.display='flex'" style="background: var(--bg-panel); border: 1px solid var(--border); color: var(--text-title); padding: 5px 15px; border-radius: 20px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: bold; transition: 0.2s;">
                <span style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background-color: <?php echo $emp_actual_color; ?>; box-shadow: 0 0 8px <?php echo $emp_actual_color; ?>;"></span>
                <?php echo htmlspecialchars($emp_actual_nombre); ?>
            </button>
        </div>
        
        <div class="nav-links">
            
            <button onclick="document.getElementById('modalListos').style.display='flex'" class="btn-nav" style="background: var(--accent); color: white; border: none; font-weight: bold; cursor: pointer;">
                📦 Pedidos Listos
            </button>
            
            <button onclick="document.getElementById('modalPantalla').style.display='flex'" class="btn-nav" style="cursor: pointer; font-weight: bold; <?php echo ($pantalla_on == 1) ? 'color: var(--success); border-color: var(--success);' : 'color: var(--danger); border-color: var(--danger);'; ?>">
                Pantalla (ON/OFF)
            </button>
            <a href="r_configuracion_pagos.php" style="background: #009EE3; color: white; border: 2px solid #007BB5; text-decoration: none; font-weight: bold; padding: 10px 20px; border-radius: 10px; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px;"
               onmouseover="this.style.background='#008cc9'" 
               onmouseout="this.style.background='#009EE3'">
                💳 Maquinitas Point
            </a>
            <a href="r_cocina.php" class="btn-nav">👨‍🍳 Cocina</a>
            <button onclick="document.getElementById('modalConfig').style.display='flex'" class="btn-nav" style="cursor: pointer;">⚙️ Ajustes</button>
            <a href="r_pantalla.php" class="btn-nav" target="_blank" title="Abrir Pantalla de Clientes" style="font-size: 1.2rem; padding: 8px 15px;">📺</a>
        </div>
    </div>

    <div class="main-layout">
        
        <div class="panel">
            <h3 style="margin-top:0; color: var(--text-title);">Menú Disponible</h3>

            <div style="margin-bottom: 20px;">
                <input type="text" id="buscadorPlatos" onkeyup="filtrarPlatos()" placeholder="🔍 Escribe para buscar un plato (Ej: dona)..." style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-title); font-size: 1.1rem; box-sizing: border-box;">
            </div>
            
            <?php if(isset($_GET['exito'])): ?>
                <div style="background: rgba(50,205,50,0.1); color: var(--success); padding: 10px; border-radius: 8px; margin-bottom: 15px; text-align: center; border: 1px solid var(--success);">
                    ✅ ¡Pedido enviado a la cocina! Listo para el siguiente.
                </div>
            <?php endif; ?>
            <div class="filtros-categoria">
                <a href="r_pedidos.php" class="btn-cat <?php echo ($categoria_actual == 'Todos') ? 'activo' : ''; ?>">🍔 Todos</a>
                
                <?php foreach($categorias as $cat): ?>
                    <a href="r_pedidos.php?cat=<?php echo urlencode($cat); ?>" class="btn-cat <?php echo ($categoria_actual == $cat) ? 'activo' : ''; ?>">
                        📁 <?php echo htmlspecialchars($cat); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="grid-menu">
                <?php while($plato = $menu->fetch_assoc()): ?>
                    <div class="plato-card">
                        <h3><?php echo htmlspecialchars($plato['nombre']); ?></h3>
                        <div class="plato-precio">$<?php echo number_format($plato['precio'], 0, ',', '.'); ?></div>
                        
                        <form method="POST" style="margin-top: auto;">
                            <input type="hidden" name="menu_id" value="<?php echo $plato['id']; ?>">
                            
                            <div style="display: flex; gap: 5px; margin-bottom: 10px;">
                                <input type="number" name="cantidad" value="1" min="1" style="width: 60px; padding: 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text);">
                                <input type="text" name="comentario" placeholder="Ej: Sin mayo" style="flex: 1; padding: 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text);">
                            </div>
                            
                            <div style="display: flex; gap: 5px;">
                                <input type="hidden" name="cupon_aplicado" class="input-cupon-oculto" value="">
                                
                                <button type="submit" name="agregar_al_carrito" class="btn-add" style="flex: 2; margin: 0;">
                                    Añadir al Ticket
                                </button>
                                
                                <?php if(isset($cantidad_cupones) && $cantidad_cupones == 1): ?>
                                    <button type="button" onclick="aplicarDstoDirecto(this.form, '<?php echo $lista_cupones[0]['codigo']; ?>')" style="flex: 1; background: var(--success); color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.85rem;" title="Aplicar Descuento a este plato">
                                        🏷️ Con Dsto.
                                    </button>
                                <?php elseif(isset($cantidad_cupones) && $cantidad_cupones > 1): ?>
                                    <button type="button" onclick="abrirModalDstoPlato(this.form)" style="flex: 1; background: var(--success); color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.85rem;" title="Ver Descuentos">
                                        🏷️ Dstos.
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>

        <div class="panel" style="border-color: var(--accent);">
            
            <div class="ticket-header">
                <?php if ($config['modo_ticket'] == 'numero'): ?>
                    <h2>Ticket #<?php echo $config['contador_ticket']; ?></h2>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="reset_contador" class="btn-reset" title="Reiniciar Contador a 1">🔄</button>
                    </form>
                <?php else: ?>
                    <input type="text" name="nombre_final" id="nombreCliente" oninput="guardarNombreTemporal()" placeholder="Nombre del cliente..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text-title); margin-bottom: 10px;">
                <?php endif; ?>
            </div>

            <div class="lista-ticket">
                <?php 
                $total = 0;
                if($carrito && $carrito->num_rows > 0): 
                    while($item = $carrito->fetch_assoc()): 
                        // Leemos el precio exacto al que se guardó (con o sin descuento)
                        // Si por algún motivo hay un producto viejo sin esto, usa el precio base para que no falle
                        $precio_unitario = isset($item['precio_al_momento']) ? $item['precio_al_momento'] : $item['precio_base'];
                        
                        $subtotal = $item['cantidad'] * $precio_unitario;
                        $total += $subtotal;
                ?>
                    <div class="carrito-item">
                        <div>
                            <strong style="color: var(--text-title);"><?php echo $item['cantidad']; ?>x <?php echo htmlspecialchars($item['plato_nombre']); ?></strong><br>
                            <?php if(!empty($item['comentario'])): ?>
                                <span style="font-size: 0.8rem; color: #8b949e;">* <?php echo htmlspecialchars($item['comentario']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <strong style="color: var(--success);">$<?php echo number_format($subtotal, 0, ',', '.'); ?></strong>
                            <a href="?borrar_item=<?php echo $item['id']; ?>" class="btn-borrar-item">×</a>
                        </div>
                    </div>
                <?php endwhile; else: ?>
                    <p style="color: #8b949e; text-align: center; margin-top: 50px;">Ticket vacío.<br>Añade platos para comenzar.</p>
                <?php endif; ?>
            </div>
            <div class="ticket-total" style="padding-top: 15px; border-top: 2px dashed var(--border); margin-top: 15px;">
                
                <?php
                // --- LÓGICA DE CUPÓN APLICADO ---
                $descuento_monto = 0;
                $mensaje_cupon = "";
                
                if (isset($_POST['aplicar_cupon'])) {
                    $cod_ingresado = strtoupper(trim($conn->real_escape_string($_POST['cupon_ingresado'])));
                    $res_c = $conn->query("SELECT * FROM descuentos WHERE codigo = '$cod_ingresado' AND restaurant_id = $mi_restaurant_id AND estado = 1");
                    
                    if ($res_c && $res_c->num_rows > 0) {
                        $d = $res_c->fetch_assoc();
                        if ($d['tipo'] == 'porcentaje') {
                            $descuento_monto = $total * (floatval($d['valor']) / 100);
                            $mensaje_cupon = "Cupón " . $d['codigo'] . " (-" . floatval($d['valor']) . "%)";
                        } else {
                            $descuento_monto = $d['valor'];
                            $mensaje_cupon = "Cupón " . $d['codigo'] . " (-$" . number_format($d['valor'], 0, ',', '.') . ")";
                        }
                    } else {
                        $mensaje_cupon = "❌ Cupón no válido";
                    }
                }
                $total_final = $total - $descuento_monto;
                if($total_final < 0) $total_final = 0;

                // --- BUSCAMOS CUÁNTOS CUPONES ACTIVOS HAY EN EL LOCAL ---
                $res_desc_activos = $conn->query("SELECT * FROM descuentos WHERE restaurant_id = $mi_restaurant_id AND estado = 1");
                $lista_cupones = [];
                if($res_desc_activos) {
                    while($row = $res_desc_activos->fetch_assoc()) {
                        $lista_cupones[] = $row;
                    }
                }
                $cantidad_cupones = count($lista_cupones);
                ?>

                <?php if($descuento_monto > 0 || !empty($mensaje_cupon)): ?>
                    <div style="display: flex; justify-content: space-between; color: var(--accent); font-size: 0.95rem; margin-bottom: 10px; font-weight: bold; padding: 10px; background: rgba(255,255,255,0.05); border-radius: 8px;">
                        <span><?php echo $mensaje_cupon; ?></span>
                        <span>-$<?php echo number_format($descuento_monto, 0, ',', '.'); ?></span>
                    </div>
                <?php endif; ?>
<h2 style="margin: 0 0 15px 0; color: var(--text-title); display: flex; justify-content: space-between; font-size: 1.8rem;">
                    <span>TOTAL:</span>
                    <span style="color: var(--success);">$<?php echo number_format($total_final, 0, ',', '.'); ?></span>
                </h2>
                
                <div style="display: flex; width: 100%;">
                    <form id="formTicket" method="POST" style="width: 100%; margin: 0;" onsubmit="document.getElementById('nombre_oculto').value = document.getElementById('nombreCliente') ? document.getElementById('nombreCliente').value : '';">
                        <input type="hidden" name="monto_pagado" value="<?php echo $total_final; ?>">
                        
                        <input type="hidden" name="nombre_oculto" id="nombre_oculto" value="">
                        
                        <?php if($carrito && $carrito->num_rows > 0): ?>
                            <button type="submit" name="enviar_a_cocina" class="btn-enviar" style="width: 100%; padding: 15px; font-size: 1.1rem;">ENVIAR A COCINA</button>
                        <?php else: ?>
                            <button type="button" class="btn-enviar" style="width: 100%; padding: 15px; font-size: 1.1rem; background: var(--border); cursor: not-allowed;">VACÍO</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div> </div> <script>
        // LÓGICA DE DESCUENTOS POR PLATO
        let formPlatoSeleccionado = null;

        function abrirModalDstoPlato(formulario) {
            formPlatoSeleccionado = formulario; 
            document.getElementById('modalDstoPlato').style.display = 'flex';
        }

        function aplicarDstoYEnviar(codigo) {
            if(formPlatoSeleccionado) {
                formPlatoSeleccionado.querySelector('.input-cupon-oculto').value = codigo;
                let btnOculto = document.createElement("input");
                btnOculto.type = "hidden";
                btnOculto.name = "agregar_al_carrito"; 
                formPlatoSeleccionado.appendChild(btnOculto);
                formPlatoSeleccionado.submit();
            }
        }

        // NUEVA FUNCIÓN: Para cuando hay 1 solo descuento
        function aplicarDstoDirecto(formulario, codigo) {
            formulario.querySelector('.input-cupon-oculto').value = codigo;
            let btnOculto = document.createElement("input");
            btnOculto.type = "hidden";
            btnOculto.name = "agregar_al_carrito"; // Simulamos el clic del botón principal
            formulario.appendChild(btnOculto);
            formulario.submit();
        }

        // MEMORIA PARA EL NOMBRE DEL CLIENTE
        const inputNombre = document.getElementById('nombreCliente');
        // ... (el resto de tu script sigue igual hacia abajo)
        document.addEventListener("DOMContentLoaded", function() {
            if (inputNombre) {
                let nombreGuardado = sessionStorage.getItem('memoria_nombre_cliente');
                if (nombreGuardado) {
                    inputNombre.value = nombreGuardado;
                }
            }

            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('exito')) {
                sessionStorage.removeItem('memoria_nombre_cliente');
                if (inputNombre) inputNombre.value = ''; 
                window.history.replaceState({}, document.title, "r_pedidos.php");
            }
        });

        function guardarNombreTemporal() {
            if (inputNombre) {
                sessionStorage.setItem('memoria_nombre_cliente', inputNombre.value);
            }
        }
        // ==========================================
// 1. LÓGICA DEL BUSCADOR EN VIVO
// ==========================================
function filtrarPlatos() {
    // Captura lo que escribes y lo pasa a minúsculas
    let input = document.getElementById('buscadorPlatos').value.toLowerCase();
    // Selecciona todas las tarjetas de platos
    let platos = document.querySelectorAll('.plato-card');

    platos.forEach(plato => {
        // Busca el texto dentro del <h3> de cada tarjeta
        let nombre = plato.querySelector('h3').innerText.toLowerCase();
        
        // Si el nombre incluye lo que escribiste, lo muestra. Si no, lo oculta al instante.
        if (nombre.includes(input)) {
            plato.style.display = 'flex';
        } else {
            plato.style.display = 'none';
        }
    });
}

// ==========================================
// 2. ENVIAR A COCINA Y MANDAR A IMPRIMIR (WINDOWS)
// ==========================================
document.addEventListener("DOMContentLoaded", function() {
    const formTicket = document.getElementById('formTicket');
    
    if(formTicket) {
        formTicket.addEventListener('submit', function(e) {
            e.preventDefault(); // 🛑 Detiene la recarga de la página

            const inputNombre = document.getElementById('nombreCliente');
            if(inputNombre) {
                document.getElementById('nombre_oculto').value = inputNombre.value;
            }

            let formData = new FormData(this);
            formData.append('enviar_a_cocina', '1');

            // Enviamos los datos a PHP
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text()) // Capturamos el texto que devuelve PHP (el ticket_id)
            .then(ticket_id => {
                ticket_id = ticket_id.trim();

                if(ticket_id) {
                    // 🖨️ MAGIA DE IMPRESIÓN: Creamos un iframe invisible
                    let iframe = document.createElement('iframe');
                    iframe.style.display = 'none';
                    // Llamamos a tu archivo de impresión pasándole el código del ticket
                    iframe.src = 'r_ticket_imprimible.php?ticket=' + ticket_id;
                    document.body.appendChild(iframe);
                    
                    // Opcional: Eliminar el iframe después de unos segundos para no ensuciar el HTML
                    setTimeout(() => { document.body.removeChild(iframe); }, 10000);
                }

                // 🧹 Limpiamos la pantalla visualmente para el siguiente cliente
                document.querySelector('.lista-ticket').innerHTML = '<p style="color: var(--success); text-align: center; margin-top: 50px; font-weight: bold;">✅ ¡Ticket enviado a cocina e imprimiendo!</p>';
                
                const totalElement = document.querySelector('.ticket-total h2 span:last-child');
                if(totalElement) totalElement.innerText = '$0';

                if(inputNombre) {
                    inputNombre.value = '';
                    sessionStorage.removeItem('memoria_nombre_cliente');
                }
                
                document.getElementById('buscadorPlatos').value = '';
                filtrarPlatos(); 
                
            }).catch(error => console.error('Error al enviar:', error));
        });
    }
});
    </script>

    <div id="modalListos" class="modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; z-index: 2000; backdrop-filter: blur(5px);">
        <div class="modal-content" style="background: var(--bg-panel); border: 1px solid var(--border); padding: 30px; border-radius: 15px; width: 100%; max-width: 450px; position: relative;">
            <button onclick="document.getElementById('modalListos').style.display='none'" class="btn-close" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; color: var(--text); font-size: 1.5rem; cursor: pointer;">×</button>
            <h3 style="margin-top: 0; color: var(--accent);">📦 Pedidos Listos</h3>
            
            <div style="max-height: 300px; overflow-y: auto; margin-bottom: 20px;">
                <?php if(empty($pedidos_listos)): ?>
                    <p style="text-align: center; color: #8b949e;">No hay pedidos listos en este momento.</p>
                <?php else: ?>
                    <?php foreach($pedidos_listos as $pl): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-body); padding: 10px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 10px;">
                            <strong style="color: var(--text-title); font-size: 1.1rem;"><?php echo htmlspecialchars($pl['cliente_nombre']); ?></strong>
                            <form action="r_pedidos.php" method="POST" style="margin: 0;">
                                <input type="hidden" name="codigo_ticket" value="<?php echo $pl['codigo_grupo']; ?>">
                                <button type="submit" name="entregar_ticket" style="background: var(--success); color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer;">✔ Entregar</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if(!empty($pedidos_listos)): ?>
                <form action="r_pedidos.php" method="POST" style="margin: 0;">
                    <button type="submit" name="limpiar_todos_listos" style="background: var(--danger); color: white; border: none; padding: 12px; width: 100%; border-radius: 6px; font-weight: bold; cursor: pointer;" onclick="return confirm('¿Seguro que quieres borrar todos los pedidos listos?');">🧹 Limpiar Todos los Pedidos</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div id="modalConfig" class="modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; z-index: 2000; backdrop-filter: blur(5px);">
        <div class="modal-content" style="background: var(--bg-panel); border: 1px solid var(--border); padding: 30px; border-radius: 15px; width: 100%; max-width: 450px; position: relative;">
            <button class="btn-close" onclick="document.getElementById('modalConfig').style.display='none'" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; color: var(--text); font-size: 1.5rem; cursor: pointer;">×</button>
            <h2 style="margin-top: 0; color: var(--text-title); border-bottom: 1px solid var(--border); padding-bottom: 10px;">Ajustes del Local</h2>
            
            <div style="margin-bottom: 25px;">
                <a href="r_menu.php" class="link-menu" style="display: block; background: var(--bg-body); color: var(--text-title); text-decoration: none; padding: 15px; margin-bottom: 10px; border-radius: 8px; border: 1px solid var(--border); font-weight: bold; text-align: center;">🍔 Editar Mis Productos</a>
                
                <a href="r_descuentos.php" class="link-menu" style="display: block; background: var(--bg-body); color: var(--text-title); text-decoration: none; padding: 15px; margin-bottom: 10px; border-radius: 8px; border: 1px solid var(--border); font-weight: bold; text-align: center;">🏷️ Gestión de Descuentos</a>
                
                <a href="r_config_impresora.php" class="link-menu" style="display: block; background: var(--bg-body); color: var(--text-title); text-decoration: none; padding: 15px; margin-bottom: 10px; border-radius: 8px; border: 1px dashed #009EE3; font-weight: bold; text-align: center;">🖨️ Configurar Ticketera</a>
                
                <?php if (isset($_SESSION['nivel_plan']) && $_SESSION['nivel_plan'] === 'plus'): ?>
                    <a href="r_stats.php" class="link-menu" style="display: block; background: var(--bg-body); color: var(--text-title); text-decoration: none; padding: 15px; margin-bottom: 10px; border-radius: 8px; border: 1px solid var(--border); font-weight: bold; text-align: center;">📊 Ver Estadísticas de Venta</a>
                <?php endif; ?>
                
                <a href="r_temas.php" class="link-menu" style="display: block; background: var(--bg-body); color: var(--accent); text-decoration: none; padding: 15px; margin-bottom: 10px; border-radius: 8px; border: 1px solid var(--accent); font-weight: bold; text-align: center;">🎨 Configurar Colores</a>
                
                <a href="../usuario_hub.php" class="link-menu" style="display: block; background: var(--bg-body); color: var(--danger); text-decoration: none; padding: 15px; margin-bottom: 10px; border-radius: 8px; border: 1px solid var(--danger); font-weight: bold; text-align: center;">🏠 Salir al Hub Principal</a>
            </div>

            <h3 style="color: var(--text-title); font-size: 1.1rem; margin-bottom: 10px;">Configuración de Tickets</h3>
            <form action="r_pedidos.php" method="POST" style="background: var(--bg-body); padding: 15px; border-radius: 8px; border: 1px solid var(--border);">
                <label style="display:block; margin-bottom: 5px; font-weight: bold;">Modo de Identificación:</label>
                <select name="modo_ticket" style="width: 100%; padding: 10px; margin-bottom: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-panel); color: var(--text);">
                    <option value="numero" <?php echo (isset($config['modo_ticket']) && $config['modo_ticket'] == 'numero') ? 'selected' : ''; ?>>Numeración Automática</option>
                    <option value="nombre" <?php echo (isset($config['modo_ticket']) && $config['modo_ticket'] == 'nombre') ? 'selected' : ''; ?>>Escribir Nombre</option>
                </select>

                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="display:block; margin-bottom: 5px; font-weight: bold; font-size: 0.9rem;">Número (Mín):</label>
                        <input type="number" name="numero_inicio" value="<?php echo isset($config['contador_ticket']) ? $config['contador_ticket'] : 1; ?>" min="1" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-panel); color: var(--text); box-sizing: border-box;">
                    </div>
                    <div style="flex: 1;">
                        <label style="display:block; margin-bottom: 5px; font-weight: bold; font-size: 0.9rem;">Límite (Máx):</label>
                        <input type="number" name="ticket_max" value="<?php echo isset($config['ticket_max']) ? $config['ticket_max'] : 99; ?>" min="10" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-panel); color: var(--text); box-sizing: border-box;">
                    </div>
                </div>
                <button type="submit" name="guardar_config" style="background: var(--success); color: white; border: none; padding: 10px; width: 100%; border-radius: 6px; font-weight: bold; cursor: pointer;">Guardar Ajustes</button>
            </form>
        </div>
    </div>

    <div id="modalPantalla" class="modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; z-index: 2000; backdrop-filter: blur(5px);">
        <div class="modal-content" style="background: var(--bg-panel); border: 1px solid var(--border); padding: 30px; border-radius: 15px; width: 100%; max-width: 450px; position: relative;">
            <button onclick="document.getElementById('modalPantalla').style.display='none'" class="btn-close" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; color: var(--text); font-size: 1.5rem; cursor: pointer;">×</button>
            <h3 style="margin-top: 0; color: var(--accent);">📺 Control de Pantalla</h3>
            
            <form action="r_pedidos.php" method="POST" style="background: var(--bg-body); padding: 15px; border-radius: 8px; border: 1px solid var(--border);">
                
                <div style="margin-bottom: 15px;">
                    <label style="display:flex; align-items: center; gap: 10px; font-weight: bold; font-size: 1.1rem; cursor: pointer;">
                        <input type="checkbox" name="pantalla_activa" value="1" <?php echo ($pantalla_on == 1) ? 'checked' : ''; ?> style="width: 20px; height: 20px; accent-color: var(--success);">
                        Encender Pantalla de Clientes
                    </label>
                    <span style="font-size: 0.8rem; color: #8b949e; display: block; margin-top: 5px;">Si se apaga, la TV mostrará un aviso de pausa.</span>
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 5px; font-weight: bold; font-size: 0.9rem;">Pedidos en "Preparando":</label>
                    <input type="number" name="max_cocinando" value="<?php echo isset($config['max_cocinando']) ? $config['max_cocinando'] : 3; ?>" min="1" max="15" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-panel); color: var(--text); box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="display:block; margin-bottom: 5px; font-weight: bold; font-size: 0.9rem;">Apagar por Emergencia (Max Listos):</label>
                    <input type="number" name="limite_emergencia" value="<?php echo $limite_emergencia; ?>" min="1" style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-panel); color: var(--text); box-sizing: border-box;">
                </div>

                <button type="submit" name="guardar_pantalla" style="background: var(--accent); color: white; border: none; padding: 10px; width: 100%; border-radius: 6px; font-weight: bold; cursor: pointer; text-transform: uppercase;">Aplicar Cambios a TV</button>
            </form>
        </div>
    </div>

    <div id="modalDstoPlato" class="modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; z-index: 2000; backdrop-filter: blur(5px);">
        <div class="modal-content" style="background: var(--bg-panel); border: 1px solid var(--border); padding: 30px; border-radius: 15px; width: 100%; max-width: 450px; position: relative;">
            <button onclick="document.getElementById('modalDstoPlato').style.display='none'" class="btn-close" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; color: var(--text); font-size: 1.5rem; cursor: pointer;">×</button>
            <h3 style="margin-top: 0; color: var(--success);">🏷️ Seleccionar Descuento</h3>
            <p style="color: var(--text); font-size: 0.9rem;">Se aplicará solo a este plato.</p>

            <div style="max-height: 300px; overflow-y: auto;">
                <?php if(empty($lista_cupones)): ?>
                    <p style="text-align: center; color: #8b949e;">No hay cupones activos.</p>
                <?php else: ?>
                    <?php foreach($lista_cupones as $c): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-body); padding: 15px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 10px;">
                            <div>
                                <strong style="color: var(--text-title); font-size: 1.2rem;"><?php echo htmlspecialchars($c['codigo']); ?></strong>
                                <br>
                                <span style="color: var(--success); font-weight: bold;">
                                    <?php echo ($c['tipo'] == 'porcentaje') ? '-'.floatval($c['valor']).'%' : '-$'.number_format($c['valor'], 0, ',', '.'); ?>
                                </span>
                            </div>
                            <button type="button" onclick="aplicarDstoYEnviar('<?php echo htmlspecialchars($c['codigo']); ?>')" style="background: var(--accent); color: white; border: none; padding: 10px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; text-transform: uppercase;">Aplicar</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
<script src="../sesion_infinita.js"></script>
<div id="modalEmpleados" class="modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; z-index: 2000; backdrop-filter: blur(5px);">
        <div class="modal-content" style="background: var(--bg-panel); border: 1px solid var(--border); padding: 30px; border-radius: 15px; width: 100%; max-width: 450px; position: relative;">
            <button onclick="document.getElementById('modalEmpleados').style.display='none'" class="btn-close" style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; color: var(--text); font-size: 1.5rem; cursor: pointer;">×</button>
            <h3 style="margin-top: 0; color: var(--accent);">👥 Cajeros / Empleados</h3>
            
            <form method="POST" style="margin-bottom: 20px; display: flex; gap: 5px;">
                <input type="hidden" name="accion_empleado" value="crear">
                <input type="text" name="emp_nombre" placeholder="Nombre del trabajador..." required style="flex: 1; padding: 10px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-body); color: var(--text);">
                <input type="color" name="emp_color" value="#009EE3" style="width: 45px; height: 40px; border: none; border-radius: 6px; cursor: pointer; padding: 0;">
                <button type="submit" style="background: var(--success); color: white; border: none; border-radius: 6px; padding: 0 15px; font-weight: bold; cursor: pointer; font-size: 1.2rem;">+</button>
            </form>

            <div style="max-height: 300px; overflow-y: auto;">
                <?php if(empty($empleados)): ?>
                    <p style="text-align: center; color: #8b949e;">No hay empleados registrados. Añade uno arriba.</p>
                <?php else: ?>
                    <?php foreach($empleados as $emp): ?>
                        <div style="background: var(--bg-body); padding: 10px; border-radius: 8px; border: 1px solid var(--border); margin-bottom: 10px; display: flex; flex-direction: column; gap: 10px;">
                            
                            <form method="POST" style="display: flex; gap: 10px; align-items: center; margin: 0;">
                                <input type="hidden" name="accion_empleado" value="editar">
                                <input type="hidden" name="emp_id" value="<?php echo $emp['id']; ?>">
                                
                                <div style="width: 30px; height: 30px; border-radius: 50%; background-color: <?php echo $emp['color']; ?>; box-shadow: 0 0 5px rgba(0,0,0,0.3); flex-shrink: 0;" title="Color asignado"></div>
                                
                                <input type="text" name="emp_nombre" value="<?php echo htmlspecialchars($emp['nombre']); ?>" style="flex: 1; padding: 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-panel); color: var(--text-title);" required>
                                
                                <button type="submit" style="background: var(--accent); color: white; border: none; border-radius: 6px; padding: 8px 12px; cursor: pointer; font-size: 0.9rem;" title="Guardar Nuevo Nombre">💾</button>
                            </form>
                            
                            <div style="display: flex; gap: 10px;">
                                <form method="POST" style="flex: 1; margin: 0;">
                                    <input type="hidden" name="accion_empleado" value="seleccionar">
                                    <input type="hidden" name="emp_id" value="<?php echo $emp['id']; ?>">
                                    <input type="hidden" name="emp_nombre" value="<?php echo htmlspecialchars($emp['nombre']); ?>">
                                    <input type="hidden" name="emp_color" value="<?php echo $emp['color']; ?>">
                                    <button type="submit" style="width: 100%; background: <?php echo $emp['color']; ?>; color: white; border: none; border-radius: 4px; padding: 8px; cursor: pointer; font-weight: bold; text-shadow: 0 0 3px rgba(0,0,0,0.5);">
                                        <?php echo (isset($_SESSION['emp_id']) && $_SESSION['emp_id'] == $emp['id']) ? '✅ Seleccionado' : '👉 Seleccionar para Caja'; ?>
                                    </button>
                                </form>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="accion_empleado" value="eliminar">
                                    <input type="hidden" name="emp_id" value="<?php echo $emp['id']; ?>">
                                    <button type="submit" style="background: var(--danger); color: white; border: none; border-radius: 4px; padding: 8px 12px; cursor: pointer;" onclick="return confirm('¿Borrar empleado permanentemente?');">🗑️</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>