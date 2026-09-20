<?php
// scanner_studente.php - Lettore QR Code interno per gli studenti

// FASE 4: INCLUSIONE MIDDLEWARE
require_once 'middleware.php';
require_once 'header.php'; 
?>
<div class="container mt-4 mb-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6 text-center">
            
            <h2 class="fw-bold mb-3" style="color: #B30000;">
                <i class="fa fa-camera me-2"></i> Inquadra il QR Code
            </h2>
            <p class="fs-5 text-muted mb-4">Centra il codice fornito dal docente nel riquadro qui sotto per registrare la tua presenza.</p>
            
            <div class="card shadow-sm border-0 mb-4 p-2 bg-white">
                <div id="reader" width="100%" style="border-radius: 8px; overflow: hidden;"></div>
            </div>
            
            <a href="area_personale.php" class="btn btn-outline-secondary fw-bold">
                <i class="fa fa-arrow-left me-1"></i> Torna indietro
            </a>
            
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    function onScanSuccess(decodedText, decodedResult) {
        html5QrcodeScanner.clear();
        
        // Verifica di sicurezza: è un nostro QR Code?
        if (decodedText.indexOf('self_checkin.php') !== -1) {
            document.getElementById('reader').innerHTML = "<div class='p-5'><i class='fa fa-spinner fa-spin fa-3x text-danger mb-3'></i><h4>Registrazione in corso...</h4></div>";
            window.location.replace(decodedText);
        } else {
            alert("Attenzione: Questo QR Code non appartiene al sistema Eventi DiBEST.");
            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        }
    }
    function onScanFailure(error) { }

    let html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: {width: 250, height: 250}, aspectRatio: 1.0 }, false);
    html5QrcodeScanner.render(onScanSuccess, onScanFailure);
</script>

<?php require_once 'footer.php'; ?>
