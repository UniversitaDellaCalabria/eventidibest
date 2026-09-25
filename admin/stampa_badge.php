<?php
// stampa_badge.php - Cruscotto Generazione Badge Nominativi (A4)
require_once 'admin_header.php';

if (!$can_manage_iscritti) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per accedere ai dati degli iscritti.</div>";
    require_once 'admin_footer.php';
    exit;
}

// 1. Recupero Dati per i Filtri della Dashboard
// 1. Recupero Dati filtrati per i Permessi
$tutti_gli_eventi = [];
$filtro_ev_sql = "";
if (!$is_full_admin && !$can_manage_settings) {
    // Se non è admin globale o gestore intera area, vede solo i propri eventi
    $filtro_ev_sql = " AND (FIND_IN_SET($u_id_curr, e.gestori_utenti_ids) > 0 OR JSON_CONTAINS(JSON_KEYS(COALESCE(e.permessi_gestori_json, '{}')), '\"$u_id_curr\"')) ";
}
$res_ev = $conn->query("SELECT e.id, e.titolo FROM eventi e WHERE e.pagina_id = $filtro_p AND e.archiviato = 0 $filtro_ev_sql ORDER BY e.ordine ASC, e.id DESC");
if ($res_ev) {
    while($row = $res_ev->fetch_assoc()) {
        $turni = [];
        $res_t = $conn->query("SELECT * FROM turni WHERE evento_id = {$row['id']} ORDER BY data_turno ASC, orario_inizio ASC");
        if($res_t) { while($t = $res_t->fetch_assoc()) $turni[] = $t; }
        $row['turni'] = $turni;
        $tutti_gli_eventi[] = $row;
    }
}

// 2. MOTORE DI GENERAZIONE STAMPA
$badges_to_print = [];
$info = null;
$col_primaria = $page_cfg['colore_primario'] ?? '#990000'; 

if (isset($_POST['avvia_stampa'])) {
    $t_id = (int)$_POST['turno_id'];
    
    // Info Base Turno e Logo
    $sql_info = "SELECT t.data_turno, e.titolo, e.luogo, e.id as ev_id, c.logo_path 
                 FROM turni t JOIN eventi e ON t.evento_id = e.id 
                 JOIN configurazione_portale c ON c.id = 1 WHERE t.id = $t_id";
    
    $res_info = $conn->query($sql_info);
    
    if ($res_info && $res_info->num_rows > 0) {
        $info = $res_info->fetch_assoc();
        $ev_id = (int)$info['ev_id'];

        // A. Iscritti Confermati
        if (isset($_POST['stampa_partecipanti'])) {
            $res_iscritti = $conn->query("SELECT codice_prenotazione, nome, cognome, matricola FROM prenotazioni WHERE turno_id = $t_id AND stato = 'confermata' ORDER BY cognome ASC, nome ASC");
            if ($res_iscritti) {
                while ($r = $res_iscritti->fetch_assoc()) {
                    $ruolo_t = !empty($r['matricola']) ? "STUDENTE - " . $r['matricola'] : "PARTECIPANTE";
                    $badges_to_print[] = ['nome' => $r['nome'], 'cognome' => $r['cognome'], 'ruolo' => $ruolo_t, 'qr' => $r['codice_prenotazione']];
                }
            }
        }

        // B. Staff / Gestori Associati all'Evento o Area
        if (isset($_POST['stampa_staff'])) {
            $staff_ids = [];
            
            $res_e = $conn->query("SELECT gestori_utenti_ids FROM eventi WHERE id = $ev_id");
            if ($res_e && $e_row = $res_e->fetch_assoc()) {
                $staff_ids = array_merge($staff_ids, explode(',', $e_row['gestori_utenti_ids'] ?? ''));
            }
            
            $res_p = $conn->query("SELECT gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id = $filtro_p");
            if ($res_p && $p_row = $res_p->fetch_assoc()) {
                $staff_ids = array_merge($staff_ids, explode(',', $p_row['gestori_utenti_ids'] ?? ''));
                if ($json = json_decode($p_row['permessi_gestori_json'] ?: '{}', true)) {
                    $staff_ids = array_merge($staff_ids, array_keys($json));
                }
            }
            
            $res_adm = $conn->query("SELECT id FROM utenti WHERE ruolo_id = 1 OR FIND_IN_SET('1', ruoli_secondari) > 0");
            if ($res_adm) {
                while ($adm = $res_adm->fetch_assoc()) $staff_ids[] = $adm['id'];
            }
            
            $staff_ids = array_unique(array_filter($staff_ids));
            
            if (!empty($staff_ids)) {
                $ids_str = implode(',', $staff_ids);
                $res_u = $conn->query("SELECT nome, cognome FROM utenti WHERE id IN ($ids_str) ORDER BY cognome ASC");
                if ($res_u) {
                    while ($u = $res_u->fetch_assoc()) {
                        $badges_to_print[] = ['nome' => $u['nome'], 'cognome' => $u['cognome'], 'ruolo' => 'STAFF / GESTORE', 'qr' => 'STAFF-' . substr(md5(uniqid()), 0, 6)];
                    }
                }
            }
        }

        // C. Badge Manuali (Aggiunti al Volo)
        if (!empty($_POST['custom_nome'])) {
            foreach ($_POST['custom_nome'] as $i => $cnome) {
                $cnome = trim($cnome);
                $ccognome = trim($_POST['custom_cognome'][$i] ?? '');
                $cruolo = trim($_POST['custom_ruolo'][$i] ?? 'EXTRA');
                if ($cnome || $ccognome) {
                    $badges_to_print[] = ['nome' => $cnome, 'cognome' => $ccognome, 'ruolo' => strtoupper($cruolo), 'qr' => 'EXT-' . substr(md5(uniqid()), 0, 6)];
                }
            }
        }
    }
}
?>

<style>
/* CLASSI PER NASCONDERE L'UI DURANTE LA STAMPA E FORZARE IL LAYOUT */
.print-only { display: none; }

@media print {
    @page { size: A4 portrait; margin: 0; }
    body, html { background-color: #ffffff !important; margin: 0 !important; padding: 0 !important; }
    
    /* Nasconde tutto ciò che è UI */
    .no-print, #sidebar, #sidebarOverlay, nav.navbar, header, footer { display: none !important; }
    
    /* Disabilita i Flexbox di Bootstrap che causano pagine bianche su Chrome */
    #wrapper, #page-content-wrapper, main, .container-fluid { 
        display: block !important; 
        margin: 0 !important; 
        padding: 0 !important; 
        width: 100% !important; 
        height: auto !important; 
        position: static !important;
        overflow: visible !important;
    }
    
    .print-only { display: block !important; visibility: visible !important; opacity: 1 !important; }
    
    /* Forza la stampa dei colori di sfondo */
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
}

/* Layout per Fogli A4 */
.a4-page { 
    width: 210mm; 
    height: 297mm; 
    padding: 10mm 15mm; 
    box-sizing: border-box; 
    background: white; 
    page-break-after: always;
    overflow: hidden;
}

/* Grafica Singolo Badge - Uso dei float classici per compatibilità stampanti */
.badge-card {
    float: left;
    width: 85mm; 
    height: 55mm; 
    border: 1px dashed #999; 
    box-sizing: border-box; 
    margin: 2.5mm; 
    background-color: #fff;
    font-family: 'Titillium Web', sans-serif;
}
.badge-sidebar-color { float: left; width: 15mm; height: 55mm; }
.badge-content { float: left; width: 68mm; height: 55mm; padding: 4mm; box-sizing: border-box; }

.badge-header { border-bottom: 2px solid; padding-bottom: 2px; margin-bottom: 4px; height: 12mm; clear: both; }
.badge-logo { float: left; width: 25mm; }
.badge-logo img { max-height: 10mm; max-width: 25mm; }
.badge-event-title { float: right; width: 33mm; font-size: 8px; font-weight: 700; color: #555; text-transform: uppercase; text-align: right; line-height: 1.1; }

.badge-body { height: 16mm; margin-bottom: 2mm; overflow: hidden; clear: both; }
.badge-name { font-size: 15px; font-weight: 900; line-height: 1.1; color: #000; text-transform: uppercase; }
.badge-role { font-size: 10px; font-weight: 600; margin-top: 1px; }

.badge-footer { height: 14mm; clear: both; }
.badge-code { float: left; width: 45mm; font-size: 8px; color: #777; font-family: monospace; padding-top: 6mm; }
.badge-qr { float: right; width: 14mm; height: 14mm; }
</style>

<!-- DASHBOARD VISIBILE A SCHERMO -->
<div class="no-print">
    <h4 class="fw-bold text-dark mb-4"><i class="fa fa-id-badge text-primary me-2"></i> Stampa Badge Nominativi</h4>
    
    <div class="card shadow-sm border-0 p-4 bg-white border-top border-primary border-4">
        <form method="POST">
            
            <h6 class="fw-bold text-primary mb-3">1. Seleziona l'Evento e il Turno</h6>
            <select name="turno_id" class="form-select form-select-lg mb-4 fw-bold border-primary text-primary" required>
                <option value="">-- Scegli da quale turno estrarre i dati --</option>
                <?php foreach ($tutti_gli_eventi as $ev_m): ?>
                    <optgroup label="<?php echo mb_strimwidth(htmlspecialchars($ev_m['titolo']), 0, 50, '...'); ?>">
                        <?php foreach ($ev_m['turni'] as $t_m): ?>
                            <option value="<?php echo $t_m['id']; ?>">
                                📅 <?php echo htmlspecialchars(etichetta_turno($t_m)); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>

            <h6 class="fw-bold text-dark border-bottom pb-2 mb-3 mt-4">2. Quali profili vuoi stampare?</h6>
            <div class="d-flex flex-wrap gap-4 mb-4">
                <div class="form-check form-switch fs-5">
                    <input class="form-check-input" type="checkbox" name="stampa_partecipanti" id="chkPart" value="1" checked>
                    <label class="form-check-label fw-bold" for="chkPart">Includi Partecipanti (Iscritti Confermati)</label>
                </div>
                <div class="form-check form-switch fs-5">
                    <input class="form-check-input" type="checkbox" name="stampa_staff" id="chkStaff" value="1">
                    <label class="form-check-label fw-bold text-secondary" for="chkStaff">Includi Gestori (Staff dell'Area e Amministratori)</label>
                </div>
            </div>

            <h6 class="fw-bold text-warning border-bottom pb-2 mb-3 mt-4" style="color: #b37700!important;"><i class="fa fa-user-plus me-1"></i> 3. Aggiunta Rapida Badge Extra (Opzionale)</h6>
            <p class="small text-muted mb-2">Utilizza questa sezione per aggiungere al volo il nome di un Relatore Esterno, un Fotografo o un addetto alla Sicurezza.</p>
            
            <div class="table-responsive">
                <table class="table table-bordered align-middle" id="manualBadgeTable">
                    <thead class="table-light"><tr><th>Nome</th><th>Cognome</th><th>Mansione / Testo sul Badge</th><th class="text-center">Rimuovi</th></tr></thead>
                    <tbody id="manualBadgeBody">
                        <!-- Le righe verranno inserite qui da Javascript -->
                    </tbody>
                </table>
                <button type="button" class="btn btn-outline-warning btn-sm text-dark fw-bold" id="addManualBadgeRow"><i class="fa fa-plus me-1"></i> Aggiungi un'altra persona</button>
            </div>

            <div class="text-end mt-5 border-top pt-4">
                <button type="submit" name="avvia_stampa" class="btn btn-danger btn-lg fw-bold shadow px-5" style="background-color: #B80000;"><i class="fa fa-print me-2"></i> GENERA PDF DA STAMPARE</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================================================= -->
<!-- MOTORE HTML PER LA STAMPA (NASCOSTO FINCHÈ NON SI PREME "GENERA")                         -->
<!-- ========================================================================================= -->
<?php if (isset($_POST['avvia_stampa'])): ?>
    
    <?php if (empty($badges_to_print)): ?>
        <div class="alert alert-warning mt-4 fw-bold shadow-sm no-print">
            <i class="fa fa-exclamation-triangle me-2"></i> Nessun nominativo trovato per questa selezione. Assicurati che ci siano partecipanti confermati o di aver compilato correttamente le righe manuali.
        </div>
    <?php else: ?>
        <div class="print-only">
            <?php 
            $badge_count = 0;
            echo '<div class="a4-page">';
            
            foreach ($badges_to_print as $b) {
                // API QR Code
                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=" . urlencode($b['qr']);
                ?>
                <div class="badge-card">
                    <div class="badge-sidebar-color" style="background-color: <?php echo $col_primaria; ?>;"></div>
                    <div class="badge-content">
                        <div class="badge-header" style="border-bottom-color: <?php echo $col_primaria; ?>;">
                            <div class="badge-logo">
                                <?php if (!empty($info['logo_path'])): ?><img src="../<?php echo htmlspecialchars($info['logo_path']); ?>" alt="Logo"><?php endif; ?>
                            </div>
                            <div class="badge-event-title"><?php echo htmlspecialchars(mb_strimwidth($info['titolo'] ?? '', 0, 45, '...')); ?></div>
                        </div>
                        <div class="badge-body">
                            <div class="badge-name"><?php echo htmlspecialchars($b['cognome'] . ' ' . $b['nome']); ?></div>
                            <div class="badge-role" style="color: <?php echo $col_primaria; ?>;"><?php echo htmlspecialchars($b['ruolo']); ?></div>
                        </div>
                        <div class="badge-footer">
                            <div class="badge-code">ID:<br><strong><?php echo htmlspecialchars($b['qr']); ?></strong></div>
                            <img src="<?php echo $qr_url; ?>" class="badge-qr" alt="QR Code">
                        </div>
                    </div>
                </div>
                <?php
                $badge_count++;
                if ($badge_count % 10 == 0 && $badge_count < count($badges_to_print)) {
                    echo '</div><div class="a4-page">';
                }
            }
            echo '</div>'; // Chiude ultima a4-page
            ?>
        </div>
        
        <script>
            // Un piccolo ritardo (mezzo secondo) permette al browser di scaricare le immagini dei QR Code prima di bloccare la pagina con la finestra di stampa
            setTimeout(function() { 
                window.print(); 
            }, 600);
        </script>
    <?php endif; ?>

<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tableBody = document.getElementById('manualBadgeBody');
    const addBtn = document.getElementById('addManualBadgeRow');

    function createRow() {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="custom_nome[]" class="form-control form-control-sm" placeholder="Nome"></td>
            <td><input type="text" name="custom_cognome[]" class="form-control form-control-sm" placeholder="Cognome"></td>
            <td><input type="text" name="custom_ruolo[]" class="form-control form-control-sm text-uppercase" placeholder="Es. Sicurezza / Relatore"></td>
            <td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm py-0 remove-row" title="Rimuovi"><i class="fa fa-times"></i></button></td>
        `;
        tr.querySelector('.remove-row').addEventListener('click', function() { tr.remove(); });
        return tr;
    }

    // Aggiungi una riga vuota di default
    tableBody.appendChild(createRow());
    addBtn.addEventListener('click', function() { tableBody.appendChild(createRow()); });
});
</script>

<?php require_once 'admin_footer.php'; ?>