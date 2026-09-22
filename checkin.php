<?php
// =========================================================================
// Fase 4: Autenticazione e variabili ruolo centralizzate in middleware.php
// $u_id, $u_ruolo, $is_full_admin, $is_gestore, $user_info
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

// Creazione colonna se manca (guard idempotente)
@$conn->query("ALTER TABLE prenotazioni ADD COLUMN IF NOT EXISTS presente INT DEFAULT 0 AFTER stato");

// CONTROLLO ACCESSO (Solo Admin o Gestori)
require_admin_or_gestore(); // helper definito in middleware.php

$code = isset($_GET['code']) ? trim($_GET['code']) : '';
$msg_esito = "";
$esito_classe = "";

// SE È STATO SCANSIONATO UN CODICE, ELABORA IL CHECK-IN
if (!empty($code)) {
    $p = get_prenotazione_per_checkin_admin($conn, $code);

    if ($p !== null) {
        
        // ==========================================
        // VERIFICA "PARAOCCHI" (RBAC) SULL'EVENTO
        // ==========================================
        $is_authorized = $is_full_admin;
        if (!$is_authorized) {
            // Controlla vecchio campo CSV
            $allowed_ids = array_filter(explode(',', $p['ev_gestori'] ?? ''));
            $pg_ids = array_filter(explode(',', $p['pg_gestori'] ?? ''));
            if ($p['pg_gestore_singolo']) $pg_ids[] = $p['pg_gestore_singolo'];
            if (in_array((string)$u_id, array_merge($allowed_ids, $pg_ids))) {
                $is_authorized = true;
            }
            // Controlla nuovo campo permessi_gestori_json (sistema abilitazioni)
            if (!$is_authorized) {
                $ev_json = json_decode($p['ev_permessi_json'] ?? '{}', true) ?: [];
                $pg_json = json_decode($p['pg_permessi_json'] ?? '{}', true) ?: [];
                $uid_str = (string)$u_id;
                if (
                    (isset($ev_json[$uid_str]) && in_array('iscritti', $ev_json[$uid_str])) ||
                    (isset($pg_json[$uid_str]) && in_array('iscritti', $pg_json[$uid_str]))
                ) {
                    $is_authorized = true;
                }
            }
        }

        if (!$is_authorized) {
            $esito_classe = "alert-danger";
            $msg_esito = "❌ <strong>ACCESSO NEGATO</strong><br>Non hai i permessi per gestire il check-in dell'evento: <br><em>{$p['evento_titolo']}</em>.";
        } elseif ($p['stato'] !== 'confermata') {
            $esito_classe = "alert-danger";
            $msg_esito = "❌ <strong>INGRESSO NEGATO</strong><br>La prenotazione non è confermata (Stato: {$p['stato']}).";
        } elseif ($p['presente'] == 1) {
            $esito_classe = "alert-warning";
            $msg_esito = "⚠️ <strong>GIÀ REGISTRATO</strong><br>Questo biglietto è già stato scansionato in precedenza.";
        } else {
            // Aggiorna come presente
            $conn->query("UPDATE prenotazioni SET presente = 1, data_presenza = NOW() WHERE id = " . $p['id']);
            invia_email_attestato_se_concluso($conn, $p['id']);
            $esito_classe = "alert-success";
            $msg_esito = "✅ <strong>INGRESSO CONSENTITO</strong><br>Utente: <strong>{$p['nome']} {$p['cognome']}</strong><br>Evento: {$p['evento_titolo']}";
        }
    } else {
        $esito_classe = "alert-danger";
        $msg_esito = "❌ <strong>ERRORE CRITICO</strong><br>Biglietto non trovato o codice non valido!";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scanner Ingressi - EventiDiBEST</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style> body { background-color: #f1f5f9; } #reader { width: 100%; border: 3px dashed #0d6efd; border-radius: 12px; overflow: hidden; } </style>
</head>
<body>
    <div class="container py-4" style="max-width: 500px;">
        <h3 class="fw-bold text-primary text-center mb-4"><i class="fa fa-qrcode me-2"></i> Scanner Ingressi</h3>

        <?php if (!empty($msg_esito)): ?>
            <div class="alert <?php echo $esito_classe; ?> text-center p-4 shadow-sm border-0 rounded-4 mb-4">
                <div class="fs-5"><?php echo $msg_esito; ?></div>
            </div>
            <div class="text-center">
                <a href="checkin.php" class="btn btn-primary btn-lg fw-bold px-5 py-3 shadow-sm rounded-pill w-100">
                    <i class="fa fa-camera me-2"></i> Nuova Scansione
                </a>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0 rounded-4 p-3 mb-4">
                <p class="text-center fw-bold text-secondary mb-3">Inquadra il QR Code sul biglietto dello studente</p>
                <div id="reader"></div>
            </div>
            
            <div class="text-center mt-3">
                <a href="admin/index.php" class="btn btn-outline-secondary fw-bold rounded-pill"><i class="fa fa-arrow-left me-1"></i> Torna ad Admin</a>
            </div>

            <script>
                function onScanSuccess(decodedText, decodedResult) {
                    html5QrcodeScanner.clear(); // Ferma la fotocamera
                    // Esegui il redirect automatico al link scansionato
                    window.location.href = decodedText;
                }
                let html5QrcodeScanner = new Html5QrcodeScanner("reader", { fps: 10, qrbox: {width: 250, height: 250} }, false);
                html5QrcodeScanner.render(onScanSuccess);
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
