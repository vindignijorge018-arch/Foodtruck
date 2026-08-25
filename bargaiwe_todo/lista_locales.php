<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CANDADO MASTER PRINCIPAL
if (!isset($_SESSION['soy_el_dios_de_bargaiwe'])) {
    header("Location: administrar_bargaiwe.php");
    exit();
}

// CANDADO PIN SECUNDARIO (1234)
if (isset($_POST['ingresar_pin'])) {
    if ($_POST['pin_seguridad'] === "2002") {
        $_SESSION['pin_verificado'] = true;
    } else {
        $error_pin = "PIN incorrecto.";
    }
}

if (!isset($_SESSION['pin_verificado'])) {
    echo '<style>body{background:#0d1117;color:white;display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;}.box{background:#161b22;padding:40px;border-radius:10px;border:1px solid #30363d;text-align:center;}input{padding:10px;width:100%;margin:10px 0;background:#0d1117;color:white;border:1px solid #30363d; text-align:center; font-size: 1.2rem; letter-spacing: 5px;}button{background:#007BFF;color:white;border:none;padding:12px;width:100%;cursor:pointer;font-weight:bold; border-radius:6px;}</style>';
    echo '<div class="box"><h2>🔐 Acceso a Base de Datos</h2><p style="color:#8b949e;">Ingresa el PIN de seguridad para ver todos los locales.</p><form method="POST"><input type="password" name="pin_seguridad" placeholder="••••" maxlength="4" autofocus><button type="submit" name="ingresar_pin">Verificar PIN</button>';
    if(isset($error_pin)) echo "<p style='color:#E53935;'>$error_pin</p>";
    echo '</form><br><a href="administrar_bargaiwe.php" style="color:#8b949e; text-decoration:none;">← Volver</a></div>';
    exit();
}

include 'gestion_restaurante/db.php';

// --- FUNCIONES DE BOTONES (Tiempos, Estados, Borrar, Clonar) ---
if (isset($_POST['modificar_tiempo_avanzado'])) {
    $id_mod = (int)$_POST['rest_id']; $accion = $_POST['accion_tiempo']; $cantidad = (int)$_POST['cantidad_tiempo']; $unidad = $_POST['unidad_tiempo'];
    $signo = ($accion === 'restar') ? '-' : '+';
    $res_f = $conn->query("SELECT fecha_vencimiento FROM restaurantes WHERE id = $id_mod");
    if ($row_f = $res_f->fetch_assoc()) {
        $fecha_base = $row_f['fecha_vencimiento']; $hoy = date('Y-m-d');
        $inicio = ($fecha_base < $hoy && $accion === 'sumar') ? $hoy : $fecha_base;
        $nueva_fecha = date('Y-m-d', strtotime("$inicio $signo$cantidad $unidad"));
        $conn->query("UPDATE restaurantes SET fecha_vencimiento = '$nueva_fecha' WHERE id = $id_mod");
    }
    header("Location: lista_locales.php"); exit();
}
if (isset($_POST['cambiar_estado'])) {
    $id_mod = (int)$_POST['rest_id']; $nuevo_estado = $conn->real_escape_string($_POST['nuevo_estado']);
    $conn->query("UPDATE restaurantes SET estado_cuenta = '$nuevo_estado' WHERE id = $id_mod");
    header("Location: lista_locales.php"); exit();
}
if (isset($_GET['borrar_id'])) {
    $id = (int)$_GET['borrar_id']; $conn->query("DELETE FROM restaurantes WHERE id = $id"); $conn->query("DELETE FROM mensajes_soporte WHERE restaurant_id = $id");
    header("Location: lista_locales.php"); exit();
}
if (isset($_POST['copiar_cuenta'])) {
    $id_origen = (int)$_POST['rest_id']; 
    $res_orig = $conn->query("SELECT * FROM restaurantes WHERE id = $id_origen");
    
    if (!function_exists('obtenerSiguienteCodigo')) {
        function obtenerSiguienteCodigo($conn) {
            $res = $conn->query("SELECT codigo_secreto FROM restaurantes WHERE codigo_secreto IS NOT NULL ORDER BY id DESC LIMIT 1");
            if ($res && $row = $res->fetch_assoc()) {
                $cod = $row['codigo_secreto']; $cod++; return $cod;
            } return 'A';
        }
    }

    if ($orig = $res_orig->fetch_assoc()) {
        $nuevo_nombre = $conn->real_escape_string("Copia " . $orig['nombre_local']); 
        $nuevo_email = $conn->real_escape_string("copia_" . time() . "_" . $orig['email']);
        $pass_hash = password_hash("1234", PASSWORD_DEFAULT);
        $vencimiento = date('Y-m-d', strtotime('+1 month')); 
        $nuevo_codigo = obtenerSiguienteCodigo($conn);
        $tipo_local = $conn->real_escape_string($orig['tipo_local']); 
        
        $plan_origen = isset($orig['nivel_plan']) ? $conn->real_escape_string($orig['nivel_plan']) : 'standard'; 

        $stmt = $conn->prepare("INSERT INTO restaurantes (nombre_local, email, password_hash, fecha_vencimiento, codigo_secreto, tipo_local, nivel_plan) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $nuevo_nombre, $nuevo_email, $pass_hash, $vencimiento, $nuevo_codigo, $tipo_local, $plan_origen);
        
        if ($stmt->execute()) {
            $nuevo_id = $conn->insert_id;
            $conn->query("INSERT INTO menu (restaurant_id, nombre, precio, descripcion, disponibilidad, seccion, tipo_articulo) SELECT $nuevo_id, nombre, precio, descripcion, disponibilidad, seccion, tipo_articulo FROM menu WHERE restaurant_id = $id_origen");
            $conn->query("INSERT INTO metas_restaurante (restaurant_id, meta_dinero, act_neta, act_prop) SELECT $nuevo_id, meta_dinero, act_neta, act_prop FROM metas_restaurante WHERE restaurant_id = $id_origen");
            
            if($tipo_local != 'rapida'){
                $conn->query("INSERT INTO mesas (restaurant_id, numero_mesa, usos, top, left_pos) SELECT $nuevo_id, numero_mesa, usos, top, left_pos FROM mesas WHERE restaurant_id = $id_origen");
            }
            header("Location: lista_locales.php"); exit();
        }
    }
}

// --- SISTEMA DE BÚSQUEDA, ORDENAMIENTO Y PAGINACIÓN ---
$limite = 15; 
$pagina_actual = isset($_GET['pag']) ? (int)$_GET['pag'] : 1;
$offset = ($pagina_actual - 1) * $limite;

// Buscador
$busqueda = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
$sql_where = "";
if (!empty($busqueda)) {
    $sql_where = "WHERE nombre_local LIKE '%$busqueda%' OR email LIKE '%$busqueda%'";
}

// Ordenamiento
$orden = isset($_GET['orden']) ? $_GET['orden'] : 'reciente';
$sql_order = "ORDER BY id DESC";
if ($orden === 'az') $sql_order = "ORDER BY nombre_local ASC";
if ($orden === 'tiempo_menos') $sql_order = "ORDER BY fecha_vencimiento ASC";
if ($orden === 'tiempo_mas') $sql_order = "ORDER BY fecha_vencimiento DESC";

$sql = "SELECT * FROM restaurantes $sql_where $sql_order LIMIT $limite OFFSET $offset";
$res_clientes = $conn->query($sql);

// Consulta para contar páginas
$res_total = $conn->query("SELECT COUNT(*) as total FROM restaurantes $sql_where");
$total_filas = $res_total->fetch_assoc()['total'];
$total_paginas = ceil($total_filas / $limite);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Base de Datos de Locales</title>
    <style>
        body { background-color: #0d1117; font-family: 'Segoe UI', sans-serif; color: #c9d1d9; margin: 0; padding: 40px 5%;}
        .header-top { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #30363d; padding-bottom: 20px; margin-bottom: 30px; }
        .card { background: #161b22; padding: 25px; border-radius: 12px; border: 1px solid #30363d; overflow-x: auto;}
        
        .search-container { margin-bottom: 20px; display: flex; gap: 10px; }
        .search-input { flex-grow: 1; padding: 12px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; outline: none; font-size: 1rem;}
        .search-input:focus { border-color: #FF8C00; }
        .btn-buscar { background: #FF8C00; color: white; border: none; padding: 0 20px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: 0.2s;}
        .btn-buscar:hover { background: #ff9d2e; }

        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 15px 12px; text-align: left; border-bottom: 1px solid #30363d; }
        
        tr.borde-restaurante td:first-child { border-left: 5px solid #32CD32; }
        tr.borde-restaurante { background: linear-gradient(to right, rgba(50, 205, 50, 0.1) 0%, transparent 20%); }
        
        tr.borde-fastfood td:first-child { border-left: 5px solid #E53935; }
        tr.borde-fastfood { background: linear-gradient(to right, rgba(229, 57, 53, 0.1) 0%, transparent 20%); }

        .badge { padding: 4px 8px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; }
        .badge-activo { background: rgba(46, 160, 67, 0.15); color: #3fb950; }
        .badge-vencido { background: rgba(248, 81, 73, 0.15); color: #f85149; }
        
        .tag-tipo { font-size: 0.7rem; padding: 3px 6px; border-radius: 4px; background: rgba(255,255,255,0.1); margin-left: 8px; }
        
        select { background: #0d1117; color: white; border: 1px solid #30363d; padding: 8px; border-radius: 6px; }
        .paginacion { margin-top: 20px; display: flex; justify-content: center; gap: 5px; }
        .paginacion a { background: #0d1117; color: white; border: 1px solid #30363d; padding: 8px 12px; text-decoration: none; border-radius: 6px; transition: 0.3s;}
        .paginacion a:hover, .paginacion a.activo { background: #007BFF; border-color: #007BFF; }
    </style>
</head>
<body>

    <div class="header-top">
        <div>
            <h1 style="color: white; margin: 0;">Base de Datos de Locales</h1>
            <p style="margin: 5px 0 0 0; color: #8b949e;">Mostrando página <?php echo $pagina_actual; ?> de <?php echo max(1, $total_paginas); ?> (Total: <?php echo $total_filas; ?> locales)</p>
        </div>
        <div style="display: flex; gap: 15px; align-items: center;">
            <form method="GET" id="formOrden">
                <input type="hidden" name="q" value="<?php echo htmlspecialchars($busqueda); ?>"> <select name="orden" onchange="document.getElementById('formOrden').submit()">
                    <option value="reciente" <?php if($orden=='reciente') echo 'selected'; ?>>Más Recientes</option>
                    <option value="az" <?php if($orden=='az') echo 'selected'; ?>>Alfabético (A-Z)</option>
                    <option value="tiempo_menos" <?php if($orden=='tiempo_menos') echo 'selected'; ?>>Vencen Pronto</option>
                    <option value="tiempo_mas" <?php if($orden=='tiempo_mas') echo 'selected'; ?>>Con Más Tiempo</option>
                </select>
            </form>
            <a href="administrar_bargaiwe.php" style="background: #30363d; color: white; padding: 10px 15px; text-decoration: none; border-radius: 6px; font-weight: bold;">← Volver al Dashboard</a>
        </div>
    </div>

    <div class="search-container">
        <form action="lista_locales.php" method="GET" style="display:flex; width:100%; gap:10px;">
            <input type="hidden" name="orden" value="<?php echo htmlspecialchars($orden); ?>"> <input type="text" name="q" class="search-input" placeholder="Buscar local por nombre o correo..." value="<?php echo htmlspecialchars($busqueda); ?>">
            <?php if(!empty($busqueda)): ?>
                <a href="lista_locales.php" style="background: #30363d; color: white; padding: 12px 15px; text-decoration: none; border-radius: 8px; font-weight: bold;">X Limpiar</a>
            <?php endif; ?>
            <button type="submit" class="btn-buscar">🔍 Buscar</button>
        </form>
    </div>

    <div class="card">
        <table>
            <tr><th>Local / Email</th><th>Plan</th><th>Vence</th><th>Estado</th><th style="text-align: right;">Acciones</th></tr>
            <?php 
            if ($res_clientes && $res_clientes->num_rows > 0):
                $hoy_dt = new DateTime(); 
                $hoy_dt->setTime(0,0,0); 
                
                while($c = $res_clientes->fetch_assoc()): 
                    $vence_dt = new DateTime($c['fecha_vencimiento']);
                    $vence_dt->setTime(0,0,0);
                    
                    $dias_absolutos = $hoy_dt->diff($vence_dt)->days;
                    $es_pasado = $vence_dt < $hoy_dt; 

                    $dias_mostrar = $es_pasado ? -$dias_absolutos : $dias_absolutos;
                    
                    $estado_bd = isset($c['estado_cuenta']) ? $c['estado_cuenta'] : 'activa';
                    
                    if ($estado_bd === 'congelada') { 
                        $estado_txt = "Congelada ($dias_mostrar d)"; 
                        $clase_badge = "badge-vencido"; 
                        $color_dias = "#007BFF"; // Azul
                        $texto_badge = "Pausado"; 
                        
                    } elseif ($es_pasado) { 
                        if ($dias_absolutos <= 5) {

                            $estado_txt = "En Gracia ($dias_mostrar d)"; 
                            $clase_badge = "badge-vencido"; 
                            $color_dias = "#FF8C00";
                            $texto_badge = "Operativo"; 
                        } else {
                            $estado_txt = "Vencido ($dias_mostrar d)"; 
                            $clase_badge = "badge-vencido"; 
                            $color_dias = "#f85149"; 
                            $texto_badge = "OFF"; 
                        }
                        
                    } else { 
                        $estado_txt = "Activo ($dias_mostrar d)"; 
                        $clase_badge = "badge-activo"; 
                        $color_dias = "#3fb950";
                        $texto_badge = 'Operativo'; 
                    }

                    // --- DEFINEN EL COLOR DE LA FILA SEGÚN EL MODELO ---
                    $tipo_local = isset($c['tipo_local']) ? $c['tipo_local'] : 'restaurante';
                    $clase_tr = ($tipo_local === 'rapida') ? 'borde-fastfood' : 'borde-restaurante';

                    $nivel_actual = (isset($c['nivel_plan']) && !empty($c['nivel_plan'])) ? $c['nivel_plan'] : 'standard';

                    $es_prueba = ($nivel_actual === 'plus' && $dias_absolutos <= 8 && $dias_absolutos > 0);

                    if ($es_prueba) {
                        $color_plan = '#17a2b8';
                        $texto_plan = 'Prueba 8d';
                    } elseif ($nivel_actual === 'plus') {
                        $color_plan = '#9C27B0'; 
                        $texto_plan = 'Plus';
                    } else {
                        $color_plan = '#30363d';
                        $texto_plan = 'Estándar';
                    }
            ?>
            <tr class="<?php echo $clase_tr; ?>">
                <?php 
                $tipo_badge = ($tipo_local === 'rapida') 
                    ? '<span style="background: #E53935; color: white; padding: 3px 8px; border-radius: 4px; font-size: 0.7rem; margin-left: 8px; font-weight: bold;">🍟 FAST FOOD</span>' 
                    : '<span style="background: #014421; color: white; padding: 3px 8px; border-radius: 4px; font-size: 0.7rem; margin-left: 8px; font-weight: bold;">🍽️ RESTAURANTE</span>';
                ?>
                <td>
                    <strong><?php echo htmlspecialchars($c['nombre_local']); ?></strong> <?php echo $tipo_badge; ?><br>
                    <span style="font-size: 0.75rem; color: #8b949e;"><?php echo htmlspecialchars($c['email']); ?></span>
                </td>
                <td>
                    <span class="badge" style="background: <?php echo $color_plan; ?>; color: white; text-transform: uppercase;">
                        <?php echo $texto_plan; ?>
                    </span>
                </td>
                <td>
                    <?php echo date('d/m/y', strtotime($c['fecha_vencimiento'])); ?><br>
                    <span style="font-size: 0.75rem; color: <?php echo $color_dias; ?>; font-weight: bold;"><?php echo $estado_txt; ?></span>
                </td>
                <td><span class="badge <?php echo $clase_badge; ?>"><?php echo $texto_badge; ?></span></td>
                <td style="text-align: right;">
                    <div style="display: flex; gap: 5px; justify-content: flex-end; flex-wrap: nowrap;">
                        <button onclick="abrirModalTiempo(<?php echo $c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['nombre_local'])); ?>')" style="background: #3fb950; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.9rem;" title="Modificar Tiempo">⏳</button>
                        <form method="POST" style="margin: 0;" onsubmit="return pedirClave()">
                            <input type="hidden" name="rest_id" value="<?php echo $c['id']; ?>">
                            <input type="hidden" name="nuevo_estado" value="<?php echo ($estado_bd === 'congelada') ? 'activa' : 'congelada'; ?>">
                            <button type="submit" name="cambiar_estado" style="background: <?php echo ($estado_bd === 'congelada') ? '#014421' : '#007BFF'; ?>; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">
                                <?php echo ($estado_bd === 'congelada') ? '▶' : '❄️'; ?>
                            </button>
                        </form>
                        <form method="POST" style="margin: 0;" onsubmit="return pedirClave()">
                            <input type="hidden" name="rest_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" name="copiar_cuenta" style="background: #9C27B0; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.9rem;" title="Clonar Cuenta">📋</button>
                        </form>
                        <button onclick="if(pedirClave()){ window.location.href='?borrar_id=<?php echo $c['id']; ?>'; }" style="background: transparent; color: #f85149; border: 1px solid #f85149; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.9rem;" title="Borrar Definitivamente">🗑️</button>
                    </div>
                </td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="5" style="text-align:center; color:#8b949e;">No se encontraron resultados.</td></tr>
            <?php endif; ?>
        </table>
        
        <?php if($total_paginas > 1): ?>
        <div class="paginacion">
            <?php for($i = 1; $i <= $total_paginas; $i++): ?>
                <a href="?pag=<?php echo $i; ?>&orden=<?php echo urlencode($orden); ?>&q=<?php echo urlencode($busqueda); ?>" class="<?php if($i == $pagina_actual) echo 'activo'; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <div id="modalTiempo" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: none; justify-content: center; align-items: center; z-index: 3000; backdrop-filter: blur(5px);">
        <div style="background: #161b22; padding: 30px; border-radius: 15px; width: 400px; border: 1px solid #30363d; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <h3 style="color: white; margin-top: 0;">⏳ Modificar Tiempo</h3>
            <p style="color: #8b949e; font-size: 0.9rem;">Restaurante: <strong id="tiempo_nombre_local" style="color:white;"></strong></p>
            <form method="POST" onsubmit="return pedirClave()">
                <input type="hidden" name="rest_id" id="tiempo_rest_id">
                <label style="color: white; font-size: 0.8rem; font-weight: bold;">ACCIÓN:</label>
                <select name="accion_tiempo" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; margin-bottom: 10px;">
                    <option value="sumar">➕ Agregar tiempo</option><option value="restar">➖ Quitar tiempo</option>
                </select>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex: 1;"><label style="color: white; font-size: 0.8rem; font-weight: bold;">CANTIDAD:</label><input type="number" name="cantidad_tiempo" value="1" min="1" required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; box-sizing: border-box;"></div>
                    <div style="flex: 1;"><label style="color: white; font-size: 0.8rem; font-weight: bold;">UNIDAD:</label><select name="unidad_tiempo" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; box-sizing: border-box;"><option value="months">Meses</option><option value="days">Días</option></select></div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="modificar_tiempo_avanzado" style="background: #3fb950; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; flex: 1; font-weight: bold;">Aplicar</button>
                    <button type="button" onclick="document.getElementById('modalTiempo').style.display='none'" style="background: #30363d; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; flex: 1;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function pedirClave() {
            let clave = prompt("🔐 PIN de administrador (B4):");
            if (clave === "2002") return true;
            if (clave !== null) alert("❌ PIN incorrecto.");
            return false;
        }
        function abrirModalTiempo(idRest, nombreRest) {
            document.getElementById('modalTiempo').style.display = 'flex';
            document.getElementById('tiempo_rest_id').value = idRest;
            document.getElementById('tiempo_nombre_local').innerText = nombreRest;
        }
    </script>
</body>
</html>