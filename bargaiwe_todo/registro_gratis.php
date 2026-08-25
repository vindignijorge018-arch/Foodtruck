<?php
session_start();
include '/var/www/html/bargaiwe/gestion_rapida/r_db.php';

$error = "";

$plan_actual = isset($_GET['plan']) ? trim($_GET['plan']) : (isset($_POST['plan_oculto']) ? trim($_POST['plan_oculto']) : 'Estandar');
$dias_actual = isset($_GET['dias']) ? (int)$_GET['dias'] : (isset($_POST['dias_oculto']) ? (int)$_POST['dias_oculto'] : 8);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nombre = $conn->real_escape_string(trim($_POST['nombre_local']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $pass_cruda = $_POST['password'];
    
    $check = $conn->query("SELECT id FROM restaurantes WHERE nombre_local = '$nombre'");
    if ($check->num_rows > 0) {
        $error = "Ese nombre de local ya está registrado. Por favor, elige otro.";
    } else {
        $pass_hash = password_hash($pass_cruda, PASSWORD_DEFAULT);
        $vencimiento = date('Y-m-d', strtotime("+$dias_actual days"));
        $codigo = 'P' . substr(time(), -5); 
        $tipo = 'rapida';
        

        $nivel_plan_db = strtolower($plan_actual); 

        $plan_visual = ucfirst($plan_actual) . " (Prueba)"; 
        
        $sql = "INSERT INTO restaurantes (nombre_local, email, password_hash, fecha_vencimiento, codigo_secreto, tipo_local, nivel_plan, plan, estado_cuenta) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activa')";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ssssssss", $nombre, $email, $pass_hash, $vencimiento, $codigo, $tipo, $nivel_plan_db, $plan_visual);
            
            if ($stmt->execute()) {
                $_SESSION['restaurant_id'] = $conn->insert_id;
                $_SESSION['nombre_local'] = $nombre;
                $_SESSION['nivel_plan'] = $nivel_plan_db; 
                
                header("Location: /bargaiwe/usuario_hub.php");
                exit();
            } else {
                $error = "Error en la base de datos: " . $stmt->error;
            }
        } else {
            $error = "Error del servidor al preparar la consulta.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Prueba Gratis - Bargaiwe</title>
    <style>
        body { background-color: #0d1117; font-family: 'Segoe UI', sans-serif; margin: 0; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-box { background: #161b22; padding: 40px; border-radius: 20px; width: 100%; max-width: 400px; text-align: center; box-shadow: 0 15px 40px rgba(0,0,0,0.5); border: 1px solid #30363d; border-top: 4px solid #32CD32; }
        h1 { color: white; font-weight: 900; margin-bottom: 5px; font-size: 2.2rem; letter-spacing: -1px; }
        h1 span { color: #32CD32; } 
        p { color: #8b949e; margin-bottom: 30px; }
        .form-grupo { margin: 15px 0; text-align: left; }
        input { width: 100%; padding: 15px; border-radius: 10px; border: 1px solid #30363d; box-sizing: border-box; background: #010409; color: white; font-size: 1rem; outline-color: #32CD32; }
        .btn-crear { background: #32CD32; color: white; border: none; padding: 15px; width: 100%; border-radius: 10px; font-weight: 900; cursor: pointer; font-size: 1.1rem; transition: 0.3s; text-transform: uppercase; margin-top: 10px; }
        .btn-crear:hover { background: #28a745; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(50, 205, 50, 0.4); }
        .error { color: #ff5252; background: rgba(229, 57, 53, 0.1); padding: 15px; border-radius: 8px; margin-bottom: 25px; font-weight: bold; border: 1px solid rgba(229, 57, 53, 0.3); }
        .volver-link { display: inline-block; margin-top: 25px; color: #58a6ff; text-decoration: none; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>bargaiwe<span>.</span></h1>
        <p>Crea tu cuenta gratis (8 días)</p>
        
        <?php if($error) echo "<div class='error'>⚠️ $error</div>"; ?>
        
        <form method="POST" action="registro_gratis.php?plan=<?php echo htmlspecialchars($plan_actual); ?>&dias=<?php echo $dias_actual; ?>">
            <input type="hidden" name="plan_oculto" value="<?php echo htmlspecialchars($plan_actual); ?>">
            <input type="hidden" name="dias_oculto" value="<?php echo $dias_actual; ?>">
            
            <div class="form-grupo">
                <input type="text" name="nombre_local" placeholder="Nombre exacto de tu local" required autofocus>
            </div>
            <div class="form-grupo">
                <input type="email" name="email" placeholder="Correo electrónico" required>
            </div>
            <div class="form-grupo">
                <input type="password" name="password" placeholder="Crea una Contraseña Maestra" required>
            </div>
            <button type="submit" class="btn-crear">Comenzar a Operar</button>
        </form>
        
        <a href="suscripciones_rapida.php" class="volver-link">← Cancelar y volver a planes</a>
    </div>
</body>
</html>