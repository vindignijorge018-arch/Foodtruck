<?php
session_start();

$plan = isset($_GET['plan']) ? $_GET['plan'] : 'Desconocido';
$meses = isset($_GET['meses']) ? (int)$_GET['meses'] : 0;
$precio = isset($_GET['precio']) ? (int)$_GET['precio'] : 0;
$original = isset($_GET['original']) ? (int)$_GET['original'] : $precio;
$beneficio = isset($_GET['beneficio']) ? htmlspecialchars($_GET['beneficio']) : '';
$tiempo_total = isset($_GET['tiempo']) ? htmlspecialchars($_GET['tiempo']) : $meses . ' Meses';

if ($meses == 0 || $precio == 0) {
    header("Location: suscripciones.php"); 
    exit();
}

// Lógica de temas visuales
$es_premium = ($plan === 'Premium');
$clase_tema = $es_premium ? 'tema-premium' : 'tema-estandar';

// --- LÓGICA DE USUARIO EXISTENTE (EL CAMBIA CARAS) ---
$es_cliente = isset($_SESSION['restaurant_id']);
$nombre_local = "";
$texto_futuro = "";

if ($es_cliente) {
    include 'gestion_restaurante/db.php';
    $id_rest = (int)$_SESSION['restaurant_id'];
    
    $res = $conn->query("SELECT nombre_local, fecha_vencimiento FROM restaurantes WHERE id = $id_rest");
    if ($row = $res->fetch_assoc()) {
        $nombre_local = $row['nombre_local'];
        
        // días que le quedan actualmente
        $fecha_venc = new DateTime($row['fecha_vencimiento']);
        $hoy = new DateTime();
        $dias_restantes = 0;
        if ($fecha_venc > $hoy) {
            $dias_restantes = $hoy->diff($fecha_venc)->days;
        }
        
        $dias_comprados = $meses * 30; 
        $total_dias_futuro = $dias_restantes + $dias_comprados;
        
        // Transformamos esos días a Meses y Días reales
        $m = floor($total_dias_futuro / 30);
        $d = $total_dias_futuro % 30;
        
        if ($m > 0 && $d > 0) {
            $texto_futuro = "Tus días totales serán $m meses y $d días.";
        } elseif ($m > 0 && $d == 0) {
            $texto_futuro = "Tus días totales serán $m meses.";
        } else {
            // Regla estricta: Si son 0 meses, solo decimos los días
            $texto_futuro = "Tus días totales serán $d días.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bargaiwe - Finalizar Compra</title>
    <style>
        body { background: #0d1117; font-family: 'Segoe UI', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        
        .btn-volver { position: absolute; top: 20px; left: 30px; color: #8b949e; text-decoration: none; font-weight: bold; padding: 8px 15px; border-radius: 8px; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .btn-volver:hover { color: white; background: rgba(255,255,255,0.05); transform: translateX(-5px); }

        .checkout-container { width: 100%; max-width: 850px; border-radius: 20px; display: flex; overflow: hidden; transition: 0.3s; }
        .resumen { padding: 40px; width: 45%; display: flex; flex-direction: column; justify-content: flex-start; }
        .formulario { padding: 40px; width: 55%; background: transparent; display: flex; flex-direction: column; justify-content: center; }
        
        .resumen h2 { margin-top: 0; font-size: 1.8rem; color: #32CD32; }
        .item-resumen { display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 1.1rem; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px; color: white;}
        .total-resumen { font-size: 2.2rem; font-weight: 900; color: #FF8C00; text-align: center; margin-top: 5px; }
        
        .checkout-container.tema-estandar { background: #161b22; border: 1px solid #30363d; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .tema-estandar .resumen { background: #014421; }

        .checkout-container.tema-premium { background: #0d1117; border: 2px solid #32CD32; box-shadow: 0 0 30px rgba(50, 205, 50, 0.2); }
        .tema-premium .resumen { background: rgba(22, 27, 34, 0.8); border-right: 1px solid rgba(50, 205, 50, 0.3); }
        
        .formulario h3 { margin-top: 0; color: #ffffff; font-size: 1.5rem; }
        .grupo-form { margin-bottom: 20px; }
        .grupo-form label { display: block; font-weight: bold; margin-bottom: 8px; color: #c9d1d9; }
        .grupo-form input { width: 100%; padding: 12px; border: 1px solid #30363d; border-radius: 8px; font-size: 1rem; box-sizing: border-box; background: #010409; color: white; outline-color: #32CD32; }
        
        .btn-pagar { background: #32CD32; color: white; border: none; padding: 15px; width: 100%; border-radius: 8px; font-size: 1.2rem; font-weight: 900; cursor: pointer; transition: 0.3s; margin-top: 10px; text-transform: uppercase; letter-spacing: 1px;}
        .btn-pagar:hover { background: #28a428; transform: scale(1.02); box-shadow: 0 5px 15px rgba(50, 205, 50, 0.3);}
        .alerta-test { background: rgba(255, 140, 0, 0.1); color: #FF8C00; padding: 12px; border-radius: 8px; text-align: center; font-size: 0.9rem; font-weight: bold; margin-bottom: 20px; border: 1px dashed rgba(255, 140, 0, 0.4);}
        
        /* Estilos nuevos para cliente existente */
        .caja-futuro { background: rgba(50, 205, 50, 0.1); border: 1px solid #32CD32; padding: 20px; border-radius: 10px; margin-bottom: 20px; text-align: center; }
        .caja-futuro strong { color: #32CD32; font-size: 1.2rem; }
    </style>
</head>
<body>

    <a href="suscripciones.php" class="btn-volver">← Volver a los Planes</a>

    <div class="checkout-container <?php echo $clase_tema; ?>">
        <div class="resumen">
            <h2>Resumen de Compra</h2>
            <div class="item-resumen"><span>Plan Elegido:</span> <strong>Bargaiwe <?php echo htmlspecialchars($plan); ?></strong></div>
            <div class="item-resumen"><span>Duración:</span> <strong><?php echo $meses; ?> Meses</strong></div>
            
            <?php if (!empty($beneficio)): ?>
            <div class="item-resumen" style="color: #32CD32; font-weight: 900; border-bottom: none;">
                <span>Beneficio Extra:</span> 
                <span>🎁 <?php echo $beneficio; ?></span>
            </div>
            <?php endif; ?>
            
            <div style="margin-top: auto;">
                <?php if ($original > $precio): ?>
                    <div style="text-align: center; color: #888; text-decoration: line-through; font-size: 1.3rem;">Valor real: $<?php echo number_format($original, 0, ',', '.'); ?></div>
                <?php endif; ?>
                
                <div style="text-align: center; font-size: 0.95rem; color: #ccc; margin-top: 5px;">Total a pagar hoy:</div>
                <div class="total-resumen">$<?php echo number_format($precio, 0, ',', '.'); ?> CLP</div>
                
                <div style="text-align: center; font-size: 1.2rem; color: #32CD32; font-weight: bold; margin-top: 5px;">
                    por <?php echo $tiempo_total; ?>
                </div>
            </div>
        </div>

        <div class="formulario">
            
            <?php if (!$es_cliente): ?>
                <h3>📝 Crea tu cuenta para activar</h3>
                <p style="color: #8b949e; font-size: 0.9rem; margin-bottom: 20px;">Serás redirigido a la plataforma segura de Mercado Pago.</p>
                
                <form action="pasarela_mp.php" method="POST">
                    <input type="hidden" name="meses_comprados" value="<?php echo $meses; ?>">
                    <input type="hidden" name="tipo_plan" value="<?php echo htmlspecialchars($plan); ?>">
                    <input type="hidden" name="precio_final" value="<?php echo $precio; ?>">
                    <input type="hidden" name="tipo_local" value="restaurante">
                    <input type="hidden" name="es_renovacion" value="0">
                    
                    <div class="grupo-form">
                        <label>Nombre de tu Restaurante</label>
                        <input type="text" name="nombre_restaurante" placeholder="Ej: Pizzería Don Lukas" required autofocus>
                    </div>
                    <div class="grupo-form">
                        <label>Correo Electrónico (Tu usuario)</label>
                        <input type="email" name="email_cliente" placeholder="admin@turestaurante.com" required>
                    </div>
                    <div class="grupo-form">
                        <label>Contraseña Maestra</label>
                        <input type="password" name="password_cliente" placeholder="Crea una contraseña segura" required>
                    </div>
                    <button type="submit" class="btn-pagar">🔒 Ir a Pagar Seguro</button>
                </form>

            <?php else: ?>
                <h3>👋 ¡Te recuerdo, <?php echo htmlspecialchars($nombre_local); ?>!</h3>
                <p style="color: #c9d1d9; font-size: 1.1rem; margin-bottom: 30px;">Elegiste agregar el plan <strong>Bargaiwe <?php echo htmlspecialchars($plan); ?></strong> a tu cuenta existente.</p>
                
                <div class="caja-futuro">
                    <strong><?php echo $texto_futuro; ?></strong>
                </div>

                <form action="pasarela_mp.php" method="POST">
                    <input type="hidden" name="meses_comprados" value="<?php echo $meses; ?>">
                    <input type="hidden" name="tipo_plan" value="<?php echo htmlspecialchars($plan); ?>">
                    <input type="hidden" name="precio_final" value="<?php echo $precio; ?>">
                    <input type="hidden" name="tipo_local" value="restaurante">
                    <input type="hidden" name="es_renovacion" value="1">
                    <button type="submit" class="btn-pagar">🔒 Pagar Renovación Segura</button>
                </form>
            <?php endif; ?>

        </div>
    </div>

</body>
</html>