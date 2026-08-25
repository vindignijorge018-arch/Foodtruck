<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---  CANDADO MAESTRO ---
$contrasena_maestra = "Bargaiwe_Master_2026"; 
if (isset($_POST['login_maestro'])) {
    if ($_POST['pass_maestra'] === $contrasena_maestra) {
        $_SESSION['soy_el_dios_de_bargaiwe'] = true;
    } else { $error_admin = "Contraseña incorrecta."; }
}

if (!isset($_SESSION['soy_el_dios_de_bargaiwe'])) {
    echo '<style>body{background:#0d1117;color:white;display:flex;justify-content:center;align-items:center;height:100vh;font-family:sans-serif;}.box{background:#161b22;padding:40px;border-radius:10px;border:1px solid #30363d;text-align:center;}input{padding:10px;width:100%;margin:10px 0;background:#0d1117;color:white;border:1px solid #30363d;}button{background:#E53935;color:white;border:none;padding:10px;width:100%;cursor:pointer;}</style>';
    echo '<div class="box"><h2>🔒 Área Restringida</h2><form method="POST"><input type="password" name="pass_maestra" placeholder="Clave de Creador"><button type="submit" name="login_maestro">Entrar</button></form></div>';
    exit();
}

include 'gestion_restaurante/db.php';

// ---  PREPARAR BASE DE DATOS ---
$conn->query("CREATE TABLE IF NOT EXISTS mensajes_soporte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    remitente VARCHAR(50) NOT NULL,
    mensaje TEXT NOT NULL,
    tipo VARCHAR(20) DEFAULT 'chat',
    leido TINYINT(1) DEFAULT 0,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS mensajes_index (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100),
    correo VARCHAR(100),
    asunto VARCHAR(150),
    mensaje TEXT,
    leido TINYINT(1) DEFAULT 0,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$check_tipo = $conn->query("SHOW COLUMNS FROM mensajes_soporte LIKE 'tipo'");
if ($check_tipo && $check_tipo->num_rows == 0) {
    $conn->query("ALTER TABLE mensajes_soporte ADD COLUMN tipo VARCHAR(20) DEFAULT 'chat'");
}

$check_estado = $conn->query("SHOW COLUMNS FROM restaurantes LIKE 'estado_cuenta'");
if ($check_estado && $check_estado->num_rows == 0) {
    $conn->query("ALTER TABLE restaurantes ADD COLUMN estado_cuenta VARCHAR(20) DEFAULT 'activa'");
}

$check_tipo_local = $conn->query("SHOW COLUMNS FROM restaurantes LIKE 'tipo_local'");
if ($check_tipo_local && $check_tipo_local->num_rows == 0) {
    $conn->query("ALTER TABLE restaurantes ADD COLUMN tipo_local VARCHAR(50) DEFAULT 'restaurante'");
}

$check_codigo = $conn->query("SHOW COLUMNS FROM restaurantes LIKE 'codigo_secreto'");
if ($check_codigo && $check_codigo->num_rows == 0) {
    $conn->query("ALTER TABLE restaurantes ADD COLUMN codigo_secreto VARCHAR(10)");
    $res_all = $conn->query("SELECT id FROM restaurantes ORDER BY id ASC");
    $cod_actual = 'A';
    while($r = $res_all->fetch_assoc()) {
        $conn->query("UPDATE restaurantes SET codigo_secreto = '$cod_actual' WHERE id = " . $r['id']);
        $cod_actual++;
    }
}

function obtenerSiguienteCodigo($conn) {
    $res = $conn->query("SELECT codigo_secreto FROM restaurantes WHERE codigo_secreto IS NOT NULL ORDER BY id DESC LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $cod = $row['codigo_secreto'];
        $cod++; 
        return $cod;
    }
    return 'A';
}

// --- PROCESAR ACCIONES DEL ADMINISTRADOR ---

// Borrar mensaje del Index
if (isset($_GET['borrar_msj_index'])) {
    $id_msj = (int)$_GET['borrar_msj_index'];
    $conn->query("DELETE FROM mensajes_index WHERE id = $id_msj");
    header("Location: administrar_bargaiwe.php");
    exit();
}

// soporte
if (isset($_POST['responder_ticket'])) {
    $rest_id_destino = (int)$_POST['restaurant_id_destino'];
    $respuesta = $conn->real_escape_string($_POST['respuesta_admin']);
    $tipo_destino = isset($_POST['tipo_destino']) ? $conn->real_escape_string($_POST['tipo_destino']) : 'chat';
    $conn->query("INSERT INTO mensajes_soporte (restaurant_id, remitente, mensaje, tipo) VALUES ($rest_id_destino, 'admin', '$respuesta', '$tipo_destino')");
    header("Location: administrar_bargaiwe.php");
    exit();
}

// Resolver ticket de soporte
if (isset($_GET['borrar_chat_id'])) {
    $chat_id = (int)$_GET['borrar_chat_id'];
    $tipo_borrar = isset($_GET['tipo']) ? $conn->real_escape_string($_GET['tipo']) : 'chat';
    $conn->query("DELETE FROM mensajes_soporte WHERE restaurant_id = $chat_id AND tipo = '$tipo_borrar'");
    header("Location: administrar_bargaiwe.php?ticket_resuelto=1");
    exit();
}

// Clonar Cuenta
if (isset($_POST['copiar_cuenta'])) {
    $id_origen = (int)$_POST['rest_id'];
    $res_orig = $conn->query("SELECT * FROM restaurantes WHERE id = $id_origen");
    if ($orig = $res_orig->fetch_assoc()) {
        $nuevo_nombre = $conn->real_escape_string("Copia " . $orig['nombre_local']);
        $nuevo_email = $conn->real_escape_string("copia_" . time() . "_" . $orig['email']);
        $pass_hash = password_hash("1234", PASSWORD_DEFAULT); 
        $vencimiento = date('Y-m-d', strtotime('+1 month')); 
        $nuevo_codigo = obtenerSiguienteCodigo($conn);
        $tipo_local = $orig['tipo_local']; 
        
        $stmt = $conn->prepare("INSERT INTO restaurantes (nombre_local, email, password_hash, fecha_vencimiento, codigo_secreto, tipo_local) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $nuevo_nombre, $nuevo_email, $pass_hash, $vencimiento, $nuevo_codigo, $tipo_local);
        
        if ($stmt->execute()) {
            $nuevo_id = $conn->insert_id;
            $conn->query("INSERT INTO menu (restaurant_id, nombre, precio, descripcion, disponibilidad, seccion, tipo_articulo) SELECT $nuevo_id, nombre, precio, descripcion, disponibilidad, seccion, tipo_articulo FROM menu WHERE restaurant_id = $id_origen");
            $conn->query("INSERT INTO mesas (restaurant_id, numero_mesa, usos, top, left_pos) SELECT $nuevo_id, numero_mesa, usos, top, left_pos FROM mesas WHERE restaurant_id = $id_origen");
            $conn->query("INSERT INTO metas_restaurante (restaurant_id, meta_dinero, act_neta, act_prop) SELECT $nuevo_id, meta_dinero, act_neta, act_prop FROM metas_restaurante WHERE restaurant_id = $id_origen");
            $conn->query("INSERT INTO pedidos (restaurant_id, mesa_id, mesa_numero, menu_id, cantidad, comentario, estado, fecha, fecha_fin, codigo_grupo) SELECT $nuevo_id, mesa_id, mesa_numero, menu_id, cantidad, comentario, estado, fecha, fecha_fin, codigo_grupo FROM pedidos WHERE restaurant_id = $id_origen AND estado = 3");
            $conn->query("INSERT INTO gastos (restaurant_id, concepto, monto, fecha) SELECT $nuevo_id, concepto, monto, fecha FROM gastos WHERE restaurant_id = $id_origen");

            header("Location: administrar_bargaiwe.php?copia=exito");
            exit();
        }
    }
}

// Modificar Tiempo Avanzado
if (isset($_POST['modificar_tiempo_avanzado'])) {
    $id_mod = (int)$_POST['rest_id'];
    $accion = $_POST['accion_tiempo']; 
    $cantidad = (int)$_POST['cantidad_tiempo'];
    $unidad = $_POST['unidad_tiempo']; 
    $signo = ($accion === 'restar') ? '-' : '+';
    
    $res_f = $conn->query("SELECT fecha_vencimiento FROM restaurantes WHERE id = $id_mod");
    if ($row_f = $res_f->fetch_assoc()) {
        $fecha_base = $row_f['fecha_vencimiento'];
        $hoy = date('Y-m-d');
        $inicio = ($fecha_base < $hoy && $accion === 'sumar') ? $hoy : $fecha_base;
        $nueva_fecha = date('Y-m-d', strtotime("$inicio $signo$cantidad $unidad"));
        $conn->query("UPDATE restaurantes SET fecha_vencimiento = '$nueva_fecha' WHERE id = $id_mod");
    }
    header("Location: administrar_bargaiwe.php");
    exit();
}

// Congelar/Descongelar
if (isset($_POST['cambiar_estado'])) {
    $id_mod = (int)$_POST['rest_id'];
    $nuevo_estado = $conn->real_escape_string($_POST['nuevo_estado']);
    $conn->query("UPDATE restaurantes SET estado_cuenta = '$nuevo_estado' WHERE id = $id_mod");
    header("Location: administrar_bargaiwe.php");
    exit();
}

// Editar Nombre y Clave
if (isset($_POST['editar_clon'])) {
    $id_mod = (int)$_POST['rest_id'];
    $nuevo_nombre = $conn->real_escape_string($_POST['nuevo_nombre']);
    $nueva_pass = $_POST['nueva_pass'];

    if (!empty($nueva_pass)) {
        $pass_hash = password_hash($nueva_pass, PASSWORD_DEFAULT);
        $conn->query("UPDATE restaurantes SET nombre_local = '$nuevo_nombre', password_hash = '$pass_hash' WHERE id = $id_mod");
    } else {
        $conn->query("UPDATE restaurantes SET nombre_local = '$nuevo_nombre' WHERE id = $id_mod");
    }
    header("Location: administrar_bargaiwe.php");
    exit();
}

// Borrar Restaurante Definitivamente
if (isset($_GET['borrar_id'])) {
    $id = (int)$_GET['borrar_id'];
    $conn->query("DELETE FROM restaurantes WHERE id = $id");
    $conn->query("DELETE FROM mensajes_soporte WHERE restaurant_id = $id");
    header("Location: administrar_bargaiwe.php?borrado=1");
    exit();
}

// Crear Cliente Rápido
if (isset($_POST['crear_cliente'])) {
    $nombre = $conn->real_escape_string($_POST['nombre_local']);
    $email = !empty($_POST['email']) ? $conn->real_escape_string($_POST['email']) : strtolower(str_replace(' ', '', $nombre)) . "_" . time() . "@test.com";
    $pass_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    $tipo_local = $conn->real_escape_string($_POST['tipo_local']); 
    $nivel_plan = $conn->real_escape_string($_POST['nivel_plan']);
    
    // NUEVA LÓGICA: Detectar si son 8 días o meses normales
    $tiempo_elegido = $_POST['meses_suscripcion'];
    if ($tiempo_elegido === '8d') {
        $vencimiento = date('Y-m-d', strtotime("+8 days"));
    } else {
        $meses = (int)$tiempo_elegido;
        $vencimiento = date('Y-m-d', strtotime("+$meses months"));
    }

    $nuevo_codigo = obtenerSiguienteCodigo($conn);

    $stmt = $conn->prepare("INSERT INTO restaurantes (nombre_local, email, password_hash, fecha_vencimiento, codigo_secreto, tipo_local, nivel_plan) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssss", $nombre, $email, $pass_hash, $vencimiento, $nuevo_codigo, $tipo_local, $nivel_plan);   
    if ($stmt->execute()) {
        header("Location: administrar_bargaiwe.php?exito=1");
        exit();
    }
// --- CONSULTAR DATOS PARA DIBUJAR LA PÁGINA ---


// Lógica para eliminar mensaje de la bandeja
if (isset($_GET['borrar_msj_index'])) {
    $id_borrar = (int)$_GET['borrar_msj_index'];
    $conn->query("DELETE FROM mensajes_index WHERE id = $id_borrar");
    header("Location: administrar_bargaiwe.php");
    exit();
}

// 👑 AQUÍ ESTÁ LA MAGIA QUE FALTA: Extraer los mensajes web
$sql_index = "SELECT * FROM mensajes_index ORDER BY fecha DESC";
$res_index = $conn->query($sql_index);

// (Y asegúrate de tener la de clientes también si la borraste por error)
$res_clientes = $conn->query("SELECT * FROM restaurantes ORDER BY id DESC");

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>SaaS Admin - Bargaiwe</title>
    <style>
        body { background-color: #0d1117; font-family: 'Segoe UI', sans-serif; color: #c9d1d9; margin: 0; padding-bottom: 50px; }
        .nav-hub { background: #161b22; padding: 15px 30px; border-bottom: 1px solid #30363d; display: flex; justify-content: space-between; align-items: center;}
        .container { padding: 40px; display: grid; grid-template-columns: 1fr 2fr; gap: 30px; max-width: 1300px; margin: auto; }
        .card { background: #161b22; padding: 25px; border-radius: 12px; border: 1px solid #30363d; }
        input, select { width: 100%; padding: 10px; margin: 10px 0; background: #0d1117; color: white; border: 1px solid #30363d; border-radius: 6px; box-sizing: border-box; }
        .btn-crear { background: #238636; color: white; border: none; padding: 12px; width: 100%; border-radius: 6px; font-weight: bold; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #30363d; }
        .badge { padding: 4px 8px; border-radius: 20px; font-size: 0.75rem; font-weight: bold; }
        .badge-activo { background: rgba(46, 160, 67, 0.15); color: #3fb950; }
        .badge-vencido { background: rgba(248, 81, 73, 0.15); color: #f85149; }
        
        /* CSS PARA LA LUZ DE ALERTA */
        .luz-alerta {
            width: 15px; height: 15px; border-radius: 50%;
            display: inline-block; margin-right: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
            transition: 0.5s;
        }
        .luz-verde { background: #32CD32; box-shadow: 0 0 15px rgba(50, 205, 50, 0.5); }
        .luz-roja { background: #E53935; box-shadow: 0 0 15px rgba(229, 57, 53, 0.8); animation: latido 1.5s infinite; }

        @keyframes latido {
            0% { opacity: 1; } 50% { opacity: 0.4; } 100% { opacity: 1; }
        }
    </style>
</head>
<body>
    
    <div class="nav-hub">
        <strong>👑 BARGAIWE MASTER PANEL</strong>
        
        <div style="display: flex; align-items: center; background: #0d1117; padding: 8px 15px; border-radius: 20px; border: 1px solid #30363d;">
            <span id="indicador-luz" class="luz-alerta luz-verde"></span>
            <span id="texto-alerta" style="font-size: 0.9rem; font-weight: bold; color: #32CD32;">Sistema Despejado</span>
        </div>
    </div>
    
    <div class="container">
        <div class="card">
            <h3>➕ Crear Cuenta Rápida</h3>
            <form method="POST">
                <input type="text" name="nombre_local" placeholder="Nombre (Ej: Bargaiwe)" required>
                <input type="email" name="email" placeholder="Email (Opcional)">
                <input type="text" name="password" placeholder="Contraseña (Ej: rojo)" required>
                
                <div style="display: flex; gap: 10px;">
                    <select name="tipo_local" style="flex: 1;" required>
                        <option value="restaurante">🍽️ Restaurante (Mesas)</option>
                        <option value="rapida">🥡 Food Truck / Rápida</option>
                    </select>
                    <select name="nivel_plan" style="flex: 1;" required>
                        <option value="standard">Estándar</option>
                        <option value="plus">👑 Plus</option>
                    </select>
                    <select name="meses_suscripcion" style="flex: 1;">
                        <option value="8d">8 Días (Prueba Gratis)</option>
                        <option value="1">1 Mes</option>
                        <option value="12">1 Año</option>
                    </select>
                </div>
                
                <button type="submit" name="crear_cliente" class="btn-crear">Generar Acceso</button>
            </form>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="margin: 0;">🏢 Cuentas de Prueba / Clones</h3>
                <a href="lista_locales.php" style="background: #007BFF; color: white; padding: 8px 15px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 0.9rem;">Ver Todos los Locales ➔</a>
            </div>
            <table style="font-size: 0.9rem;">
                <tr><th>Local / Email</th><th>Plan</th><th>Vence</th><th>Estado</th><th style="text-align: right;">Acciones</th></tr>
                <?php 
                if ($res_clientes && $res_clientes->num_rows > 0):
                    $hoy_dt = new DateTime(); 
                    while($c = $res_clientes->fetch_assoc()): 
                        $vence_dt = new DateTime($c['fecha_vencimiento']);
                        $diferencia_dias = $hoy_dt->diff($vence_dt)->days;
                        $es_pasado = $vence_dt < $hoy_dt; 
                        
                        $estado_bd = isset($c['estado_cuenta']) ? $c['estado_cuenta'] : 'activa';
                        
                        if ($estado_bd === 'congelada') {
                            $estado_txt = "Congelada"; $clase_badge = "badge-vencido"; $color_dias = "#007BFF"; $texto_badge = "Pausado";
                        } elseif ($es_pasado) {
                            $estado_txt = ($diferencia_dias <= 4) ? "En Gracia ($diferencia_dias d)" : "Cortado";
                            $clase_badge = "badge-vencido"; $color_dias = ($diferencia_dias <= 4) ? "#FF8C00" : "#f85149";
                            $texto_badge = ($diferencia_dias > 4) ? 'Suspendido' : 'Operativo';
                        } else {
                            $estado_txt = "Activo ($diferencia_dias d)"; $clase_badge = "badge-activo"; $color_dias = "#3fb950"; $texto_badge = 'Operativo';
                        }
                        
                        $es_clon_o_prueba = (strpos($c['email'], '@test.com') !== false || strpos($c['nombre_local'], 'Copia ') === 0);
                        $es_prueba_js = $es_clon_o_prueba ? 'true' : 'false';
                        
                        $icono_tipo = (isset($c['tipo_local']) && $c['tipo_local'] == 'rapida') ? '🥡' : '🍽️';
                ?>
                <tr>
                    <td>
                        <strong><?php echo $icono_tipo . " " . htmlspecialchars($c['nombre_local']); ?> <span style="color:#FF8C00;">[#<?php echo $c['codigo_secreto']; ?>]</span></strong><br>
                        <span style="font-size: 0.75rem; color: #8b949e;"><?php echo htmlspecialchars($c['email']); ?></span>
                    </td>
                    
                    <td>
                        <?php 
                        $nivel = isset($c['nivel_plan']) ? $c['nivel_plan'] : 'standard'; 
                        $plan_detallado = isset($c['plan']) ? $c['plan'] : ''; 

                        if ($nivel === 'plus') {
                            echo '<span class="badge" style="background: rgba(255, 215, 0, 0.15); color: #FFD700; border: 1px solid #FFD700; font-size: 0.8rem; padding: 5px 10px;">👑 Plus</span>';
                        } else {
                            echo '<span class="badge" style="background: #30363d; color: #8b949e; border: 1px solid #30363d;">Estándar</span>';
                        }

                        if (strpos($plan_detallado, '(Prueba)') !== false) {
                            echo '<br><span class="badge" style="background: rgba(50, 205, 50, 0.15); color: #32CD32; border: 1px solid #32CD32; font-size: 0.72rem; margin-top: 5px; display: inline-block; padding: 3px 6px;">⏱️ Modo Prueba</span>';
                        }
                        ?>
                    </td>
                    
                    <td>
                        <?php echo date('d/m/y', strtotime($c['fecha_vencimiento'])); ?><br>
                        <span style="font-size: 0.75rem; color: <?php echo $color_dias; ?>; font-weight: bold;"><?php echo $estado_txt; ?></span>
                    </td>
                    
                    <td><span class="badge <?php echo $clase_badge; ?>"><?php echo $texto_badge; ?></span></td>
                    
                    <td style="text-align: right;">
                        <div style="display: flex; gap: 5px; justify-content: flex-end; flex-wrap: nowrap;">
                            
                            <?php if ($es_clon_o_prueba): ?>
                                <button onclick="abrirModalEditar(<?php echo $c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['nombre_local'])); ?>')" style="background: #58a6ff; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.9rem;" title="Editar Nombre y Clave">⚙️</button>
                            <?php endif; ?>

                            <button onclick="abrirModalTiempo(<?php echo $c['id']; ?>, '<?php echo addslashes(htmlspecialchars($c['nombre_local'])); ?>', <?php echo $es_prueba_js; ?>)" style="background: #3fb950; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.9rem;" title="Modificar Tiempo">⏳</button>

                            <form method="POST" style="margin: 0;" onsubmit="return pedirClaveSegura(<?php echo $es_prueba_js; ?>)">
                                <input type="hidden" name="rest_id" value="<?php echo $c['id']; ?>">
                                <input type="hidden" name="nuevo_estado" value="<?php echo ($estado_bd === 'congelada') ? 'activa' : 'congelada'; ?>">
                                <button type="submit" name="cambiar_estado" style="background: <?php echo ($estado_bd === 'congelada') ? '#014421' : '#007BFF'; ?>; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.9rem;" title="<?php echo ($estado_bd === 'congelada') ? 'Activar' : 'Congelar'; ?>">
                                    <?php echo ($estado_bd === 'congelada') ? '▶' : '❄️'; ?>
                                </button>
                            </form>

                            <form method="POST" style="margin: 0;" onsubmit="return pedirClaveSegura(<?php echo $es_prueba_js; ?>)">
                                <input type="hidden" name="rest_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" name="copiar_cuenta" style="background: #9C27B0; color: white; border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.9rem;" title="Clonar Cuenta">📋</button>
                            </form>

                            <?php if ($es_pasado || $es_clon_o_prueba): ?>
                                <button onclick="if(pedirClaveSegura(<?php echo $es_prueba_js; ?>)){ window.location.href='?borrar_id=<?php echo $c['id']; ?>'; }" style="background: transparent; color: #f85149; border: 1px solid #f85149; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.9rem;" title="Borrar Definitivamente">🗑️</button>
                            <?php endif; ?>

                        </div>
                    </td>
                </tr>
                <?php endwhile; endif; ?>
            </table>
        </div> </div> <div style="max-width: 1300px; margin: 20px auto; padding: 0 40px; display: flex; flex-direction: column; gap: 30px; box-sizing: border-box;">
        
        <?php
        // Función para borrar el mensaje si haces clic en el basurero
        if (isset($_GET['borrar_msj_index'])) {
            $id_borrar = (int)$_GET['borrar_msj_index'];
            $conn->query("DELETE FROM mensajes_index WHERE id = $id_borrar");
            header("Location: administrar_bargaiwe.php"); 
            exit();
        }
        ?>
        
        <?php if(isset($_GET['ticket_resuelto'])): ?>
            <div style="background: rgba(50, 205, 50, 0.1); color: #32CD32; border: 1px solid #32CD32; padding: 10px; border-radius: 8px; font-weight: bold; text-align: center;">✅ El ticket ha sido cerrado y borrado correctamente.</div>
        <?php endif; ?>

        <div class="card" style="border-top: 4px solid #FFD700; width: 100%;">
            <h2 style="color: #FFD700; margin-top: 0; border-bottom: 1px solid #30363d; padding-bottom: 10px;">👑 Bandeja Index (Web Principal)</h2>
            <?php if($res_index && $res_index->num_rows > 0): ?>
                <table style="width: 100%; font-size: 0.9rem; text-align: left; border-collapse: collapse;">
                    <tr style="color: #8b949e; border-bottom: 1px solid #30363d;">
                        <th style="padding: 10px;">Fecha</th>
                        <th style="padding: 10px;">Cliente</th>
                        <th style="padding: 10px;">Asunto / Mensaje</th>
                        <th style="text-align: right; padding: 10px;">Acción</th>
                    </tr>
                    <?php 
                    while($m = $res_index->fetch_assoc()): 
                        if($m['leido'] == 0) {
                            $conn->query("UPDATE mensajes_index SET leido = 1 WHERE id = {$m['id']}");
                        }
                    ?>
                        <tr style="border-bottom: 1px solid #30363d;">
                            <td style="padding: 10px; color: #8b949e;"><?php echo date('d/m/Y H:i', strtotime($m['fecha'])); ?></td>
                            <td style="padding: 10px;">
                                <strong style="color: white;"><?php echo htmlspecialchars($m['nombre']); ?></strong><br>
                                <span style="font-size: 0.75rem; color: #8b949e;"><?php echo htmlspecialchars($m['correo']); ?></span>
                            </td>
                            <td style="padding: 10px; max-width: 500px;">
                                <span style="color: #FFD700; font-weight: bold;"><?php echo htmlspecialchars($m['asunto']); ?></span><br>
                                <span style="color: #c9d1d9;"><?php echo nl2br(htmlspecialchars($m['mensaje'])); ?></span>
                            </td>
                            <td style="text-align: right; padding: 10px;">
                                <a href="?borrar_msj_index=<?php echo $m['id']; ?>" onclick="return confirm('¿Eliminar mensaje?')" style="color: #f85149; text-decoration: none; border: 1px solid #f85149; padding: 6px 10px; border-radius: 4px; font-size: 0.8rem; display: inline-block;">🗑️ Borrar</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </table>
            <?php else: ?>
                <p style="color: #8b949e; font-style: italic;">No hay mensajes desde la web.</p>
            <?php endif; ?>
        </div>

        <?php 
        $bandejas = [
            'problema' => ['titulo' => '🚨 Informes de Problemas', 'color' => '#E53935'],
            'chat' => ['titulo' => '💬 Chats de Ayuda', 'color' => '#007BFF'],
            'comentario' => ['titulo' => '💡 Comentarios y Opiniones', 'color' => '#32CD32']
        ];

        foreach ($bandejas as $tipo => $config): 
            $sql_bandeja = "SELECT r.id, r.nombre_local, r.codigo_secreto,
                    (SELECT COUNT(*) FROM mensajes_soporte WHERE restaurant_id = r.id AND remitente = 'cliente' AND leido = 0 AND tipo = '$tipo') as msjs_nuevos
                    FROM restaurantes r
                    WHERE EXISTS (SELECT 1 FROM mensajes_soporte WHERE restaurant_id = r.id AND tipo = '$tipo')";
            $restaurantes_con_chat = $conn->query($sql_bandeja);
        ?>
        
        <div class="card" style="border-top: 4px solid <?php echo $config['color']; ?>; width: 100%;">
            <h2 style="color: <?php echo $config['color']; ?>; margin-top: 0; border-bottom: 1px solid #30363d; padding-bottom: 10px;"><?php echo $config['titulo']; ?></h2>
            
            <?php if($restaurantes_con_chat && $restaurantes_con_chat->num_rows > 0): ?>
                <?php while($rest = $restaurantes_con_chat->fetch_assoc()): ?>
                    <div style="border: 1px solid #30363d; border-radius: 10px; margin-bottom: 15px; overflow: hidden;">
                        <div style="background: #010409; padding: 15px; display: flex; justify-content: space-between; align-items: center;">
                            <strong style="font-size: 1.2rem; color: #c9d1d9;">
                                <?php echo htmlspecialchars($rest['nombre_local']); ?> <span style="color:#FF8C00; font-size: 1rem;">[#<?php echo $rest['codigo_secreto']; ?>]</span>
                                <?php if($rest['msjs_nuevos'] > 0): ?>
                                    <span style="background: #E53935; color: white; padding: 3px 8px; border-radius: 10px; font-size: 0.8rem; margin-left: 10px;">¡<?php echo $rest['msjs_nuevos']; ?> Nuevos!</span>
                                <?php endif; ?>
                            </strong>
                            <div style="display: flex; gap: 10px;">
                                <button onclick="let c = document.getElementById('chat-admin-<?php echo $tipo; ?>-<?php echo $rest['id']; ?>'); c.style.display = (c.style.display === 'none' || c.style.display === '') ? 'block' : 'none';" style="background: <?php echo $config['color']; ?>; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold;">Ver / Ocultar</button>
                                <a href="?borrar_chat_id=<?php echo $rest['id']; ?>&tipo=<?php echo $tipo; ?>" onclick="return confirm('¿Seguro que quieres cerrar este ticket?')" style="background: #E53935; color: white; text-decoration: none; padding: 8px 15px; border-radius: 6px; font-weight: bold; display: flex; align-items: center; font-size: 0.9rem;">🗑️ Resolver</a>
                            </div>
                        </div>

                        <div id="chat-admin-<?php echo $tipo; ?>-<?php echo $rest['id']; ?>" style="display: none; padding: 15px; border-top: 1px solid #30363d;">
                            <div style="max-height: 250px; overflow-y: auto; background: #0d1117; padding: 15px; border: 1px inset #30363d; border-radius: 8px; margin-bottom: 15px; display: flex; flex-direction: column; gap: 10px;">
                                <?php 
                                $conn->query("UPDATE mensajes_soporte SET leido = 1 WHERE restaurant_id = {$rest['id']} AND remitente = 'cliente' AND tipo = '$tipo'");
                                
                                $historial_admin = $conn->query("SELECT * FROM mensajes_soporte WHERE restaurant_id = {$rest['id']} AND tipo = '$tipo' ORDER BY fecha ASC");
                                while($msg = $historial_admin->fetch_assoc()):
                                    $es_admin = ($msg['remitente'] == 'admin');
                                ?>
                                    <div style="max-width: 70%; padding: 10px 15px; border-radius: 10px; font-size: 0.95rem; align-self: <?php echo $es_admin ? 'flex-end' : 'flex-start'; ?>; background: <?php echo $es_admin ? '#014421' : '#30363d'; ?>; color: <?php echo $es_admin ? 'white' : '#c9d1d9'; ?>;">
                                        <?php echo htmlspecialchars($msg['mensaje']); ?>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                            <form method="POST" style="display: flex; gap: 10px;">
                                <input type="hidden" name="restaurant_id_destino" value="<?php echo $rest['id']; ?>">
                                <input type="hidden" name="tipo_destino" value="<?php echo $tipo; ?>">
                                <input type="text" name="respuesta_admin" placeholder="Responder..." required style="flex-grow: 1; margin: 0; padding: 10px; border-radius: 6px; border: 1px solid #30363d; background: #0d1117; color: white;">
                                <button type="submit" name="responder_ticket" style="background: #32CD32; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer;">Enviar</button>
                            </form>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="color: #8b949e; font-style: italic;">Bandeja vacía en este momento.</p>
            <?php endif; ?>
        </div>
       <?php endforeach; ?>

    </div> <div id="modalTiempo" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: none; justify-content: center; align-items: center; z-index: 3000; backdrop-filter: blur(5px);">
        <div style="background: #161b22; padding: 30px; border-radius: 15px; width: 400px; border: 1px solid #30363d; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <h3 style="color: white; margin-top: 0;">⏳ Modificar Tiempo</h3>
            
            <p style="color: #8b949e; font-size: 0.9rem;">Restaurante: <strong id="tiempo_nombre_local" style="color:white;"></strong></p>
            
            <form method="POST" onsubmit="return validarFormTiempo()">
                <input type="hidden" name="rest_id" id="tiempo_rest_id">
                <label style="color: white; font-size: 0.8rem; font-weight: bold;">ACCIÓN:</label>
                <select name="accion_tiempo" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; margin-bottom: 10px;">
                    <option value="sumar">➕ Agregar tiempo</option>
                    <option value="restar">➖ Quitar tiempo</option>
                </select>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="color: white; font-size: 0.8rem; font-weight: bold;">CANTIDAD:</label>
                        <input type="number" name="cantidad_tiempo" value="1" min="1" required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; box-sizing: border-box;">
                    </div>
                    <div style="flex: 1;">
                        <label style="color: white; font-size: 0.8rem; font-weight: bold;">UNIDAD:</label>
                        <select name="unidad_tiempo" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; box-sizing: border-box;">
                            <option value="months">Meses</option>
                            <option value="days">Días</option>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="modificar_tiempo_avanzado" style="background: #3fb950; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; flex: 1; font-weight: bold;">Aplicar</button>
                    <button type="button" onclick="document.getElementById('modalTiempo').style.display='none'" style="background: #30363d; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; flex: 1;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalEditar" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: none; justify-content: center; align-items: center; z-index: 3000; backdrop-filter: blur(5px);">
        <div style="background: #161b22; padding: 30px; border-radius: 15px; width: 400px; border: 1px solid #30363d; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
            <h3 style="color: white; margin-top: 0;">⚙️ Editar Cuenta</h3>
            <form method="POST">
                <input type="hidden" name="rest_id" id="editar_rest_id">
                <label style="color: white; font-size: 0.8rem; font-weight: bold;">Nombre del Local:</label>
                <input type="text" name="nuevo_nombre" id="editar_nombre_local" required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; margin-bottom: 15px; box-sizing: border-box;">
                <label style="color: white; font-size: 0.8rem; font-weight: bold;">Forzar Nueva Contraseña:</label>
                <input type="text" name="nueva_pass" placeholder="Dejar en blanco para no cambiarla" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; margin-bottom: 15px; box-sizing: border-box;">
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="editar_clon" style="background: #58a6ff; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; flex: 1; font-weight: bold;">Guardar Cambios</button>
                    <button type="button" onclick="document.getElementById('modalEditar').style.display='none'" style="background: #30363d; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; flex: 1;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- LÓGICA DE PINES DE SEGURIDAD ---
        let requierePinGlobal = true;

        function pedirClaveSegura(esPrueba) {
            if (esPrueba) return true;
            let clave = prompt("🔐 Restaurante REAL. Ingresa el PIN (1234):");
            if (clave === "1234") return true; 
            if (clave !== null) alert("❌ PIN incorrecto. Acción denegada.");
            return false; 
        }

        function abrirModalTiempo(idRest, nombreRest, esPrueba) {
            requierePinGlobal = !esPrueba; 
            document.getElementById('modalTiempo').style.display = 'flex';
            document.getElementById('tiempo_rest_id').value = idRest;
            document.getElementById('tiempo_nombre_local').innerText = nombreRest;
        }

        function validarFormTiempo() {
            if (!requierePinGlobal) return true; 
            let clave = prompt("🔐 Restaurante REAL. Ingresa el PIN (1234):");
            if (clave === "1234") return true; 
            if (clave !== null) alert("❌ PIN incorrecto.");
            return false;
        }

        function abrirModalEditar(idRest, nombreRest) {
            document.getElementById('modalEditar').style.display = 'flex';
            document.getElementById('editar_rest_id').value = idRest;
            document.getElementById('editar_nombre_local').value = nombreRest;
        }

        // --- LÓGICA DE LA LUZ DE ALERTA INTELIGENTE ---
        function verificarNotificaciones() {
            fetch('check_notificacion.php')
                .then(response => response.json())
                .then(data => {
                    const luz = document.getElementById('indicador-luz');
                    const texto = document.getElementById('texto-alerta');
                    
                    if (data.nuevos_mensajes > 0) {
                        luz.classList.remove('luz-verde');
                        luz.classList.add('luz-roja');
                        texto.innerText = "¡TIENES MENSAJES NUEVOS (" + data.nuevos_mensajes + ")!";
                        texto.style.color = "#E53935";
                    } else {
                        luz.classList.remove('luz-roja');
                        luz.classList.add('luz-verde');
                        texto.innerText = "Sistema Despejado";
                        texto.style.color = "#32CD32";
                    }
                })
                .catch(error => console.error('Error revisando notificaciones:', error));
        }

        // Ejecutar cada 10 segundos
        setInterval(verificarNotificaciones, 10000);
        verificarNotificaciones();
    </script>
    <script src="../sesion_infinita.js"></script>
</body>
</html>