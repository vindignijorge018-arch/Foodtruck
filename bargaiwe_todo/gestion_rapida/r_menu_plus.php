<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['restaurant_id'])) { 
    header("Location: ../portal_bargaiwe.php"); 
    exit(); 
}

include 'r_db.php'; // Esta es tu conexión correcta en esta carpeta
verificarPlanPlus(); // El portero de seguridad
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

$res_tema = $conn->query("SELECT modo_global, color_cajero FROM config_temas WHERE restaurant_id = $mi_restaurant_id");
$tema = ($res_tema && $res_tema->num_rows > 0) ? $res_tema->fetch_assoc() : ['modo_global' => 'oscuro', 'color_cajero' => '#FF8C00'];
// --- FUNCIÓN MAESTRA DE INSERCIÓN ---
function insertarPlato($conn, $res_id, $sec, $nom, $desc, $pre) {
    $seccion = $conn->real_escape_string(trim($sec));
    $nombre = $conn->real_escape_string(trim($nom));
    $descripcion = $conn->real_escape_string(trim($desc)); 
    $precio = (int) preg_replace('/[^0-9]/', '', trim($pre));

    if ($precio >= 0 && !empty($nombre)) {
        $res_tipo = $conn->query("SELECT tipo_articulo FROM menu WHERE seccion = '$seccion' AND restaurant_id = $res_id LIMIT 1");
        $tipo = ($res_tipo->num_rows > 0) ? $res_tipo->fetch_assoc()['tipo_articulo'] : 'plato';

        $sql = "INSERT INTO menu (restaurant_id, seccion, nombre, descripcion, precio, disponibilidad, tipo_articulo) 
                VALUES ($res_id, '$seccion', '$nombre', '$descripcion', $precio, 1, '$tipo')";
        return $conn->query($sql);
    }
    return false;
}

// ==========================================================
// PROCESAMIENTO DE IMAGEN CON GOOGLE GEMINI (OPCIÓN 1)
// ==========================================================
if (isset($_POST['procesar_imagen']) && isset($_FILES['archivo_ia']) && $_FILES['archivo_ia']['size'] > 0) {
    
    $tipo_archivo = $_FILES['archivo_ia']['type'];
    
    if (strpos($tipo_archivo, 'image/') === 0) {
        
        $api_key = ''; 
        
        // --- CAMBIO CLAVE: Usamos v1 en lugar de v1beta y el nombre base del modelo ---
        $url = 'https://generativelanguage.googleapis.com/v1alpha/models/gemini-3-flash-preview:generateContent?key=' . $api_key;

        $imagen_base64 = base64_encode(file_get_contents($_FILES['archivo_ia']['tmp_name']));
        $mime = $_FILES['archivo_ia']['type'];

        // TEST RÁPIDO: Antes de procesar, vamos a ver si la API responde a un texto simple
        $prompt = "Actúa como un extractor de datos. Extrae los platos de esta imagen y devuélveme SOLO texto plano separado por '|'. Estructura: Categoría | Nombre | Ingredientes | Precio. Si no ves precios, usa 0.";

        $payload = json_encode([
            "contents" => [[
                "parts" => [
                    ["text" => $prompt],
                    ["inline_data" => ["mime_type" => $mime, "data" => $imagen_base64]]
                ]
            ]]
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
        
        $respuesta = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // --- SISTEMA DE DESCARTAR ERRORES ---
        if ($http_code !== 200) {
            die("<div style='background: #fff; padding: 20px; color: #000; border: 5px solid red; font-family: monospace;'>
                <h1 style='color:red;'>🚨 ERROR DE CONEXIÓN</h1>
                <strong>Código HTTP:</strong> $http_code <br>
                <strong>Respuesta de Google:</strong> <pre>" . htmlspecialchars($respuesta) . "</pre>
                <hr>
                <strong>Sugerencia:</strong> Si el error es 404, intenta cambiar la URL a <code>v1beta</code> o viceversa. Si es 400, el formato de la imagen o el API Key están mal.
            </div>");
        }

        $resultado = json_decode($respuesta, true);
        $texto_ia = $resultado['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Si quieres ver el "HOLA" de la IA para descartar, descomenta la siguiente línea:
        // die("<pre>La IA leyó esto:\n\n" . htmlspecialchars($texto_ia) . "</pre>");

        $texto_ia = trim(str_replace(['```text', '```txt', '```'], '', $texto_ia));
        $creados = 0;
        
        if (!empty($texto_ia)) {
            $lineas = explode("\n", str_replace("\r", "", $texto_ia));
            foreach ($lineas as $linea) {
                $datos = explode('|', $linea);
                if (count($datos) >= 4) {
                    $cat = trim($datos[0]);
                    $nom = trim($datos[1]);
                    $ing = trim($datos[2]);
                    $pre = trim($datos[3]);
                    if (!empty($nom)) {
                        if (insertarPlato($conn, $mi_restaurant_id, $cat, $nom, $ing, $pre)) { $creados++; }
                    }
                }
            }
        }

        header("Location: r_menu.php?exito=ia&creados=" . $creados);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importador IA - Bargaiwe Plus</title>
    <style>
        :root {
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: <?php echo $tema['color_cajero']; ?>; 
            --success: #32CD32; --danger: #E53935;
        }
        
        <?php if($tema['modo_global'] === 'claro'): ?>
        body { --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000; }
        <?php endif; ?>

        body { background: var(--bg-body); color: var(--text); font-family: 'Segoe UI', sans-serif; margin: 0; padding: 40px 20px; display: flex; justify-content: center; }
        
        .container { width: 100%; max-width: 900px; }
        .card-plus { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 15px; padding: 30px; margin-bottom: 20px;}
        
        .prompt-box { background: #010409; border: 1px dashed var(--accent); padding: 15px; border-radius: 8px; font-family: monospace; color: var(--success); font-size: 0.9rem; margin-bottom: 20px; white-space: pre-wrap;}
        textarea { width: 100%; height: 200px; padding: 15px; background: #010409; color: var(--text-title); border: 1px solid var(--border); border-radius: 8px; font-family: monospace; font-size: 1rem; box-sizing: border-box; resize: vertical; }
        textarea:focus { outline: none; border-color: var(--accent); }
        
        .btn-magico { background: var(--accent); color: white; border: none; padding: 15px 30px; border-radius: 8px; font-weight: 900; font-size: 1.2rem; cursor: pointer; width: 100%; margin-top: 15px; text-transform: uppercase; transition: 0.2s;}
        .btn-magico:hover { opacity: 0.9; transform: scale(1.01); }

        /* --- ESTILOS DE LA ZONA DRAG & DROP --- */
        .drop-zone { border: 2px dashed var(--border); border-radius: 12px; padding: 40px 20px; text-align: center; background: rgba(0, 0, 0, 0.1); cursor: pointer; transition: all 0.3s ease; margin-bottom: 15px; position: relative; overflow: hidden; }
        .drop-zone.dragover { background: rgba(50, 205, 50, 0.05); border-color: var(--success); transform: scale(1.02); }
        .drop-zone:hover { border-color: var(--accent); background: rgba(255, 255, 255, 0.02); }
        
        .drop-zone-icon { font-size: 3.5rem; margin-bottom: 15px; display: block; filter: grayscale(1); opacity: 0.7; transition: 0.3s; }
        .drop-zone:hover .drop-zone-icon { filter: grayscale(0); opacity: 1; transform: translateY(-5px); }
        
        .preview-container { display: none; margin-top: 15px; }
        .preview-img { max-width: 100%; max-height: 250px; border-radius: 8px; border: 1px solid var(--accent); box-shadow: 0 10px 20px rgba(0,0,0,0.3); }
        
        /* Loading overlay */
        #loading-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; justify-content: center; align-items: center; flex-direction: column; color: white; }
        .spinner { border: 5px solid rgba(255,255,255,0.3); border-top: 5px solid var(--accent); border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin-bottom: 15px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>

    <div id="loading-overlay">
        <div class="spinner"></div>
        <h2 style="margin: 0; color: var(--accent);">La IA está leyendo tu menú...</h2>
        <p>Esto puede tardar unos segundos. ¡Magia en proceso!</p>
    </div>

    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <a href="r_menu.php" style="color: var(--text); text-decoration: none; font-weight: bold; background: var(--bg-panel); padding: 8px 15px; border-radius: 8px; border: 1px solid var(--border);">← Volver al Menú</a>
            <span style="background: linear-gradient(45deg, #9C27B0, #3f51b5); color: white; padding: 5px 15px; border-radius: 20px; font-weight: bold; border: 1px solid #7B1FA2;">Módulo IA Conectado 🟢</span>
        </div>

        <div class="card-plus">
            <h1 style="color: var(--accent); margin-top: 0; text-align: center;">🤖 Digitalizador de Menú</h1>
            <p style="text-align: center; color: #8b949e; margin-bottom: 30px;">Transforma una foto de tu menú físico en productos digitales al instante usando Google Gemini.</p>
            
            <h3 style="color: var(--text-title);">🚀 Opción 1: Escáner Automático (Recomendado)</h3>
            
            <form method="POST" enctype="multipart/form-data" onsubmit="document.getElementById('loading-overlay').style.display='flex';">
                <div class="drop-zone" id="drop-zone">
                    <span class="drop-zone-icon" id="drop-icon">📸</span>
                    <span id="drop-text-1" style="font-size: 1.1rem; font-weight: bold; color: var(--text-title);">Arrastra la foto de tu menú aquí (JPG, PNG)</span>
                    <span id="drop-text-2" style="display: block; font-size: 0.9rem; color: #8b949e; margin-top: 5px;">También puedes subir un archivo .TXT con el formato de columnas</span>
                    
                    <input type="file" name="archivo_ia" id="file-input" accept="image/*,.txt" style="display: none;" required>
                    
                    <div class="preview-container" id="preview-container">
                        <img src="" alt="Vista previa" class="preview-img" id="preview-img">
                        <p id="preview-text" style="color: var(--success); font-weight: bold; margin-top: 10px; margin-bottom: 0;">✅ Archivo cargado exitosamente</p>
                    </div>
                </div>
                <button type="submit" name="procesar_imagen" class="btn-magico" style="background: linear-gradient(45deg, #9C27B0, #3f51b5);">✨ Procesar Archivo con IA</button>
            </form>


        </div>
    </div>

    <script>
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        const previewContainer = document.getElementById('preview-container');
        const previewImg = document.getElementById('preview-img');
        const previewText = document.getElementById('preview-text');
        
        const dropIcon = document.getElementById('drop-icon');
        const dropText1 = document.getElementById('drop-text-1');
        const dropText2 = document.getElementById('drop-text-2');

        dropZone.addEventListener('click', () => fileInput.click());

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            
            if (e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                mostrarVistaPrevia(fileInput.files[0]);
            }
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) {
                mostrarVistaPrevia(fileInput.files[0]);
            }
        });

        function mostrarVistaPrevia(file) {
            if (file) {
                dropIcon.style.display = 'none';
                dropText1.style.display = 'none';
                dropText2.style.display = 'none';
                previewContainer.style.display = 'block';

                if (file.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        previewImg.src = e.target.result;
                        previewImg.style.display = 'inline-block';
                        previewText.innerText = "✅ Imagen cargada. Lista para la API de visión.";
                    };
                    reader.readAsDataURL(file);
                } else if (file.type === 'text/plain') {
                    previewImg.style.display = 'none';
                    previewText.innerText = "📄 Archivo TXT cargado correctamente.";
                } else {
                    alert("Por favor, sube solo archivos de imagen (JPG, PNG) o de texto (.txt).");
                    fileInput.value = ""; // Limpiar
                    previewContainer.style.display = 'none';
                    dropIcon.style.display = 'block';
                    dropText1.style.display = 'block';
                    dropText2.style.display = 'block';
                }
            }
        }
    </script>
</body>
</html>
