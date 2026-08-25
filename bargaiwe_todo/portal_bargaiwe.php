<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'gestion_restaurante/db.php'; 

if (isset($_SESSION['restaurant_id'])) {
    header("Location: usuario_hub.php");
    exit();
}

$error = "";

if (isset($_GET['error']) && $_GET['error'] === 'vencido') {
    $error = "Tu suscripción ha sido cortada. Por favor, inicia sesión para renovar.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['btn_login'])) {
    
    $nombre_crudo = isset($_POST['nombre_local']) ? $_POST['nombre_local'] : '';
    $nombre_ingresado = $conn->real_escape_string($nombre_crudo);
    $password_ingresada = isset($_POST['password']) ? $_POST['password'] : '';

    $sql = "SELECT id, nombre_local, password_hash, estado_cuenta, tipo_local FROM restaurantes WHERE nombre_local = '$nombre_ingresado'";
    $res = $conn->query($sql);

    if ($res && $res->num_rows > 0) {
        $restaurante = $res->fetch_assoc();
        
        if (password_verify($password_ingresada, $restaurante['password_hash'])) {
            
            $_SESSION['restaurant_id'] = $restaurante['id'];
            $_SESSION['nombre_local'] = $restaurante['nombre_local'];
            
            header("Location: usuario_hub.php");
            exit();
            
        } else {
            $error = "Contraseña incorrecta.";
        }
    } else {
        $error = "Local no encontrado. Verifica el nombre exacto.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bargaiwe - Iniciar Sesión</title>
    <style>
        body { 
            background-color: #0d1117; 
            font-family: 'Segoe UI', sans-serif; 
            margin: 0; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
        }
        .login-box { 
            background: #161b22; 
            padding: 50px 40px; 
            border-radius: 20px; 
            width: 100%; 
            max-width: 400px; 
            text-align: center; 
            box-shadow: 0 15px 40px rgba(0,0,0,0.5); 
            border: 1px solid #30363d;
        }
        h1 { 
            color: white; 
            font-weight: 900; 
            margin-bottom: 5px; 
            font-size: 2.5rem;
            letter-spacing: -1px;
        }
        h1 span { color: #FF8C00; }
        p { color: #8b949e; margin-bottom: 30px; }
        
        .form-grupo { margin: 20px 0; text-align: left; }
        input { 
            width: 100%; 
            padding: 15px; 
            border-radius: 10px; 
            border: 1px solid #30363d; 
            box-sizing: border-box; 
            background: #010409; 
            color: white;
            font-size: 1rem;
            outline-color: #FF8C00;
        }
        input::placeholder { color: #484f58; }
        
        .btn-login { 
            background: #FF8C00; 
            color: white; 
            border: none; 
            padding: 15px; 
            width: 100%; 
            border-radius: 10px; 
            font-weight: 900; 
            cursor: pointer; 
            font-size: 1.1rem; 
            transition: 0.3s;
            text-transform: uppercase;
        }
        .btn-login:hover { 
            background: #ff9d2e; 
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 140, 0, 0.4);
        }
        
        .error { 
            color: #ff5252; 
            background: rgba(229, 57, 53, 0.1); 
            padding: 15px; 
            border-radius: 8px; 
            margin-bottom: 25px; 
            font-weight: bold; 
            border: 1px solid rgba(229, 57, 53, 0.3);
        }
        
        .volver-link {
            display: inline-block;
            margin-top: 25px;
            color: #58a6ff;
            text-decoration: none;
            font-size: 0.9rem;
            transition: 0.3s;
        }
        .volver-link:hover { text-decoration: underline; color: #79c0ff; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>bargaiwe<span>.</span></h1>
        <p>Portal de Acceso Gastronómico</p>
        
        <?php if($error) echo "<div class='error'>⚠️ $error</div>"; ?>
        
        <form method="POST">
            <div class="form-grupo">
                <input type="text" name="nombre_local" placeholder="Nombre exacto del local" required autofocus>
            </div>
            <div class="form-grupo">
                <input type="password" name="password" placeholder="Tu Contraseña Maestra" required>
            </div>
            <button type="submit" name="btn_login" class="btn-login">Ingresar a mi Sistema</button>
        </form>
        
        <a href="index.html" class="volver-link">← Volver a la página principal</a>
    </div>
</body>
</html>