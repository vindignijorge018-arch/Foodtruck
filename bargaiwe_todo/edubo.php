<?php
session_start();
include 'gestion_restaurante/db.php'; 

if (!isset($_SESSION['restaurant_id'])) { 
    exit("No autorizado"); 
}

$pregunta = $_POST['pregunta'] ?? '';
$tipo = $_SESSION['tipo_servicio'] ?? 'restaurante';
$plan = $_SESSION['nivel_plan'] ?? 'standard';

if (empty($pregunta)) { 
    exit("Escribe una pregunta válida."); 
}

$api_key = '';
$url = '' . $api_key;

// --- CONTEXTO ACTUALIZADO PARA EDUBO ---
$contexto = "Eres Edubo, asistente de Bargaiwe. 
Tipo de negocio: [$tipo]. Plan: [$plan].
RESTRICCIÓN DE PLAN:
- Si el plan es 'standard', NO puede acceder a Estadísticas Avanzadas (r_stats.php) ni a Metas (r_metas.php). 
- Si un usuario 'standard' pregunta por estas funciones, dile que necesita subir al plan 'Plus' para desbloquear el análisis financiero inteligente.
PRUEBA GRATIS 8 DÍAS:
- Si el usuario pregunta cómo obtener la prueba gratis, explícale que debe entrar a la sección de 'Planes', hacer clic en el botón de cambiar a 'VER PLANES FAST PLUS +' (o seleccionar el plan Plus), y en el menú desplegable elegir la opción de '8 Días de Prueba (Gratis)'.";

$payload = json_encode([
    "contents" => [
        ["role" => "user", "parts" => [["text" => $contexto . "\n\nPregunta del usuario: " . $pregunta]]]
    ]
]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$respuesta = curl_exec($ch);
curl_close($ch);

$resultado = json_decode($respuesta, true);
$texto_ia = $resultado['candidates'][0]['content']['parts'][0]['text'] ?? 'Edubo está procesando la información... intenta en unos segundos.';


echo trim(str_replace(['**', '*'], '', $texto_ia));
?>