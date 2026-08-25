<?php
session_start();
include 'r_db.php';

// Verificación de seguridad
if (!isset($_SESSION['restaurant_id'])) {
    die("Error: Sesión no iniciada. Por favor, vuelve a loguearte.");
}

$mi_restaurant_id = (int)$_SESSION['restaurant_id'];
$msg = "";

if (isset($_POST['guardar_impresora'])) {
    $activa = isset($_POST['activa']) ? 1 : 0;
    $texto = $conn->real_escape_string($_POST['encabezado']);
    $ver_precio = isset($_POST['ver_precio']) ? 1 : 0;
    $tamano_letra = (int)$_POST['tamano_letra'];
    $titulo = $conn->real_escape_string($_POST['titulo_ticket']);
    
    // NUEVO: Capturamos el mensaje opcional
    $mensaje_op = $conn->real_escape_string($_POST['mensaje_opcional'] ?? '');

    // 1. REVISAMOS SI YA EXISTE CONFIGURACIÓN PARA ESTE RESTAURANTE
    $check = $conn->query("SELECT restaurant_id FROM config_impresora WHERE restaurant_id = $mi_restaurant_id");

    if ($check->num_rows > 0) {
        // Si existe, ACTUALIZAMOS
        $sql = "UPDATE config_impresora SET 
            impresora_activa = $activa, 
            titulo_ticket = '$titulo',
            encabezado_ticket = '$texto',
            mensaje_opcional = '$mensaje_op',
            imprimir_valor = $ver_precio,
            tamano_letra = $tamano_letra
            WHERE restaurant_id = $mi_restaurant_id";
    } else {
        // Si no existe, CREAMOS EL REGISTRO NUEVO
        $sql = "INSERT INTO config_impresora 
            (restaurant_id, impresora_activa, titulo_ticket, encabezado_ticket, mensaje_opcional, imprimir_valor, tamano_letra) 
            VALUES ($mi_restaurant_id, $activa, '$titulo', '$texto', '$mensaje_op', $ver_precio, $tamano_letra)";
    }

    if ($conn->query($sql)) {
        $msg = "Ajustes guardados correctamente.";
    } else {
        $msg = "Error en la base de datos: " . $conn->error;
    }
}

// Obtener configuración actual para mostrar en el formulario
$res = $conn->query("SELECT * FROM config_impresora WHERE restaurant_id = $mi_restaurant_id");
$conf = ($res) ? $res->fetch_assoc() : null;

if (!$conf) { 
    $conf = [
        'impresora_activa' => 0, 
        'titulo_ticket' => 'MI NEGOCIO',
        'encabezado_ticket' => '¡Gracias por su compra!',
        'mensaje_opcional' => '¡Gracias, vuelva pronto!',
        'imprimir_valor' => 1, 
        'tamano_letra' => 2
    ]; 
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Configurar Impresora - Bargaiwe</title>
    <style>
        body { background: #0d1117; color: white; font-family: 'Segoe UI', sans-serif; padding: 40px; display: flex; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #161b22; padding: 30px; border-radius: 12px; border: 1px solid #30363d; width: 100%; max-width: 550px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); overflow-y: auto;}
        h2 { margin-top: 0; border-bottom: 1px solid #30363d; padding-bottom: 15px; color: #58a6ff; display: flex; justify-content: space-between; align-items: center;}
        
        .instrucciones { background: rgba(88, 166, 255, 0.1); border-left: 4px solid #58a6ff; padding: 15px; margin-bottom: 20px; border-radius: 4px; font-size: 0.9rem; color: #c9d1d9;}
        
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #c9d1d9; }
        input[type="text"], select { width: 100%; padding: 12px; background: #010409; color: white; border: 1px solid #30363d; border-radius: 6px; box-sizing: border-box; }
        .checkbox-label { display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .checkbox-label input { width: 18px; height: 18px; accent-color: #238636;}
        
        .btn-group { display: flex; gap: 15px; margin-top: 20px; }
        .btn { border: none; padding: 12px; border-radius: 6px; font-weight: bold; cursor: pointer; flex: 1; transition: 0.2s; }
        .btn-guardar { background: #238636; color: white; }
        .btn-prueba { background: #30363d; color: white; border: 1px solid #8b949e; }
        .btn-prueba:hover { background: #8b949e; color: #000; }
        .btn-guia { background: transparent; color: #58a6ff; border: 1px solid #58a6ff; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; font-weight: bold; transition: 0.2s;}
        .btn-guia:hover { background: rgba(88, 166, 255, 0.1); }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); display: none; justify-content: center; align-items: center; z-index: 2000; backdrop-filter: blur(5px); }
        .modal-content { background: #161b22; border: 1px solid #30363d; padding: 30px; border-radius: 12px; width: 100%; max-width: 600px; position: relative; max-height: 90vh; overflow-y: auto;}
        .btn-close { position: absolute; top: 20px; right: 20px; background: transparent; border: none; color: #8b949e; font-size: 1.5rem; cursor: pointer; }
        .btn-close:hover { color: #f85149; }
        .modal-content h3 { color: #58a6ff; margin-top: 0; }
        .browser-box { background: #0d1117; border: 1px solid #30363d; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .browser-box h4 { margin: 0 0 10px 0; color: #c9d1d9; }
        code { background: #24292f; padding: 3px 6px; border-radius: 4px; color: #58a6ff; font-family: monospace; }
    </style>
</head>
<body>
    <div class="card">
        <h2>
            🖨️ Ticketera
            <button class="btn-guia" onclick="abrirModal()">❓ Ayuda e Instalación</button>
        </h2>
        
        <div class="instrucciones">
            <strong>¿Cómo vincular tu impresora?</strong><br>
            1. Conecta la impresora por USB a tu computador.<br>
            2. Instala los drivers oficiales (CD o descarga).<br>
            3. Haz clic en "Ayuda e Instalación" para ver el manual de configuración paso a paso.
        </div>

        <?php if(!empty($msg)) echo "<div style='background:#238636; padding:10px; border-radius:6px; margin-bottom:15px;'>✅ $msg</div>"; ?>

        <form method="POST">
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="activa" <?php if($conf['impresora_activa']) echo 'checked'; ?>> 
                    Imprimir boleta automáticamente al pagar
                </label>
            </div>
            
            <div class="form-group">
                <label>Tamaño de letra del ticket:</label>
                <select name="tamano_letra">
                    <option value="1" <?php if($conf['tamano_letra']==1) echo 'selected'; ?>>1 - Muy Pequeña (Ahorra papel, ideal para tickets largos)</option>
                    <option value="2" <?php if($conf['tamano_letra']==2) echo 'selected'; ?>>2 - Normal (Recomendado)</option>
                    <option value="3" <?php if($conf['tamano_letra']==3) echo 'selected'; ?>>3 - Grande</option>
                </select>
            </div>
            
            <div class="form-group">
                 <label>Nombre del Restaurante (Título del Ticket):</label>
                 <input type="text" name="titulo_ticket" value="<?php echo htmlspecialchars($conf['titulo_ticket'] ?? 'MI NEGOCIO'); ?>" placeholder="Ej: La Picada de Lukas">
            </div>

            <div class="form-group">
                 <label>Mensaje de Agradecimiento (Bajo el total):</label>
                 <input type="text" name="mensaje_opcional" value="<?php echo htmlspecialchars($conf['mensaje_opcional'] ?? '¡Gracias, vuelva pronto!'); ?>" placeholder="Ej: ¡Disfrute su pedido!">
            </div>

            <div class="form-group">
                <label>Texto adicional al final del ticket (Opcional):</label>
                <input type="text" name="encabezado" value="<?php echo htmlspecialchars($conf['encabezado_ticket'] ?? '¡Gracias por su compra!'); ?>" placeholder="Ej: ¡Síguenos en nuestras redes!">
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="ver_precio" <?php if($conf['imprimir_valor']) echo 'checked'; ?>> 
                    Mostrar precios y total a pagar en el ticket
                </label>
            </div>
            
            <div class="btn-group">
                <button type="submit" name="guardar_impresora" class="btn btn-guardar">💾 Guardar Ajustes</button>
                <button type="button" class="btn btn-prueba" onclick="imprimirPrueba()">📄 Imprimir Prueba Visual</button>
            </div>
        </form>
        <br><a href="r_pedidos.php" style="color:#8b949e; text-decoration:none;">← Volver al Punto de Venta</a>
    </div>

    <div id="modalInstrucciones" class="modal-overlay">
        <div class="modal-content">
            <button class="btn-close" onclick="cerrarModal()">×</button>
            <h3>❓ Guía de Instalación y Modo Silencioso</h3>
            <p style="color: #8b949e; font-size: 0.95rem;">Sigue estos pasos en orden para que tu ticketera funcione perfectamente en Windows o Mac.</p>

            <div class="browser-box">
                <h4 style="color: #3fb950;">Paso 1: Driver Oficial (Muy Importante)</h4>
                <ol style="margin-bottom: 0; color: #c9d1d9; font-size: 0.9rem;">
                    <li>Conecta la impresora por USB a tu computador.</li>
                    <li>Es <strong>obligatorio</strong> instalar el controlador oficial.</li>
                </ol>
            </div>

            <div class="browser-box">
                <h4 style="color: #58a6ff;">Paso 2: Primera Prueba y Márgenes</h4>
                <ol style="margin-bottom: 0; color: #c9d1d9; font-size: 0.9rem;">
                    <li>Presiona <strong>"Imprimir Prueba Visual"</strong>.</li>
                    <li>Cambia el "Destino" y selecciona tu impresora térmica.</li>
                    <li>Pon los <strong>Márgenes en "Ninguno"</strong> y quita "Encabezados y pies de página".</li>
                </ol>
            </div>
        </div>
    </div>

    <script>
        function imprimirPrueba() { window.open('r_impresora_prueba_visual.php', 'PruebaTicket', 'width=350,height=500'); }
        function abrirModal() { document.getElementById('modalInstrucciones').style.display = 'flex'; }
        function cerrarModal() { document.getElementById('modalInstrucciones').style.display = 'none'; }
    </script>
</body>
</html>