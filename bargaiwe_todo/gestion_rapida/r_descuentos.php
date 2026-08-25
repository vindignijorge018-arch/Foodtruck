<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['restaurant_id'])) { 
    header("Location: ../portal_bargaiwe.php"); 
    exit(); 
}

include 'r_db.php'; 
$mi_restaurant_id = (int)$_SESSION['restaurant_id'];

// --- OBTENER TEMA DESDE LA BASE DE DATOS ---
$res_tema = $conn->query("SELECT modo_global, color_cajero FROM config_temas WHERE restaurant_id = $mi_restaurant_id");
$tema = ($res_tema && $res_tema->num_rows > 0) ? $res_tema->fetch_assoc() : ['modo_global' => 'oscuro', 'color_cajero' => '#FF8C00'];

// --- 0. MAGIA: CREAR TABLA DE DESCUENTOS SI NO EXISTE ---
$conn->query("CREATE TABLE IF NOT EXISTS descuentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id INT NOT NULL,
    codigo VARCHAR(50) NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'porcentaje',
    valor DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    estado INT DEFAULT 1
)");

// Aseguramos que si la tabla ya existía con INT, se convierta a DECIMAL ahora
$conn->query("ALTER TABLE descuentos MODIFY COLUMN valor DECIMAL(10,2) NOT NULL");

// --- 1. CREAR NUEVO DESCUENTO ---
if (isset($_POST['crear_descuento'])) {
    $codigo = strtoupper(trim($conn->real_escape_string($_POST['codigo'])));
    $tipo = $conn->real_escape_string($_POST['tipo']);
    
    // MAGIA: Quitamos cualquier símbolo como %, $ o espacios para quedarnos solo con el número/punto
    $valor_limpio = str_replace(['%', '$', ' '], '', $_POST['valor']);
    // Convertimos comas a puntos por si el usuario escribe "12,7"
    $valor_limpio = str_replace(',', '.', $valor_limpio);
    $valor = floatval($valor_limpio);
    
    if ($tipo == 'porcentaje' && $valor > 100) { $valor = 100; }
    
    $check = $conn->query("SELECT id FROM descuentos WHERE codigo = '$codigo' AND restaurant_id = $mi_restaurant_id");
    
    if ($check->num_rows == 0) {
        $conn->query("INSERT INTO descuentos (restaurant_id, codigo, tipo, valor, estado) VALUES ($mi_restaurant_id, '$codigo', '$tipo', $valor, 1)");
        header("Location: r_descuentos.php?exito=1"); exit();
    } else {
        $error_cupon = "Ese código ya existe.";
    }
}

// --- 2. ACTIVAR / DESACTIVAR DESCUENTO ---
if (isset($_GET['toggle'])) {
    $id_mod = (int)$_GET['toggle'];
    $conn->query("UPDATE descuentos SET estado = IF(estado = 1, 0, 1) WHERE id = $id_mod AND restaurant_id = $mi_restaurant_id");
    header("Location: r_descuentos.php"); exit();
}

// --- 3. BORRAR DESCUENTO ---
if (isset($_GET['borrar'])) {
    $id_borrar = (int)$_GET['borrar'];
    $conn->query("DELETE FROM descuentos WHERE id = $id_borrar AND restaurant_id = $mi_restaurant_id");
    header("Location: r_descuentos.php"); exit();
}

// Consultar la lista de descuentos
$lista_descuentos = $conn->query("SELECT * FROM descuentos WHERE restaurant_id = $mi_restaurant_id ORDER BY estado DESC, id DESC");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Descuentos - Bargaiwe Fast</title>
    <style>
        /* Variables del Tema Fast Food */
        :root {
            --bg-body: #0d1117; --bg-panel: #161b22; --border: #30363d; --text: #c9d1d9; --text-title: #ffffff;
            --accent: <?php echo $tema['color_cajero']; ?>; 
            --danger: #E53935; --success: #32CD32;
        }
        
        <?php if($tema['modo_global'] === 'claro'): ?>
        body {
            --bg-body: #f0f2f5; --bg-panel: #ffffff; --border: #d0d7de; --text: #24292f; --text-title: #000000;
        }
        <?php endif; ?>

        body { background: var(--bg-body); color: var(--text); font-family: 'Segoe UI', sans-serif; margin: 0; transition: 0.3s; }
        
        .nav-hub { background: var(--bg-panel); border-bottom: 1px solid var(--border); padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; color: var(--text-title); }
        .nav-hub a { color: white; text-decoration: none; font-weight: bold; background: var(--bg-panel); padding: 10px 20px; border-radius: 8px; border: 1px solid var(--border); transition: 0.3s; }
        .nav-hub a:hover { border-color: var(--accent); color: var(--accent); }

        .container { padding: 40px; display: grid; grid-template-columns: 350px 1fr; gap: 40px; align-items: start; max-width: 1200px; margin: 0 auto;}
        
        .card { background: var(--bg-panel); padding: 30px; border-radius: 15px; border: 1px solid var(--border); height: auto; }
        h3 { color: var(--accent); margin-top: 0; border-bottom: 1px solid var(--border); padding-bottom: 10px; font-size: 1.4rem; }

        input, select { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid var(--border); border-radius: 8px; box-sizing: border-box; font-size: 1rem; background: var(--bg-body); color: var(--text); text-transform: uppercase;}
        input:focus, select:focus { outline: none; border-color: var(--accent); }
        
        .btn-crear { background: var(--accent); color: white; border: none; padding: 15px; border-radius: 8px; font-weight: bold; width: 100%; cursor: pointer; font-size: 1.1rem; transition: 0.2s; text-transform: uppercase; letter-spacing: 1px; margin-top: 15px;}
        .btn-crear:hover { opacity: 0.8; transform: translateY(-2px); }
        
        /* DISEÑO DE LOS CUPONES */
        .grid-cupones { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }
        
        .cupon { 
            background: var(--bg-body); 
            border: 2px dashed var(--border); 
            border-radius: 12px; 
            padding: 20px; 
            text-align: center;
            position: relative;
            transition: 0.3s;
        }
        
        .cupon.activo { border-color: var(--success); background: rgba(50, 205, 50, 0.05); }
        .cupon.inactivo { opacity: 0.6; filter: grayscale(100%); }

        .cupon-codigo { 
            font-size: 1.8rem; 
            font-weight: 900; 
            color: var(--text-title); 
            margin: 10px 0; 
            letter-spacing: 2px; 
            font-family: monospace;
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }
        .cupon-valor { font-size: 1.2rem; color: var(--accent); font-weight: bold; margin-bottom: 15px;}
        
        .badge-estado { padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; text-transform: uppercase; }
        .badge-activo { background: var(--success); color: #014421; }
        .badge-inactivo { background: var(--border); color: var(--text); }

        .btn-acciones { display: flex; justify-content: center; gap: 10px; margin-top: 15px; }
        .btn-toggle { background: var(--bg-panel); border: 1px solid var(--border); color: var(--text); padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; text-decoration: none; font-size: 0.9rem;}
        .btn-toggle:hover { border-color: var(--text); color: var(--text-title);}
        .btn-borrar { background: rgba(229, 57, 53, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 8px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; text-decoration: none; font-size: 0.9rem;}
        .btn-borrar:hover { background: var(--danger); color: white;}
    </style>
</head>
<body>



    <div class="nav-hub">
        <span style="font-size: 1.8rem; font-weight: 800; color: var(--accent);">🏷️ Fast Descuentos</span>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="r_cocina.php" style="border-color: var(--accent); color: var(--accent);">👨‍🍳 Cocina</a>
            
            <?php if (isset($_SESSION['nivel_plan']) && $_SESSION['nivel_plan'] === 'plus'): ?>
                <a href="r_stats.php" style="border-color: var(--success); color: var(--success);">📊 Estadísticas</a>
            <?php endif; ?>
            <a href="r_pedidos.php">← Volver al Cajero</a>
        </div>
    </div>

    <div class="container">
        
        <div class="card">
            <h3>Nuevo Descuento</h3>
            
            <?php if(isset($error_cupon)): ?>
                <div style="background: rgba(229, 57, 53, 0.1); border: 1px solid var(--danger); color: var(--danger); padding: 10px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-weight: bold;">
                    ⚠️ <?php echo $error_cupon; ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_GET['exito'])): ?>
                <div style="background: rgba(50, 205, 50, 0.1); border: 1px solid var(--success); color: var(--success); padding: 10px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-weight: bold;">
                    ✅ Cupón creado con éxito.
                </div>
            <?php endif; ?>

            <form method="POST">
                <label style="font-weight: bold; font-size: 0.85rem; color: var(--text-title);">CÓDIGO DEL CUPÓN:</label>
                <input type="text" name="codigo" placeholder="Ej: VERANO20" required maxlength="20">
                <span style="font-size: 0.8rem; color: #8b949e; display: block; margin-top: -5px; margin-bottom: 15px;">Se guardará siempre en MAYÚSCULAS.</span>
                
                <label style="font-weight: bold; font-size: 0.85rem; color: var(--text-title);">TIPO DE REBAJA:</label>
                <select name="tipo" id="tipoSelect" onchange="cambiarPlaceholder()">
                    <option value="porcentaje">📉 Porcentaje (%)</option>
                    <option value="fijo">💵 Dinero Fijo ($)</option>
                </select>

                <label style="font-weight: bold; font-size: 0.85rem; color: var(--text-title);">VALOR:</label>
                <input type="text" name="valor" id="valorInput" placeholder="Ej: 12.7%" required>
                <button type="submit" name="crear_descuento" class="btn-crear">Generar Cupón</button>
            </form>
        </div>

        <div class="card">
            <h3>Mis Cupones Activos</h3>
            
            <?php if($lista_descuentos->num_rows == 0): ?>
                <p style="text-align: center; color: #8b949e; padding: 40px;">No tienes cupones creados.<br>¡Crea promociones para atraer más clientes!</p>
            <?php else: ?>
                <div class="grid-cupones">
                    <?php while($d = $lista_descuentos->fetch_assoc()): 
                      $es_activo = ($d['estado'] == 1);
                      $clase_cupon = $es_activo ? 'activo' : 'inactivo';
    
                      // --- LÓGICA DE FORMATEO INTELIGENTE ---
                      // floatval quita los ceros sobrantes a la derecha (ej: 12.70 -> 12.7)
                      $valor_limpio = floatval($d['valor']); 
    
                      if ($d['tipo'] == 'porcentaje') {
                     // Formateamos con coma para que sea más natural en español (opcional)
                        $texto_valor = "-" . str_replace('.', ',', $valor_limpio) . "% de Dcto.";
                      } else {
        // Para dinero fijo, mantenemos el formato de miles sin decimales
                        $texto_valor = "-$" . number_format($d['valor'], 0, ',', '.') . " CLP";
                      }
                  ?>
                      <div class="cupon <?php echo $clase_cupon; ?>">
        
                          <?php if($es_activo): ?>
                              <span class="badge-estado badge-activo">Activo</span>
                          <?php else: ?>
                              <span class="badge-estado badge-inactivo">Apagado</span>
                          <?php endif; ?>

                          <div class="cupon-codigo"><?php echo htmlspecialchars($d['codigo']); ?></div>
                          <div class="cupon-valor"><?php echo $texto_valor; ?></div>

                          <div class="btn-acciones">
                            <a href="?toggle=<?php echo $d['id']; ?>" class="btn-toggle">
                                 <?php echo $es_activo ? 'Pausar' : 'Activar'; ?>
                            </a>
                            <a href="?borrar=<?php echo $d['id']; ?>" class="btn-borrar" onclick="return confirm('¿Borrar este cupón definitivamente?')">🗑️</a>
                          </div>
                      </div>
                  <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        function cambiarPlaceholder() {
            var tipo = document.getElementById('tipoSelect').value;
            var input = document.getElementById('valorInput');
            if (tipo === 'porcentaje') {
                input.placeholder = "Ej: 20 (Para un 20%)";
            } else {
                input.placeholder = "Ej: 1500 (Para descontar $1.500)";
            }
        }
    </script>
</body>
</html>