<?php
// --- 1. GESTIÓN DE SESIONES (Motor del SaaS) ---
// Revisamos si la sesión está apagada ANTES de configurarla y encenderla
if (session_status() === PHP_SESSION_NONE) {
    // Configura la sesión para que dure 12 horas (43200 segundos)
    ini_set('session.gc_maxlifetime', 43200);
    ini_set('session.cookie_lifetime', 43200);
    session_start();
}

// --- 2. BARRERA DE SEGURIDAD DUAL MAESTRA ---
$mi_restaurant_id = 0;

// Caso A: Es el dueño del restaurante (PC)
if (isset($_SESSION['restaurant_id']) && !empty($_SESSION['restaurant_id'])) {
    $mi_restaurant_id = (int)$_SESSION['restaurant_id'];
} 
// Caso B: Es un mesero o cliente (Celular)
elseif (isset($_SESSION['rest_id_movil']) && !empty($_SESSION['rest_id_movil'])) {
    $mi_restaurant_id = (int)$_SESSION['rest_id_movil'];
}
// Caso C: Es el momento del QR
elseif (isset($_GET['r']) && isset($_GET['t'])) {
    $mi_restaurant_id = (int)$_GET['r'];
} 
// Caso D: 👑 ¡ES LUKAS! (Super Admin)
elseif (isset($_SESSION['soy_el_dios_de_bargaiwe'])) {
    // Si eres tú, te dejamos pasar sin asignar un restaurant_id de cliente
    $mi_restaurant_id = 0; 
}
// Si no es NINGUNO de los anteriores, verificamos si debemos echarlo
else {
    $pagina_actual = basename($_SERVER['PHP_SELF']);
    
    // AÑADIMOS LAS PÁGINAS DE PAGO A LA LISTA DE EXCEPCIONES
    $excepciones = [
        'portal_bargaiwe.php', 
        'administrar_bargaiwe.php', 
        'r_registro_pago.php', 
        'r_pago_exitoso.php',
        'suscripciones_rapida.php',
        'registro_gratis.php' // <--- Añade esta línea
    ];

    if (!in_array($pagina_actual, $excepciones)) {
        // ARREGLO DE RUTA: Ruta absoluta para que nunca se pierda
        header("Location: /bargaiwe/portal_bargaiwe.php"); 
        exit();
    }
}

// --- 3. CREDENCIALES DE BASE DE DATOS ---
$host = "localhost";
$user = "jorger32_admin";
$pass = "Dinosaurio123";
$db   = "jorger32_Bargaiwe";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Fallo de conexión a la base de datos: " . $conn->connect_error);
}

// MEJORA TÉCNICA: Forzamos UTF-8 para tildes y 'ñ'
$conn->set_charset("utf8mb4");

// --- 4. VARIABLES GLOBALES ORIGINALES ---
$delivery_id = isset($_GET['delivery_id']) ? (int)$_GET['delivery_id'] : 0;
$mesa_id     = isset($_GET['mesa_id'])     ? (int)$_GET['mesa_id']     : 0;

// --- GUARDIA DE SEGURIDAD SAAS (ACTUALIZADO) ---
if (isset($_SESSION['restaurant_id'])) {
    $id_revisar = (int)$_SESSION['restaurant_id'];
    
    // 1. Preguntamos si el local AÚN EXISTE en la base de datos
    $check_query = $conn->query("SELECT fecha_vencimiento, estado_cuenta FROM restaurantes WHERE id = $id_revisar");
    
    if ($check_query->num_rows === 0) {
        // EL LOCAL FUE BORRADO: Quitamos solo la sesión del cliente, salvando al administrador
        unset($_SESSION['restaurant_id']);
        unset($_SESSION['nombre_local']);
        
        header("Location: /bargaiwe/portal_bargaiwe.php?error=eliminado");
        exit();
    } else {
        // 2. EL LOCAL EXISTE: Revisamos estado y vencimiento
        $datos_local = $check_query->fetch_assoc();
        $fecha_vencimiento = $datos_local['fecha_vencimiento'];
        $estado_cta = isset($datos_local['estado_cuenta']) ? $datos_local['estado_cuenta'] : 'activa';
        
        $hoy = date('Y-m-d');
        // ACTUALIZADO: Sumamos los 5 días de gracia
        $fecha_limite = date('Y-m-d', strtotime($fecha_vencimiento . ' + 5 days'));
        
        // 3. ¿Está congelada o ya pasaron los 5 días de gracia?
        if ($estado_cta === 'congelada' || $hoy > $fecha_limite) {
            // Lo enviamos al Hub (NO lo expulsamos del sistema)
            header("Location: /bargaiwe/usuario_hub.php");
            exit();
        }
    }
}
// Función de seguridad para cuentas PLUS
if (!function_exists('verificarPlanPlus')) {
    function verificarPlanPlus() {
        if (!isset($_SESSION['nivel_plan']) || $_SESSION['nivel_plan'] !== 'plus') {
            header("Location: /bargaiwe/usuario_hub.php?error=plan_insuficiente");
            exit();
        }
    }
}
?>