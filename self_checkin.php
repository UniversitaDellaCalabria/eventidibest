<?php
// self_checkin.php - Motore di Auto-Registrazione (Versione Autenticata)
require_once 'header.php'; 

// Sicurezza: lo studente deve arrivare qui già loggato!
if (empty($_SESSION['utente_id'])) {
    echo "<script>window.location.replace('saml_login.php');</script>";
    exit;
}

// Auto-Patch database
@$conn->query("ALTER TABLE prenotazioni ADD COLUMN data_presenza DATETIME NULL");

$t_id = (int)($_GET['t'] ?? 0);
$token = $conn->real_escape_string($_GET['k'] ?? '');
$u_id = (int)$_SESSION['utente_id'];
$esito = ""; $msg = ""; $colore = ""; $icona = "";

if ($t_id === 0 || empty($token)) {
    $esito = "error";
    $msg = "Dati del QR Code mancanti o incompleti. Prova a ripetere la scansione.";
    $colore = "danger"; $icona = "fa-qrcode";
} else {
    $sql_check = "SELECT t.*, e.titolo as evento_titolo, e.luogo 
                  FROM turni t 
                  JOIN eventi e ON t.evento_id = e.id 
                  WHERE t.id = $t_id AND t.token_checkin = '$token' LIMIT 1";
    $res_check = $conn->query($sql_check);

    if (!$res_check || $res_check->num_rows === 0) {
        $esito = "error";
        $msg = "QR Code non valido o scaduto. La segreteria potrebbe aver ruotato il codice di sicurezza.";
        $colore = "danger"; $icona = "fa-times-circle";
    } else {
        $turno = $res_check->fetch_assoc();
        
        $inizio_ts = strtotime($turno['data_turno'] . ' ' . $turno['orario_inizio']) - (30 * 60);
        $fine_ts = strtotime($turno['data_turno'] . ' ' . $turno['orario_fine']);
        $now = time();
        
        if ($now < $inizio_ts) {
            $esito = "warning"; $colore = "warning"; $icona = "fa-clock";
            $msg = "È troppo presto per registrarsi! Il check-in aprirà 30 minuti prima dell'inizio.";
        } elseif ($now > $fine_ts) {
            $esito = "error"; $colore = "secondary"; $icona = "fa-calendar-times";
            $msg = "Il periodo per registrare la presenza a questo evento è terminato.";
        } else {
            $sql_pren = "SELECT id, stato, presente FROM prenotazioni WHERE turno_id = $t_id AND utente_id = $u_id LIMIT 1";
            $res_pren = $conn->query($sql_pren);
            
            if (!$res_pren || $res_pren->num_rows === 0) {
                $esito = "error"; $colore = "danger"; $icona = "fa-user-times";
                $msg = "Non risulti iscritto a questo turno. Devi prima effettuare la prenotazione.";
            } else {
                $pren = $res_pren->fetch_assoc();
                
                if ($pren['stato'] !== 'confermata') {
                    $esito = "error"; $colore = "danger"; $icona = "fa-exclamation-triangle";
                    $msg = "La tua iscrizione non è confermata (Stato: " . strtoupper($pren['stato']) . ").";
                } elseif ($pren['presente'] == 1) {
                    $esito = "success"; $colore = "success"; $icona = "fa-check-double";
                    $msg = "La tua presenza era già stata registrata. Nessuna ulteriore azione richiesta.";
                } else {
                    $conn->query("UPDATE prenotazioni SET presente = 1, data_presenza = NOW() WHERE id = " . $pren['id']);
                    $esito = "success"; $colore = "success"; $icona = "fa-check-circle";
                    $msg = "Check-in completato con successo! Presenza convalidata ufficialmente.";
                }
            }
        }
    }
}
?>

<div class="row justify-content-center mt-4 mb-5">
    <div class="col-md-8 col-lg-6">
        <div class="card shadow-lg border-0 rounded-4 text-center overflow-hidden">
            <div class="bg-<?php echo $colore; ?> py-4">
                <i class="fa <?php echo $icona; ?> mb-2 text-white" style="font-size: 4rem;"></i>
                <h2 class="fw-bold m-0 text-white"><?php echo $esito === 'success' ? 'Operazione Riuscita' : 'Attenzione'; ?></h2>
            </div>
            <div class="card-body p-4 bg-white">
                <?php if(!empty($turno['evento_titolo'])): ?>
                    <h4 class="fw-bold text-dark mb-3 border-bottom pb-3"><?php echo htmlspecialchars($turno['evento_titolo']); ?></h4>
                <?php endif; ?>
                <p class="fs-5 text-dark mb-4"><?php echo $msg; ?></p>
                <div class="d-grid gap-2 mt-4">
                    <a href="area_personale.php" class="btn btn-outline-<?php echo $colore; ?> btn-lg fw-bold rounded-3">
                        <i class="fa fa-id-card me-2"></i> Torna alla tua Area
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
if (file_exists('footer.php')) { require_once 'footer.php'; } 
else { echo '</main></body></html>'; } 
?>
