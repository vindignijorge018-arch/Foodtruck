<?php
session_start();
include 'db.php'; // Tu archivo de conexión de restaurante

if (!isset($_SESSION['restaurant_id'])) { die("Sesión no iniciada."); }
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];
$mesa_id = isset($_GET['mesa_id']) ? (int)$_GET['mesa_id'] : 0;
$msg = "";

if (isset($_POST['guardar_impresora'])) {
    $activa = isset($_POST['activa']) ? 1 : 0;
    $texto = $conn->real_escape_string($_POST['encabezado']);
    $ver_precio = isset($_POST['ver_precio']) ? 1 : 0;
    $tamano_letra = (int)$_POST['tamano_letra'];
    $titulo = $conn->real_escape_string($_POST['titulo_ticket']);
    $mensaje_op = $conn->real_escape_string($_POST['mensaje_opcional']);

    $check = $conn->query("SELECT restaurant_id FROM config_impresora WHERE restaurant_id = $mi_restaurant_id");

    if ($check->num_rows > 0) {
        $sql = "UPDATE config_impresora SET 
            impresora_activa = $activa, 
            titulo_ticket = '$titulo',
            encabezado_ticket = '$texto',
            mensaje_opcional = '$mensaje_op',
            imprimir_valor = $ver_precio,
            tamano_letra = $tamano_letra
            WHERE restaurant_id = $mi_restaurant_id";
    } else {
        $sql = "INSERT INTO config_impresora 
            (restaurant_id, impresora_activa, titulo_ticket, encabezado_ticket, mensaje_opcional, imprimir_valor, tamano_letra) 
            VALUES ($mi_restaurant_id, $activa, '$titulo', '$texto', '$mensaje_op', $ver_precio, $tamano_letra)";
    }

    if ($conn->query($sql)) { $msg = "Ajustes guardados correctamente."; }
}

$res = $conn->query("SELECT * FROM config_impresora WHERE restaurant_id = $mi_restaurant_id");
$conf = ($res) ? $res->fetch_assoc() : null;

if (!$conf) { 
    $conf = ['impresora_activa' => 0, 'titulo_ticket' => 'MI NEGOCIO', 'encabezado_ticket' => '¡Gracias!', 'mensaje_opcional' => '¡Vuelva pronto!', 'imprimir_valor' => 1, 'tamano_letra' => 2]; 
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Bargaiwe - Configuración de Impresión</title>
    <style>
        body { background: #121212; color: white; font-family: 'Segoe UI', sans-serif; padding: 40px; display: flex; justify-content: center; }
        .card { background: #1e1e1e; padding: 30px; border-radius: 12px; width: 100%; max-width: 500px; border: 1px solid #333; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #aaa; }
        input[type="text"], select { width: 100%; padding: 12px; background: #333; color: white; border: 1px solid #444; border-radius: 8px; }
        .success { background: #1b5e20; padding: 10px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        
        /* Nuevos estilos para los botones paralelos */
        .btn-group { display: flex; gap: 15px; margin-top: 20px; }
        .btn { border: none; padding: 15px; border-radius: 10px; font-weight: bold; cursor: pointer; flex: 1; transition: 0.2s; text-align: center; font-size: 1rem;}
        .btn-guardar { background: #2e7d32; color: white; }
        .btn-guardar:hover { background: #1b5e20; }
        .btn-prueba { background: #444; color: white; border: 1px solid #666; }
        .btn-prueba:hover { background: #555; }
    </style>
</head>
<body>
    <div class="card">
        <h2>🖨️ Ajustes de Ticketera</h2>
        <?php if($msg) echo "<div class='success'>✅ $msg</div>"; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Nombre del Restaurante (Título):</label>
                <input type="text" name="titulo_ticket" value="<?php echo htmlspecialchars($conf['titulo_ticket']); ?>">
            </div>
            <div class="form-group">
                <label>Mensaje de Agradecimiento (Bajo el total):</label>
                <input type="text" name="mensaje_opcional" value="<?php echo htmlspecialchars($conf['mensaje_opcional']); ?>">
            </div>
            <div class="form-group">
                <label>Tamaño de letra:</label>
                <select name="tamano_letra">
                    <option value="1" <?php if($conf['tamano_letra']==1) echo 'selected'; ?>>1 - Pequeña</option>
                    <option value="2" <?php if($conf['tamano_letra']==2) echo 'selected'; ?>>2 - Normal</option>
                    <option value="3" <?php if($conf['tamano_letra']==3) echo 'selected'; ?>>3 - Grande</option>
                </select>
            </div>
            
            <div class="btn-group">
                <button type="submit" name="guardar_impresora" class="btn btn-guardar">💾 Guardar Configuración</button>
                <button type="button" class="btn btn-prueba" onclick="imprimirPrueba()">📄 Imprimir Prueba Visual</button>
            </div>
        </form>
        <br>
        <a href="pago.php?mesa_id=<?php echo $mesa_id; ?>" style="color: #58a6ff; text-decoration: none;">← Volver a la Caja</a>
    </div>

    <script>
        function imprimirPrueba() { 
            window.open('impresora_prueba_visual.php', 'PruebaTicket', 'width=350,height=500'); 
        }
    </script>
</body>
</html>