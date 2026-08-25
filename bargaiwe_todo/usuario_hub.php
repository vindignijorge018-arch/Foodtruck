<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['restaurant_id'])) { 
    header("Location: portal_bargaiwe.php"); 
    exit(); 
}

include 'gestion_restaurante/db.php';
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];


$conn->query("CREATE TABLE IF NOT EXISTS mensajes_soporte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    remitente VARCHAR(50) NOT NULL,
    mensaje TEXT NOT NULL,
    tipo VARCHAR(20) DEFAULT 'chat',
    leido TINYINT(1) DEFAULT 0,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// --- SISTEMA DE CONGELACIÓN DE CUENTA ---
if (isset($_POST['congelar_cuenta'])) {
    $conn->query("UPDATE restaurantes SET estado_cuenta = 'congelada' WHERE id = $mi_restaurant_id");
    header("Location: usuario_hub.php"); exit();
}
if (isset($_POST['descongelar_cuenta'])) {
    $conn->query("UPDATE restaurantes SET estado_cuenta = 'activa' WHERE id = $mi_restaurant_id");
    header("Location: usuario_hub.php"); exit();
}
if (isset($_POST['enviar_mensaje'])) {
    $msg = $conn->real_escape_string($_POST['mensaje_texto']);
    $tipo = isset($_POST['tipo_mensaje']) ? $conn->real_escape_string($_POST['tipo_mensaje']) : 'chat';
    $conn->query("INSERT INTO mensajes_soporte (restaurant_id, remitente, mensaje, tipo) VALUES ($mi_restaurant_id, 'cliente', '$msg', '$tipo')");
    header("Location: usuario_hub.php?ticket=enviado");
    exit();
}

$mostrar_chat = isset($_GET['chat']) && $_GET['chat'] == 'abierto';
if ($mostrar_chat) {
    $conn->query("UPDATE mensajes_soporte SET leido = 1 WHERE restaurant_id = $mi_restaurant_id AND remitente = 'admin'");
}

$res_unread = $conn->query("SELECT COUNT(*) as unread FROM mensajes_soporte WHERE restaurant_id = $mi_restaurant_id AND remitente = 'admin' AND leido = 0");
$mensajes_no_leidos = $res_unread->fetch_assoc()['unread'];

$res_admin_history = $conn->query("SELECT COUNT(*) as total FROM mensajes_soporte WHERE restaurant_id = $mi_restaurant_id AND remitente = 'admin'");
$tiene_mensajes_admin = ($res_admin_history->fetch_assoc()['total'] > 0);

$historial = $conn->query("SELECT * FROM mensajes_soporte WHERE restaurant_id = $mi_restaurant_id ORDER BY fecha ASC");

// Ahora traemos el tipo de servicio, el plan y el ESTADO para Edubo y la UI
$sql = "SELECT nombre_local, email, fecha_vencimiento, codigo_secreto, tipo_local, tipo_servicio, nivel_plan, estado_cuenta FROM restaurantes WHERE id = $mi_restaurant_id";
$resultado = $conn->query($sql); 
$restaurante = $resultado->fetch_assoc(); 

$_SESSION['tipo_servicio'] = $restaurante['tipo_servicio'] ?? 'restaurante';
$_SESSION['nivel_plan'] = $restaurante['nivel_plan'] ?? 'standard';

$estado_cuenta_actual = $restaurante['estado_cuenta'] ?? 'activa';

$fecha_vencimiento = new DateTime($restaurante['fecha_vencimiento']);
$hoy = new DateTime();
$interval = $hoy->diff($fecha_vencimiento);
$dias_restantes = $interval->days;
$cuenta_vencida = ($hoy > $fecha_vencimiento);


$dias_mostrar = $cuenta_vencida ? "-$dias_restantes" : $dias_restantes;


$fecha_corte = clone $fecha_vencimiento;
$fecha_corte->modify('+5 days');
$corte_definitivo = ($hoy > $fecha_corte);

// LÓGICA INTELIGENTE DE ESTADOS
if ($estado_cuenta_actual === 'congelada') {
    $estado_texto = "Congelada ❄️";
    $color_estado = "#007BFF"; // Azul hielo
    $bloqueado = true;
    $motivo_bloqueo = "congelada";
} else {
    if ($corte_definitivo) {

        $estado_texto = "Cortada ❌";
        $color_estado = "#E53935"; 
        $bloqueado = true;
        $motivo_bloqueo = "pago";
    } elseif ($cuenta_vencida) {

        $estado_texto = "Vencida (En Gracia)";
        $color_estado = "#E53935"; 
        $bloqueado = false;
        $motivo_bloqueo = "";
    } else {

        $estado_texto = "Activa";
        $color_estado = "#32CD32";
        $bloqueado = false;
        $motivo_bloqueo = "";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bargaiwe - Mi Hub</title>
    <style>
        body { background: #0d1117; color: #c9d1d9; font-family: 'Segoe UI', sans-serif; margin: 0; padding: 40px 5%; min-height: 100vh; box-sizing: border-box; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #30363d; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { margin: 0; color: white; font-size: 2.2rem; }
        .header h1 span { color: #32CD32; }
        .btn-logout { background: transparent; color: #E53935; border: 1px solid #E53935; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: bold; transition: 0.3s; }
        .btn-logout:hover { background: #E53935; color: white; }

        .grid-hub { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 30px; }
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 15px; padding: 30px; display: flex; flex-direction: column; transition: 0.3s; }
        .card:hover { border-color: #555; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .card h3 { margin-top: 0; color: white; font-size: 1.5rem; border-bottom: 1px solid #30363d; padding-bottom: 15px; display: flex; align-items: center; gap: 10px;}
        
        .dato-resaltado { font-size: 3rem; font-weight: 900; color: <?php echo $color_estado; ?>; line-height: 1; margin: 15px 0; }
        .subtexto { font-size: 0.9rem; color: #8b949e; }
        .btn-accion { display: block; text-align: center; background: #30363d; color: white; text-decoration: none; padding: 12px; border-radius: 8px; font-weight: bold; margin-top: 10px; border: none; cursor: pointer; width: 100%; box-sizing: border-box;}
        
        .chatbot-bubble { position: fixed; bottom: 30px; right: 30px; background: #FF8C00; width: 60px; height: 60px; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; box-shadow: 0 10px 25px rgba(0,0,0,0.5); transition: 0.3s; z-index: 1000;}
        .chatbot-bubble:hover { transform: scale(1.1); }
        .chatbot-bubble svg { fill: white; width: 30px; }
        .notificacion-roja { position: absolute; top: 0; right: 0; background: #E53935; color: white; font-size: 0.8rem; font-weight: bold; width: 22px; height: 22px; border-radius: 50%; display: flex; justify-content: center; align-items: center; border: 2px solid #0d1117;}
        
        .chat-ventana { display: <?php echo $mostrar_chat ? 'flex' : 'none'; ?>; flex-direction: column; position: fixed; bottom: 100px; right: 30px; width: 350px; height: 500px; background: #161b22; border: 1px solid #30363d; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.7); z-index: 1000; overflow: hidden; }
        .chat-header { background: #014421; color: white; padding: 15px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .chat-body { flex-grow: 1; padding: 15px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; background: #0d1117;}
        .mensaje { padding: 10px 15px; border-radius: 15px; max-width: 80%; font-size: 0.95rem;}
        .msg-cliente { background: #32CD32; color: #014421; align-self: flex-end; border-bottom-right-radius: 2px;}
        .msg-admin { background: #30363d; color: white; align-self: flex-start; border-bottom-left-radius: 2px;}
        .chat-input { padding: 15px; background: #161b22; border-top: 1px solid #30363d; display: flex; gap: 10px; }
        .chat-input input { flex-grow: 1; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; }
        .chat-input button { background: #FF8C00; color: white; border: none; padding: 10px 15px; border-radius: 8px; font-weight: bold; cursor: pointer; }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); display: none; justify-content: center; align-items: center; z-index: 2000; backdrop-filter: blur(5px);}
        .modal-content { background: #161b22; padding: 30px; border-radius: 15px; width: 400px; border: 1px solid #30363d; box-shadow: 0 10px 30px rgba(0,0,0,0.5);}
        .alerta-exito { background: rgba(50, 205, 50, 0.1); color: #32CD32; border: 1px solid #32CD32; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; text-align: center;}
    </style>
</head>
<body>

    <div class="header">
        <h1>Bargaiwe <span>Hub</span></h1>
        <a href="cerrar_sesion.php" class="btn-logout" title="Salir del sistema">Cerrar Sesión</a>
    </div>

    <div style="margin-bottom: 30px;">
        <h2 style="color: white; margin-bottom: 5px;">Bienvenido, <?php echo htmlspecialchars($restaurante['nombre_local']); ?> <span style="color:#FF8C00; font-size: 1.2rem;">[#<?php echo $restaurante['codigo_secreto']; ?>]</span></h2>
        <span class="subtexto">Usuario: <?php echo htmlspecialchars($restaurante['email']); ?></span>
    </div>

    <?php if(isset($_GET['ticket']) && $_GET['ticket'] == 'enviado'): ?>
        <div class="alerta-exito">✅ Tu mensaje ha sido enviado a soporte. Te responderemos pronto.</div>
    <?php endif; ?>

<div class="grid-hub">
        <div class="card" style="border-color: <?php echo (isset($restaurante['tipo_local']) && $restaurante['tipo_local'] == 'rapida') ? '#FF8C00' : '#32CD32'; ?>;">
            <h3><?php echo (isset($restaurante['tipo_local']) && $restaurante['tipo_local'] == 'rapida') ? '🥡 Mi Local (Fast Food)' : '🏪 Mi Restaurante'; ?></h3>
            <p style="color: #c9d1d9; flex-grow: 1;">
                <?php echo (isset($restaurante['tipo_local']) && $restaurante['tipo_local'] == 'rapida') ? 'Atiende clientes, toma pedidos rápidos y revisa la cocina.' : 'Gestiona mesas, toma pedidos y revisa la cocina.'; ?>
            </p>
            
            <?php if ($bloqueado && $motivo_bloqueo === 'congelada'): ?>
                <div style="color: #007BFF; font-weight: bold; text-align: center; padding: 15px; border: 1px dashed #007BFF; border-radius: 8px; background: rgba(0, 123, 255, 0.1);">
                    ❄️ Sistema Pausado.<br>Reactiva tu cuenta para operar.
                </div>
            <?php elseif ($bloqueado && $motivo_bloqueo === 'pago'): ?>
                <div style="color: #E53935; font-weight: bold; text-align: center; padding: 15px; border: 1px dashed #E53935; border-radius: 8px; background: rgba(229, 57, 53, 0.1);">
                    ❌ Sistema Bloqueado por Falta de Pago.<br>Por favor, renueva tu suscripción.
                </div>
            <?php else: ?>
                <?php if (isset($restaurante['tipo_local']) && $restaurante['tipo_local'] == 'rapida'): ?>
                    <a href="gestion_rapida/r_pedidos.php" class="btn-accion" style="background: #30363d; color: #FF8C00; border: 1px solid #FF8C00; padding: 20px;">🚀 PUNTO DE VENTA (RÁPIDO)</a>
                <?php else: ?>
                    <a href="gestion_restaurante/mesas.php" class="btn-accion" style="background: #014421; color: #32CD32; padding: 20px;">🚀 GESTIÓN DE MESAS</a>
                <?php endif; ?>
                
                <?php if ($cuenta_vencida): ?>
                    <div style="color: #E53935; font-size: 0.85rem; text-align: center; margin-top: 15px; font-weight: bold;">
                        ⚠️ Estás en periodo de gracia.<br>El sistema se cortará pronto.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card" style="border-color: <?php echo $color_estado; ?>;">
            <h3>💳 Suscripción</h3>
            <div class="subtexto">Estado: <strong style="color: <?php echo $color_estado; ?>;"><?php echo $estado_texto; ?></strong></div>
            <div class="dato-resaltado"><?php echo $dias_mostrar; ?></div>
            <div class="subtexto">Días restantes</div>
            
            <div style="margin-top: 25px; display: flex; flex-direction: column; gap: 10px;">
                <a href="suscripciones.php" class="btn-accion" style="background: #FF8C00; border: 1px solid #FF8C00;">Renovar o Mejorar Plan</a>
                
                <form method="POST" style="margin: 0;">
                    <?php if ($estado_cuenta_actual === 'congelada'): ?>
                        <button type="submit" name="descongelar_cuenta" class="btn-accion" style="background: #007BFF; color: white; border: 1px solid #007BFF; font-size: 1rem;">▶️ Reactivar Cuenta Ahora</button>
                    <?php else: ?>
                        <button type="submit" name="congelar_cuenta" class="btn-accion" style="background: transparent; color: #007BFF; border: 1px solid #007BFF; font-size: 0.9rem;" onclick="return confirm('❄️ ¿Seguro que quieres congelar tu cuenta? Nadie en el local podrá ingresar al sistema hasta que vuelvas a reactivarla.');">❄️ Congelar Cuenta</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card">
            <h3>🛡️ Soporte Técnico</h3>
            <p style="color: #c9d1d9; flex-grow:1;">¿En qué podemos ayudarte hoy?</p>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button class="btn-accion" style="background: transparent; border: 1px solid #32CD32; color: #32CD32;" onclick="abrirModal('comentario')">💡 Comentario / Opinión</button>
                <button class="btn-accion" style="background: transparent; border: 1px solid #007BFF; color: #007BFF;" onclick="abrirModal('chat')">💬 Abrir Chat de Ayuda</button>
                <button class="btn-accion" style="background: transparent; border: 1px solid #E53935; color: #E53935;" onclick="abrirModal('problema')">🚨 Informar Problema</button>
            </div>
        </div>

        <div class="card" style="border-color: #007BFF; box-shadow: 0 0 20px rgba(0, 123, 255, 0.1);">
            <h3>🧠 Asistente Edubo (IA)</h3>
            
            <div id="chat-edubo" style="flex-grow: 1; background: #0d1117; border-radius: 8px; border: 1px solid #30363d; padding: 15px; display: flex; flex-direction: column; gap: 10px; height: 120px; overflow-y: auto;">
                <div style="background: #30363d; color: white; padding: 10px; border-radius: 10px; max-width: 90%; align-self: flex-start; font-size: 0.9rem;">
                    ¡Hola! Soy Edubo. ¿En qué te ayudo con la gestión de tu <?php echo htmlspecialchars($_SESSION['tipo_servicio'] ?? 'local'); ?> hoy?
                </div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 15px;">
                <input type="text" id="pregunta-edubo" 
                       onkeypress="if(event.key === 'Enter') preguntarEdubo();" 
                       placeholder="Pregúntale algo a Edubo..." 
                       style="flex-grow: 1; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white;">
                
                <button type="button" onclick="preguntarEdubo()" 
                        style="background: #007BFF; color: white; border: none; padding: 10px 15px; border-radius: 8px; cursor: pointer; font-weight: bold;">
                    ➤
                </button>
            </div>
        </div>
    </div>
    <div id="modalTicket" class="modal-overlay">
        <div class="modal-content">
            <h3 id="modalTitulo" style="color: white; margin-top: 0;">Contacto</h3>
            <p id="modalDesc" style="color: #8b949e; font-size: 0.9rem;">Escribe tu mensaje.</p>
            <form method="POST">
                <input type="hidden" name="tipo_mensaje" id="modalTipo" value="chat">
                <textarea name="mensaje_texto" rows="4" placeholder="Escribe aquí..." required style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: white; box-sizing: border-box; margin-bottom: 15px;"></textarea>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="enviar_mensaje" style="background: #32CD32; color: #014421; border: none; padding: 10px; border-radius: 8px; cursor: pointer; flex: 1; font-weight: bold;">Enviar</button>
                    <button type="button" onclick="document.getElementById('modalTicket').style.display='none'" style="background: #30363d; color: white; border: none; padding: 10px; border-radius: 8px; cursor: pointer; flex: 1;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <?php if($tiene_mensajes_admin): ?>
        <div class="chatbot-bubble" onclick="toggleChat()">
            <?php if($mensajes_no_leidos > 0): ?>
                <div class="notificacion-roja"><?php echo $mensajes_no_leidos; ?></div>
            <?php endif; ?>
            <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
        </div>

        <div class="chat-ventana" id="ventanaChat">
            <div class="chat-header">
                <span>Soporte Bargaiwe</span>
                <button onclick="toggleChat()" style="background:none; border:none; color:white; cursor:pointer; font-size:1.2rem;">✖</button>
            </div>
            <div class="chat-body" id="cuerpoChat">
                <div class="mensaje msg-admin">¡Hola! ¿En qué te podemos ayudar hoy? Si reportas un problema, te responderemos pronto por aquí.</div>
                <?php while($msg = $historial->fetch_assoc()): ?>
                    <div class="mensaje <?php echo ($msg['remitente'] == 'cliente') ? 'msg-cliente' : 'msg-admin'; ?>">
                        <?php echo htmlspecialchars($msg['mensaje']); ?>
                    </div>
                <?php endwhile; ?>
            </div>
            <form class="chat-input" method="POST">
                <input type="text" name="mensaje_texto" placeholder="Escribe tu mensaje..." required autocomplete="off">
                <button type="submit" name="enviar_mensaje">➤</button>
            </form>
        </div>
    <?php endif; ?>

    <script>
        // 1. Funciones del Modal de Contacto
        function abrirModal(tipo) {
            document.getElementById('modalTicket').style.display = 'flex';
            document.getElementById('modalTipo').value = tipo;
            
            let titulo = document.getElementById('modalTitulo');
            let desc = document.getElementById('modalDesc');
            
            if (tipo === 'comentario') {
                titulo.innerHTML = '💡 Comentario o Sugerencia';
                desc.innerHTML = 'Agradecemos que te tomes tu tiempo para proporcionarnos tu opinión. ¿Qué podemos mejorar?';
            } else if (tipo === 'chat') {
                titulo.innerHTML = '💬 Abrir Chat de Ayuda';
                desc.innerHTML = 'Cuéntanos cómo podemos ayudarte. Un agente te responderá pronto.';
            } else if (tipo === 'problema') {
                titulo.innerHTML = '🚨 Informar Problema Técnico';
                desc.innerHTML = 'Cuéntanos qué sucedió o qué dejó de funcionar.';
            }
        }

        // 2. Funciones del Chat de Soporte
        function toggleChat() {
            let chat = document.getElementById('ventanaChat');
            if(chat.style.display === 'none' || chat.style.display === '') {
                <?php if($mensajes_no_leidos > 0): ?>
                    window.location.href = "usuario_hub.php?chat=abierto";
                <?php else: ?>
                    chat.style.display = 'flex';
                    let cuerpo = document.getElementById('cuerpoChat');
                    if(cuerpo) cuerpo.scrollTop = cuerpo.scrollHeight;
                <?php endif; ?>
            } else {
                chat.style.display = 'none';
                window.history.replaceState(null, null, window.location.pathname);
            }
        }
        
        <?php if($mostrar_chat && $tiene_mensajes_admin): ?>
            window.addEventListener('DOMContentLoaded', (event) => {
                let cuerpo = document.getElementById('cuerpoChat');
                if(cuerpo) cuerpo.scrollTop = cuerpo.scrollHeight;
            });
        <?php endif; ?>

        // 3. Función del Asistente Edubo
        function preguntarEdubo() {
            const input = document.getElementById('pregunta-edubo');
            const cajaChat = document.getElementById('chat-edubo');
            const pregunta = input.value.trim();

            if (pregunta === "") return;

            // Mostrar tu pregunta en azul
            cajaChat.innerHTML += `<div style="background: #007BFF; color: white; padding: 10px; border-radius: 10px; max-width: 90%; align-self: flex-end; font-size: 0.9rem;">${pregunta}</div>`;
            input.value = ""; 
            cajaChat.scrollTop = cajaChat.scrollHeight; 

            const formData = new FormData();
            formData.append('pregunta', pregunta);

            // Llamada a edubo.php 
            fetch('edubo.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(data => {
                // Mostrar respuesta de la IA en gris
                cajaChat.innerHTML += `<div style="background: #30363d; color: white; padding: 10px; border-radius: 10px; max-width: 90%; align-self: flex-start; font-size: 0.9rem;">${data}</div>`;
                cajaChat.scrollTop = cajaChat.scrollHeight;
            })
            .catch(error => {
                console.error("Error en Edubo:", error);
                cajaChat.innerHTML += `<div style="color: #E53935; font-size: 0.8rem;">Error: No se pudo conectar con Edubo.</div>`;
            });
        }
    </script>
</body>
</html>