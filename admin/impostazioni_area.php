<?php
// impostazioni_area.php - Configurazione Grafica, Layout e Contenuti Area
require_once 'admin_header.php';

if (!$can_manage_settings) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per modificare le impostazioni di quest'area.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}


if (isset($_POST['save_pagina_config'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $p_id = (int)($_POST['pagina_id'] ?? $filtro_p);

    $titolo = $conn->real_escape_string($_POST['titolo'] ?? '');
    $sottotitolo = $conn->real_escape_string($_POST['sottotitolo'] ?? '');
    $col_prim = $conn->real_escape_string($_POST['colore_primario'] ?? '#990000');
    $col_sec = $conn->real_escape_string($_POST['colore_secondario'] ?? '#0056b3');
    $larg_cont = $conn->real_escape_string($_POST['larghezza_contenitore'] ?? '85%');
    $tmpl = $conn->real_escape_string($_POST['layout_template'] ?? 'grid');
    if (!in_array($tmpl, ['grid', 'list', 'advanced_list', 'calendar', 'timeline', 'agenda', 'gruppi'], true)) $tmpl = 'grid';
    $mostra_home = isset($_POST['mostra_in_home']) ? 1 : 0;
    $limite_isc = in_array($_POST['limite_iscrizioni'] ?? '', ['nessuno', 'un_evento', 'un_turno'], true) ? $_POST['limite_iscrizioni'] : 'nessuno';
    $num_col = isset($_POST['num_colonne']) ? (int)$_POST['num_colonne'] : 2;
    $spazio_c = isset($_POST['spazio_card']) ? (int)$_POST['spazio_card'] : 30;
    
    $m_sidebar = isset($_POST['mostra_sidebar']) ? (int)$_POST['mostra_sidebar'] : 0;
    $ch_matr = isset($_POST['chiedi_matricola']) ? (int)$_POST['chiedi_matricola'] : 0;
    $sb_titolo = $conn->real_escape_string($_POST['sidebar_titolo'] ?? '');
    $sb_date = $conn->real_escape_string($_POST['sidebar_intervallo_date'] ?? '');
    $sb_testo = $conn->real_escape_string($_POST['sidebar_testo'] ?? '');
    
    $pos_box_info = $conn->real_escape_string($_POST['posizione_box_info'] ?? 'top');
    $hero_desc = $conn->real_escape_string($_POST['hero_descrizione'] ?? '');
    $box_info = $conn->real_escape_string($_POST['box_info_html'] ?? '');

    // NUOVI CAMPI FIRMA ATTESTATO AREA
    $firma_nome = $conn->real_escape_string($_POST['firma_nome'] ?? '');
    $firma_titolo = $conn->real_escape_string($_POST['firma_titolo'] ?? '');

    $res_curr_p = $conn->query("SELECT allegati_box_info, allegati_sidebar FROM pagine_eventi WHERE id = $p_id");
    $curr_row = ($res_curr_p && $res_curr_p->num_rows > 0) ? $res_curr_p->fetch_assoc() : ['allegati_box_info'=>'', 'allegati_sidebar'=>''];
    
    $allegati_list = !empty($curr_row['allegati_box_info']) ? explode(',', $curr_row['allegati_box_info']) : [];
    $allegati_list = array_map('trim', $allegati_list);
    if (isset($_POST['elimina_allegati_box']) && is_array($_POST['elimina_allegati_box'])) {
        foreach ($_POST['elimina_allegati_box'] as $del_file) {
            if (($key = array_search($del_file, $allegati_list)) !== false) unset($allegati_list[$key]);
        }
    }
    if (isset($_FILES['allegati_box_info']) && is_array($_FILES['allegati_box_info']['name'])) {
        $upload_dir = dirname(__DIR__) . '/uploads/';
        $file_count = count($_FILES['allegati_box_info']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            $fn = secure_upload([
                'error'    => $_FILES['allegati_box_info']['error'][$i],
                'name'     => $_FILES['allegati_box_info']['name'][$i],
                'tmp_name' => $_FILES['allegati_box_info']['tmp_name'][$i],
            ], $upload_dir, ['pdf'], ['application/pdf']);
            if ($fn) $allegati_list[] = 'uploads/' . $fn;
        }
    }
    $allegati_box_info_final = $conn->real_escape_string(implode(',', array_filter($allegati_list)));

    $sb_list = !empty($curr_row['allegati_sidebar']) ? explode(',', $curr_row['allegati_sidebar']) : [];
    $sb_list = array_map('trim', $sb_list);
    if (isset($_POST['elimina_allegati_sidebar']) && is_array($_POST['elimina_allegati_sidebar'])) {
        foreach ($_POST['elimina_allegati_sidebar'] as $del_file) {
            if (($key = array_search($del_file, $sb_list)) !== false) unset($sb_list[$key]);
        }
    }
    if (isset($_FILES['allegati_sidebar']) && is_array($_FILES['allegati_sidebar']['name'])) {
        $upload_dir = dirname(__DIR__) . '/uploads/';
        $file_count = count($_FILES['allegati_sidebar']['name']);
        for ($i = 0; $i < $file_count; $i++) {
            $fn = secure_upload([
                'error'    => $_FILES['allegati_sidebar']['error'][$i],
                'name'     => $_FILES['allegati_sidebar']['name'][$i],
                'tmp_name' => $_FILES['allegati_sidebar']['tmp_name'][$i],
            ], $upload_dir, ['pdf'], ['application/pdf']);
            if ($fn) $sb_list[] = 'uploads/' . $fn;
        }
    }
    $allegati_sidebar_final = $conn->real_escape_string(implode(',', array_filter($sb_list)));

    $banner_query = "";
    if (isset($_FILES['hero_banner_file'])) {
        $upload_dir = dirname(__DIR__) . '/uploads/';
        $fn = secure_upload($_FILES['hero_banner_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $banner_query = ", hero_banner_path='uploads/$fn'";
    }

    // COPERTINA CARD HOME
    $copertina_query = "";
    if (isset($_FILES['copertina_file']) && $_FILES['copertina_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = dirname(__DIR__) . '/uploads/';
        $fn = secure_upload($_FILES['copertina_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $copertina_query = ", copertina_path='uploads/$fn'";
    } elseif (isset($_POST['rimuovi_copertina']) && $_POST['rimuovi_copertina'] == '1') {
        $copertina_query = ", copertina_path=NULL";
    }

    // GESTIONE UPLOAD LOGO ATTESTATO AREA
    $logo_att_query = "";
    if (isset($_FILES['logo_attestato_file'])) {
        $upload_dir = dirname(__DIR__) . '/uploads/';
        $fn = secure_upload($_FILES['logo_attestato_file'], $upload_dir, ['jpg','jpeg','png','gif','webp','svg'], ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml']);
        if ($fn) $logo_att_query = ", logo_attestato_path='uploads/$fn'";
    }

    $conn->query("UPDATE pagine_eventi SET titolo='$titolo', sottotitolo='$sottotitolo', colore_primario='$col_prim', colore_secondario='$col_sec', larghezza_contenitore='$larg_cont', layout_template='$tmpl', mostra_in_home=$mostra_home, limite_iscrizioni='$limite_isc', num_colonne=$num_col, spazio_card=$spazio_c, mostra_sidebar=$m_sidebar, chiedi_matricola=$ch_matr, sidebar_titolo='$sb_titolo', sidebar_intervallo_date='$sb_date', sidebar_testo='$sb_testo', posizione_box_info='$pos_box_info', hero_descrizione='$hero_desc', box_info_html='$box_info', allegati_box_info='$allegati_box_info_final', allegati_sidebar='$allegati_sidebar_final', firma_nome='$firma_nome', firma_titolo='$firma_titolo' $logo_att_query $banner_query $copertina_query WHERE id = $p_id");
    flash_set("Impostazioni Pagina salvate con successo!");
    admin_redirect("impostazioni_area.php?p_id=$p_id");
}
?>

<div class="d-flex align-items-center mb-3 gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-paint-brush me-2 text-primary"></i>Impostazioni Area: <?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?></h4>
</div>

<ul class="nav nav-tabs mb-0" id="impoTabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-grafica" role="tab"><i class="fa fa-palette me-1"></i>Grafica &amp; Layout</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-sidebar" role="tab"><i class="fa fa-columns me-1"></i>Sidebar</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-contenuti" role="tab"><i class="fa fa-file-alt me-1"></i>Contenuti</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-attestati" role="tab"><i class="fa fa-certificate me-1"></i>Attestati</a></li>
</ul>

<div class="border border-top-0 rounded-bottom bg-white shadow-sm">
    <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <input type="hidden" name="pagina_id" value="<?php echo $filtro_p; ?>">

        <div class="tab-content p-4">

        <!-- ===== TAB: GRAFICA & LAYOUT ===== -->
        <div class="tab-pane fade show active" id="tab-grafica" role="tabpanel">
            <h6 class="fw-semibold text-primary border-bottom pb-2 mb-3">Colori, template e dimensioni</h6>
            <div class="row g-3 mb-4 p-3 bg-light rounded border">
                <div class="col-md-2"><label class="form-label small fw-bold">Colore Primario</label><input type="color" name="colore_primario" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars($page_cfg['colore_primario'] ?? '#990000'); ?>"></div>
                <div class="col-md-2"><label class="form-label small fw-bold">Colore Secondario</label><input type="color" name="colore_secondario" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars($page_cfg['colore_secondario'] ?? '#0056b3'); ?>"></div>
                <div class="col-md-2"><label class="form-label small fw-bold">Larghezza Pagina</label><input type="text" name="larghezza_contenitore" class="form-control" value="<?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>"></div>
                <div class="col-md-2"><label class="form-label small fw-bold">Template Layout</label>
                    <select name="layout_template" class="form-select">
                        <option value="grid" <?php echo ($page_cfg['layout_template'] ?? '') == 'grid' ? 'selected' : ''; ?>>Griglia Colonne (per Categoria)</option>
                        <option value="list" <?php echo ($page_cfg['layout_template'] ?? '') == 'list' ? 'selected' : ''; ?>>Lista Cronologica per Date</option>
                        <option value="advanced_list" <?php echo ($page_cfg['layout_template'] ?? '') == 'advanced_list' ? 'selected' : ''; ?>>Elenco Avanzato con Ricerca Laterale</option>
                        <option value="calendar" <?php echo ($page_cfg['layout_template'] ?? '') == 'calendar' ? 'selected' : ''; ?>>Calendario Interattivo Mensile</option>
                        <option value="timeline" <?php echo ($page_cfg['layout_template'] ?? '') == 'timeline' ? 'selected' : ''; ?>>Timeline (Cronologia Verticale)</option>
                        <option value="agenda" <?php echo ($page_cfg['layout_template'] ?? '') == 'agenda' ? 'selected' : ''; ?>>Agenda a schede per giorno (orari, posti e prenotazione)</option>
                        <option value="gruppi" <?php echo ($page_cfg['layout_template'] ?? '') == 'gruppi' ? 'selected' : ''; ?>>Gruppi / Corsi (con posti e iscrizione)</option>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small fw-bold">Colonne Griglia</label>
                    <select name="num_colonne" class="form-select">
                        <option value="1" <?php echo ($page_cfg['num_colonne'] ?? 2) == 1 ? 'selected' : ''; ?>>1 Colonna</option>
                        <option value="2" <?php echo ($page_cfg['num_colonne'] ?? 2) == 2 ? 'selected' : ''; ?>>2 Colonne</option>
                        <option value="3" <?php echo ($page_cfg['num_colonne'] ?? 2) == 3 ? 'selected' : ''; ?>>3 Colonne</option>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small fw-bold">Spazio tra Card (px)</label><input type="number" name="spazio_card" class="form-control" value="<?php echo (int)($page_cfg['spazio_card'] ?? 30); ?>"></div>
            </div>

            <h6 class="fw-semibold text-primary border-bottom pb-2 mb-3">Visibilità in home e regole di iscrizione</h6>
            <div class="row g-3 p-3 bg-light rounded border">
                <div class="col-md-5">
                    <label class="form-label small fw-bold">Visibilità in Home</label>
                    <div class="form-check form-switch pt-1">
                        <input class="form-check-input" type="checkbox" name="mostra_in_home" id="chkMostraHome" value="1" <?php echo (int)($page_cfg['mostra_in_home'] ?? 1) === 1 ? 'checked' : ''; ?>>
                        <label class="form-check-label small fw-bold" for="chkMostraHome">Mostra card e appuntamenti in home</label>
                    </div>
                    <small class="text-muted d-block mt-1">Se disattivato l'area non compare in home (né la card, né i prossimi appuntamenti) ma resta raggiungibile col suo link diretto.</small>
                </div>
                <div class="col-md-7">
                    <label class="form-label small fw-bold">Limite iscrizioni per persona</label>
                    <?php $lim_cur = $page_cfg['limite_iscrizioni'] ?? 'nessuno'; ?>
                    <select name="limite_iscrizioni" class="form-select">
                        <option value="nessuno" <?php echo $lim_cur === 'nessuno' ? 'selected' : ''; ?>>Nessun limite</option>
                        <option value="un_evento" <?php echo $lim_cur === 'un_evento' ? 'selected' : ''; ?>>Un solo evento/gruppo in tutta l'area</option>
                        <option value="un_turno" <?php echo $lim_cur === 'un_turno' ? 'selected' : ''; ?>>Un solo turno per ciascun evento</option>
                    </select>
                    <small class="text-muted d-block mt-1">La persona è riconosciuta da account, email o matricola. Le prenotazioni annullate, rifiutate o scadute non contano.</small>
                </div>
            </div>
        </div><!-- /tab-grafica -->

        <!-- ===== TAB: SIDEBAR ===== -->
        <div class="tab-pane fade" id="tab-sidebar" role="tabpanel">
            <h6 class="fw-semibold text-danger border-bottom pb-2 mb-3">Sidebar laterale e campo matricola</h6>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label small fw-bold">Stato Sidebar</label>
                    <select name="mostra_sidebar" class="form-select form-select-sm">
                        <option value="1" <?php echo ($page_cfg['mostra_sidebar'] ?? 1) == 1 ? 'selected' : ''; ?>>✔️ Abilitata (Visibile)</option>
                        <option value="0" <?php echo ($page_cfg['mostra_sidebar'] ?? 1) == 0 ? 'selected' : ''; ?>>❌ Disabilitata (Nascosta)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-dark">Campo Matricola nel Form</label>
                    <div class="form-check form-switch pt-1">
                        <input class="form-check-input" type="checkbox" name="chiedi_matricola" id="chkMatrPage" value="1" <?php echo ($page_cfg['chiedi_matricola'] ?? 1) == 1 ? 'checked' : ''; ?>>
                        <label class="form-check-label small fw-bold text-primary" for="chkMatrPage">Mostra Matricola (Opzionale)</label>
                    </div>
                </div>
                <div class="col-md-3"><label class="form-label small fw-bold">Titolo Sidebar</label><input type="text" name="sidebar_titolo" class="form-control form-control-sm" value="<?php echo htmlspecialchars($page_cfg['sidebar_titolo'] ?? ''); ?>"></div>
                <div class="col-md-3"><label class="form-label small fw-bold">Intervallo Date / Sottotitolo</label><input type="text" name="sidebar_intervallo_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($page_cfg['sidebar_intervallo_date'] ?? ''); ?>"></div>
                
                <div class="col-12 mt-3 border-top pt-3">
                    <label class="form-label small fw-bold text-primary"><i class="fa fa-file-pdf me-1"></i> Upload Guide / Documenti Sidebar (Solo PDF)</label>
                    <input type="file" name="allegati_sidebar[]" class="form-control form-control-sm border-primary" accept="application/pdf" multiple>
                    <?php if (!empty($page_cfg['allegati_sidebar'])): ?>
                        <div class="mt-2 pt-2">
                            <strong class="small text-danger d-block mb-2">Allegati Attuali (spunta il cestino per eliminare):</strong>
                            <div class="d-flex flex-wrap gap-2">
                                <?php 
                                    $all_sb = explode(',', $page_cfg['allegati_sidebar']);
                                    foreach($all_sb as $asb): 
                                        if(empty(trim($asb))) continue;
                                ?>
                                    <div class="badge bg-light text-dark border p-2 d-flex align-items-center gap-2 shadow-sm">
                                        <a href="../<?php echo htmlspecialchars($asb); ?>" target="_blank" class="text-decoration-none text-primary"><i class="fa fa-eye"></i> <?php echo basename($asb); ?></a>
                                        <div class="form-check m-0 ms-1">
                                            <input class="form-check-input m-0" type="checkbox" name="elimina_allegati_sidebar[]" value="<?php echo htmlspecialchars($asb); ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="col-12"><label class="form-label small fw-bold">Contenuto / Istruzioni Sidebar</label><textarea name="sidebar_testo" class="form-control form-control-sm editor-html" rows="3"><?php echo htmlspecialchars($page_cfg['sidebar_testo'] ?? ''); ?></textarea></div>
            </div>
        </div><!-- /tab-sidebar -->

        <!-- ===== TAB: CONTENUTI ===== -->
        <div class="tab-pane fade" id="tab-contenuti" role="tabpanel">
            <h6 class="fw-semibold text-dark border-bottom pb-2 mb-3">Banner, testi, copertina e allegati</h6>
            <div class="row g-3 mb-4">
            <div class="col-md-4"><label class="form-label small fw-bold text-danger">Upload Banner Custom (pagina area)</label><input type="file" name="hero_banner_file" class="form-control" accept="image/*"></div>
            <div class="col-md-4"><label class="form-label small fw-bold">Titolo Banner Principale</label><input type="text" name="titolo" class="form-control" value="<?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?>" required></div>
            <div class="col-md-4"><label class="form-label small fw-bold">Sottotitolo Banner</label><input type="text" name="sottotitolo" class="form-control" value="<?php echo htmlspecialchars($page_cfg['sottotitolo'] ?? ''); ?>"></div>

            <div class="col-md-6 mt-2 p-3 border rounded bg-light shadow-sm">
                <label class="form-label small fw-bold"><i class="fa fa-image text-success me-1"></i> Foto Copertina (Card Home)</label>
                <input type="file" name="copertina_file" class="form-control mb-2" accept="image/*">
                <small class="text-muted d-block mb-2">Apparirà come sfondo della card area nella home. Consigliato: 800×500 px, jpg/png.</small>
                <?php if (!empty($page_cfg['copertina_path'])): ?>
                    <div class="d-flex align-items-center gap-3 mt-1 p-2 border rounded bg-white">
                        <img src="../<?php echo htmlspecialchars($page_cfg['copertina_path']); ?>" alt="Copertina attuale" style="height:60px;width:100px;object-fit:cover;border-radius:4px;">
                        <div>
                            <small class="text-success fw-bold d-block">Copertina attiva</small>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="rimuovi_copertina" value="1" id="chkRimCopert">
                                <label class="form-check-label small text-danger" for="chkRimCopert"><i class="fa fa-trash me-1"></i> Rimuovi copertina</label>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mb-3"><label class="form-label small fw-bold">Testo Introduttivo (Hero Description)</label><textarea name="hero_descrizione" class="form-control editor-html" rows="3"><?php echo htmlspecialchars($page_cfg['hero_descrizione'] ?? ''); ?></textarea></div>
        <div class="mb-4"><label class="form-label small fw-bold">Box Informativo HTML (Referenti / Regole FSL / Convenzioni)</label><textarea name="box_info_html" class="form-control editor-html" rows="4"><?php echo htmlspecialchars($page_cfg['box_info_html'] ?? ''); ?></textarea></div>

        <div class="mb-4 p-3 border rounded bg-light border-danger" style="margin-bottom:0!important;">
            <label class="form-label small fw-bold text-dark"><i class="fa fa-file-pdf text-danger me-1"></i> Allegati Guide/Istruzioni (Solo PDF)</label>
            <input type="file" name="allegati_box_info[]" class="form-control form-control-sm" accept="application/pdf" multiple>
            <small class="text-muted d-block mt-1">Puoi selezionare anche più file contemporaneamente. Verranno accodati agli esistenti.</small>
            
            <?php if (!empty($page_cfg['allegati_box_info'])): ?>
                <div class="mt-3 pt-2 border-top">
                    <strong class="small d-block mb-2 text-danger">Allegati Caricati (Spunta il cestino per eliminare e salva):</strong>
                    <div class="d-flex flex-wrap gap-2">
                        <?php 
                            $allegati_attuali = explode(',', $page_cfg['allegati_box_info']);
                            foreach($allegati_attuali as $all): 
                                if(empty(trim($all))) continue;
                        ?>
                            <div class="badge bg-white text-dark border p-2 d-flex align-items-center gap-2 shadow-sm">
                                <a href="../<?php echo htmlspecialchars($all); ?>" target="_blank" class="text-decoration-none text-primary"><i class="fa fa-eye"></i> <?php echo basename($all); ?></a>
                                <div class="form-check m-0 ms-2">
                                    <input class="form-check-input" type="checkbox" name="elimina_allegati_box[]" value="<?php echo htmlspecialchars($all); ?>" id="del_<?php echo md5($all); ?>">
                                    <label class="form-check-label text-danger small" for="del_<?php echo md5($all); ?>" title="Elimina file"><i class="fa fa-trash"></i></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        </div><!-- /tab-contenuti -->

        <!-- ===== TAB: ATTESTATI ===== -->
        <div class="tab-pane fade" id="tab-attestati" role="tabpanel">
            <h6 class="fw-semibold text-secondary border-bottom pb-2 mb-3">Firmatario e logo per gli attestati di quest'area</h6>
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label small fw-bold">Upload Logo per Attestato</label>
                    <input type="file" name="logo_attestato_file" class="form-control" accept="image/*">
                    <?php if(!empty($page_cfg['logo_attestato_path'])): ?>
                        <div class="mt-2 p-2 border rounded bg-white d-flex align-items-center gap-3">
                            <img src="../<?php echo htmlspecialchars($page_cfg['logo_attestato_path']); ?>" alt="Logo Attestato Corrente" style="height: 40px; object-fit: contain;">
                            <small class="text-success fw-bold">Logo Attuale</small>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6 mt-3">
                    <label class="form-label small fw-bold">Nome Firmatario / Direttore</label>
                    <input type="text" name="firma_nome" class="form-control" value="<?php echo htmlspecialchars($page_cfg['firma_nome'] ?? ''); ?>">
                </div>
                <div class="col-md-6 mt-3">
                    <label class="form-label small fw-bold">Qualifica / Sottotitolo Firmatario</label>
                    <input type="text" name="firma_titolo" class="form-control" value="<?php echo htmlspecialchars($page_cfg['firma_titolo'] ?? ''); ?>">
                </div>
            </div>
        </div><!-- /tab-attestati -->

        </div><!-- /tab-content -->

        <div class="p-3 border-top bg-light">
            <button type="submit" name="save_pagina_config" onclick="tinymce.triggerSave();" class="btn btn-primary fw-bold px-4 py-2"><i class="fa fa-save me-1"></i> Salva Impostazioni Pagina</button>
        </div>
    </form>
</div><!-- /border wrapper -->

<?php require_once 'admin_footer.php'; ?>
