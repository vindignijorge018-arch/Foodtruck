<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Prueba de Tamaños - Bargaiwe</title>
    <style>
    /* Ajuste para la página física */
    @page { 
        margin: 0; 
        size: 58mm auto; 
    }

    /* Ajuste para el contenido */
    body { 
        width: 48mm; /* Es mejor dejar 48mm para el contenido y evitar que se corte el texto */
        margin: 0 auto; 
        padding: 5px 0; /* Un poco de respiro arriba y abajo */
        font-family: 'Courier New', Courier, monospace;
        color: black;
        text-align: center;
        /* Evita que el navegador fuerce escalas raras */
        -webkit-print-color-adjust: exact; 
    }

    /* ESTO ES LO QUE TE FALTA: Limpia el ruido del navegador */
    @media print { 
        .no-imprimir { display: none !important; } 
        
        /* Asegura que no haya márgenes fantasma al imprimir */
        html, body {
            margin: 0;
            padding: 0;
        }
    }

    .linea { margin-bottom: 10px; border-bottom: 1px dashed black; padding-bottom: 5px; }
    .tam-1 { font-size: 10px; }
    .tam-2 { font-size: 12px; }
    .tam-3 { font-size: 14px; }
    
    .btn-accion { margin-top: 15px; padding: 12px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; width: 100%;}
</style>
</head>
<body>
    
    <div style="font-size: 14px; font-weight: bold; margin-bottom: 15px;">
        BARGAIWE<br>TEST DE TAMAÑOS
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

    <div class="no-imprimir" style="margin-top: 30px;">
        <button class="btn-accion" onclick="window.print()" style="background: #238636; color: white;">
            🖨️ ABRIR VENTANA DE IMPRESIÓN
        </button>
        
        <button class="btn-accion" onclick="window.close()" style="background: #E53935; color: white;">
            ❌ Cerrar
        </button>
    </div>

</body>
</html>