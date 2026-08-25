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

// --- AUTO-CREAR COLUMNA DE TIPO SI NO EXISTE ---
$check_col = $conn->query("SHOW COLUMNS FROM menu LIKE 'tipo_articulo'");
if ($check_col->num_rows == 0) {
    $conn->query("ALTER TABLE menu ADD COLUMN tipo_articulo VARCHAR(50) DEFAULT 'otros'");
}

// --- CONFIGURACIÓN DE MONEDA ---
$simbolo = "$"; $decimales = 0;

// =========================================================
// 1. Lógica para AGREGAR PLATO
// =========================================================
if (isset($_POST['guardar_plato'])) {
    $nombre = $conn->real_escape_string($_POST['nombre']);
    $precio = (int)$_POST['precio'];
    $descripcion = isset($_POST['descripcion']) ? $conn->real_escape_string($_POST['descripcion']) : '';
    
    $seccion_existente = $_POST['seccion_existente'];
    $nueva_seccion = trim($_POST['nueva_seccion']);
    
    // Determinar si es una sección nueva o existente para asignar el TIPO
    if (!empty($nueva_seccion)) {
        $seccion = $conn->real_escape_string($nueva_seccion);
        $tipo = $conn->real_escape_string($_POST['tipo_articulo']); // Toma el tipo del formulario
    } else {
        $seccion = $conn->real_escape_string($seccion_existente);
        // Si es carpeta existente, hereda el tipo de los platos que ya están ahí
        $res_tipo = $conn->query("SELECT tipo_articulo FROM menu WHERE seccion = '$seccion' AND restaurant_id = $mi_restaurant_id LIMIT 1");
        $tipo = ($res_tipo->num_rows > 0) ? $res_tipo->fetch_assoc()['tipo_articulo'] : 'otros';
    }
    
    $conn->query("INSERT INTO menu (restaurant_id, nombre, precio, descripcion, disponibilidad, seccion, tipo_articulo) VALUES ($mi_restaurant_id, '$nombre', $precio, '$descripcion', 1, '$seccion', '$tipo')");
    header("Location: r_menu.php"); exit();
}

// =========================================================
// 2. Lógica para ACCIÓN MASIVA DE CARPETA (Borrar o Mover)
// =========================================================
if (isset($_POST['accion_carpeta'])) {
    $seccion_vieja = $conn->real_escape_string($_POST['seccion_objetivo']);
    $accion = $_POST['tipo_accion'];

    if ($accion == 'borrar') {
        $conn->query("DELETE FROM menu WHERE seccion = '$seccion_vieja' AND restaurant_id = $mi_restaurant_id");
    } else {
        $seccion_nueva = $conn->real_escape_string($_POST['seccion_destino']);
        // Al mover, actualizamos la sección
        $conn->query("UPDATE menu SET seccion = '$seccion_nueva' WHERE seccion = '$seccion_vieja' AND restaurant_id = $mi_restaurant_id");
    }
    header("Location: r_menu.php"); exit();
}
// =========================================================
// 3. Lógica para EDITAR PRECIO DE UN PLATO
// =========================================================
if (isset($_POST['editar_precio'])) {
    $nombre_plato = $conn->real_escape_string($_POST['nombre_plato']);
    $seccion_plato = $conn->real_escape_string($_POST['seccion_plato']);
    // Limpiamos todo dejando solo números
    $nuevo_precio = (int) preg_replace('/[^0-9]/', '', $_POST['nuevo_precio']);

    if ($nuevo_precio >= 0) {
        $conn->query("UPDATE menu SET precio = $nuevo_precio WHERE nombre = '$nombre_plato' AND seccion = '$seccion_plato' AND restaurant_id = $mi_restaurant_id");
    }
    header("Location: r_menu.php?exito=precio"); 
    exit();
}
// =========================================================
// 4. Lógica para IMPORTAR ARCHIVO TXT DESDE IA
// =========================================================
if (isset($_POST['importar_txt']) && isset($_FILES['archivo_menu'])) {
    $archivoTmp = $_FILES['archivo_menu']['tmp_name'];
    
    if (file_exists($archivoTmp)) {
        // Leemos el archivo línea por línea ignorando líneas vacías
        $lineas = file($archivoTmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lineas as $linea) {
            // Rompemos la línea usando el separador "|"
            $datos = explode('|', $linea);
            
            // Verificamos que tenga exactamente 4 partes (Categoría, Nombre, Descripción, Precio)
            if (count($datos) >= 4) {
                $seccion = $conn->real_escape_string(trim($datos[0]));
                $nombre = $conn->real_escape_string(trim($datos[1]));
                $descripcion = $conn->real_escape_string(trim($datos[2])); 
                // Limpiamos el precio dejando solo los números (ej. remueve "$" o "CLP")
                $precio = (int) preg_replace('/[^0-9]/', '', trim($datos[3]));
                
                // Determinamos el tipo de artículo de forma genérica (o heredado)
                $res_tipo = $conn->query("SELECT tipo_articulo FROM menu WHERE seccion = '$seccion' AND restaurant_id = $mi_restaurant_id LIMIT 1");
                $tipo = ($res_tipo->num_rows > 0) ? $res_tipo->fetch_assoc()['tipo_articulo'] : 'plato';

                // INSERTAMOS EN LA BASE DE DATOS (Bloqueado al local actual)
                $sql = "INSERT INTO menu (restaurant_id, seccion, nombre, descripcion, precio, disponibilidad, tipo_articulo) 
                        VALUES ($mi_restaurant_id, '$seccion', '$nombre', '$descripcion', $precio, 1, '$tipo')";
                $conn->query($sql);
            }
        }
        header("Location: r_menu.php?importado=1");
        exit();
    }
}


// CONSULTAS PARA MOSTRAR DATOS EN PANTALLA
$res_secciones = $conn->query("SELECT DISTINCT seccion FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion ASC");
$lista_secciones = [];
while($s = $res_secciones->fetch_assoc()) { $lista_secciones[] = $s['seccion']; }

$platos_query = $conn->query("SELECT * FROM menu WHERE restaurant_id = $mi_restaurant_id ORDER BY seccion ASC, nombre ASC");
$menu_agrupado = [];
$tipos_seccion = []; // Array para guardar qué tipo es cada carpeta

while($row = $platos_query->fetch_assoc()){ 
    $menu_agrupado[$row['seccion']][] = $row; 
    // Guardamos el tipo de la sección para mostrarlo en la UI
    if(!isset($tipos_seccion[$row['seccion']])) {
        $tipos_seccion[$row['seccion']] = $row['tipo_articulo'];
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bargaiwe Fast - Gestión de Menú</title>
    <style>
        /* Variables del Tema (Fast Food Oscuro por Defecto) */
        :root {
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: #FF8C00; --danger: #E53935; --success: #32CD32;
        }
        /* Tema Claro */
        body.tema-claro {
            --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000;
        }

        body { background: var(--bg-body); color: var(--text); font-family: 'Segoe UI', sans-serif; margin: 0; transition: 0.3s; }
        
        .nav-hub { background: var(--bg-panel); border-bottom: 1px solid var(--border); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: var(--text-title); }
        .nav-hub a { color: white; text-decoration: none; font-weight: bold; background: var(--bg-panel); padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); transition: 0.3s; }
        .nav-hub a:hover { border-color: var(--accent); color: var(--accent); }

        .container { padding: 40px; display: grid; grid-template-columns: 350px 1fr; gap: 40px; align-items: start; max-width: 1200px; margin: 0 auto;}
        
        .card { background: var(--bg-panel); padding: 30px; border-radius: 15px; border: 1px solid var(--border); height: auto; }
        h3 { color: var(--accent); margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 10px; font-size: 1.4rem; }

        .folder-wrapper { margin-bottom: 15px; border-radius: 12px; border: 1px solid var(--border); overflow: hidden; background: var(--bg-panel); }
        .folder-header { background: #010409; color: var(--text-title); padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: 0.3s; }
        .folder-header:hover { background: #161b22; }
        .folder-content { display: none; padding: 10px; background: var(--bg-panel); }

        input, select, textarea { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid var(--border); border-radius: 8px; box-sizing: border-box; font-size: 0.9rem; background: var(--bg-body); color: var(--text); }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); }
        
        .btn-add-plato { background: var(--success); color: white; border: none; padding: 15px; border-radius: 8px; font-weight: bold; width: 100%; cursor: pointer; font-size: 1rem; transition: 0.2s; text-transform: uppercase; letter-spacing: 1px;}
        .btn-add-plato:hover { background: #28a428; transform: translateY(-2px); }
        .btn-danger { background: var(--danger); color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight: bold; }
        .btn-danger:hover { background: #c62828; }
        
        .alert-box { display: none; background: rgba(229, 57, 53, 0.1); border: 2px solid var(--danger); padding: 20px; border-radius: 12px; margin-bottom: 30px; }
        
        .item-list { display: flex; flex-direction: column; padding: 12px; border-bottom: 1px solid var(--border); }
        .item-list:last-child { border-bottom: none; }
        .item-list-header { display: flex; justify-content: space-between; align-items: center; color: var(--text-title); font-weight: bold; }
        .item-desc { font-size: 0.8rem; color: #8b949e; font-style: italic; margin-top: 4px; }
        
        .badge-tipo { background: var(--accent); color: white; font-size: 0.7rem; padding: 3px 8px; border-radius: 10px; margin-left: 10px; text-transform: uppercase; letter-spacing: 0.5px;}
        .hint-text { font-size: 0.8rem; color: #8b949e; margin-top: -5px; margin-bottom: 10px; display: block;}
    </style>
    <script>
        // LEER EL TEMA GUARDADO EN r_pedidos.php
        if (localStorage.getItem('tema-rapida') === 'claro') {
            document.body.classList.add('tema-claro');
        }

        function toggleFolder(id) { 
            var el = document.getElementById(id);
            el.style.display = (el.style.display === 'block') ? 'none' : 'block'; 
        }
        function openAlert(seccion) {
            document.getElementById('alert-box').style.display = 'block';
            document.getElementById('nombre_sec').innerText = seccion;
            document.getElementById('sec_obj').value = seccion;
            window.scrollTo({top: 0, behavior: 'smooth'});
        }
    </script>
</head>
<body>

    <div class="nav-hub">
        <span style="font-size: 1.8rem; font-weight: 800; color: var(--accent);">🍔 Fast Menú</span>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="r_pedidos.php">← Volver al Cajero</a>
        </div>
    </div>

    <?php if (isset($_SESSION['nivel_plan']) && $_SESSION['nivel_plan'] === 'plus'): ?>
    <div style="padding: 20px 20px 0 20px; display: flex; justify-content: flex-end; max-width: 1200px; margin: 0 auto;">
        <a href="r_menu_plus.php" style="
            background: linear-gradient(45deg, #9C27B0, #3f51b5); 
            color: white; 
            text-decoration: none; 
            padding: 12px 20px; 
            border-radius: 8px; 
            border: 1px solid #7B1FA2; 
            box-shadow: 0 4px 15px rgba(156, 39, 176, 0.4); 
            font-weight: bold; 
            display: flex; 
            align-items: center; 
            gap: 10px;
            transition: 0.3s;
        " onmouseover="this.style.opacity='0.9'; this.style.transform='translateY(-2px)';" 
           onmouseout="this.style.opacity='1'; this.style.transform='translateY(0)';"
        >
            ✨ Importar Menú con IA (Beta)
        </a>
    </div>
    <?php endif; ?>

    <div class="container">
        
        <?php if (isset($_GET['exito']) && $_GET['exito'] == 'ia'): ?>
            <div style="grid-column: span 2; background: rgba(50, 205, 50, 0.1); border: 1px solid var(--success); color: var(--success); padding: 15px; border-radius: 8px; margin-bottom: 10px; font-weight: bold; text-align: center; box-shadow: 0 4px 10px rgba(50, 205, 50, 0.1);">
                ✅ ¡Magia pura! La IA ha importado y creado exitosamente <strong><?php echo (int)$_GET['creados']; ?></strong> platos nuevos en tu menú.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['importado']) && $_GET['importado'] == '1'): ?>
            <div style="grid-column: span 2; background: rgba(50, 205, 50, 0.1); border: 1px solid var(--success); color: var(--success); padding: 15px; border-radius: 8px; margin-bottom: 10px; font-weight: bold; text-align: center;">
                ✅ Archivo TXT importado con éxito.
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h3>Añadir Producto</h3>
            <form action="r_menu.php" method="POST">
                
                <label style="font-weight: bold; font-size: 0.8rem; color: var(--text-title);">ELEGIR CARPETA EXISTENTE:</label>
                <select name="seccion_existente">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach($lista_secciones as $sec): ?>
                        <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint-text">El plato heredará automáticamente el tipo de la carpeta.</span>
                
                <hr style="border: 1px solid var(--border); margin: 15px 0;">
                
                <label style="font-weight: bold; font-size: 0.8rem; color: var(--text-title);">O CREAR CARPETA NUEVA:</label>
                <input type="text" name="nueva_seccion" placeholder="Escribe el nombre de la nueva carpeta...">
                
                <select name="tipo_articulo">
                    <option value="plato">🥘 Es un Plato Principal</option>
                    <option value="entrada">🥗 Es una Entrada</option>
                    <option value="postre">🍰 Es un Postre</option>
                    <option value="bebestible">🥤 Es un Bebestible</option>
                    <option value="otros">🛒 Otros</option>
                </select>
                <span class="hint-text">Elige el tipo solo si estás creando una carpeta nueva.</span>

                <hr style="border: 1px solid var(--border); margin: 20px 0;">
                
                <input type="text" name="nombre" placeholder="Nombre del producto" required>
                <input type="number" name="precio" placeholder="Precio (Ej: 5000)" required>
                <textarea name="descripcion" placeholder="Descripción breve (opcional)" rows="2"></textarea>
                
                <button type="submit" name="guardar_plato" class="btn-add-plato">Guardar Producto</button>
            </form>
        </div>

        <div class="card">
            
            <div id="alert-box" class="alert-box">
                <h3 style="color: var(--danger); border-bottom: none; margin-bottom: 5px;">Gestionar Carpeta: <span id="nombre_sec"></span></h3>
                <form action="r_menu.php" method="POST">
                    <input type="hidden" name="seccion_objetivo" id="sec_obj">
                    
                    <p style="color: var(--text-title);"><input type="radio" name="tipo_accion" value="borrar" checked> <strong>Borrar</strong> permanentemente la carpeta y sus productos.</p>
                    
                    <p style="color: var(--text-title);"><input type="radio" name="tipo_accion" value="mover"> <strong>Mover</strong> productos a otra carpeta:</p>
                    <select name="seccion_destino" style="margin-bottom: 15px;">
                        <?php foreach($lista_secciones as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div style="display:flex; gap:10px;">
                        <button type="submit" name="accion_carpeta" class="btn-add-plato" style="background:var(--danger);">Confirmar Acción</button>
                        <button type="button" onclick="document.getElementById('alert-box').style.display='none'" class="btn-add-plato" style="background:var(--border); color: var(--text-title);">Cancelar</button>
                    </div>
                </form>
            </div>

            <h3>Mi Menú Organizado</h3>
            
            <?php if(empty($menu_agrupado)): ?>
                <p style="text-align: center; color: #8b949e; padding: 40px;">Aún no tienes productos en tu menú. ¡Agrega el primero a la izquierda!</p>
            <?php endif; ?>

            <?php foreach($menu_agrupado as $seccion => $items): ?>
                <div class="folder-wrapper">
                    <div class="folder-header">
                        <div onclick="toggleFolder('f-<?php echo md5($seccion); ?>')" style="flex-grow: 1;">
                            <strong>📁 <?php echo strtoupper(htmlspecialchars($seccion)); ?></strong> 
                            <span class="badge-tipo"><?php echo htmlspecialchars($tipos_seccion[$seccion]); ?></span>
                            <span style="font-size: 0.8rem; margin-left: 10px; color: #8b949e;"><?php echo count($items); ?> items</span>
                        </div>
                        <button class="btn-danger" onclick="openAlert('<?php echo htmlspecialchars($seccion); ?>')">🗑️ Borrar</button>
                    </div>
                    
                    <div id="f-<?php echo md5($seccion); ?>" class="folder-content">
                        <?php foreach($items as $it): ?>
                            <div class="item-list">
                                <div class="item-list-header">
                                    <span><?php echo htmlspecialchars($it['nombre']); ?></span>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <strong style="color: var(--success);"><?php echo $simbolo . number_format($it['precio'], 0, ',', '.'); ?></strong>
                                        <button onclick="editarPrecio('<?php echo addslashes($it['nombre']); ?>', '<?php echo addslashes($it['seccion']); ?>', <?php echo $it['precio']; ?>)" style="background: transparent; border: none; cursor: pointer; font-size: 1rem; transition: 0.2s;" title="Editar Precio" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'">✏️</button>
                                    </div>
                                </div>
                                <?php if(!empty($it['descripcion'])): ?>
                                    <div class="item-desc"><?php echo htmlspecialchars($it['descripcion']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
    <form id="form-editar-precio" method="POST" style="display: none;">
        <input type="hidden" name="nombre_plato" id="edit-nombre">
        <input type="hidden" name="seccion_plato" id="edit-seccion">
        <input type="hidden" name="nuevo_precio" id="edit-precio">
        <input type="hidden" name="editar_precio" value="1">
    </form>

    <script>
        function editarPrecio(nombre, seccion, precioActual) {
            // Lanza una ventana emergente preguntando el nuevo precio
            let nuevoPrecio = prompt(`Editar precio para: ${nombre}\nPrecio actual: $${precioActual}\n\nIngresa el nuevo precio (solo números):`, precioActual);
            
            // Si el usuario no canceló y escribió algo
            if (nuevoPrecio !== null && nuevoPrecio.trim() !== "") {
                // Asegurarnos de que solo pasen números
                let precioLimpio = nuevoPrecio.replace(/[^0-9]/g, '');
                
                if (precioLimpio !== "") {
                    // Metemos los datos en el formulario invisible y disparamos
                    document.getElementById('edit-nombre').value = nombre;
                    document.getElementById('edit-seccion').value = seccion;
                    document.getElementById('edit-precio').value = precioLimpio;
                    document.getElementById('form-editar-precio').submit();
                }
            }
        }
    </script>

</body>
</html>