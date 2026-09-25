<?php
// admin/stampa_qr_aula.php - Versione con Admin UI ma Stampa Pulita
require_once 'admin_header.php';

$t_id = isset($_GET['t_id']) ? (int)$_GET['t_id'] : 0;
if ($t_id === 0) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Nessun turno selezionato.</div></div>";
    require_once 'admin_footer.php';
    exit;
}

// Creazione colonna Token se mancante
$check_col = $conn->query("SHOW COLUMNS FROM turni LIKE 'token_checkin'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE turni ADD COLUMN token_checkin VARCHAR(64) NULL");
}

// Ruota token
if (isset($_GET['rotate']) && $_GET['rotate'] == 1) {
    $new_token = bin2hex(random_bytes(16));
    $conn->query("UPDATE turni SET token_checkin = '$new_token' WHERE id = $t_id");
    flash_set("QR Code aggiornato con successo!");
    echo "<script>window.location.replace('stampa_qr_aula.php?t_id=$t_id');</script>";
    exit;
}

$sql_t = "SELECT t.*, e.titolo, e.luogo, e.pagina_id FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = $t_id";
$res_t = $conn->query($sql_t);
if (!$res_t || $res_t->num_rows === 0) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Turno non trovato.</div></div>";
    require_once 'admin_footer.php';
    exit;
}
$turno = $res_t->fetch_assoc();

if (empty($turno['token_checkin'])) {
    $turno['token_checkin'] = bin2hex(random_bytes(16));
    $conn->query("UPDATE turni SET token_checkin = '{$turno['token_checkin']}' WHERE id = $t_id");
}

// URL per il checkin
$domain = "https://" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
$checkin_url = $domain . "/self_checkin.php?t=" . $t_id . "&k=" . $turno['token_checkin'];

// API funzionante presa da stampa_badge.php
$qr_image = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=1&data=" . urlencode($checkin_url);

// Logo
$logo_url = "../assets/logo_dibest.png"; // Fallback
$res_cfg = $conn->query("SELECT logo_path FROM configurazione_portale WHERE id = 1");
if ($res_cfg && $row_cfg = $res_cfg->fetch_assoc()) {
    if (!empty($row_cfg['logo_path'])) { $logo_url = "../" . $row_cfg['logo_path']; }
}
?>

<style>
/* IL SEGRETO DEFINITIVO PER ISOLARE LA STAMPA */
@media print {
    @page { size: A4 portrait; margin: 0; }
    body, html { background-color: #ffffff !important; margin: 0 !important; padding: 0 !important; }

    /* 1. Nascondiamo tutto il documento visivamente */
    body * { visibility: hidden; }
    
    /* 2. Rimuoviamo fisicamente gli elementi che non ci servono */
    .no-print { display: none !important; }

    /* 3. Rendiamo visibile SOLO la nostra area stampabile */
    #printableArea, #printableArea * { visibility: visible; }
    
    /* 4. Il colpo di grazia: estraiamo l'area e la piazziamo forzatamente in alto a sinistra, ignorando il menu admin! */
    #printableArea { 
        position: absolute !important; 
        left: 0 !important; 
        top: 0 !important; 
        width: 100% !important;
        margin: 0 !important; 
        padding: 15mm !important; /* Crea un margine pulito per il foglio A4 */
        box-shadow: none !important;
        border: none !important;
    }

    /* Disabilitiamo flexbox che possono interferire con il posizionamento assoluto */
    #wrapper, #page-content-wrapper, main, .container-fluid { 
        display: block !important; 
        position: static !important;
        overflow: visible !important;
    }
    
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
}

/* Stile per la visualizzazione a schermo nel pannello Admin */
#printableArea {
    max-width: 800px; 
    background: #ffffff;
    margin: 20px auto;
    border-radius: 10px;
}
</style>

<!-- BARRA DEGLI STRUMENTI ADMIN (Visibile solo a schermo) -->
<div class="container-fluid no-print mb-4 mt-3">
    <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded shadow-sm border border-primary border-top border-4">
        <a href="iscritti.php?p_id=<?php echo $turno['pagina_id']; ?>&f_turno=<?php echo $t_id; ?>" class="btn btn-outline-secondary fw-bold">
            <i class="fa fa-arrow-left me-1"></i> Torna agli Iscritti
        </a>
        <div class="d-flex gap-2">
            <a href="?t_id=<?php echo $t_id; ?>&rotate=1" class="btn btn-warning fw-bold text-dark shadow-sm" data-confirm="Sicuro? Il QR Code attuale smetterà di funzionare.">
                <i class="fa fa-sync me-1"></i> Ruota QR Code
            </a>
            <button onclick="window.print();" class="btn btn-primary fw-bold shadow-sm" style="background-color: #B30000; border-color: #B30000;">
                <i class="fa fa-print me-1"></i> Stampa Foglio A4
            </button>
        </div>
    </div>
    
    <?php echo flash_html(); ?>
</div>

<!-- FOGLIO A4 DA STAMPARE -->
<div id="printableArea" class="card shadow-lg border-0 text-center p-5">
    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo Dipartimento" style="max-height: 90px; margin: 0 auto 20px auto;" onerror="this.style.display='none';">
    
    <h2 class="fw-bold mb-1" style="color: #333; font-size: 2.2rem;">Dipartimento DiBEST</h2>
    <h4 class="text-muted fw-bold mb-5">Self Check-in Evento</h4>
    
    <h1 class="fw-bold text-dark mb-4" style="font-size: 2.8rem; line-height: 1.2; color: #B30000 !important;">
        <?php echo htmlspecialchars($turno['titolo']); ?>
    </h1>
    
    <div class="bg-white p-3 rounded-4 border shadow-sm mb-4 mx-auto" style="display: inline-block;">
        <img src="<?php echo $qr_image; ?>" alt="QR Code" style="width: 320px; height: 320px;">
    </div>
    
    <h3 class="fw-bold mt-3 mb-2" style="color: #0056b3; font-size: 1.8rem;"><i class="fa fa-camera me-2"></i> INQUADRA IL CODICE</h3>
    <p class="fs-5 text-muted fw-bold mb-5 mx-auto" style="max-width: 550px;">Usa la fotocamera del tuo smartphone per registrare la tua presenza all'evento.</p>
    
    <div class="mt-4 pt-4 border-top w-100 d-flex justify-content-center gap-4 text-muted fw-bold fs-5 mx-auto flex-wrap">
        <?php if (!empty($turno['nome_turno'])): ?><span><i class="fa fa-tag me-1"></i> <?php echo htmlspecialchars($turno['nome_turno']); ?></span><?php endif; ?>
        <?php if (!empty($turno['data_turno'])): ?><span><i class="fa fa-calendar-day me-1"></i> <?php echo date('d/m/Y', strtotime($turno['data_turno'])); ?></span><?php endif; ?>
        <?php if (!empty($turno['orario_inizio'])): ?><span><i class="fa fa-clock me-1"></i> <?php echo substr($turno['orario_inizio'],0,5); ?></span><?php endif; ?>
        <span><i class="fa fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($turno['luogo'] ?: 'Aula Non Specificata'); ?></span>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>
