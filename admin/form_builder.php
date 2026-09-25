<?php
// form_builder.php - Gestione Campi Personalizzati (Form Builder)
require_once 'admin_header.php';

if (!$can_manage_form) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}

// RBAC: un campo è modificabile solo se appartiene all'area corrente;
// i gestori di singolo evento possono toccare solo i campi dei propri eventi.
function campo_form_autorizzato($conn, int $cf_id, int $p_id, bool $is_area_manager, array $allowed_ev): bool {
    $res = $conn->query("SELECT evento_id FROM campi_form WHERE id = $cf_id AND pagina_id = $p_id LIMIT 1");
    if (!$res || !($row = $res->fetch_assoc())) return false;
    return $is_area_manager || in_array((int)$row['evento_id'], $allowed_ev, true);
}
function evento_form_autorizzato(int $ev_id, bool $is_area_manager, array $allowed_ev): bool {
    return $is_area_manager || ($ev_id > 0 && in_array($ev_id, $allowed_ev, true));
}
$allowed_ev_int = array_map('intval', $allowed_events_ids);

// ==============================================================================
// BACKEND AJAX: salva ordine
// ==============================================================================
if (isset($_POST['ajax_salva_ordine'])) {
    header('Content-Type: application/json');
    csrf_verify($_POST['csrf_token'] ?? '');
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    foreach ($ids as $pos => $id) {
        if (!campo_form_autorizzato($conn, $id, $filtro_p, $is_area_manager, $allowed_ev_int)) continue;
        $conn->query("UPDATE campi_form SET ordine=" . (int)$pos . " WHERE id=$id");
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ==============================================================================
// BACKEND: sposta su/giù
// ==============================================================================
if (isset($_POST['move_campo'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $cf_id = (int)$_POST['campo_id'];
    if (!campo_form_autorizzato($conn, $cf_id, $filtro_p, $is_area_manager, $allowed_ev_int)) { http_response_code(403); die("Accesso negato."); }
    $dir   = ($_POST['dir'] ?? '') === 'up' ? -15 : 15;
    $r_ord = $conn->query("SELECT ordine FROM campi_form WHERE id=$cf_id LIMIT 1");
    if ($r_ord && $row_ord = $r_ord->fetch_assoc()) {
        $new_ord = max(0, (int)$row_ord['ordine'] + $dir);
        $conn->query("UPDATE campi_form SET ordine=$new_ord WHERE id=$cf_id");
    }
    admin_redirect("form_builder.php?p_id=$filtro_p");
}

// ==============================================================================
// BACKEND: aggiungi / modifica / elimina
// ==============================================================================
if (isset($_POST['add_campo_custom'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $p_id      = $filtro_p; // i permessi sono calcolati su p_id dell'URL: non fidarsi del campo POST
    $ev_id     = (int)($_POST['evento_id'] ?? 0);
    if (!evento_form_autorizzato($ev_id, $is_area_manager, $allowed_ev_int)) { http_response_code(403); die("Accesso negato."); }
    $ev_id_sql = $ev_id > 0 ? $ev_id : "NULL";
    $etichetta = $conn->real_escape_string(trim($_POST['etichetta'] ?? ''));
    $tipo      = $conn->real_escape_string($_POST['tipo_campo'] ?? 'text');
    $opzioni   = $conn->real_escape_string(trim($_POST['opzioni_select'] ?? ''));
    $req       = isset($_POST['obbligatorio']) ? 1 : 0;
    $ord       = (int)($_POST['ordine'] ?? 99);
    $nome_c    = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $etichetta)));
    if (empty($nome_c)) $nome_c = 'campo_' . time();
    // Condizione
    $cond_json = 'NULL';
    $if_id  = (int)($_POST['cond_campo_id'] ?? 0);
    $if_val = trim($_POST['cond_valore'] ?? '');
    if ($if_id > 0 && $if_val !== '') {
        $cond_json = "'" . $conn->real_escape_string(json_encode(['se_id' => $if_id, 'se_val' => $if_val])) . "'";
    }
    $conn->query("INSERT INTO campi_form (pagina_id, evento_id, nome_campo, etichetta, tipo_campo, opzioni_select, obbligatorio, ordine, condizione_json) VALUES ($p_id, $ev_id_sql, '$nome_c', '$etichetta', '$tipo', '$opzioni', $req, $ord, $cond_json)");
    flash_set("Campo aggiunto!");
    admin_redirect("form_builder.php?p_id=$p_id");
}

if (isset($_POST['edit_campo_custom'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $cf_id     = (int)$_POST['campo_id'];
    $p_id      = $filtro_p;
    $ev_id     = (int)($_POST['evento_id'] ?? 0);
    if (!campo_form_autorizzato($conn, $cf_id, $filtro_p, $is_area_manager, $allowed_ev_int)
        || !evento_form_autorizzato($ev_id, $is_area_manager, $allowed_ev_int)) { http_response_code(403); die("Accesso negato."); }
    $ev_id_sql = $ev_id > 0 ? $ev_id : "NULL";
    $etichetta = $conn->real_escape_string(trim($_POST['etichetta'] ?? ''));
    $tipo      = $conn->real_escape_string($_POST['tipo_campo'] ?? 'text');
    $opzioni   = $conn->real_escape_string(trim($_POST['opzioni_select'] ?? ''));
    $req       = isset($_POST['obbligatorio']) ? 1 : 0;
    $cond_set  = 'NULL';
    $if_id  = (int)($_POST['cond_campo_id'] ?? 0);
    $if_val = trim($_POST['cond_valore'] ?? '');
    if ($if_id > 0 && $if_val !== '') {
        $cond_set = "'" . $conn->real_escape_string(json_encode(['se_id' => $if_id, 'se_val' => $if_val])) . "'";
    }
    $conn->query("UPDATE campi_form SET evento_id=$ev_id_sql, etichetta='$etichetta', tipo_campo='$tipo', opzioni_select='$opzioni', obbligatorio=$req, condizione_json=$cond_set WHERE id=$cf_id");
    flash_set("Campo modificato!");
    admin_redirect("form_builder.php?p_id=$p_id");
}

if (isset($_GET['del_campo_custom'])) {
    csrf_verify($_GET['csrf'] ?? '');
    $cf_id_del = (int)$_GET['del_campo_custom'];
    if (!campo_form_autorizzato($conn, $cf_id_del, $filtro_p, $is_area_manager, $allowed_ev_int)) { http_response_code(403); die("Accesso negato."); }
    $conn->query("DELETE FROM campi_form WHERE id=$cf_id_del");
    flash_set("Campo eliminato.");
    admin_redirect("form_builder.php?p_id=$filtro_p");
}

// ==============================================================================
// DATI
// ==============================================================================
$tutti_gli_eventi = [];
$res_ev = $conn->query("SELECT id, titolo FROM eventi e WHERE pagina_id=$filtro_p AND archiviato=0 ORDER BY ordine ASC, id DESC");
if ($res_ev) while ($row = $res_ev->fetch_assoc()) $tutti_gli_eventi[] = $row;

$campi_custom = [];
$res_cf = $conn->query("SELECT cf.*, COALESCE(e.titolo, '') AS evento_titolo FROM campi_form cf LEFT JOIN eventi e ON cf.evento_id = e.id WHERE cf.pagina_id=$filtro_p OR (cf.evento_id > 0 AND e.pagina_id=$filtro_p) ORDER BY cf.ordine ASC, cf.id ASC");
if ($res_cf) while ($r = $res_cf->fetch_assoc()) $campi_custom[] = $r;

// Tipi di campo disponibili
$tipo_info = [
    'text'       => ['icon' => 'fa-font',          'col' => '#3b82f6', 'label' => 'Testo'],
    'email'      => ['icon' => 'fa-at',             'col' => '#6366f1', 'label' => 'Email'],
    'tel'        => ['icon' => 'fa-phone',          'col' => '#8b5cf6', 'label' => 'Telefono'],
    'url'        => ['icon' => 'fa-link',           'col' => '#0ea5e9', 'label' => 'URL/Link'],
    'textarea'   => ['icon' => 'fa-align-left',    'col' => '#64748b', 'label' => 'Testo lungo'],
    'number'     => ['icon' => 'fa-hashtag',       'col' => '#f59e0b', 'label' => 'Numero'],
    'date'       => ['icon' => 'fa-calendar',      'col' => '#10b981', 'label' => 'Data'],
    'time'       => ['icon' => 'fa-clock',         'col' => '#14b8a6', 'label' => 'Orario'],
    'select'     => ['icon' => 'fa-chevron-down',  'col' => '#7c3aed', 'label' => 'Tendina'],
    'radio'      => ['icon' => 'fa-dot-circle',    'col' => '#ec4899', 'label' => 'Scelta singola'],
    'checkbox'   => ['icon' => 'fa-check-square',  'col' => '#06b6d4', 'label' => 'Checkbox'],
    'checkboxes' => ['icon' => 'fa-list-check',    'col' => '#2563eb', 'label' => 'Scelta multipla'],
    'file'       => ['icon' => 'fa-file-upload',   'col' => '#f97316', 'label' => 'Upload file'],
    'rating'     => ['icon' => 'fa-star',          'col' => '#eab308', 'label' => 'Valutazione stelle'],
    'hidden'     => ['icon' => 'fa-eye-slash',     'col' => '#94a3b8', 'label' => 'Campo nascosto'],
    'separator'  => ['icon' => 'fa-grip-lines',    'col' => '#334155', 'label' => 'Separatore/Titolo'],
];

// Tipi che richiedono opzioni
$tipi_con_opzioni = ['select', 'radio', 'checkboxes'];
// Tipi su cui possono appoggiarsi condizioni (hanno valori predefiniti)
$tipi_condizionabili = ['select', 'radio', 'checkboxes', 'checkbox'];
// Tipi che NON sono input reali (non richiedono obbligatorio)
$tipi_non_input = ['separator', 'hidden'];
?>

<!-- ============================================================
     FRONT-END
     ============================================================ -->
<style>
.fb-card { border:1px solid #e2e8f0; border-radius:12px; background:#fff; box-shadow:0 1px 5px rgba(0,0,0,.05); margin-bottom:6px; overflow:hidden; transition:box-shadow .15s; }
.fb-card:hover { box-shadow:0 3px 10px rgba(0,0,0,.1); }
.fb-card-header { padding:10px 14px; display:flex; align-items:center; gap:10px; }
.fb-type-dot { width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0; }
.fb-dest-badge { font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:10px; }
.fb-add-panel { border:2px dashed #cbd5e1; border-radius:14px; background:#f8fafc; }
.fb-drag-handle { cursor:grab; color:#cbd5e1; padding:0 4px; font-size:1rem; }
.fb-drag-handle:active { cursor:grabbing; }
.sortable-ghost { opacity:.4; background:#e0f2fe !important; }
.cond-panel { background:#fefce8; border:1px dashed #fde68a; border-radius:8px; padding:10px 12px; margin-top:8px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-list-check text-primary me-2"></i>Form Builder</h4>
    <button class="btn btn-sm btn-danger fw-bold" style="border-radius:8px;" type="button" data-bs-toggle="collapse" data-bs-target="#panelAddCampo">
        <i class="fa fa-plus me-1"></i>Aggiungi Campo
    </button>
</div>

<!-- ── PANNELLO AGGIUNGI ─────────────────────────────────────── -->
<div class="collapse mb-4" id="panelAddCampo">
<div class="fb-add-panel p-4">
    <div class="fw-bold text-primary mb-3" style="font-size:.85rem;"><i class="fa fa-plus-circle me-1"></i>Nuovo campo personalizzato</div>
    <form method="POST" class="row g-3" id="formAddCampo">
        <?php csrf_field(); ?>
        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">

        <div class="col-md-4">
            <label class="form-label small fw-bold">Etichetta <span class="text-danger">*</span></label>
            <input type="text" name="etichetta" class="form-control form-control-sm" required placeholder="Es. Restrizioni alimentari...">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Tipo di Input</label>
            <select name="tipo_campo" class="form-select form-select-sm" id="addTipoCampo" onchange="onTipoChange('add',this.value)">
                <?php foreach ($tipo_info as $tv => $td): ?>
                    <option value="<?php echo $tv; ?>"><?php echo $td['label']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold">Evento destinazione</label>
            <select name="evento_id" class="form-select form-select-sm">
                <option value="0">Tutti gli eventi dell'area</option>
                <?php foreach ($tutti_gli_eventi as $ev): ?>
                    <option value="<?php echo $ev['id']; ?>">Solo: <?php echo htmlspecialchars($ev['titolo']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <label class="form-label small fw-bold">Ordine</label>
            <input type="number" name="ordine" class="form-control form-control-sm" value="<?php echo count($campi_custom) * 10; ?>" min="0">
        </div>
        <div class="col-md-1 d-flex align-items-end" id="addObblWrap">
            <div class="form-check form-switch pb-1">
                <input class="form-check-input" type="checkbox" name="obbligatorio" id="chkObbl" value="1" checked>
                <label class="form-check-label small fw-bold" for="chkObbl">Obbl.</label>
            </div>
        </div>

        <!-- Opzioni (select/radio/checkboxes) -->
        <div class="col-12 d-none" id="addOpzRow">
            <label class="form-label small fw-bold">Opzioni <small class="text-muted fw-normal">(separate da virgola)</small></label>
            <input type="text" name="opzioni_select" class="form-control form-control-sm" placeholder="Es. Opzione A, Opzione B, Opzione C">
        </div>

        <!-- Valore default (hidden) -->
        <div class="col-12 d-none" id="addDefaultRow">
            <label class="form-label small fw-bold">Valore predefinito <small class="text-muted fw-normal">(inviato silenziosamente)</small></label>
            <input type="text" name="opzioni_select" class="form-control form-control-sm" placeholder="Es. sorgente=form_evento">
        </div>

        <!-- Numero stelle (rating) -->
        <div class="col-12 d-none" id="addRatingRow">
            <label class="form-label small fw-bold">Scala valutazione <small class="text-muted fw-normal">(es: 1,2,3,4,5)</small></label>
            <input type="text" name="opzioni_select" class="form-control form-control-sm" value="1,2,3,4,5">
        </div>

        <!-- Campo condizionale -->
        <?php if (count($campi_custom) > 0): ?>
        <div class="col-12">
            <div class="form-check form-switch mb-1">
                <input class="form-check-input" type="checkbox" id="addCondToggle" onchange="document.getElementById('addCondPanel').classList.toggle('d-none',!this.checked)">
                <label class="form-check-label small fw-bold" for="addCondToggle">Mostra solo se un altro campo ha un certo valore</label>
            </div>
            <div class="cond-panel d-none" id="addCondPanel">
                <div class="row g-2">
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">Se il campo...</label>
                        <select name="cond_campo_id" class="form-select form-select-sm">
                            <option value="0">— seleziona campo —</option>
                            <?php foreach ($campi_custom as $cc):
                                if (!in_array($cc['tipo_campo'], $tipi_condizionabili)) continue; ?>
                                <option value="<?php echo $cc['id']; ?>"><?php echo htmlspecialchars($cc['etichetta']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-bold mb-1">...vale esattamente</label>
                        <input type="text" name="cond_valore" class="form-control form-control-sm" placeholder="Es. Sì, Altro, Opzione B...">
                    </div>
                </div>
                <div class="mt-1" style="font-size:.7rem;color:#92400e;"><i class="fa fa-lightbulb me-1"></i>Il campo sarà nascosto finché la condizione non è soddisfatta.</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-12 d-flex gap-2">
            <button type="submit" name="add_campo_custom" class="btn btn-sm btn-danger fw-bold px-4"><i class="fa fa-plus me-1"></i>Aggiungi</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#panelAddCampo">Annulla</button>
        </div>
    </form>
</div>
</div>

<!-- ── LISTA CAMPI ──────────────────────────────────────────── -->
<?php if (empty($campi_custom)): ?>
<div class="text-center py-5 text-muted">
    <i class="fa fa-list-check fa-3x mb-3 d-block" style="opacity:.2;"></i>
    <p class="fw-semibold mb-2">Nessun campo personalizzato per quest'area.</p>
    <button class="btn btn-danger btn-sm fw-bold" data-bs-toggle="collapse" data-bs-target="#panelAddCampo"><i class="fa fa-plus me-1"></i>Aggiungi il primo campo</button>
</div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-2">
    <small class="text-muted fw-semibold"><?php echo count($campi_custom); ?> campo<?php echo count($campi_custom) != 1 ? 'i' : ''; ?> — trascina per riordinare</small>
    <small class="text-muted"><i class="fa fa-grip-vertical me-1"></i>drag &amp; drop abilitato</small>
</div>

<div id="fbSortable">
<?php foreach ($campi_custom as $cf):
    $ti       = $tipo_info[$cf['tipo_campo']] ?? ['icon'=>'fa-question','col'=>'#94a3b8','label'=>ucfirst($cf['tipo_campo'])];
    $is_all   = empty($cf['evento_id']) || (int)$cf['evento_id'] === 0;
    $cond     = $cf['condizione_json'] ? json_decode($cf['condizione_json'], true) : null;
    $is_sep   = $cf['tipo_campo'] === 'separator';
?>
<div class="fb-card" data-id="<?php echo $cf['id']; ?>">
    <div class="fb-card-header">
        <!-- Drag handle -->
        <span class="fb-drag-handle" title="Trascina per riordinare"><i class="fa fa-grip-vertical"></i></span>

        <!-- Icona tipo -->
        <div class="fb-type-dot" style="background:<?php echo $ti['col']; ?>1a;color:<?php echo $ti['col']; ?>;">
            <i class="fa <?php echo $ti['icon']; ?>"></i>
        </div>

        <!-- Etichetta + info -->
        <div style="flex:1;min-width:0;">
            <div class="fw-semibold text-dark" style="font-size:.88rem;"><?php echo htmlspecialchars($cf['etichetta']); ?></div>
            <div class="d-flex gap-2 flex-wrap mt-1">
                <span style="display:inline-flex;align-items:center;gap:4px;padding:1px 7px;border-radius:6px;background:<?php echo $ti['col']; ?>18;color:<?php echo $ti['col']; ?>;font-size:.69rem;font-weight:700;">
                    <i class="fa <?php echo $ti['icon']; ?>"></i><?php echo $ti['label']; ?>
                </span>
                <?php if (!empty($cf['opzioni_select']) && !$is_sep): ?>
                    <span style="font-size:.67rem;color:#64748b;background:#f1f5f9;padding:1px 6px;border-radius:5px;"><?php echo htmlspecialchars(mb_strimwidth($cf['opzioni_select'], 0, 40, '…')); ?></span>
                <?php endif; ?>
                <?php if ($cond): ?>
                    <span style="font-size:.67rem;background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:5px;font-weight:600;"><i class="fa fa-code-branch me-1"></i>Condizionale</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Destinazione -->
        <div class="d-none d-sm-block" style="min-width:120px;">
            <?php if ($is_all): ?>
                <span class="fb-dest-badge" style="background:#fee2e2;color:#991b1b;">Tutti gli eventi</span>
            <?php else: ?>
                <span class="fb-dest-badge" style="background:#dbeafe;color:#1d4ed8;"><?php echo htmlspecialchars(mb_strimwidth($cf['evento_titolo'], 0, 22, '…')); ?></span>
            <?php endif; ?>
        </div>

        <!-- Obbligatorio -->
        <?php if (!$is_sep && $cf['tipo_campo'] !== 'hidden'): ?>
        <div class="d-none d-md-block" style="min-width:72px;text-align:center;">
            <?php if ($cf['obbligatorio']): ?>
                <span style="font-size:.67rem;background:#dcfce7;color:#166534;padding:1px 7px;border-radius:8px;font-weight:700;"><i class="fa fa-asterisk"></i> Obbl.</span>
            <?php else: ?>
                <span style="font-size:.67rem;background:#f1f5f9;color:#94a3b8;padding:1px 7px;border-radius:8px;">Opz.</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Ordine # -->
        <div class="d-none d-lg-block text-muted" style="font-size:.68rem;min-width:28px;text-align:center;">#<?php echo (int)$cf['ordine']; ?></div>

        <!-- Frecce ordinamento -->
        <div class="d-flex flex-column gap-0" style="gap:1px!important;">
            <form method="POST" class="m-0">
                <?php csrf_field(); ?>
                <input type="hidden" name="campo_id" value="<?php echo $cf['id']; ?>">
                <input type="hidden" name="dir" value="up">
                <button type="submit" name="move_campo" class="btn p-0" style="width:20px;height:18px;font-size:.6rem;border:1px solid #e2e8f0;border-radius:4px 4px 0 0;background:#f8fafc;color:#64748b;" title="Su"><i class="fa fa-chevron-up"></i></button>
            </form>
            <form method="POST" class="m-0">
                <?php csrf_field(); ?>
                <input type="hidden" name="campo_id" value="<?php echo $cf['id']; ?>">
                <input type="hidden" name="dir" value="down">
                <button type="submit" name="move_campo" class="btn p-0" style="width:20px;height:18px;font-size:.6rem;border:1px solid #e2e8f0;border-radius:0 0 4px 4px;border-top:none;background:#f8fafc;color:#64748b;" title="Giù"><i class="fa fa-chevron-down"></i></button>
            </form>
        </div>

        <!-- Azioni -->
        <div class="d-flex gap-1">
            <button type="button" class="btn btn-outline-primary btn-sm" style="border-radius:7px;width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;"
                data-bs-toggle="modal" data-bs-target="#modEdit<?php echo $cf['id']; ?>" title="Modifica">
                <i class="fa fa-edit" style="font-size:.75rem;"></i>
            </button>
            <a href="?del_campo_custom=<?php echo $cf['id']; ?>&p_id=<?php echo $filtro_p; ?>&csrf=<?php echo urlencode(csrf_token()); ?>"
                class="btn btn-outline-danger btn-sm" style="border-radius:7px;width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;"
                data-confirm="Eliminare definitivamente questo campo?" title="Elimina">
                <i class="fa fa-trash" style="font-size:.75rem;"></i>
            </a>
        </div>
    </div>
</div>

<!-- MODALE MODIFICA -->
<div class="modal fade" id="modEdit<?php echo $cf['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="campo_id" value="<?php echo $cf['id']; ?>">
                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                <div class="modal-header py-2" style="background:#1e293b;">
                    <h6 class="modal-title fw-bold text-white"><i class="fa fa-edit me-1"></i>Modifica: <?php echo htmlspecialchars(mb_strimwidth($cf['etichetta'],0,40,'…')); ?></h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-5">
                        <label class="form-label small fw-bold">Etichetta</label>
                        <input type="text" name="etichetta" class="form-control form-control-sm" value="<?php echo htmlspecialchars($cf['etichetta']); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Tipo di Input</label>
                        <select name="tipo_campo" class="form-select form-select-sm" onchange="onTipoChange('edit<?php echo $cf['id']; ?>',this.value)">
                            <?php foreach ($tipo_info as $tv => $td): ?>
                                <option value="<?php echo $tv; ?>" <?php echo $cf['tipo_campo']===$tv?'selected':''; ?>><?php echo $td['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Evento destinazione</label>
                        <select name="evento_id" class="form-select form-select-sm">
                            <option value="0">Tutti gli eventi</option>
                            <?php foreach ($tutti_gli_eventi as $ev): ?>
                                <option value="<?php echo $ev['id']; ?>" <?php echo ($cf['evento_id']==$ev['id'])?'selected':''; ?>>
                                    Solo: <?php echo htmlspecialchars($ev['titolo']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Opzioni (select/radio/checkboxes) -->
                    <div class="col-12" id="editOpzRow<?php echo $cf['id']; ?>"
                        <?php echo !in_array($cf['tipo_campo'], $tipi_con_opzioni) ? 'style="display:none;"' : ''; ?>>
                        <label class="form-label small fw-bold">Opzioni <small class="text-muted fw-normal">(separate da virgola)</small></label>
                        <input type="text" name="opzioni_select" class="form-control form-control-sm"
                            value="<?php echo htmlspecialchars($cf['opzioni_select']); ?>"
                            placeholder="Es. Opzione A, Opzione B, Opzione C">
                    </div>

                    <!-- Valore default (hidden) -->
                    <div class="col-12" id="editDefaultRow<?php echo $cf['id']; ?>"
                        <?php echo $cf['tipo_campo'] !== 'hidden' ? 'style="display:none;"' : ''; ?>>
                        <label class="form-label small fw-bold">Valore predefinito</label>
                        <input type="text" name="opzioni_select" class="form-control form-control-sm"
                            value="<?php echo htmlspecialchars($cf['opzioni_select']); ?>">
                    </div>

                    <!-- Rating -->
                    <div class="col-12" id="editRatingRow<?php echo $cf['id']; ?>"
                        <?php echo $cf['tipo_campo'] !== 'rating' ? 'style="display:none;"' : ''; ?>>
                        <label class="form-label small fw-bold">Scala valutazione</label>
                        <input type="text" name="opzioni_select" class="form-control form-control-sm"
                            value="<?php echo htmlspecialchars($cf['opzioni_select'] ?: '1,2,3,4,5'); ?>">
                    </div>

                    <!-- Obbligatorio -->
                    <div class="col-12" id="editObblRow<?php echo $cf['id']; ?>"
                        <?php echo in_array($cf['tipo_campo'], $tipi_non_input) ? 'style="display:none;"' : ''; ?>>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="obbligatorio"
                                id="editObbl<?php echo $cf['id']; ?>" value="1" <?php echo $cf['obbligatorio']?'checked':''; ?>>
                            <label class="form-check-label small fw-bold" for="editObbl<?php echo $cf['id']; ?>">Campo obbligatorio</label>
                        </div>
                    </div>

                    <!-- Campo condizionale -->
                    <?php
                    $has_cond = !empty($cond);
                    $cond_campo_id = $has_cond ? (int)($cond['se_id'] ?? 0) : 0;
                    $cond_valore   = $has_cond ? ($cond['se_val'] ?? '') : '';
                    $other_campi   = array_filter($campi_custom, function($c) use ($cf, $tipi_condizionabili) { return $c['id'] != $cf['id'] && in_array($c['tipo_campo'], $tipi_condizionabili); });
                    if (count($other_campi) > 0):
                    ?>
                    <div class="col-12">
                        <div class="form-check form-switch mb-1">
                            <input class="form-check-input" type="checkbox" id="editCondToggle<?php echo $cf['id']; ?>"
                                <?php echo $has_cond ? 'checked' : ''; ?>
                                onchange="document.getElementById('editCondPanel<?php echo $cf['id']; ?>').classList.toggle('d-none',!this.checked)">
                            <label class="form-check-label small fw-bold" for="editCondToggle<?php echo $cf['id']; ?>">Mostra solo se un altro campo vale...</label>
                        </div>
                        <div class="cond-panel <?php echo $has_cond ? '' : 'd-none'; ?>" id="editCondPanel<?php echo $cf['id']; ?>">
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label small fw-bold mb-1">Se il campo...</label>
                                    <select name="cond_campo_id" class="form-select form-select-sm">
                                        <option value="0">— seleziona —</option>
                                        <?php foreach ($other_campi as $cc): ?>
                                            <option value="<?php echo $cc['id']; ?>" <?php echo ($cond_campo_id==$cc['id'])?'selected':''; ?>>
                                                <?php echo htmlspecialchars($cc['etichetta']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label small fw-bold mb-1">...vale esattamente</label>
                                    <input type="text" name="cond_valore" class="form-control form-control-sm"
                                        value="<?php echo htmlspecialchars($cond_valore); ?>"
                                        placeholder="Es. Sì, Altro, Opzione B...">
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="edit_campo_custom" class="btn btn-primary btn-sm fw-bold">Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div><!-- /#fbSortable -->

<?php endif; ?>

<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
// ── Tipi di campo: visibilità campi correlati ─────────────────────────────────
var TIPI_OPZIONI  = ['select','radio','checkboxes'];
var TIPI_DEFAULT  = ['hidden'];
var TIPI_RATING   = ['rating'];
var TIPI_NO_OBBL  = ['separator','hidden'];

function onTipoChange(prefix, tipo) {
    var rows = {
        opz:     document.getElementById(prefix + 'OpzRow')     || document.getElementById('add' + 'OpzRow'),
        def:     document.getElementById(prefix + 'DefaultRow') || document.getElementById('add' + 'DefaultRow'),
        rat:     document.getElementById(prefix + 'RatingRow')  || document.getElementById('add' + 'RatingRow'),
        obbl:    document.getElementById(prefix + 'ObblRow')    || document.getElementById('add' + 'ObblWrap'),
    };
    // Normalizza ID per il form add vs edit modal
    var isAdd = (prefix === 'add');
    if (isAdd) {
        rows = {
            opz:  document.getElementById('addOpzRow'),
            def:  document.getElementById('addDefaultRow'),
            rat:  document.getElementById('addRatingRow'),
            obbl: document.getElementById('addObblWrap'),
        };
    } else {
        rows = {
            opz:  document.getElementById('editOpzRow' + prefix.replace('edit','')),
            def:  document.getElementById('editDefaultRow' + prefix.replace('edit','')),
            rat:  document.getElementById('editRatingRow' + prefix.replace('edit','')),
            obbl: document.getElementById('editObblRow' + prefix.replace('edit','')),
        };
    }
    function show(el) { if (el) { el.classList.remove('d-none'); el.style.display = ''; } }
    function hide(el) { if (el) { el.classList.add('d-none'); el.style.display = 'none'; } }
    hide(rows.opz); hide(rows.def); hide(rows.rat);
    if (TIPI_OPZIONI.indexOf(tipo) >= 0)  show(rows.opz);
    if (TIPI_DEFAULT.indexOf(tipo) >= 0)  show(rows.def);
    if (TIPI_RATING.indexOf(tipo) >= 0)   show(rows.rat);
    if (TIPI_NO_OBBL.indexOf(tipo) >= 0)  hide(rows.obbl);
    else                                  show(rows.obbl);
}

// Inizializza visibilità per ogni modale edit all'apertura
document.querySelectorAll('[id^="modEdit"]').forEach(function(modal) {
    modal.addEventListener('shown.bs.modal', function() {
        var sel = modal.querySelector('select[name="tipo_campo"]');
        if (sel) {
            var id = modal.id.replace('modEdit','');
            onTipoChange('edit' + id, sel.value);
        }
    });
});

// ── Drag & drop con SortableJS ────────────────────────────────────────────────
var sortEl = document.getElementById('fbSortable');
if (sortEl) {
    Sortable.create(sortEl, {
        handle: '.fb-drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        onEnd: function() {
            var ids = [];
            sortEl.querySelectorAll('.fb-card[data-id]').forEach(function(c) { ids.push(c.dataset.id); });
            var fd = new FormData();
            fd.append('ajax_salva_ordine', '1');
            fd.append('p_id', '<?php echo $filtro_p; ?>');
            fd.append('csrf_token', '<?php echo htmlspecialchars(csrf_token()); ?>');
            ids.forEach(function(id, i) { fd.append('ids[]', id); });
            fetch('form_builder.php?p_id=<?php echo $filtro_p; ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(d) { if (d.ok) { /* ordine salvato silenziosamente */ } })
                .catch(function() {});
        }
    });
}
</script>

<?php require_once 'admin_footer.php'; ?>
