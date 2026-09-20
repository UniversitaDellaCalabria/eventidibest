<?php
// form_builder.php - Gestione Campi Personalizzati (Form Builder)
require_once 'admin_header.php';

// Controllo Permessi RBAC
if (!$can_manage_form) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per gestire il Form Builder in quest'area.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}

// ==============================================================================
// BLOCCO ELABORAZIONE AZIONI BACKEND (GET / POST)
// ==============================================================================

// 1. AGGIUNGI NUOVO CAMPO CUSTOM
if (isset($_POST['add_campo_custom'])) {
    $p_id = (int)($_POST['p_id'] ?? $_POST['pagina_id'] ?? $filtro_p);
    if ($p_id <= 0) { $p_id = $filtro_p; }
    
    $ev_id = (int)($_POST['evento_id'] ?? 0);
    $ev_id_sql = ($ev_id > 0) ? $ev_id : "NULL";
    
    $etichetta = $conn->real_escape_string(trim($_POST['etichetta'] ?? ''));
    $tipo = $conn->real_escape_string($_POST['tipo_campo'] ?? 'text');
    $opzioni = $conn->real_escape_string(trim($_POST['opzioni_select'] ?? ''));
    $req = isset($_POST['obbligatorio']) ? 1 : 0;
    $ord = (int)($_POST['ordine'] ?? 0);
    
    // Genera un nome campo per il database univoco partendo dall'etichetta
    $nome_c = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $etichetta)));
    if (empty($nome_c)) { $nome_c = 'campo_' . time(); }
    
    $sql_ins_cf = "INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, opzioni_select, obbligatorio, ordine) 
                   VALUES ($p_id, $ev_id_sql, '$nome_c', '$etichetta', '$tipo', '$opzioni', $req, $ord)";
                   
    if ($conn->query($sql_ins_cf)) { 
        flash_set("Campo personalizzato aggiunto con successo!"); 
    }
    admin_redirect("form_builder.php?p_id=$p_id");
}

// 2. MODIFICA CAMPO ESISTENTE
if (isset($_POST['edit_campo_custom'])) {
    $cf_id = (int)$_POST['campo_id'];
    $p_id = (int)($_POST['p_id'] ?? $filtro_p);
    
    $ev_id = (int)($_POST['evento_id'] ?? 0);
    $ev_id_sql = ($ev_id > 0) ? $ev_id : "NULL";
    
    $etichetta = $conn->real_escape_string(trim($_POST['etichetta'] ?? ''));
    $tipo = $conn->real_escape_string($_POST['tipo_campo'] ?? 'text');
    $opzioni = $conn->real_escape_string(trim($_POST['opzioni_select'] ?? ''));
    $req = isset($_POST['obbligatorio']) ? 1 : 0;
    $ord = (int)($_POST['ordine'] ?? 0);
    
    $conn->query("UPDATE campi_form SET evento_id = $ev_id_sql, etichetta = '$etichetta', tipo_campo = '$tipo', opzioni_select = '$opzioni', obbligatorio = $req, ordine = $ord WHERE id = $cf_id");
    
    flash_set("Campo modificato!");
    admin_redirect("form_builder.php?p_id=$p_id");
}

// 3. ELIMINA CAMPO
if (isset($_GET['del_campo_custom'])) {
    $cf_id_del = (int)$_GET['del_campo_custom'];
    $conn->query("DELETE FROM campi_form WHERE id = $cf_id_del");
    flash_set("Campo personalizzato eliminato.");
    admin_redirect("form_builder.php?p_id=$filtro_p");
}

// ==============================================================================
// PREPARAZIONE DATI FRONT-END
// ==============================================================================

// Recupero Eventi (per assegnare i campi ai singoli eventi)
$tutti_gli_eventi = [];
$event_filter_sql = (!$is_full_admin && !$can_manage_eventi) ? " AND FIND_IN_SET($u_id_curr, e.gestori_utenti_ids) > 0 " : "";
$res_ev = $conn->query("SELECT id, titolo FROM eventi e WHERE pagina_id = $filtro_p AND archiviato = 0 $event_filter_sql ORDER BY ordine ASC, id DESC");
if ($res_ev) {
    while($row = $res_ev->fetch_assoc()) {
        $tutti_gli_eventi[] = $row;
    }
}

// Recupero Campi Custom
$campi_custom = [];
$res_cf = $conn->query("SELECT cf.*, COALESCE(e.titolo, '⭐ TUTTI GLI EVENTI') as evento_titolo FROM campi_form cf LEFT JOIN eventi e ON cf.evento_id = e.id WHERE cf.pagina_id = $filtro_p OR (cf.evento_id > 0 AND e.pagina_id = $filtro_p) ORDER BY cf.ordine ASC, cf.id ASC");
if ($res_cf) { 
    while($r = $res_cf->fetch_assoc()) { 
        $campi_custom[] = $r; 
    } 
}
?>

<!-- FRONT-END DELLA PAGINA -->
<h4 class="fw-bold text-dark mb-4"><i class="fa fa-list-check text-primary me-2"></i> Costruttore di Form (Form Builder)</h4>

<!-- FORM CREAZIONE NUOVO CAMPO -->
<div class="card shadow-sm border-0 p-4 mb-4">
    <h5 class="fw-bold text-danger border-bottom pb-2 mb-3"><i class="fa fa-plus-circle me-1"></i> Aggiungi Campo Personalizzato al Form di Registrazione</h5>
    <form method="POST" class="row g-3">
        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
        <input type="hidden" name="pagina_id" value="<?php echo $filtro_p; ?>">
        
        <div class="col-md-5">
            <label class="form-label small fw-bold">Evento di Destinazione</label>
            <select name="evento_id" class="form-select fw-bold border-danger" required>
                <option value="0">⭐ TUTTI GLI EVENTI DI QUESTA PAGINA</option>
                <?php foreach ($tutti_gli_eventi as $ev): ?>
                    <option value="<?php echo $ev['id']; ?>">🎯 Solo: <?php echo htmlspecialchars($ev['titolo']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label small fw-bold">Etichetta Campo (Domanda visibile all'utente)</label>
            <input type="text" name="etichetta" class="form-control" required placeholder="Es. Carica documento, Restrizioni Alimentari...">
        </div>

        <div class="col-md-3">
            <label class="form-label small fw-bold">Tipo di Input</label>
            <select name="tipo_campo" class="form-select fw-bold border-primary text-primary">
                <option value="text">Testo Libero (Riga singola)</option>
                <option value="textarea">Testo Lungo (Multiriga)</option>
                <option value="number">Numero</option>
                <option value="date">Data</option>
                <option value="select">Menu a Tendina (Select)</option>
                <option value="radio">Scelta Singola (Radio Button)</option>
                <option value="checkbox">Checkbox Singolo (Spunta di conferma)</option>
                <option value="checkboxes">Checkbox Multiplo (Scelta multipla)</option>
                <option value="file">📁 Upload File / Allegato (PDF/DOC/IMG)</option>
            </select>
        </div>

        <div class="col-md-7">
            <label class="form-label small fw-bold">Opzioni (separate da virgola - SOLO per tendina, radio o checkbox multiplo)</label>
            <input type="text" name="opzioni_select" class="form-control" placeholder="Es. Opzione A, Opzione B, Opzione C">
        </div>

        <div class="col-md-2 d-flex align-items-center pt-4">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="obbligatorio" id="chkObbl" value="1" checked>
                <label class="form-check-label small fw-bold" for="chkObbl">Obbligatorio</label>
            </div>
        </div>

        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" name="add_campo_custom" class="btn btn-danger w-100 fw-bold"><i class="fa fa-plus me-1"></i> Aggiungi Campo</button>
        </div>
    </form>
</div>

<!-- LISTA CAMPI ESISTENTI -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-bold py-3 text-danger fs-6 border-bottom">
        <i class="fa fa-list me-1"></i> Campi Personalizzati Attivi in questa Area (<?php echo count($campi_custom); ?>)
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0">
                <thead class="table-dark">
                    <tr>
                        <th>#ID</th>
                        <th>Etichetta Domanda</th>
                        <th>Tipo Input</th>
                        <th>Opzioni</th>
                        <th>Destinazione</th>
                        <th>Obbligatorio</th>
                        <th class="text-end">Azione</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($campi_custom)): ?>
                        <tr><td colspan="7" class="text-center p-4 text-muted">Nessun campo personalizzato definito per questa area di lavoro.</td></tr>
                    <?php else: ?>
                        <?php foreach ($campi_custom as $cf): ?>
                            <tr>
                                <td>#<?php echo $cf['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($cf['etichetta']); ?></strong></td>
                                <td><span class="badge bg-secondary"><?php echo strtoupper($cf['tipo_campo']); ?></span></td>
                                <td><small><?php echo htmlspecialchars($cf['opzioni_select'] ?: '-'); ?></small></td>
                                <td>
                                    <?php if (empty($cf['evento_id']) || (int)$cf['evento_id'] === 0): ?>
                                        <span class="badge bg-danger">⭐ TUTTI GLI EVENTI</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">🎯 <?php echo htmlspecialchars($cf['evento_titolo']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $cf['obbligatorio'] ? '<span class="badge bg-success">Sì</span>' : '<span class="badge bg-light text-dark border">No</span>'; ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-outline-primary btn-sm me-1" data-bs-toggle="modal" data-bs-target="#modEditCampoCustom<?php echo $cf['id']; ?>">
                                        <i class="fa fa-edit"></i> Modifica
                                    </button>
                                    <a href="?del_campo_custom=<?php echo $cf['id']; ?>&p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-danger btn-sm" data-confirm="Sicuro di voler eliminare definitivamente questo campo dal form?">
                                        <i class="fa fa-trash"></i> Elimina
                                    </a>
                                </td>
                            </tr>

                            <!-- MODALE MODIFICA CAMPO PERSONALIZZATO -->
                            <div class="modal fade" id="modEditCampoCustom<?php echo $cf['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="campo_id" value="<?php echo $cf['id']; ?>">
                                            <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                            
                                            <div class="modal-header bg-primary text-white py-2">
                                                <h6 class="modal-title fw-bold"><i class="fa fa-edit me-1"></i> Modifica Campo Personalizzato</h6>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            
                                            <div class="modal-body text-start row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-bold">Evento di Destinazione</label>
                                                    <select name="evento_id" class="form-select form-select-sm fw-bold">
                                                        <option value="0">⭐ TUTTI GLI EVENTI DI QUESTA PAGINA</option>
                                                        <?php foreach ($tutti_gli_eventi as $ev): ?>
                                                            <option value="<?php echo $ev['id']; ?>" <?php echo ($cf['evento_id'] == $ev['id']) ? 'selected' : ''; ?>>
                                                                🎯 Solo: <?php echo htmlspecialchars($ev['titolo']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-bold">Etichetta Campo</label>
                                                    <input type="text" name="etichetta" class="form-control form-control-sm" value="<?php echo htmlspecialchars($cf['etichetta']); ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-bold">Tipo di Input</label>
                                                    <select name="tipo_campo" class="form-select form-select-sm">
                                                        <option value="text" <?php echo $cf['tipo_campo'] === 'text' ? 'selected' : ''; ?>>Testo Libero (Riga singola)</option>
                                                        <option value="textarea" <?php echo $cf['tipo_campo'] === 'textarea' ? 'selected' : ''; ?>>Testo Lungo (Multiriga)</option>
                                                        <option value="number" <?php echo $cf['tipo_campo'] === 'number' ? 'selected' : ''; ?>>Numero</option>
                                                        <option value="date" <?php echo $cf['tipo_campo'] === 'date' ? 'selected' : ''; ?>>Data</option>
                                                        <option value="select" <?php echo $cf['tipo_campo'] === 'select' ? 'selected' : ''; ?>>Menu a Tendina (Select)</option>
                                                        <option value="radio" <?php echo $cf['tipo_campo'] === 'radio' ? 'selected' : ''; ?>>Scelta Singola (Radio Button)</option>
                                                        <option value="checkbox" <?php echo $cf['tipo_campo'] === 'checkbox' ? 'selected' : ''; ?>>Checkbox Singolo (Spunta)</option>
                                                        <option value="checkboxes" <?php echo $cf['tipo_campo'] === 'checkboxes' ? 'selected' : ''; ?>>Checkbox Multiplo</option>
                                                        <option value="file" <?php echo $cf['tipo_campo'] === 'file' ? 'selected' : ''; ?>>📁 Upload File / Allegato</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small fw-bold">Opzioni (separate da virgola)</label>
                                                    <input type="text" name="opzioni_select" class="form-control form-control-sm" value="<?php echo htmlspecialchars($cf['opzioni_select']); ?>">
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" name="obbligatorio" id="editObbl<?php echo $cf['id']; ?>" value="1" <?php echo $cf['obbligatorio'] ? 'checked' : ''; ?>>
                                                        <label class="form-check-label small fw-bold" for="editObbl<?php echo $cf['id']; ?>">Obbligatorio per procedere con l'iscrizione</label>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="modal-footer py-2">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                                                <button type="submit" name="edit_campo_custom" class="btn btn-primary btn-sm fw-bold">Salva Modifiche Campo</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>
