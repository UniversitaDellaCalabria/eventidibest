<?php
// menu.php - Gestione Voci di Menu e Navigazione Portale (Supporto 3 Livelli)
require_once 'admin_header.php';

// Controllo Permessi RBAC (Solo Full Admin)
if (!$is_full_admin) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Questa sezione è riservata agli amministratori globali del sistema.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}

// Utility per i badge visibilità
if (!function_exists('getVisibilitaBadge')) {
    function getVisibilitaBadge($v_id, $ruoli) {
        if ($v_id == 0) return '<span class="badge bg-success shadow-sm">🌐 Tutti (Pubblico)</span>';
        if ($v_id == -1) return '<span class="badge bg-warning text-dark shadow-sm">🔑 Autenticati SSO</span>';
        foreach($ruoli as $r) { if ($r['id'] == $v_id) return '<span class="badge bg-dark shadow-sm">🔒 Solo ' . htmlspecialchars($r['nome']) . '</span>'; }
        return '<span class="badge bg-secondary shadow-sm">Tutti</span>';
    }
}

// ==============================================================================
// BLOCCO ELABORAZIONE AZIONI BACKEND
// ==============================================================================

// 1. Aggiunta
if (isset($_POST['add_menu_voce'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $label = $conn->real_escape_string($_POST['etichetta'] ?? '');
    $url = $conn->real_escape_string($_POST['url'] ?? '');
    $ord = (int)($_POST['ordine'] ?? 0);
    $genitore_id = (int)($_POST['genitore_id'] ?? 0);
    $visibilita_id = (int)($_POST['ruolo_visibilita_id'] ?? 0);
    $scheda = isset($_POST['apri_nuova_scheda']) ? 1 : 0;
    
    $conn->query("INSERT INTO menu_voci (genitore_id, etichetta, url, ordine, apri_nuova_scheda, ruolo_visibilita_id, visibile) 
                  VALUES ($genitore_id, '$label', '$url', $ord, $scheda, $visibilita_id, 1)");
                  
    flash_set("Voce di menu aggiunta con successo!");
    admin_redirect("menu.php?p_id=$filtro_p");
}

// 2. Modifica
if (isset($_POST['edit_menu_voce'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['menu_id'];
    $label = $conn->real_escape_string($_POST['etichetta'] ?? '');
    $url = $conn->real_escape_string($_POST['url'] ?? '');
    $ord = (int)($_POST['ordine'] ?? 0);
    $genitore_id = (int)($_POST['genitore_id'] ?? 0);
    $visibilita_id = (int)($_POST['ruolo_visibilita_id'] ?? 0);
    $scheda = isset($_POST['apri_nuova_scheda']) ? 1 : 0;
    
    $conn->query("UPDATE menu_voci SET genitore_id=$genitore_id, etichetta='$label', url='$url', ordine=$ord, apri_nuova_scheda=$scheda, ruolo_visibilita_id=$visibilita_id WHERE id=$id");
    
    flash_set("Voce di menu aggiornata con successo!");
    admin_redirect("menu.php?p_id=$filtro_p");
}

// 3. Toggle Visibilità (On/Off)
if (isset($_POST['toggle_vis'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $id = (int)$_POST['toggle_vis'];
    $conn->query("UPDATE menu_voci SET visibile = 1 - COALESCE(visibile, 1) WHERE id = $id");
    flash_set("Stato pubblicazione menu modificato.");
    admin_redirect("menu.php?p_id=$filtro_p");
}

// 4. Eliminazione
if (isset($_POST['del_menu'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $m_del_id = (int)$_POST['del_menu'];
    // Elimina il padre, i figli e i nipoti (cascata grezza gestita da logica se innestata a 3 livelli)
    $res_figli = $conn->query("SELECT id FROM menu_voci WHERE genitore_id = $m_del_id");
    if ($res_figli) {
        while($f = $res_figli->fetch_assoc()) {
            $conn->query("DELETE FROM menu_voci WHERE genitore_id = {$f['id']}"); // Elimina Nipoti
        }
    }
    $conn->query("DELETE FROM menu_voci WHERE genitore_id = $m_del_id"); // Elimina Figli
    $conn->query("DELETE FROM menu_voci WHERE id = $m_del_id"); // Elimina Padre
    flash_set("Voce di menu e sottomenu associati eliminati.");
    admin_redirect("menu.php?p_id=$filtro_p");
}

// ==============================================================================
// PREPARAZIONE DATI FRONT-END
// ==============================================================================

// Recupero Ruoli per i filtri di visibilità
$ruoli = get_ruoli($conn);

// Lista Pagine/Aree di Lavoro (Per Autocompletamento URL)
$aree_lavoro = [];
$res_aree = $conn->query("SELECT titolo, slug FROM pagine_eventi ORDER BY titolo ASC");
if ($res_aree) { while($a = $res_aree->fetch_assoc()) { $aree_lavoro[] = $a; } }

// Funzione Helper per disegnare la Select delle parentele (Max 2 livelli di profondità per l'assegnazione, così crei il 3° livello)
function getGenitoriOptions($conn, $selected_id = 0, $exclude_id = 0) {
    $html = '<option value="0">-- 👑 Voce Principale (Livello 1) --</option>';
    $res0 = $conn->query("SELECT id, etichetta FROM menu_voci WHERE genitore_id = 0 ORDER BY ordine ASC");
    if ($res0) {
        while ($m0 = $res0->fetch_assoc()) {
            if ($m0['id'] == $exclude_id) continue;
            $sel0 = ($m0['id'] == $selected_id) ? 'selected' : '';
            $html .= "<option value='{$m0['id']}' $sel0>📂 {$m0['etichetta']} (Menu Padre)</option>";
            
            // Figli di livello 1 (Diventano padri del Livello 3)
            $res1 = $conn->query("SELECT id, etichetta FROM menu_voci WHERE genitore_id = {$m0['id']} ORDER BY ordine ASC");
            if ($res1) {
                while ($m1 = $res1->fetch_assoc()) {
                    if ($m1['id'] == $exclude_id) continue;
                    $sel1 = ($m1['id'] == $selected_id) ? 'selected' : '';
                    $html .= "<option value='{$m1['id']}' $sel1>&nbsp;&nbsp;&nbsp;↳ Sotto: {$m1['etichetta']}</option>";
                }
            }
        }
    }
    return $html;
}

// Helper per generare l'HTML della riga della tabella (ricorsivo per array pre-ordinato)
$tutti_i_menu = [];
$res_all = $conn->query("SELECT * FROM menu_voci ORDER BY ordine ASC");
if ($res_all) { while($m = $res_all->fetch_assoc()) { $tutti_i_menu[] = $m; } }
?>

<!-- FRONT-END DELLA PAGINA -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-dark m-0"><i class="fa fa-link text-primary me-2"></i> Costruttore Menu di Navigazione</h4>
</div>

<div class="card shadow-sm border-0 p-4">
    <h5 class="fw-bold text-primary border-bottom pb-2 mb-4">Aggiungi Nuova Voce</h5>
    
    <!-- FORM AGGIUNTA NUOVA VOCE -->
    <form method="POST" class="row g-3 mb-5 bg-light p-3 border rounded shadow-sm align-items-end">
        <?php csrf_field(); ?>
        <div class="col-md-4">
            <label class="form-label small fw-bold text-secondary">1. Posizione (Scegli Genitore)</label>
            <select name="genitore_id" class="form-select border-primary fw-bold text-primary">
                <?php echo getGenitoriOptions($conn); ?>
            </select>
        </div>
        
        <div class="col-md-4">
            <label class="form-label small fw-bold text-secondary">2. Etichetta (Nome visibile)</label>
            <input type="text" name="etichetta" class="form-control" required placeholder="Es. Offerta Formativa, Corsi...">
        </div>
        
        <div class="col-md-4">
            <label class="form-label small fw-bold text-secondary">3. Collegamento Rapido Area</label>
            <select class="form-select border-success text-success fw-bold" onchange="document.getElementById('url_nuovo').value = this.value;">
                <option value="">-- Scrivi URL manualmente sotto --</option>
                <optgroup label="🔗 Aree di Lavoro Correnti">
                    <?php foreach ($aree_lavoro as $al): ?>
                        <option value="<?php echo htmlspecialchars($al['slug']); ?>.php">Area: <?php echo htmlspecialchars($al['titolo']); ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="🗄️ Archivi Storici Aree">
                    <?php foreach ($aree_lavoro as $al): ?>
                        <option value="<?php echo htmlspecialchars($al['slug']); ?>_archivio.php">Archivio: <?php echo htmlspecialchars($al['titolo']); ?></option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </div>

        <div class="col-md-4">
            <label class="form-label small fw-bold text-secondary">URL di Destinazione Definitivo</label>
            <input type="text" id="url_nuovo" name="url" class="form-control" placeholder="Es. index.php o https://..." required>
        </div>
        
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary">Permessi (Chi lo vede?)</label>
            <select name="ruolo_visibilita_id" class="form-select">
                <option value="0">🌐 Tutti (Pubblico)</option>
                <option value="-1">🔑 Solo Autenticati SSO</option>
                <?php foreach ($ruoli as $r): ?>
                    <option value="<?php echo $r['id']; ?>">🔒 Solo: <?php echo htmlspecialchars($r['nome']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-2">
            <label class="form-label small fw-bold text-secondary">Ordine (1, 2, 3...)</label>
            <input type="number" name="ordine" class="form-control text-center fw-bold" value="0" required>
        </div>
        
        <div class="col-md-3 d-flex align-items-center">
            <div class="form-check form-switch pt-2">
                <input class="form-check-input" type="checkbox" name="apri_nuova_scheda" id="chkNew" value="1">
                <label class="form-check-label small fw-bold" for="chkNew">Apri in nuova scheda</label>
            </div>
        </div>

        <div class="col-12 mt-3 text-end border-top pt-3">
            <button type="submit" name="add_menu_voce" class="btn btn-primary fw-bold px-5 shadow">
                <i class="fa fa-plus me-1"></i> Crea Voce Menu
            </button>
        </div>
    </form>

    <h5 class="fw-bold text-dark border-bottom pb-2 mb-3"><i class="fa fa-sitemap me-2"></i> Albero di Navigazione Attuale</h5>

    <!-- TABELLA VOCI DI MENU -->
    <div class="table-responsive">
        <table class="table table-hover align-middle border">
            <thead class="table-dark">
                <tr>
                    <th style="width: 80px;" class="text-center">Ordine</th>
                    <th>Alberatura Menu</th>
                    <th>Destinazione (URL)</th>
                    <th>Visibilità</th>
                    <th class="text-end" style="width: 250px;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                    $has_menus = false;
                    
                    // LIVELLO 0 (Genitori Principali)
                    foreach ($tutti_i_menu as $m0) {
                        if ($m0['genitore_id'] != 0) continue;
                        $has_menus = true;
                        $m0_vis = (!isset($m0['visibile']) || $m0['visibile'] == 1);
                        $opacity0 = $m0_vis ? '1' : '0.5';
                ?>
                    <!-- RIGA LIVELLO 0 -->
                    <tr class="table-secondary border-bottom border-dark" style="opacity: <?php echo $opacity0; ?>;">
                        <td class="text-center fw-bold text-dark">#<?php echo $m0['ordine']; ?></td>
                        <td><span class="badge bg-primary me-2">L1</span> <strong class="fs-6"><?php echo htmlspecialchars($m0['etichetta']); ?></strong> <?php if(!$m0_vis) echo '<span class="badge bg-danger ms-2">Nascosto</span>'; ?></td>
                        <td><code><?php echo htmlspecialchars($m0['url']); ?></code></td>
                        <td><?php echo getVisibilitaBadge((int)($m0['ruolo_visibilita_id']), $ruoli); ?></td>
                        <td class="text-end">
                            <form method="POST" class="d-inline">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="toggle_vis" value="<?php echo $m0['id']; ?>">
                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $m0_vis ? 'btn-outline-warning text-dark' : 'btn-outline-success'; ?> py-0 px-2" title="On/Off Pubblicazione"><i class="fa <?php echo $m0_vis ? 'fa-eye-slash' : 'fa-eye'; ?>"></i></button>
                            </form>
                            <button class="btn btn-outline-info btn-sm py-0 px-2" data-bs-toggle="modal" data-bs-target="#modEditMenu<?php echo $m0['id']; ?>"><i class="fa fa-edit"></i></button>
                            <form method="POST" class="d-inline">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="del_menu" value="<?php echo $m0['id']; ?>">
                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" data-confirm="Eliminando questa voce verranno eliminati anche tutti i suoi sottomenu. Procedere?"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>

                    <?php
                    // LIVELLO 1 (Sottomenu)
                    foreach ($tutti_i_menu as $m1) {
                        if ($m1['genitore_id'] != $m0['id']) continue;
                        $m1_vis = (!isset($m1['visibile']) || $m1['visibile'] == 1);
                        $opacity1 = $m1_vis ? '1' : '0.5';
                    ?>
                        <!-- RIGA LIVELLO 1 -->
                        <tr style="opacity: <?php echo $opacity1; ?>;">
                            <td class="text-center text-muted small">#<?php echo $m1['ordine']; ?></td>
                            <td class="ps-4"><span class="badge bg-info text-dark me-2">L2</span> <span class="fw-bold text-secondary">↳ <?php echo htmlspecialchars($m1['etichetta']); ?></span> <?php if(!$m1_vis) echo '<span class="badge bg-danger ms-2">Nascosto</span>'; ?></td>
                            <td><code><?php echo htmlspecialchars($m1['url']); ?></code></td>
                            <td><?php echo getVisibilitaBadge((int)($m1['ruolo_visibilita_id']), $ruoli); ?></td>
                            <td class="text-end">
                                <form method="POST" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="toggle_vis" value="<?php echo $m1['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $m1_vis ? 'btn-outline-warning text-dark' : 'btn-outline-success'; ?> py-0 px-2" title="On/Off Pubblicazione"><i class="fa <?php echo $m1_vis ? 'fa-eye-slash' : 'fa-eye'; ?>"></i></button>
                                </form>
                                <button class="btn btn-outline-info btn-sm py-0 px-2" data-bs-toggle="modal" data-bs-target="#modEditMenu<?php echo $m1['id']; ?>"><i class="fa fa-edit"></i></button>
                                <form method="POST" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="del_menu" value="<?php echo $m1['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" data-confirm="Eliminare questo sottomenu e i suoi figli?"><i class="fa fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>

                        <?php 
                        // LIVELLO 2 (Sotto-Sottomenu)
                        foreach ($tutti_i_menu as $m2) {
                            if ($m2['genitore_id'] != $m1['id']) continue;
                            $m2_vis = (!isset($m2['visibile']) || $m2['visibile'] == 1);
                            $opacity2 = $m2_vis ? '1' : '0.5';
                        ?>
                            <!-- RIGA LIVELLO 2 -->
                            <tr class="bg-light" style="opacity: <?php echo $opacity2; ?>;">
                                <td class="text-center text-muted" style="font-size: 0.7rem;">#<?php echo $m2['ordine']; ?></td>
                                <td class="ps-5"><span class="badge bg-light border text-dark me-2">L3</span> <span class="text-muted">— <?php echo htmlspecialchars($m2['etichetta']); ?></span> <?php if(!$m2_vis) echo '<span class="badge bg-danger ms-2">Nascosto</span>'; ?></td>
                                <td class="text-muted"><code class="text-secondary"><?php echo htmlspecialchars($m2['url']); ?></code></td>
                                <td><?php echo getVisibilitaBadge((int)($m2['ruolo_visibilita_id']), $ruoli); ?></td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="toggle_vis" value="<?php echo $m2['id']; ?>">
                                        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                        <button type="submit" class="btn btn-sm <?php echo $m2_vis ? 'btn-outline-warning text-dark' : 'btn-outline-success'; ?> py-0 px-2" title="On/Off Pubblicazione"><i class="fa <?php echo $m2_vis ? 'fa-eye-slash' : 'fa-eye'; ?>"></i></button>
                                    </form>
                                    <button class="btn btn-outline-info btn-sm py-0 px-2" data-bs-toggle="modal" data-bs-target="#modEditMenu<?php echo $m2['id']; ?>"><i class="fa fa-edit"></i></button>
                                    <form method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="del_menu" value="<?php echo $m2['id']; ?>">
                                        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" data-confirm="Eliminare questo collegamento?"><i class="fa fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } ?>
                <?php } ?>

                <?php if (!$has_menus): ?>
                    <tr><td colspan="5" class="text-center p-5 text-muted"><i class="fa fa-sitemap fs-1 mb-3 d-block opacity-50"></i> Nessuna voce di menu configurata. Il menu del portale sarà vuoto.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- =================================================================================== -->
<!-- GENERAZIONE MODALI DI MODIFICA PER TUTTE LE VOCI -->
<!-- =================================================================================== -->
<?php foreach ($tutti_i_menu as $em): ?>
<div class="modal fade" id="modEditMenu<?php echo $em['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-info">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="menu_id" value="<?php echo $em['id']; ?>">
                <div class="modal-header bg-info text-white py-2">
                    <h6 class="modal-title fw-bold"><i class="fa fa-edit me-1"></i> Modifica: <?php echo htmlspecialchars($em['etichetta']); ?></h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start bg-light">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Genitore / Posizione</label>
                            <select name="genitore_id" class="form-select border-info">
                                <?php echo getGenitoriOptions($conn, $em['genitore_id'], $em['id']); ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Etichetta visibile</label>
                            <input type="text" name="etichetta" class="form-control" value="<?php echo htmlspecialchars($em['etichetta']); ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-success">Aggiorna da Area Esistente (Opzionale)</label>
                            <select class="form-select border-success text-success" onchange="document.getElementById('edit_url_<?php echo $em['id']; ?>').value = this.value;">
                                <option value="">-- Mantiene URL Corrente --</option>
                                <optgroup label="Aree di Lavoro Correnti">
                                    <?php foreach ($aree_lavoro as $al): ?>
                                        <option value="<?php echo htmlspecialchars($al['slug']); ?>.php">Area: <?php echo htmlspecialchars($al['titolo']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="Archivi Storici">
                                    <?php foreach ($aree_lavoro as $al): ?>
                                        <option value="<?php echo htmlspecialchars($al['slug']); ?>_archivio.php">Archivio: <?php echo htmlspecialchars($al['titolo']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">URL di Destinazione</label>
                            <input type="text" id="edit_url_<?php echo $em['id']; ?>" name="url" class="form-control" value="<?php echo htmlspecialchars($em['url']); ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Visibilità</label>
                            <select name="ruolo_visibilita_id" class="form-select">
                                <option value="0" <?php echo ($em['ruolo_visibilita_id'] == 0) ? 'selected' : ''; ?>>🌐 Tutti</option>
                                <option value="-1" <?php echo ($em['ruolo_visibilita_id'] == -1) ? 'selected' : ''; ?>>🔑 Autenticati SSO</option>
                                <?php foreach ($ruoli as $r): ?>
                                    <option value="<?php echo $r['id']; ?>" <?php echo ($em['ruolo_visibilita_id'] == $r['id']) ? 'selected' : ''; ?>>🔒 Solo: <?php echo htmlspecialchars($r['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Ordine</label>
                            <input type="number" name="ordine" class="form-control text-center" value="<?php echo $em['ordine']; ?>" required>
                        </div>

                        <div class="col-md-4 d-flex align-items-center pt-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="apri_nuova_scheda" id="chkEdit<?php echo $em['id']; ?>" value="1" <?php echo ($em['apri_nuova_scheda'] == 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label small fw-bold" for="chkEdit<?php echo $em['id']; ?>">Nuova scheda</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="edit_menu_voce" class="btn btn-info text-white fw-bold shadow-sm px-4"><i class="fa fa-save me-1"></i> Salva Modifiche</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php require_once 'admin_footer.php'; ?>