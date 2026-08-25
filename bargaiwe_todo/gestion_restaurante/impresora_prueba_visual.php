<?php
session_start();
include 'db.php';

$titulo = "MI NEGOCIO";
if (isset($_SESSION['restaurant_id'])) {
    $mi_restaurant_id = (int)$_SESSION['restaurant_id'];
    $res = $conn->query("SELECT titulo_ticket FROM config_impresora WHERE restaurant_id = $mi_restaurant_id");
    if ($res && $row = $res->fetch_assoc()) {
        $titulo = $row['titulo_ticket'];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Prueba Visual - Ticketera</title>
    <style>
        /* === RESET PARA IMPRESORAS TÉRMICAS DE 58mm === */
        @media print { 
            @page { margin: 0; size: 58mm auto; } 
            html, body { margin: 0; padding: 0; width: 58mm; background: white; } 
            .no-imprimir { display: none !important; }
        }
        
        body { 
            font-family: 'Courier New', Courier, monospace; 
            width: 100%; 
            max-width: 58mm; 
            margin: 0 auto; 
            padding: 0 3mm; 
            box-sizing: border-box; 
            color: black;
            background: white;
            text-align: center;
        }
        
        .linea { margin-bottom: 10px; border-bottom: 1px dashed black; padding-bottom: 5px; }
        .tam-1 { font-size: 10px; }
        .tam-2 { font-size: 12px; }
        .tam-3 { font-size: 14px; }
        
        .btn-accion { margin-top: 15px; padding: 12px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; width: 100%; font-size: 14px;}
    </style>
</head>
<body>
    
    <div style="font-size: 9px; margin-bottom: 5px; margin-top: 5px;">
        --------------------------------
    </div>

    <div style="font-size: 16px; font-weight: bold; margin-bottom: 15px; text-transform: uppercase;">
        <?php echo htmlspecialchars($titulo); ?><br>
        <span style="font-size: 12px; font-weight: normal;">TEST DE TAMAÑOS</span>
    </div>

    <div class="linea tam-1">
        Tamaño 1 (Pequeña)<br>AaBbCc = 1<br>1234567890
    </div>

    <div class="linea tam-2">
        Tamaño 2 (Normal)<br>AaBbCc = 2<br>1234567890
    </div>

    <div class="linea tam-3">
        Tamaño 3 (Grande)<br>AaBbCc = 3<br>1234567890
    </div>

    <div class="no-imprimir" style="margin-top: 30px; padding: 10px; border-top: 1px solid #ccc; background: #f9f9f9;">
        <button class="btn-accion" onclick="window.print()" style="background: #238636; color: white;">
            🖨️ IMPRIMIR AHORA
        </button>
        
        <button class="btn-accion" onclick="window.close()" style="background: #E53935; color: white;">
            ❌ Cerrar Ventana
        </button>
    </div>

</body>
</html>