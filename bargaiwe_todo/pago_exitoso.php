<?php
session_start();

if (!isset($_SESSION['restaurant_id'])) {
    header("Location: suscripciones.php");
    exit();
}
$nombre_local = isset($_SESSION['nombre_local']) ? $_SESSION['nombre_local'] : "Restaurante";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>¡Pago Exitoso! - Bargaiwe</title>
    <style>
        body { background: #0d1117; font-family: 'Segoe UI', sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; color: white;}
        .success-box { background: #161b22; padding: 50px; border-radius: 20px; border: 1px solid #32CD32; text-align: center; box-shadow: 0 10px 40px rgba(50, 205, 50, 0.2); max-width: 500px; animation: popIn 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        @keyframes popIn { 0% { transform: scale(0.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
        .icon { font-size: 5rem; margin-bottom: 20px; animation: bounce 2s infinite; }
        @keyframes bounce { 0%, 20%, 50%, 80%, 100% {transform: translateY(0);} 40% {transform: translateY(-20px);} 60% {transform: translateY(-10px);} }
        h1 { color: #32CD32; margin-top: 0; }
        p { color: #c9d1d9; font-size: 1.1rem; margin-bottom: 30px; }
        .btn-entrar { background: #32CD32; color: white; text-decoration: none; padding: 15px 40px; border-radius: 50px; font-weight: 900; font-size: 1.2rem; transition: 0.3s; display: inline-block; box-shadow: 0 5px 15px rgba(50, 205, 50, 0.3); }
        .btn-entrar:hover { background: #28a428; transform: translateY(-3px); box-shadow: 0 8px 20px rgba(50, 205, 50, 0.5); }
    </style>
</head>
<body>
    <div class="success-box">
        <div class="icon">✅</div>
        <h1>¡Cuenta Creada con Éxito!</h1>
        <p>Bienvenido a <strong>Bargaiwe</strong>, <?php echo htmlspecialchars($nombre_local); ?>. Tu sistema de gestión gastronómica está listo para operar.</p>
        <a href="gestion_restaurante/mesas.php" class="btn-entrar">CONFIGURAR MI LOCAL</a>
    </div>

    <script src=""></script>
    <script>
        var duration = 3 * 1000;
        var end = Date.now() + duration;
        (function frame() {
            confetti({ particleCount: 5, angle: 60, spread: 55, origin: { x: 0 }, colors: ['#32CD32', '#ffffff'] });
            confetti({ particleCount: 5, angle: 120, spread: 55, origin: { x: 1 }, colors: ['#32CD32', '#ffffff'] });
            if (Date.now() < end) { requestAnimationFrame(frame); }
        }());
    </script>
</body>
</html>