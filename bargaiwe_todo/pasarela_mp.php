<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();


$host = "localhost";
$user = "admin"; 
$pass = '1234'; 
$db   = "Bargaiwe"; 

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { die("Conexión fallida: " . $conn->connect_error); }

$access_token = ""; 

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 2. CAPTURAR DATOS DEL FORMULARIO 
    $nombre_local = $conn->real_escape_string($_POST['nombre_restaurante'] ?? '');
    $email = strtolower(trim($conn->real_escape_string($_POST['email_cliente'] ?? '')));
    $password = $_POST['password_cliente'] ?? '';
    $plan = $conn->real_escape_string($_POST['tipo_plan'] ?? '');
    $meses = (int)($_POST['meses_comprados'] ?? 1);
    $tipo_local = $conn->real_escape_string($_POST['tipo_local'] ?? 'restaurante');
    $es_renovacion = (int)($_POST['es_renovacion'] ?? 0);
    $precio = (float)($_POST['precio_final'] ?? 0);

    $pass_hash = password_hash($password, PASSWORD_DEFAULT);
    
    $url_base = "https://bargaiwe.com"; 
    $url_exito = ($tipo_local === 'rapida') ? "$url_base/r_pago_exitoso.php" : "$url_base/pago_exitoso.php";

    // 3. LÓGICA VIP PARA $0 
    if ($precio <= 0) {
        if ($es_renovacion == 0 && !empty($email)) {
            $check = $conn->query("SELECT id FROM restaurantes WHERE email = '$email'");
            if ($check->num_rows == 0) {
                $dias_regalo = ($meses == 3) ? 15 : 0;
                $fecha_venc = date('Y-m-d', strtotime("+$meses months +$dias_regalo days")); 
                $codigo_secreto = rand(1000, 9999);

                $stmt = $conn->prepare("INSERT INTO restaurantes (nombre_local, email, password_hash, fecha_vencimiento, plan, tipo_local, codigo_secreto) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("sssssss", $nombre_local, $email, $pass_hash, $fecha_venc, $plan, $tipo_local, $codigo_secreto);
                
                if ($stmt->execute()) {
                    $nuevo_id = $conn->insert_id;
                    $_SESSION['restaurant_id'] = $nuevo_id;
                    $_SESSION['nombre_local'] = $nombre_local;
                    if ($tipo_local === 'rapida') {
                        $conn->query("INSERT INTO menu (restaurant_id, nombre, precio, seccion, disponibilidad) VALUES ($nuevo_id, 'Hamburguesa Clásica', 5000, 'Principal', 1)");
                        $conn->query("INSERT IGNORE INTO config_cocina (restaurant_id, minutos_amarillo, minutos_rojo) VALUES ($nuevo_id, 10, 20)");
                    }
                }
            } else {
                $user = $check->fetch_assoc();
                $_SESSION['restaurant_id'] = $user['id'];
            }
        }

        header("Location: " . $url_exito);
        exit();
    }
    $datos_preferencia = [
        "items" => [
            [
                "title" => "Suscripción Bargaiwe " . $plan,
                "quantity" => 1,
                "currency_id" => "CLP",
                "unit_price" => $precio
            ]
        ],
        "metadata" => [
            "nombre_local" => $nombre_local,
            "email_cliente" => $email,
            "password_hash" => $pass_hash,
            "plan" => $plan,
            "meses" => (string)$meses, 
            "tipo_local" => $tipo_local,
            "es_renovacion" => (string)$es_renovacion
        ],
        "back_urls" => [
            "success" => $url_exito,
            "failure" => "$url_base/index.html",
            "pending" => "$url_base/index.html"
        ],
        "auto_return" => "approved"
    ];

    // 5. MERCADO PAGO
    $ch = curl_init("https://api.mercadopago.com/checkout/preferences");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datos_preferencia));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
    ]);

    $respuesta = curl_exec($ch);
    curl_close($ch);
    
    $mp_data = json_decode($respuesta, true);

    if (isset($mp_data['init_point'])) {
        header("Location: " . $mp_data['init_point']); 
        exit();
    } else {
        echo "<h3 style='color:white; font-family:sans-serif;'>Error de Mercado Pago.</h3>";
        echo "<pre style='color:white;'>"; print_r($mp_data); echo "</pre>";
        exit();
    }
}
?>