<?php
// abilitazioni.php - Console Centralizzata Permessi Area ed Eventi (Architettura Unificata)
require_once 'admin_header.php';

// Sicurezza Assoluta: Solo i Full Admin possono accedere
if (!$is_full_admin) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Gestione Abilitazioni riservata agli amministratori globali.</div>";
    require_once 'admin_footer.php';
    exit;
}

// ==============================================================================
// AUTO-PATCH DATABASE (Risolve il Fatal Error sui Singoli Eventi)
// ==============================================================================
$check_col = $conn->query("SHOW COLUMNS FROM eventi LIKE 'permessi_gestori_json'");
if ($check_col && $check_col->num_rows == 0) {
    $conn->query("ALTER TABLE eventi ADD COLUMN permessi_gestori_json TEXT NULL AFTER gestori_utenti_ids");
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}

// Controllo selezione Area di Lavoro
if ($filtro_p == 0) {
    echo "<div class='container mt-4'><div class='alert alert-warning fw-bold shadow-sm border-start border-4 border-warning'><i class='fa fa-exclamation-triangle me-2'></i> Seleziona un'Area di Lavoro dal menu in alto per gestire le sue abilitazioni.</div></div>";
    require_once 'admin_footer.php';
    exit;
}

// ==============================================================================
// ELABORAZIONE AZIONI BACKEND (POST) - MOTORE UNIFICATO
// ==============================================================================

if (isset($_POST['assegna_permessi'])) {
    $u_id = (int)$_POST['utente_id'];
    $permessi = $_POST['permessi'] ?? [];
    $ambito = $_POST['ambito_eventi'] ?? 'tutti'; // 'tutti' o 'specifici'
    $eventi_sel = $_POST['eventi_specifici'] ?? []; // Array di ID eventi

    if ($u_id > 0 && !empty($permessi)) {
        
        // FASE 1: PULIZIA TOTALE (Tabula Rasa per questo utente in questa Area)
        // Rimuove l'utente dalle impostazioni dell'Area
        $res_p = $conn->query("SELECT permessi_gestori_json, gestori_utenti_ids FROM pagine_eventi WHERE id = $filtro_p LIMIT 1");
        if ($res_p && $p_row = $res_p->fetch_assoc()) {
            $p_json = json_decode($p_row['permessi_gestori_json'] ?: '{}', true) ?: [];
            if (isset($p_json[$u_id])) unset($p_json[$u_id]);
            
            $p_csv = array_filter(array_map('trim', explode(',', $p_row['gestori_utenti_ids'] ?? '')));
            $p_csv = array_diff($p_csv, [(string)$u_id]);
            
            $conn->query("UPDATE pagine_eventi SET permessi_gestori_json = '".$conn->real_escape_string(json_encode($p_json))."', gestori_utenti_ids = '".$conn->real_escape_string(implode(',', $p_csv))."' WHERE id = $filtro_p");
        }

        // Rimuove l'utente da TUTTI i singoli eventi di quest'area (Con Paracadute di Sicurezza)
        $res_ev = $conn->query("SELECT id, permessi_gestori_json, gestori_utenti_ids FROM eventi WHERE pagina_id = $filtro_p");
        if ($res_ev) {
            while ($e_row = $res_ev->fetch_assoc()) {
                $e_id = $e_row['id'];
                $e_json = json_decode($e_row['permessi_gestori_json'] ?: '{}', true) ?: [];
                $e_csv = array_filter(array_map('trim', explode(',', $e_row['gestori_utenti_ids'] ?? '')));
                
                $changed = false;
                if (isset($e_json[$u_id])) { unset($e_json[$u_id]); $changed = true; }
                if (in_array((string)$u_id, $e_csv)) { $e_csv = array_diff($e_csv, [(string)$u_id]); $changed = true; }
                
                if ($changed) {
                    $conn->query("UPDATE eventi SET permessi_gestori_json = '".$conn->real_escape_string(json_encode($e_json))."', gestori_utenti_ids = '".$conn->real_escape_string(implode(',', $e_csv))."' WHERE id = $e_id");
                }
            }
        }

        // FASE 2: ASSEGNAZIONE DELLE NUOVE REGOLE
        if ($ambito === 'tutti') {
            // Assegna all'intera Area
            $p_json[$u_id] = $permessi;
            $conn->query("UPDATE pagine_eventi SET permessi_gestori_json = '".$conn->real_escape_string(json_encode($p_json))."' WHERE id = $filtro_p");
            if (function_exists('registra_log_audit')) registra_log_audit($conn, "Assegnati permessi Area", ["Utente ID" => $u_id, "Area ID" => $filtro_p, "Permessi" => $permessi]);
        
        } elseif ($ambito === 'specifici' && !empty($eventi_sel)) {
            // Assegna solo agli eventi selezionati
            foreach ($eventi_sel as $e_id_assegna) {
                $e_id_assegna = (int)$e_id_assegna;
                $res_e_upd = $conn->query("SELECT permessi_gestori_json FROM eventi WHERE id = $e_id_assegna LIMIT 1");
                if ($res_e_upd && $er = $res_e_upd->fetch_assoc()) {
                    $e_json_upd = json_decode($er['permessi_gestori_json'] ?: '{}', true) ?: [];
                    $e_json_upd[$u_id] = $permessi;
                    $conn->query("UPDATE eventi SET permessi_gestori_json = '".$conn->real_escape_string(json_encode($e_json_upd))."' WHERE id = $e_id_assegna");
                }
            }
            if (function_exists('registra_log_audit')) registra_log_audit($conn, "Assegnati permessi Singoli Eventi", ["Utente ID" => $u_id, "Eventi" => $eventi_sel, "Permessi" => $permessi]);
        }
        
        flash_set("Permessi assegnati e aggiornati con successo!");
    }
    admin_redirect("abilitazioni.php?p_id=$filtro_p");
}

// RIMOZIONE TOTALE UTENTE DALL'AREA
if (isset($_POST['remove_user_all'])) {
    $u_id = (int)$_POST['utente_id'];
    
    // Pulisce l'Area
    $res_p = $conn->query("SELECT permessi_gestori_json, gestori_utenti_ids FROM pagine_eventi WHERE id = $filtro_p LIMIT 1");
    if ($res_p && $p_row = $res_p->fetch_assoc()) {
        $p_json = json_decode($p_row['permessi_gestori_json'] ?: '{}', true) ?: [];
        if (isset($p_json[$u_id])) unset($p_json[$u_id]);
        $p_csv = array_filter(array_map('trim', explode(',', $p_row['gestori_utenti_ids'] ?? '')));
        $p_csv = array_diff($p_csv, [(string)$u_id]);
        $conn->query("UPDATE pagine_eventi SET permessi_gestori_json = '".$conn->real_escape_string(json_encode($p_json))."', gestori_utenti_ids = '".$conn->real_escape_string(implode(',', $p_csv))."' WHERE id = $filtro_p");
    }

    // Pulisce gli Eventi
    $res_ev = $conn->query("SELECT id, permessi_gestori_json, gestori_utenti_ids FROM eventi WHERE pagina_id = $filtro_p");
    if ($res_ev) {
        while ($e_row = $res_ev->fetch_assoc()) {
            $e_id = $e_row['id'];
            $e_json = json_decode($e_row['permessi_gestori_json'] ?: '{}', true) ?: [];
            $e_csv = array_filter(array_map('trim', explode(',', $e_row['gestori_utenti_ids'] ?? '')));
            $changed = false;
            if (isset($e_json[$u_id])) { unset($e_json[$u_id]); $changed = true; }
            if (in_array((string)$u_id, $e_csv)) { $e_csv = array_diff($e_csv, [(string)$u_id]); $changed = true; }
            if ($changed) {
                $conn->query("UPDATE eventi SET permessi_gestori_json = '".$conn->real_escape_string(json_encode($e_json))."', gestori_utenti_ids = '".$conn->real_escape_string(implode(',', $e_csv))."' WHERE id = $e_id");
            }
        }
    }
    
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Revoca Totale Permessi Area", ["Utente ID" => $u_id]);
    flash_set("L'utente è stato completamente rimosso dalla gestione di quest'area e dei suoi eventi.");
    admin_redirect("abilitazioni.php?p_id=$filtro_p");
}

// ==============================================================================
// PREPARAZIONE DATI FRONT-END
// ==============================================================================

// 1. Lista Amministratori Globali
$full_admins = [];
$res_admins = $conn->query("SELECT id, nome, cognome, email FROM utenti WHERE ruolo_id = 1 OR FIND_IN_SET('1', ruoli_secondari) > 0 ORDER BY cognome ASC, nome ASC");
if ($res_admins) { while ($a = $res_admins->fetch_assoc()) { $full_admins[] = $a; } }

// 2. Lista Utenti Selezionabili
$candidati = [];
$res_ut = $conn->query("SELECT id, nome, cognome, email FROM utenti WHERE ruolo_id != 1 AND (ruoli_secondari IS NULL OR FIND_IN_SET('1', ruoli_secondari) = 0) ORDER BY cognome ASC, nome ASC");
if ($res_ut) { while ($u = $res_ut->fetch_assoc()) { $candidati[$u['id']] = $u; } }

// 3. Lista Eventi della Pagina Corrente
$eventi_area = [];
$res_ev_list = $conn->query("SELECT id, titolo FROM eventi WHERE pagina_id = $filtro_p ORDER BY ordine ASC, id DESC");
if ($res_ev_list) { while ($e = $res_ev_list->fetch_assoc()) { $eventi_area[$e['id']] = $e['titolo']; } }

// 4. MAPPA UNIFICATA DEI GESTORI (Scansione profonda)
$mappa_gestori = [];

// Scansiona Area
$res_p = $conn->query("SELECT gestori_utenti_ids, permessi_gestori_json FROM pagine_eventi WHERE id = $filtro_p LIMIT 1");
if ($res_p && $p_row = $res_p->fetch_assoc()) {
    $p_json = json_decode($p_row['permessi_gestori_json'] ?: '{}', true) ?: [];
    foreach ($p_json as $uid => $perms) {
        $mappa_gestori[$uid] = ['ambito' => 'Tutta l\'Area', 'permessi' => $perms, 'eventi' => []];
    }
    $p_csv = array_filter(array_map('trim', explode(',', $p_row['gestori_utenti_ids'] ?? '')));
    foreach ($p_csv as $uid) {
        if (!isset($mappa_gestori[$uid])) $mappa_gestori[$uid] = ['ambito' => 'Tutta l\'Area', 'permessi' => ['eventi', 'iscritti', 'sondaggi', 'form'], 'eventi' => []];
    }
}

// Scansiona Eventi
$res_e_scan = $conn->query("SELECT id, titolo, gestori_utenti_ids, permessi_gestori_json FROM eventi WHERE pagina_id = $filtro_p");
if ($res_e_scan) {
    while ($e_row = $res_e_scan->fetch_assoc()) {
        $e_id = $e_row['id'];
        $e_titolo = $e_row['titolo'];
        
        $e_json = json_decode($e_row['permessi_gestori_json'] ?: '{}', true) ?: [];
        foreach ($e_json as $uid => $perms) {
            if (!isset($mappa_gestori[$uid])) $mappa_gestori[$uid] = ['ambito' => 'Eventi Specifici', 'permessi' => [], 'eventi' => []];
            if ($mappa_gestori[$uid]['ambito'] !== 'Tutta l\'Area') {
                $mappa_gestori[$uid]['eventi'][] = "🎯 $e_titolo";
                $mappa_gestori[$uid]['permessi'] = array_unique(array_merge($mappa_gestori[$uid]['permessi'], $perms));
            }
        }
        
        $e_csv = array_filter(array_map('trim', explode(',', $e_row['gestori_utenti_ids'] ?? '')));
        foreach ($e_csv as $uid) {
            if (!isset($mappa_gestori[$uid])) $mappa_gestori[$uid] = ['ambito' => 'Eventi Specifici', 'permessi' => ['eventi', 'iscritti'], 'eventi' => []];
            if ($mappa_gestori[$uid]['ambito'] !== 'Tutta l\'Area' && !in_array("🎯 $e_titolo", $mappa_gestori[$uid]['eventi'])) {
                $mappa_gestori[$uid]['eventi'][] = "🎯 $e_titolo";
            }
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-dark m-0"><i class="fa fa-key text-primary me-2"></i> Abilitazioni: <?php echo htmlspecialchars($page_cfg['titolo'] ?? 'Area Corrente'); ?></h4>
</div>

<!-- SEZIONE 1: AMMINISTRATORI GLOBALI (Read-Only) -->
<div class="card shadow-sm border-0 mb-4" style="border-left: 4px solid #dc3545 !important;">
    <div class="card-body">
        <h6 class="fw-bold text-danger mb-3"><i class="fa fa-shield-alt me-2"></i> Amministratori Globali (Accesso Completo)</h6>
        <p class="small text-muted mb-2">Questi utenti hanno permessi assoluti su tutte le aree e tutti gli eventi del portale.</p>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($full_admins as $adm): ?>
                <span class="badge bg-danger bg-opacity-10 border border-danger text-danger p-2 shadow-sm">
                    <i class="fa fa-user-tie me-1"></i> <?php echo htmlspecialchars($adm['nome'] . ' ' . $adm['cognome']); ?> 
                </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- SEZIONE 2: MODULO ASSEGNAZIONE UNIFICATO -->
<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-primary text-white py-3">
        <h6 class="fw-bold m-0"><i class="fa fa-user-plus me-2"></i> Assegna Nuovi Permessi / Modifica Esistenti</h6>
    </div>
    <div class="card-body bg-light">
        <form method="POST" class="bg-white p-4 rounded border border-primary shadow-sm">
            
            <div class="row g-4 mb-4">
                <!-- 1. SELEZIONE UTENTE E PERMESSI -->
                <div class="col-md-6 border-end">
                    <h6 class="fw-bold text-dark mb-3">1. Chi vuoi abilitare e cosa può fare?</h6>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Seleziona Utente</label>
                        <select name="utente_id" class="form-select select2-ricerca" required>
                            <option value="">-- Cerca per Cognome o Email --</option>
                            <?php foreach ($candidati as $id => $u): ?>
                                <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($u['cognome'] . ' ' . $u['nome'] . ' (' . $u['email'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary d-block">Moduli Abilitati (Sezioni visibili)</label>
                        <div class="d-flex flex-wrap gap-3 p-3 border rounded bg-light">
                            <div class="form-check m-0"><input class="form-check-input area-perm" type="checkbox" name="permessi[]" value="eventi" id="p_ev" checked><label class="form-check-label fw-bold" for="p_ev">Eventi</label></div>
                            <div class="form-check m-0"><input class="form-check-input area-perm" type="checkbox" name="permessi[]" value="iscritti" id="p_is" checked><label class="form-check-label fw-bold" for="p_is">Iscritti</label></div>
                            <div class="form-check m-0"><input class="form-check-input area-perm" type="checkbox" name="permessi[]" value="sondaggi" id="p_so"><label class="form-check-label fw-bold" for="p_so">Sondaggi</label></div>
                            <div class="form-check m-0 border-start ps-3"><input class="form-check-input border-danger" type="checkbox" name="permessi[]" value="full" id="p_fu" onchange="toggleAreaFull(this)"><label class="form-check-label fw-bold text-danger" for="p_fu">Full Admin Area</label></div>
                        </div>
                    </div>
                </div>

                <!-- 2. SELEZIONE AMBITO (TUTTO O SINGOLO EVENTO) -->
                <div class="col-md-6">
                    <h6 class="fw-bold text-dark mb-3">2. Su quali eventi applichiamo queste regole?</h6>
                    
                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="ambito_eventi" id="radioTutti" value="tutti" checked>
                            <label class="form-check-label fw-bold" for="radioTutti">
                                <i class="fa fa-folder-open text-primary me-1"></i> Tutta l'Area (Vede e gestisce tutti gli eventi presenti)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="ambito_eventi" id="radioSpecifici" value="specifici">
                            <label class="form-check-label fw-bold" for="radioSpecifici">
                                <i class="fa fa-crosshairs text-success me-1"></i> Solo Eventi Specifici (Viene isolato dal resto dell'Area)
                            </label>
                        </div>
                    </div>

                    <div id="boxEventiSpecifici" class="d-none p-3 border rounded border-success bg-light">
                        <label class="form-label small fw-bold text-success"><i class="fa fa-check-square me-1"></i> Scegli uno o più eventi da assegnargli:</label>
                        <select name="eventi_specifici[]" id="selectEventi" class="form-select select2-multi" multiple="multiple">
                            <?php foreach ($eventi_area as $e_id => $e_titolo): ?>
                                <option value="<?php echo $e_id; ?>"><?php echo htmlspecialchars($e_titolo); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted mt-2 d-block">Cerca e clicca sugli eventi. Puoi selezionarne quanti ne vuoi.</small>
                    </div>
                </div>
            </div>

            <div class="border-top pt-3 text-end">
                <button type="submit" name="assegna_permessi" class="btn btn-primary btn-lg fw-bold px-5 shadow-sm"><i class="fa fa-save me-2"></i> Salva e Applica Abilitazioni</button>
            </div>
        </form>
    </div>
</div>

<!-- SEZIONE 3: TABELLA RIEPILOGATIVA -->
<div class="card shadow-sm border-0 mb-5">
    <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold m-0"><i class="fa fa-users-cog me-2"></i> Elenco Utenti Abilitati in questa Area</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0">
                <thead class="table-light">
                    <tr>
                        <th>Nominativo</th>
                        <th>Ambito di Gestione</th>
                        <th>Permessi (Sezioni)</th>
                        <th class="text-end px-4">Azione</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mappa_gestori)): ?>
                        <tr><td colspan="4" class="text-center text-muted p-5">Nessun utente ha permessi attivi in questa Area.</td></tr>
                    <?php else: ?>
                        <?php foreach ($mappa_gestori as $u_id => $dati): ?>
                            <?php if (isset($candidati[$u_id])): $g = $candidati[$u_id]; ?>
                            <tr>
                                <td class="px-4">
                                    <strong class="text-dark fs-6"><?php echo htmlspecialchars($g['cognome'] . ' ' . $g['nome']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($g['email']); ?></small>
                                </td>
                                <td>
                                    <?php if ($dati['ambito'] === 'Tutta l\'Area'): ?>
                                        <span class="badge bg-primary fs-6 shadow-sm"><i class="fa fa-folder-open me-1"></i> Intera Area</span>
                                    <?php else: ?>
                                        <div class="text-success fw-bold small mb-1"><i class="fa fa-crosshairs me-1"></i> Singoli Eventi:</div>
                                        <ul class="list-unstyled m-0 small">
                                            <?php foreach ($dati['eventi'] as $ev_tit): ?>
                                                <li class="text-muted ms-3 border-start border-success ps-2 mb-1"><?php echo htmlspecialchars($ev_tit); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                        $perms = $dati['permessi'];
                                        if (in_array('full', $perms)) echo '<span class="badge bg-danger shadow-sm fs-6"><i class="fa fa-star me-1"></i> Controllo Totale</span>';
                                        else {
                                            if(in_array('eventi', $perms)) echo '<span class="badge bg-primary me-1 mb-1 shadow-sm px-2 py-1"><i class="fa fa-calendar me-1"></i> Eventi</span>';
                                            if(in_array('iscritti', $perms)) echo '<span class="badge bg-success me-1 mb-1 shadow-sm px-2 py-1"><i class="fa fa-users me-1"></i> Iscritti</span>';
                                            if(in_array('sondaggi', $perms)) echo '<span class="badge bg-warning text-dark me-1 mb-1 shadow-sm px-2 py-1"><i class="fa fa-star me-1"></i> Sondaggi</span>';
                                            if(in_array('form', $perms)) echo '<span class="badge bg-info text-dark me-1 mb-1 shadow-sm px-2 py-1"><i class="fa fa-list me-1"></i> Form</span>';
                                        }
                                    ?>
                                </td>
                                <td class="text-end px-4">
                                    <form method="POST" class="m-0">
                                        <input type="hidden" name="utente_id" value="<?php echo $u_id; ?>">
                                        <button type="submit" name="remove_user_all" class="btn btn-outline-danger btn-sm fw-bold" data-confirm="Sei sicuro? Questa azione rimuoverà l\'utente sia dalla gestione dell\'Area che da tutti i singoli eventi a lui assegnati in quest\'area."><i class="fa fa-trash-alt me-1"></i> Revoca Tutto</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if(typeof jQuery !== 'undefined' && $.fn.select2) {
        $('.select2-ricerca').select2({ width: '100%', theme: 'bootstrap-5' });
        
        // Multi-select per gli eventi specifici
        $('.select2-multi').select2({
            width: '100%',
            theme: 'bootstrap-5',
            placeholder: "-- Digita per cercare e clicca per aggiungere --",
            allowClear: true
        });
    }

    // Toggle logico per la visualizzazione della casella "Eventi Specifici"
    $('input[name="ambito_eventi"]').on('change', function() {
        if ($(this).val() === 'specifici') {
            $('#boxEventiSpecifici').removeClass('d-none');
            $('#selectEventi').attr('required', true);
        } else {
            $('#boxEventiSpecifici').addClass('d-none');
            $('#selectEventi').attr('required', false).val(null).trigger('change');
        }
    });
});

function toggleAreaFull(fullChk) {
    let perms = document.querySelectorAll('.area-perm');
    if (fullChk.checked) {
        perms.forEach(c => { c.checked = true; c.disabled = true; });
    } else {
        perms.forEach(c => { c.disabled = false; });
    }
}
</script>

<?php require_once 'admin_footer.php'; ?>
