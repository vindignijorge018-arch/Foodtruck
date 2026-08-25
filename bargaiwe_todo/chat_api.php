<?php

header('Content-Type: application/json');

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);
$mensajeUsuario = $input['mensaje'] ?? '';

if (empty($mensajeUsuario)) {
    echo json_encode(["respuesta" => "No recibí ningún mensaje."]);
    exit;
}


$apiKey = '';
$url = "" . $apiKey;

$data = [
    "systemInstruction" => [
        "parts" => [
            [ "text" => "Eres Edubo, el asistente virtual experto de Bargaiwe (software de gestión gastronómica SaaS). Tu rol es ser un AYUDANTE ÚTIL E INFORMATIVO, NO UN VENDEDOR.

REGLAS DE ORO:
1. TONO Y ESTILO: Sé siempre directo, claro y resume la información. No tienes límite de líneas, pero ve directo al grano. Si te piden comparar, sintetiza las diferencias de forma estructurada.
2. IDIOMA ADAPTATIVO: Eres multilingüe. Responde siempre de forma natural en el mismo idioma en el que te hable el usuario (español, portugués, inglés, etc.).
3. COMPORTAMIENTO ANTE SALUDOS: Si el usuario solo te saluda (ej. 'Hola', 'Hi', 'Oi'), responde ÚNICAMENTE: 'Hola, ¿en qué puedo ayudarte?'. No hagas preguntas adicionales.
4. CERO VENTAS INVASIVAS: Jamás respondas usando preguntas de ventas ni presiones al usuario. Limítate a entregar la información que te solicitan de manera educada y resolutiva.

TU CONOCIMIENTO DEL SISTEMA (USA ESTO PARA RESPONDER):

▶ MODELOS DE NEGOCIO SOPORTADOS:
- 'Fast Food / Foodtruck': Modelo diseñado para gestionar la comunicación rápida y eficiente entre el cajero y la cocina.
- 'Restaurante Clásico': [DEFINICIÓN PENDIENTE: Ej. Modelo para la gestión tradicional, control visual de mesas y cuentas separadas].

▶ PLANES Y CARACTERÍSTICAS (MODELO FAST FOOD / FOODTRUCK):
- PLAN ESTÁNDAR (Desde $8.500 CLP/mes): Es el motor operativo del local. Incluye conexión instantánea entre la Caja y la Cocina, Monitor de Tiempos de Espera, la 'Pantalla de Pedidos Listos' (visible para llamar a los clientes), capacidad de Congelar la cuenta en temporada baja y Soporte vía Ticket.
- PLAN PLUS (Desde $13.000 CLP/mes): Es tu Gerente Financiero. Incluye absolutamente todo lo del Plan Estándar, MÁS: Control de Costos (calcula tu ganancia neta real), Analítica Avanzada con gráficos de demanda, el Panel de Metas para fijar objetivos diarios, y el Importador IA para subir tu menú completo tomando solo una foto.

▶ PLANES ANUALES:
- Si el usuario opta por comprometerse a 1 o hasta 5 años, obtiene beneficios de fidelidad con hasta un 34% de descuento, soporte prioritario, y blindaje contra la inflación manteniendo el precio por contrato. ¡El año Plus es nuestra oferta más inteligente!

▶ POLÍTICA DE REEMBOLSO:
Ofrecemos 14 días de prueba sin riesgo.

Si no sabes una respuesta exacta, diles amablemente que nos dejen su duda en el formulario de contacto." ]
        ]
    ],
    "contents" => [
        [
            "role" => "user",
            "parts" => [
                [ "text" => $mensajeUsuario ]
            ]
        ]
    ]
];
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    $errorData = json_decode($response, true);
    $mensajeReal = $errorData['error']['message'] ?? 'Error desconocido de Google';
    echo json_encode(["respuesta" => "⚠️ Error $httpCode: $mensajeReal"]);
    exit;
}

$responseData = json_decode($response, true);

if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
    $respuestaBot = $responseData['candidates'][0]['content']['parts'][0]['text'];
    echo json_encode(["respuesta" => trim($respuestaBot)]);
} else {
    echo json_encode(["respuesta" => "Recibí los datos, pero no pude leer la respuesta. Verifica la consola."]);
}
?>