// sesion_infinita.js
setInterval(function() {
    console.log("Manteniendo sesión activa...");
    fetch('../pulso.php')
        .then(response => {
            if(response.ok) console.log("Pulso exitoso.");
        })
        .catch(err => console.error("Error en pulso:", err));
}, 600000); 