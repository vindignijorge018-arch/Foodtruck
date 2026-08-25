<?php
session_start();
$estado_cliente = 'Nuevo'; // Por defecto, asumimos que no es cliente

if (isset($_SESSION['restaurant_id'])) {
    include '../gestion_restaurante/db.php'; 
    $id_rest = (int)$_SESSION['restaurant_id'];
    
    $check_plan = $conn->query("SHOW COLUMNS FROM restaurantes LIKE 'plan'");
    if ($check_plan->num_rows == 0) {
        $conn->query("ALTER TABLE restaurantes ADD COLUMN plan VARCHAR(50) DEFAULT 'Estandar'");
    }

    $res = $conn->query("SELECT plan FROM restaurantes WHERE id = $id_rest");
    if ($res && $row = $res->fetch_assoc()) {
        $estado_cliente = $row['plan'] ? $row['plan'] : 'Estandar';
    } else {
        $estado_cliente = 'Estandar'; // Fallback
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Planes Food Truck - Bargaiwe Fast</title>
    <style>
        /* BOTÓN VOLVER */
        .btn-volver {
            position: absolute;
            top: 20px;
            left: 30px;
            color: #8b949e;
            text-decoration: none;
            font-weight: bold;
            font-size: 0.95rem;
            padding: 8px 15px;
            border-radius: 8px;
            border: 1px solid transparent;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 100;
        }

        .btn-volver:hover {
            color: white;
            background: rgba(255,255,255,0.05);
            border: 1px solid #30363d;
            transform: translateX(-5px);
        }
        
        :root { 
            /* IDENTIDAD FAST FOOD: ROJO Y NARANJA */
            --primario: #E53935; /* Rojo Intenso */
            --secundario: #FF8C00; /* Naranja Acción */
            --fondo: #0d1117; 
            --tarjeta: #161b22; 
            --texto: #c9d1d9; 
            --premium-glow: rgba(229, 57, 53, 0.4);
        }

        body { 
            background: var(--fondo); 
            color: var(--texto); 
            font-family: 'Segoe UI', sans-serif; 
            margin: 0;
            padding: 40px 5%; 
            transition: background 0.8s ease-in-out;
            min-height: 100vh;
        }

        /* EFECTO TIRA LED ROJA (Solo cuando es Premium) */
        body.modo-premium {
            background: linear-gradient(180deg, #8B0000 0%, #0d1117 35%); /* Rojo Oscuro (Sangre) al fondo normal */
        }

        .header-section { text-align: center; margin-bottom: 50px; }
        h1 { color: white; font-size: 2.5rem; margin-bottom: 10px; }

        /* BOTÓN TOGGLE PLUS+ */
        .toggle-container {
            display: flex;
            justify-content: center;
            margin-bottom: 40px;
        }

        .btn-plus {
            background: var(--primario);
            color: white;
            border: 2px solid #FF5252; /* Rojo más brillante para el borde */
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 900;
            cursor: pointer;
            font-size: 1.1rem;
            box-shadow: 0 0 15px rgba(229, 57, 53, 0.3);
            transition: 0.3s;
        }

        .btn-plus:hover {
            box-shadow: 0 0 25px rgba(229, 57, 53, 0.6);
            transform: scale(1.05);
        }

        /* GRID Y ANIMACIÓN DE CASILLAS */
        .grid-planes { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); 
            gap: 25px; 
            max-width: 900px;
            margin: auto;
        }

        .card { 
            background: var(--tarjeta); 
            padding: 30px; 
            border-radius: 20px; 
            border: 1px solid #30363d; 
            text-align: center; 
            display: flex; 
            flex-direction: column;
            transition: 0.5s;
            animation: fadeIn 0.5s;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* DISEÑO PREMIUM */
        .card.premium-style {
            border: 1px solid #E53935; /* Borde rojo */
            box-shadow: 0 10px 30px rgba(229, 57, 53, 0.2);
            background: rgba(22, 27, 34, 0.8);
            backdrop-filter: blur(10px);
        }

        h3 { color: white; font-size: 1.5rem; margin-top: 0; }
        .precio-grande { font-size: 2.5rem; font-weight: 900; color: white; margin: 15px 0; }
        .regalo { color: #FF5252; font-weight: bold; font-size: 0.95rem; height: 24px; margin-bottom: 20px; }

        select { 
            width: 100%; padding: 12px; 
            background: #0d1117; color: white; 
            border: 1px solid #30363d; border-radius: 8px; 
            margin-bottom: 20px; font-weight: bold;
        }

        ul { text-align: left; font-size: 0.9rem; padding: 0; list-style: none; flex-grow: 1; margin-bottom: 25px; }
        ul li { margin-bottom: 10px; color: #8b949e; }
        ul li::before { content: "✓ "; color: #FF5252; font-weight: bold; } /* Ticks rojos */

        .btn-pago { 
            background: var(--secundario); 
            color: white; 
            text-decoration: none; 
            padding: 15px; 
            border-radius: 10px; 
            font-weight: 900; 
            transition: 0.3s;
            text-transform: uppercase;
        }

        .btn-pago:hover { background: #ff9d2e; box-shadow: 0 5px 15px rgba(255, 140, 0, 0.4); }

        .oculto { display: none; }
    </style>
</head>
<body id="cuerpo">
    <a href="index.html" class="btn-volver">← Volver al Inicio</a>

    <div class="header-section">
        <h1 id="titulo">Planes Rápida y Para Llevar</h1>
        <p id="subtitulo">Velocidad y control para negocios al paso.</p>
    </div>

    <div class="toggle-container">
        <button class="btn-plus" onclick="togglePlanes()" id="btnSwitch">VER PLANES FAST PLUS +</button>
    </div>

    <div class="grid-planes" id="gridEstandar">
        <div class="card">
            <h3>Estándar (Meses)</h3>
            <p>Velocidad en la caja</p>
            <select id="select1" onchange="calcular(1, 6800)">
                <option value="1">1 Mes</option>
                <option value="3">3 Meses</option>
                <option value="6">6 Meses</option>
                <option value="8">8 Meses</option>
                <option value="10">10 Meses</option>
                <option value="12">12 Meses</option>
            </select>
            <div class="precio-grande" id="p1">$8.000</div>
            <div class="regalo" id="r1">+5 días GRATIS</div>
            <ul style="text-align: left; margin-bottom: 20px;">
                <li> Conexión instantánea Caja-Cocina</li>
                <li> Monitor de Tiempos de Espera</li>
                <li> Pantalla Pública de "Pedidos Listos"</li>
                <li> Congelación de Suscripción (Pausa tu mes)</li>
                <li> Soporte Estándar vía Ticket</li>
            </ul>
            <a href="#" class="btn-pago" id="btn1">ELEGIR ESTE PLAN</a>
        </div>

        <div class="card">
            <h3>Estándar (Años)</h3>
            <p>Ahorro máximo por tiempo</p>
            <select id="select2" onchange="calcular(2, 6800)">
                <option value="1">1 Año</option>
                <option value="2">2 Años</option>
                <option value="3">3 Años</option>
                <option value="4">4 Años</option>
                <option value="5">5 Años</option>
            </select>
            <div class="precio-grande" id="p2">$96.000</div>
            <div class="regalo" id="r2">Precio preferencial</div>
            <ul style="text-align: left; margin-bottom: 20px;">
                <li> <b>Todo lo del plan mensual</b></li>
                <li> Ahorro progresivo por fidelidad</li>
                <li> Soporte Técnico Prioritario</li>
            </ul>
            <a href="#" class="btn-pago" id="btn2">ASEGURAR AÑO</a>
        </div>
    </div>

    <div class="grid-planes oculto" id="gridPremium">
        <div class="card premium-style">
            <h3>Fast Plus (Meses)</h3>
            <p>Datos puros para crecer</p>
            <select id="select3" onchange="calcular(3, 8000)">
                <option value="8d">8 Días de Prueba (Gratis)</option>
                <option value="1">1 Mes</option>
                <option value="3">3 Meses</option>
                <option value="6">6 Meses</option>
                <option value="8">8 Meses</option>
                <option value="10">10 Meses</option>
                <option value="12">12 Meses</option>
            </select>
            <div class="precio-grande" id="p3">$0</div>
            <div class="regalo" id="r3">Sin tarjeta de crédito</div>
            <ul style="text-align: left; margin-bottom: 20px;">
                <li>⭐ <b>Todo lo del Plan Estándar, MÁS:</b></li>
                <li>🧠 <b>Control de Costos:</b> Calcula tu ganancia neta real.</li>
                <li>📊 <b>Analítica Avanzada:</b> Gráficos de ventas y demanda.</li>
                <li>🤖 <b>Importador IA:</b> Sube tu menú digital con una foto.</li>
                <li>🎯 <b>Panel de Metas:</b> Fija y mide objetivos diarios.</li>
            </ul>
            <a href="/bargaiwe/registro_gratis.php?plan=Plus&dias=8" class="btn-pago" id="btn3" style="background: #32CD32; box-shadow: 0 5px 15px rgba(50, 205, 50, 0.4);">COMENZAR PRUEBA GRATIS</a>
        </div>

        <div class="card premium-style">
            <h3>Fast Plus (Años)</h3>
            <p>Control Total de Inversión</p>
            <select id="select4" onchange="calcular(4, 8000)">
                <option value="1">1 Año</option>
                <option value="2">2 Años</option>
                <option value="3">3 Años</option>
                <option value="4">4 Años</option>
                <option value="5">5 Años</option>
            </select>
            <div class="precio-grande" id="p4">$132.000</div>
            <div class="regalo" id="r4">Precio preferencial</div>
            <ul style="text-align: left; margin-bottom: 20px;">
                <li>🏆 <b>Todo el poder del Plan Plus</b></li>
                <li>💰 Mayor porcentaje de descuento del sistema</li>
                <li>🛡️ Respaldo prioritario de Base de Datos</li>
                <li>❄️ Congelaciones de cuenta ilimitadas</li>
            </ul>
            <a href="#" class="btn-pago" id="btn4">ASEGURAR AÑO</a>
        </div>
    </div>

    <script>
        // la mente del servidor (PHP a JS)
        const estado_cliente = "<?php echo $estado_cliente; ?>";

        function togglePlanes() {
            const body = document.getElementById('cuerpo');
            const estandar = document.getElementById('gridEstandar');
            const premium = document.getElementById('gridPremium');
            const btn = document.getElementById('btnSwitch');
            const titulo = document.getElementById('titulo');
            const subtitulo = document.getElementById('subtitulo');

            if (premium.classList.contains('oculto')) {
                estandar.classList.add('oculto');
                premium.classList.remove('oculto');
                body.classList.add('modo-premium');
                btn.innerText = "← VOLVER A BÁSICO";
                btn.style.background = "#8C8C8C";
                btn.style.borderColor = "#8C8C8C";
                titulo.innerText = "Bargaiwe Fast Intelligence";
                subtitulo.innerText = "Análisis de datos avanzado para comida al paso.";
            } else {
                premium.classList.add('oculto');
                estandar.classList.remove('oculto');
                body.classList.remove('modo-premium');
                btn.innerText = "VER PLANES FAST PLUS +";
                btn.style.background = "var(--primario)";
                btn.style.borderColor = "#FF5252";
                titulo.innerText = "Planes Rápida y Para Llevar";
                subtitulo.innerText = "Velocidad y control para negocios al paso.";
            }
        }

        function configurarBotones() {
            if (estado_cliente === 'Nuevo') {
                document.getElementById('btn1').innerText = "ELEGIR ESTE PLAN";
                document.getElementById('btn2').innerText = "ASEGURAR AÑO";
                document.getElementById('btn3').innerText = "ELEGIR ESTE PLAN";
                document.getElementById('btn4').innerText = "ASEGURAR AÑO";
            } else if (estado_cliente === 'Estandar') {
                document.getElementById('btn1').innerText = "AGREGAR MES";
                document.getElementById('btn2').innerText = "AGREGAR AÑO";
                document.getElementById('btn3').innerText = "SUBIR DE NIVEL";
                document.getElementById('btn4').innerText = "ASEGURAR AÑO PLUS";
            } else if (estado_cliente === 'Premium') {
                document.getElementById('btn1').innerText = "AGREGAR MES NORMAL";
                document.getElementById('btn2').innerText = "AGREGAR AÑO NORMAL";
                document.getElementById('btn3').innerText = "AGREGAR MES PLUS";
                document.getElementById('btn4').innerText = "AGREGAR AÑO PLUS";
            }
        }

        function calcular(id, base) {
            let val = parseInt(document.getElementById('select' + id).value);
            let precio = 0; let regalo = ""; let mesesTotales = 0;
            let precio_original = 0; let tiempo_total = ""; 
            // En el archivo de Restaurante se llama 'Premium', en el de Fast Food 'Plus'. Lo adaptamos.
            let nombrePlan = (id === 1 || id === 2) ? 'Estandar' : (document.title.includes('Fast') ? 'Plus' : 'Premium');

            if (id === 1 || id === 3) { 
                let val = document.getElementById('select' + id).value;
                let btn = document.getElementById('btn' + id);
                
                // LÓGICA NUEVA: Si eligen la prueba gratis
                if (val === '8d') {
                    precio = 0;
                    regalo = "Sin tarjeta de crédito";
                    tiempo_total = "8 días";
                    
                    document.getElementById('p' + id).innerText = "GRATIS";
                    document.getElementById('r' + id).innerText = regalo;
                                
                    // Botón Verde y redirige al registro gratis (Saltando Mercado Pago)
                    btn.innerText = "COMENZAR PRUEBA GRATIS";
                    btn.style.background = "#32CD32";
                    btn.style.boxShadow = "0 5px 15px rgba(50, 205, 50, 0.4)";
                    btn.href = "/bargaiwe/registro_gratis.php?plan=" + nombrePlan + "&dias=8"; // <-- ¡Aquí estaba el detalle!
                    return; // Terminamos la función aquí
                }
                
                // Si eligen meses normales 
                val = parseInt(val);
                mesesTotales = val;
                precio_original = val * base;
                
                const tablaDesc = { 1: 0.20, 3: 0.23, 6: 0.27, 8: 0.30, 10: 0.31, 12: 0.32 };
                let desc = tablaDesc[val] || 0.20; 
                
                precio = precio_original * (1 - desc);
                regalo = "¡Descuento de " + Math.round(desc * 100) + "% incluido!";
                tiempo_total = val === 1 ? "1 mes" : val + " meses";
                
                // Restauramos el diseño del botón para pagos
                btn.innerText = "ELEGIR ESTE PLAN";
                btn.style.background = "var(--secundario)";
                btn.style.boxShadow = "none";
            } else {
                // 🟡 LÓGICA DE AÑOS (Ahorro por Meses Gratis Adicionales)
                let mesesBase = val * 12;
                precio_original = mesesBase * base;
                precio = precio_original; // Pagan el precio completo del año(s)
                
                // Tabla exacta de meses de regalo: 1a:+1m, 2a:+2m, 3a:+4m, 4a:+6m, 5a:+10m
                const tablaExtra = { 1: 1, 2: 2, 3: 4, 4: 6, 5: 10 };
                let extra = tablaExtra[val] || 0;
                
                mesesTotales = mesesBase + extra;
                
                let textoExtra = extra === 1 ? " mes GRATIS" : " meses GRATIS";
                regalo = "+" + extra + textoExtra;
                
                let textoAnio = val === 1 ? "1 año" : val + " años";
                let textoMes = extra === 1 ? "1 mes" : extra + " meses";
                tiempo_total = `${textoAnio} y ${textoMes}`;
            }

            // Actualizamos la pantalla visualmente
            precio = Math.round(precio);
            document.getElementById('p' + id).innerText = "$" + precio.toLocaleString('es-CL');
            document.getElementById('r' + id).innerText = regalo;

            // Elegimos el archivo de destino según si es Rápida o Restaurante
            let url_destino = document.title.includes('Fast') ? 'r_registro_pago.php' : 'registro_pago.php';

            let btn = document.getElementById('btn' + id);
            if(btn) {
                 btn.href = `${url_destino}?plan=${nombrePlan}&meses=${mesesTotales}&precio=${precio}&original=${precio_original}&beneficio=${encodeURIComponent(regalo)}&tiempo=${encodeURIComponent(tiempo_total)}`;

            }
        }

        document.addEventListener("DOMContentLoaded", function() {
            configurarBotones(); 
            calcular(1, 6800);  // Estándar Meses
            calcular(2, 6800);  // Estándar Años
            calcular(3, 8000);  // Plus Meses
            calcular(4, 8000);  // Plus Años
        });
    </script>
</body>
</html>