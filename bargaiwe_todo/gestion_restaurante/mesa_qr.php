<?php
// --- 1. MATAR EL CACHÉ DEL CELULAR ---
// Obliga al teléfono a recargar la página fresca al presionar "Atrás"
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php'; 

$clave_secreta = "Bargaiwe_Secreto_SaaS_2026"; 
$pin_seguridad_meseros = "1234"; // 🔒 Cambia esto por el PIN real que usarán los meseros

// --- 2. SEGURIDAD DEL QR: Validamos el token encriptado ---
if (isset($_GET['r']) && isset($_GET['t'])) {
    $r = (int)$_GET['r'];
    $t = $_GET['t'];
    if ($t === hash('sha256', $r . $clave_secreta)) {
        $_SESSION['rest_id_movil'] = $r;
    } else {
        die("<h2 style='text-align:center; color:#E53935; font-family:sans-serif;'>⛔ Token Inválido o Expirado</h2>");
    }
}

if (!isset($_SESSION['rest_id_movil'])) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>⛔ Acceso Denegado</h2><p>Por favor, escanea el QR oficial en la caja.</p></div>");
}

// RESTAURAMOS LA VARIABLE PARA QUE FUNCIONEN LAS MESAS
$mi_restaurant_id = (int)$_SESSION['rest_id_movil'];

// --- 3. BARRERA DEL PIN ---
$clave_secreta = "Bargaiwe_Secreto_SaaS_2026"; 
$pin_seguridad_meseros = "1234"; // 🔒 Cambia esto por el PIN real que usarán los meseros

// --- 2. SEGURIDAD DEL QR: Validamos el token encriptado ---
if (isset($_GET['r']) && isset($_GET['t'])) {
    $r = (int)$_GET['r'];
    $t = $_GET['t'];
    if ($t === hash('sha256', $r . $clave_secreta)) {
        $_SESSION['rest_id_movil'] = $r;
    } else {
        die("<h2 style='text-align:center; color:#E53935; font-family:sans-serif;'>⛔ Token Inválido o Expirado</h2>");
    }
}

if (!isset($_SESSION['rest_id_movil'])) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>⛔ Acceso Denegado</h2><p>Por favor, escanea el QR oficial en la caja.</p></div>");
}

// Nota: Ya NO necesitamos escribir "$mi_restaurant_id = $_SESSION['rest_id_movil'];" 
// porque nuestro nuevo archivo db.php ya hace esa tarea de forma automática y segura.


// --- 3. BARRERA DEL PIN ---
if (isset($_POST['validar_pin'])) {
    if ($_POST['pin_ingresado'] === $pin_seguridad_meseros) {
        $_SESSION['mesero_autorizado'] = true;
        header("Location: mesa_qr.php"); // Recargar limpio para limpiar el formulario
        exit();
    } else {
        $error_pin = "PIN incorrecto. Intenta de nuevo.";
    }
}

// Si el mesero no ha puesto el PIN, mostramos el teclado y bloqueamos el resto
if (!isset($_SESSION['mesero_autorizado'])) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>Bloqueo Mesero</title>
        <style>
            body { background: #1a1a1a; color: white; font-family: sans-serif; display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .pin-box { background: #333; padding: 30px; border-radius: 15px; text-align: center; box-shadow: 0 10px 20px rgba(0,0,0,0.5); width: 80%; max-width: 300px; }
            input[type="number"] { width: 100%; padding: 15px; font-size: 2rem; text-align: center; border-radius: 10px; border: none; margin: 20px 0; box-sizing: border-box; letter-spacing: 5px;}
            button { background: #32CD32; color: black; border: none; padding: 15px; font-size: 1.2rem; font-weight: bold; width: 100%; border-radius: 10px; cursor: pointer; }
        </style>
    </head>
    <body>
        <div class="pin-box">
            <h2 style="margin-top:0;">🔒 Acceso Meseros</h2>
            <p style="color:#aaa; font-size: 0.9rem;">Ingresa el PIN de seguridad de este local.</p>
            <?php if(isset($error_pin)) echo "<div style='color:#ff4d4d; font-weight:bold;'>$error_pin</div>"; ?>
            <form method="POST">
                <input type="number" name="pin_ingresado" placeholder="••••" required autofocus>
                <button type="submit" name="validar_pin">Entrar</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit(); 
}



// --- 4. LÓGICA: OCUPAR MESA ---
if (isset($_GET['ocupar_mesa'])) {
    $id_mesa = (int)$_GET['ocupar_mesa'];
    $conn->query("UPDATE mesas SET estado = 1 WHERE id = $id_mesa AND restaurant_id = $mi_restaurant_id");
    header("Location: mesa_qr.php"); 
    exit();
}

// --- 5. ORDENAMIENTO FORZADO ---
// CAST asegura que 10 no se ponga antes que 2
$res_mesas = $conn->query("SELECT * FROM mesas WHERE restaurant_id = $mi_restaurant_id ORDER BY CAST(numero_mesa AS UNSIGNED) ASC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Meseros - bargaiwe</title>
    <style>
        body { background-color: #1a1a1a; font-family: 'Segoe UI', sans-serif; margin: 0; padding: 0; color: white; }
        .header-app { background: #333; padding: 20px; text-align: center; font-size: 1.4rem; font-weight: 900; box-shadow: 0 4px 10px rgba(0,0,0,0.3); position: sticky; top: 0; z-index: 100; }
        .container { padding: 15px; }
        
        /* --- CORRECCIÓN DE LA CUADRÍCULA CSS --- */
        .grid-movil { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 15px; 
            align-items: stretch; /* Obliga a que todos los botones de una fila midan lo mismo */
        }
        
        .mesa-btn { 
            background: #2d2d2d; 
            border-radius: 15px; 
            padding: 20px 10px; 
            text-align: center; 
            text-decoration: none; 
            color: white; 
            box-shadow: 0 4px 8px rgba(0,0,0,0.2); 
            border: 3px solid transparent; 
            display: flex; 
            flex-direction: column; 
            justify-content: center; /* Centra el contenido verticalmente */
            align-items: center;     /* Centra el contenido horizontalmente */
            gap: 8px;
            min-height: 130px;       /* Le da una altura mínima fija para que no se deformen */
            box-sizing: border-box; 
            transition: transform 0.1s, border-color 0.2s;
        }
        .mesa-btn:active { transform: scale(0.95); } /* Efecto de toque para móviles */

        .libre { border-color: #32CD32; }
        .libre .estado { color: #32CD32; }
        .ocupada { border-color: #FF8C00; background-color: #3d2b1f; }
        .ocupada .estado { color: #FF8C00; }

        .numero { font-size: 2.2rem; font-weight: 900; line-height: 1; }
        .estado { font-size: 0.85rem; font-weight: bold; text-transform: uppercase; }
    </style>
</head>
<body>

    <div class="header-app">📱 bargaiwe Meseros</div>

    <div class="container">
        <div class="grid-movil">
            <?php while($m = $res_mesas->fetch_assoc()): ?>
                <?php 
                    $es_libre = ($m['estado'] == 0);
                    $clase = $es_libre ? 'libre' : 'ocupada';
                    $texto = $es_libre ? 'LIBRE' : 'OCUPADA';
                    $link = $es_libre ? "?ocupar_mesa=".$m['id'] : "pedido_qr.php?mesa_id=".$m['id'];
                ?>
                <a href="<?php echo $link; ?>" class="mesa-btn <?php echo $clase; ?>">
                    <span class="numero"><?php echo $m['numero_mesa']; ?></span>
                    <span class="estado"><?php echo $texto; ?></span>
                </a>
            <?php endwhile; ?>
        </div>
    </div>

</body>
</html>