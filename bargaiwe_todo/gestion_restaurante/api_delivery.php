<?php
// Permite que páginas externas (como tu menú público) envíen datos aquí
header("Access-Control-Allow-Origin: *"); 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

include 'db.php';


// 1. MAGIA: CREAR TABLA DE DELIVERY SI NO EXISTE
$query_tabla = "CREATE TABLE IF NOT EXISTS registro_delivery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    cliente VARCHAR(100),
    direccion TEXT,
    metodo_pago VARCHAR(50),
    estado_pago VARCHAR(50),
    total INT NOT NULL DEFAULT 0,
    estado_pedido INT DEFAULT 1, -- 1: En Cocina, 2: En Camino, 3: Entregado/Pagado
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($query_tabla);

// 2. RECIBIR EL PAQUETE DE DATOS DE LA WEB (Formato JSON)
$json_recibido = file_get_contents("php://input");
$data = json_decode($json_recibido, true);

// 3. PROCESAR EL PEDIDO SI HAY DATOS
if (!empty($data) && isset($data['cliente'])) {
    
    // Limpiar los datos de texto para seguridad
    $cliente = $conn->real_escape_string($data['cliente']);
    $direccion = $conn->real_escape_string($data['direccion']);
    $metodo_pago = $conn->real_escape_string($data['metodo_pago']); // Ej: Tarjeta, Efectivo
    $estado_pago = $conn->real_escape_string($data['estado_pago']); // Ej: Pagado, Pendiente
    
    $total_pedido = 0;

    // A. Crear la "Ficha" del delivery
    $conn->query("INSERT INTO registro_delivery (restaurant_id, cliente, direccion, metodo_pago, estado_pago) 
                  VALUES ($mi_restaurant_id, '$cliente', '$direccion', '$metodo_pago', '$estado_pago')");
    $delivery_id = $conn->insert_id; // Obtenemos el ID de este nuevo delivery

    // B. Procesar cada plato que pidió el cliente
    if (isset($data['platos']) && is_array($data['platos'])) {
        foreach ($data['platos'] as $plato) {
            $menu_id = (int)$plato['id'];
            $precio = (int)$plato['precio'];
            $total_pedido += $precio;

            // Insertamos el plato en tu tabla 'pedidos' principal.
            // OJO: Usamos 'delivery_id' en vez de 'mesa_id' y tipo_pedido = 'delivery'.
            // estado = 1 significa que la cocina ya lo puede ver.
            $conn->query("INSERT INTO pedidos (restaurant_id, menu_id, delivery_id, tipo_pedido, precio_al_momento, estado, fecha) 
                          VALUES ($mi_restaurant_id, $menu_id, $delivery_id, 'delivery', $precio, 1, NOW())");
            
            // --- ZONA DE INVENTARIO ---
            // Aquí en el futuro agregaremos la consulta que resta los ingredientes de este $menu_id
            // Ej: $conn->query("UPDATE inventario SET cantidad = cantidad - 1 WHERE plato_id = $menu_id");
        }
    }

    // C. Actualizar el total de la cuenta en la ficha del delivery
    $conn->query("UPDATE registro_delivery SET total = $total_pedido WHERE id = $delivery_id");

    // D. Responder a la página web que todo salió bien
    echo json_encode([
        "status" => "success", 
        "mensaje" => "Pedido recibido y enviado a cocina.", 
        "delivery_id" => $delivery_id
    ]);

} else {
    // Si entran al archivo directamente sin enviar datos, mostramos error
    echo json_encode(["status" => "error", "mensaje" => "No se recibieron datos del pedido."]);
}
?>