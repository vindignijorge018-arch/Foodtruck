<?php
include 'db.php'; 


// --- AUTO-CREAR COLUMNA DE TIPO SI NO EXISTE ---
$check_col = $conn->query("SHOW COLUMNS FROM menu LIKE 'tipo_articulo'");
if ($check_col->num_rows == 0) {
    $conn->query("ALTER TABLE menu ADD COLUMN tipo_articulo VARCHAR(50) DEFAULT 'otros'");
}

// --- CONFIGURACIÓN DE MONEDA ---
$simbolo = "$"; $decimales = 0;

// 1. Lógica para AGREGAR PLATO
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
    header("Location: menu.php"); exit();
}

// 2. Lógica para ACCIÓN MASIVA DE CARPETA (Borrar o Mover)
if (isset($_POST['accion_carpeta'])) {
    $seccion_vieja = $conn->real_escape_string($_POST['seccion_objetivo']);
    $accion = $_POST['tipo_accion'];

    if ($accion == 'borrar') {
        $conn->query("DELETE FROM menu WHERE seccion = '$seccion_vieja' AND restaurant_id = $mi_restaurant_id");
    } else {
        $seccion_nueva = $conn->real_escape_string($_POST['seccion_destino']);
        // Al mover, actualizamos la sección, el tipo se mantiene igual o podríamos forzar a que herede el de la nueva sección (por simplicidad lo dejamos heredar luego)
        $conn->query("UPDATE menu SET seccion = '$seccion_nueva' WHERE seccion = '$seccion_vieja' AND restaurant_id = $mi_restaurant_id");
    }
    header("Location: menu.php"); exit();
}

// 3. Lógica para ACTUALIZAR PLATO
if (isset($_POST['actualizar_plato'])) {
    $id = (int)$_POST['id'];
    $nombre = $conn->real_escape_string($_POST['nombre']);
    $precio = (int)$_POST['precio'];
    $seccion = $conn->real_escape_string($_POST['seccion_act']);
    $descripcion = isset($_POST['descripcion']) ? $conn->real_escape_string($_POST['descripcion']) : '';
    
    $conn->query("UPDATE menu SET nombre='$nombre', precio=$precio, descripcion='$descripcion', seccion='$seccion' WHERE id=$id");
    header("Location: menu.php"); exit();
}

// CONSULTAS
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
    <title>bargaiwe - Gestión de Menú</title>
    <style>
        
        body { background-color: #FDFCF0; font-family: 'Segoe UI', sans-serif; margin: 0; color: #333; }
        .nav-hub { background: #014421; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .nav-hub a { color: white; text-decoration: none; font-weight: bold; background: #FF8C00; padding: 10px 20px; border-radius: 10px; }

        .container { padding: 40px; display: grid; grid-template-columns: 350px 1fr; gap: 40px; align-items: start; }
        
        .card { background: white; padding: 30px; border-radius: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); height: auto; }
        h3 { color: #014421; margin-top: 0; border-bottom: 2px solid #FDFCF0; padding-bottom: 10px; font-size: 1.4rem; }

        .folder-wrapper { margin-bottom: 15px; border-radius: 15px; border: 1px solid #eee; overflow: hidden; }
        .folder-header { background: #014421; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; transition: 0.3s; }
        .folder-header:hover { background: #01331a; }
        .folder-content { display: none; padding: 10px; background: #fff; }

        input, select, textarea { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 12px; box-sizing: border-box; font-size: 0.9rem; }
        .btn-add-plato { background: #32CD32; color: white; border: none; padding: 15px; border-radius: 12px; font-weight: bold; width: 100%; cursor: pointer; font-size: 1rem; transition: 0.2s; }
        .btn-add-plato:hover { background: #28a428; transform: translateY(-2px); }
        .btn-danger { background: #ff4d4d; color: white; border: none; padding: 6px 12px; border-radius: 8px; cursor: pointer; font-size: 0.8rem; font-weight: bold; }
        
        .alert-box { display: none; background: #fff5f5; border: 2px solid #ff4d4d; padding: 20px; border-radius: 20px; margin-bottom: 30px; box-shadow: 0 5px 15px rgba(255,77,77,0.1); }
        .item-list { display: flex; flex-direction: column; padding: 12px; border-bottom: 1px solid #f9f9f9; }
        .item-list:last-child { border-bottom: none; }
        .item-list-header { display: flex; justify-content: space-between; align-items: center; }
        .item-desc { font-size: 0.8rem; color: #777; font-style: italic; margin-top: 4px; }
        
        .badge-tipo { background: #FF8C00; color: white; font-size: 0.7rem; padding: 3px 8px; border-radius: 10px; margin-left: 10px; text-transform: uppercase; letter-spacing: 0.5px;}
        .hint-text { font-size: 0.8rem; color: #666; margin-top: -5px; margin-bottom: 10px; display: block;}
        /* --- MODO OSCURO AUTOMÁTICO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; }
        body.modo-oscuro .card { background: #1e1e1e !important; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        body.modo-oscuro h3 { color: #ccc !important; border-bottom-color: #333; }
        body.modo-oscuro input, body.modo-oscuro select, body.modo-oscuro textarea { background: #333; color: white; border-color: #555; }
        body.modo-oscuro .folder-wrapper { border-color: #333; }
        body.modo-oscuro .folder-header { background: #111; color: #aaa; border: 1px solid #333; }
        body.modo-oscuro .folder-content { background: #222; }
        body.modo-oscuro .item-list { border-bottom-color: #444; }
        body.modo-oscuro .alert-box { background: #3a1c1c; border-color: #ff4d4d; color: white; }
        body.modo-oscuro #edit-box-plato { background: #332615 !important; border-color: #FF8C00 !important; }
        body.modo-oscuro .hint-text, body.modo-oscuro .item-desc { color: #aaa; }
        body.modo-oscuro .nav-hub a[href="../usuario_hub.php"] { background: #111 !important; }
        /* --- MODO OSCURO AUTOMÁTICO --- */
        body.modo-oscuro { background-color: #121212 !important; color: #ffffff !important; }
        body.modo-oscuro .nav-hub { background: #000000; border-bottom: 1px solid #333; }
        body.modo-oscuro .card { background: #1e1e1e !important; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        body.modo-oscuro h3 { color: #ccc !important; border-bottom-color: #333; }
        body.modo-oscuro input, body.modo-oscuro select, body.modo-oscuro textarea { background: #333; color: white; border-color: #555; }
        body.modo-oscuro .folder-wrapper { border-color: #333; }
        body.modo-oscuro .folder-header { background: #111; color: #aaa; border: 1px solid #333; }
        body.modo-oscuro .folder-content { background: #222; }
        body.modo-oscuro .item-list { border-bottom-color: #444; }
        body.modo-oscuro .alert-box { background: #3a1c1c; border-color: #ff4d4d; color: white; }
        body.modo-oscuro #edit-box-plato { background: #332615 !important; border-color: #FF8C00 !important; }
        body.modo-oscuro .hint-text, body.modo-oscuro .item-desc { color: #aaa; }
        body.modo-oscuro .nav-hub a[href="../usuario_hub.php"] { background: #111 !important; border-color: #333 !important; }
    </style>
    <script>
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
        function abrirEditarPlato(plato) {
    // Cerramos el otro alert por si estuviera abierto
            document.getElementById('alert-box').style.display = 'none';
    
    // Mostramos el panel de edición
            document.getElementById('edit-box-plato').style.display = 'block';
    
    // Rellenamos los campos con la info del plato
            document.getElementById('edit_id').value = plato.id;
            document.getElementById('edit_nombre').value = plato.nombre;
            document.getElementById('edit_precio').value = plato.precio;
            document.getElementById('edit_descripcion').value = plato.descripcion;
            document.getElementById('edit_seccion').value = plato.seccion;
    
    // Scroll suave hacia arriba para ver el formulario
            window.scrollTo({top: 0, behavior: 'smooth'});
        }
    </script>
</head>
<body>
    <div id="loading-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 9999; justify-content: center; align-items: center; flex-direction: column; color: white; backdrop-filter: blur(5px);">
        <div class="spinner" style="border: 5px solid rgba(255,255,255,0.2); border-top: 5px solid #9C27B0; border-radius: 50%; width: 60px; height: 60px; animation: spin 1s linear infinite; margin-bottom: 20px;"></div>
        <h2 style="margin: 0; color: white;">🤖 Edubo está procesando...</h2>
    </div>

    <div class="nav-hub">
        <span style="font-size: 1.8rem; font-weight: 800;">bargaiwe</span>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="../usuario_hub.php" style="background: #30363d; border: 1px solid #555;"> Membresía</a>
            <a href="mesas.php">← Volver a Mesas</a>
        </div>
    </div>

    <div class="container">
        
        <div class="card">
            <h3>Añadir Producto</h3>
            <form action="menu.php" method="POST">
                <label style="font-weight: bold; font-size: 0.8rem;">ELEGIR CARPETA EXISTENTE:</label>
                <select name="seccion_existente">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach($lista_secciones as $sec): ?>
                        <option value="<?php echo htmlspecialchars($sec); ?>"><?php echo htmlspecialchars($sec); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <hr style="border: 1px solid #FDFCF0; margin: 15px 0;">
                <label style="font-weight: bold; font-size: 0.8rem;">O CREAR CARPETA NUEVA:</label>
                <input type="text" name="nueva_seccion" placeholder="Nombre de carpeta...">
                
                <select name="tipo_articulo" style="background-color: #f9f9f9;">
                    <option value="plato">🥘 Plato Principal</option>
                    <option value="entrada">🥗 Entrada</option>
                    <option value="bebestible">🥤 Bebestible</option>
                    <option value="otros">🛒 Otros</option>
                </select>

                <hr style="border: 1px solid #FDFCF0; margin: 20px 0;">
                <input type="text" name="nombre" placeholder="Nombre del producto" required>
                <input type="number" name="precio" placeholder="Precio (Ej: 5000)" required>
                <textarea name="descripcion" placeholder="Descripción (opcional)" rows="2"></textarea>
                
                <button type="submit" name="guardar_plato" class="btn-add-plato">Guardar en Menú</button>
            </form>

            <?php if (isset($_SESSION['nivel_plan']) && $_SESSION['nivel_plan'] === 'plus'): ?>
            <div style="margin-top: 20px;"> 
                <a href="menu_ia.php" style="background: linear-gradient(45deg, #9C27B0, #3f51b5); color: white; text-decoration: none; padding: 12px 20px; border-radius: 12px; font-weight: bold; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; box-sizing: border-box;">
                    ✨ Importar Menú con IA
                </a>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            
            <div id="edit-box-plato" class="alert-box" style="border-color: #FF8C00; background: #fffdf5;">
                <h3 style="color: #FF8C00; border-color: #ffe0b3;">Editar Producto</h3>
                <form action="menu.php" method="POST">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="text" name="nombre" id="edit_nombre" required placeholder="Nombre">
                    <input type="number" name="precio" id="edit_precio" required placeholder="Precio">
                    <textarea name="descripcion" id="edit_descripcion" rows="2" placeholder="Descripción"></textarea>
                    <select name="seccion_act" id="edit_seccion">
                        <?php foreach($lista_secciones as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex; gap:10px; margin-top: 10px;">
                        <button type="submit" name="actualizar_plato" class="btn-add-plato" style="background:#FF8C00;">Actualizar</button>
                        <button type="button" onclick="document.getElementById('edit-box-plato').style.display='none'" class="btn-add-plato" style="background:#aaa;">Cancelar</button>
                    </div>
                </form>
            </div>

            <div id="alert-box" class="alert-box">
                <h3 style="color: #ff4d4d;">Gestionar Carpeta: <span id="nombre_sec"></span></h3>
                <form action="menu.php" method="POST">
                    <input type="hidden" name="seccion_objetivo" id="sec_obj">
                    <p><input type="radio" name="tipo_accion" value="borrar" checked> Borrar carpeta y platos.</p>
                    <p><input type="radio" name="tipo_accion" value="mover"> Mover a:</p>
                    <select name="seccion_destino">
                        <?php foreach($lista_secciones as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex; gap:10px; margin-top:10px;">
                        <button type="submit" name="accion_carpeta" class="btn-add-plato" style="background:#ff4d4d;">Confirmar</button>
                        <button type="button" onclick="document.getElementById('alert-box').style.display='none'" class="btn-add-plato" style="background:#aaa;">Cerrar</button>
                    </div>
                </form>
            </div>

            <h3>Mi Menú Organizado</h3>
            
            <?php if(empty($menu_agrupado)): ?>
                <p style="text-align: center; color: #999; padding: 40px;">Aún no tienes platos.</p>
            <?php endif; ?>

            <?php foreach($menu_agrupado as $seccion => $items): ?>
                <div class="folder-wrapper">
                    <div class="folder-header">
                        <div onclick="toggleFolder('f-<?php echo md5($seccion); ?>')" style="flex-grow: 1;">
                            <strong>📁 <?php echo strtoupper(htmlspecialchars($seccion)); ?></strong> 
                            <span class="badge-tipo"><?php echo htmlspecialchars($tipos_seccion[$seccion]); ?></span>
                            <span style="font-size: 0.8rem; margin-left: 10px; opacity: 0.8;"><?php echo count($items); ?> items</span>
                        </div>
                        <button class="btn-danger" onclick="openAlert('<?php echo htmlspecialchars($seccion); ?>')">🗑️ Borrar</button>
                    </div>
                    
                    <div id="f-<?php echo md5($seccion); ?>" class="folder-content">
                        <?php foreach($items as $it): ?>
                            <div class="item-list">
                                <div class="item-list-header">
                                    <span><?php echo htmlspecialchars($it['nombre']); ?></span>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <strong style="color: #014421;"><?php echo $simbolo . number_format($it['precio'], 0, ',', '.'); ?></strong>
                                        <button type="button" onclick='abrirEditarPlato(<?php echo json_encode($it, JSON_HEX_APOS); ?>)' style="background: none; border: none; cursor: pointer;">✏️</button>
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

    <script>
        function toggleFolder(id) { 
            var el = document.getElementById(id);
            el.style.display = (el.style.display === 'block') ? 'none' : 'block'; 
        }
        function openAlert(seccion) {
            document.getElementById('edit-box-plato').style.display = 'none';
            document.getElementById('alert-box').style.display = 'block';
            document.getElementById('nombre_sec').innerText = seccion;
            document.getElementById('sec_obj').value = seccion;
            window.scrollTo({top: 0, behavior: 'smooth'});
        }
        function abrirEditarPlato(plato) {
            document.getElementById('alert-box').style.display = 'none';
            document.getElementById('edit-box-plato').style.display = 'block';
            document.getElementById('edit_id').value = plato.id;
            document.getElementById('edit_nombre').value = plato.nombre;
            document.getElementById('edit_precio').value = plato.precio;
            document.getElementById('edit_descripcion').value = plato.descripcion;
            document.getElementById('edit_seccion').value = plato.seccion;
            window.scrollTo({top: 0, behavior: 'smooth'});
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