<?php
// testata.php - Personalizzazione Testata, Logo e Firma Attestati
require_once 'admin_header.php';

// Controllo Permessi RBAC
if (!$is_full_admin) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Questa sezione è riservata agli amministratori globali.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}


// ── POST: salva configurazione widget ────────────────────────────────────────
if (isset($_POST['save_widgets_home'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $widgets_arr = [
        'slideshow'       => isset($_POST['w_slideshow'])       ? 1 : 0,
        'prossimi_eventi' => isset($_POST['w_prossimi_eventi']) ? 1 : 0,
        'card_aree'       => isset($_POST['w_card_aree'])       ? 1 : 0,
        'annunci'         => isset($_POST['w_annunci'])         ? 1 : 0,
        'statistiche'     => isset($_POST['w_statistiche'])     ? 1 : 0,
        'mia_prenotazione'=> isset($_POST['w_mia_prenotazione']) ? 1 : 0,
        'ultimi_posti'    => isset($_POST['w_ultimi_posti'])    ? 1 : 0,
        'ordine'         => isset($_POST['ordine']) && is_array($_POST['ordine']) ? array_map('strval', $_POST['ordine']) : [],
        'aree_colonne'    => (int)($_POST['aree_colonne'] ?? 2),
        'aree_max'        => (int)($_POST['aree_max'] ?? 0),
        'eventi_num'      => (int)($_POST['eventi_num'] ?? 8),
        'eventi_layout'   => (string)($_POST['eventi_layout'] ?? 'scroll'),
    ];
    // Normalizza (valori ammessi, ordine valido) prima di salvare
    $widgets_arr = get_widgets_home(['widgets_home' => json_encode($widgets_arr)]);
    $wj  = $conn->real_escape_string(json_encode($widgets_arr));
    $ann = $conn->real_escape_string(trim($_POST['annuncio_home'] ?? ''));
    $ann_col = $conn->real_escape_string(preg_replace('/[^a-z]/', '', $_POST['annuncio_colore'] ?? 'info'));
    $conn->query("UPDATE configurazione_portale SET widgets_home='$wj', annuncio_home='$ann', annuncio_colore='$ann_col' WHERE id=1");
    if (function_exists('invalidate_configurazione_portale_cache')) invalidate_configurazione_portale_cache();
    flash_set("Configurazione widget home salvata!");
    admin_redirect("testata.php?p_id=$filtro_p&sezione=widgets");
}

if (isset($_POST['add_slide'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (isset($_FILES['slide_img']) && $_FILES['slide_img']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = dirname(__DIR__) . '/uploads/slide_home/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0775, true);
        $fn = secure_upload($_FILES['slide_img'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) {
            $path  = $conn->real_escape_string('uploads/slide_home/' . $fn);
            $tit   = $conn->real_escape_string(trim($_POST['slide_titolo'] ?? ''));
            $sub   = $conn->real_escape_string(trim($_POST['slide_sottotitolo'] ?? ''));
            $lnk   = $conn->real_escape_string(trim($_POST['slide_link'] ?? ''));
            $max_o = $conn->query("SELECT MAX(ordine) as m FROM slide_home")->fetch_assoc()['m'] ?? 0;
            $conn->query("INSERT INTO slide_home (immagine_path, titolo, sottotitolo, link, ordine) VALUES ('$path','$tit','$sub','$lnk'," . ((int)$max_o + 1) . ")");
            flash_set("Slide aggiunta!");
        } else {
            flash_set("Errore upload immagine.", 'danger');
        }
    }
    admin_redirect("testata.php?p_id=$filtro_p&sezione=slideshow");
}

if (isset($_POST['delete_slide'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sid = (int)$_POST['slide_id'];
    $row = $conn->query("SELECT immagine_path FROM slide_home WHERE id = $sid")->fetch_assoc();
    if ($row) {
        $file_path = dirname(__DIR__) . '/' . $row['immagine_path'];
        if (file_exists($file_path)) @unlink($file_path);
        $conn->query("DELETE FROM slide_home WHERE id = $sid");
        flash_set("Slide eliminata.");
    }
    admin_redirect("testata.php?p_id=$filtro_p&sezione=slideshow");
}

if (isset($_POST['toggle_slide'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sid = (int)$_POST['slide_id'];
    $conn->query("UPDATE slide_home SET attiva = 1 - attiva WHERE id = $sid");
    admin_redirect("testata.php?p_id=$filtro_p&sezione=slideshow");
}

if (isset($_POST['save_slide_ordini'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (!empty($_POST['ordini']) && is_array($_POST['ordini'])) {
        foreach ($_POST['ordini'] as $sid => $ord) {
            $conn->query("UPDATE slide_home SET ordine = " . (int)$ord . " WHERE id = " . (int)$sid);
        }
        flash_set("Ordine salvato!");
    }
    admin_redirect("testata.php?p_id=$filtro_p&sezione=slideshow");
}

if (isset($_POST['save_portal_header'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $nome = $conn->real_escape_string($_POST['nome_portale'] ?? '');
    $sotto = $conn->real_escape_string($_POST['sottotitolo_portale'] ?? '');
    $desc = $conn->real_escape_string($_POST['descrizione_portale'] ?? '');
    $col_m_bg = $conn->real_escape_string($_POST['colore_menu_bg'] ?? '#ffffff');
    $col_m_txt = $conn->real_escape_string($_POST['colore_menu_testo'] ?? '#334155');
    
    // Campi Footer - Colonna Sinistra
    $f_nome_dip = $conn->real_escape_string($_POST['footer_nome_dipartimento'] ?? '');
    $f_indirizzo = $conn->real_escape_string($_POST['footer_indirizzo'] ?? '');
    $f_contatti = $conn->real_escape_string($_POST['footer_contatti'] ?? '');
    
    // Campi Footer - Colonna Destra e Base
    $f_realizzato = $conn->real_escape_string($_POST['footer_realizzato_da'] ?? '');
    $f_assistenza = $conn->real_escape_string($_POST['footer_assistenza'] ?? '');
    $f_copyright = $conn->real_escape_string($_POST['footer_copyright'] ?? '');
    
    $upload_dir = dirname(__DIR__) . '/uploads/';
    $logo_query = "";
    if (isset($_FILES['logo_file'])) {
        $fn = secure_upload($_FILES['logo_file'], $upload_dir, ['jpg','jpeg','png','gif','webp','svg'], ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml']);
        if ($fn) $logo_query = ", logo_path='uploads/$fn'";
    }

    $fav_query = "";
    if (isset($_FILES['favicon_file'])) {
        $fn = secure_upload($_FILES['favicon_file'], $upload_dir, ['ico','png','svg'], ['image/x-icon','image/vnd.microsoft.icon','image/png','image/svg+xml']);
        if ($fn) $fav_query = ", favicon_path='uploads/$fn'";
    }

    $conn->query("UPDATE configurazione_portale SET 
        nome_portale='$nome', 
        sottotitolo_portale='$sotto', 
        descrizione_portale='$desc', 
        colore_menu_bg='$col_m_bg', 
        colore_menu_testo='$col_m_txt', 
        footer_nome_dipartimento='$f_nome_dip',
        footer_indirizzo='$f_indirizzo',
        footer_contatti='$f_contatti',
        footer_realizzato_da='$f_realizzato',
        footer_assistenza='$f_assistenza',
        footer_copyright='$f_copyright'
        $logo_query $fav_query WHERE id = 1");

    // Fase 3: invalida subito la cache locale delle impostazioni portale, altrimenti
    // header.php/footer.php mostrerebbero i vecchi valori fino alla scadenza naturale (5 min).
    if (function_exists('invalidate_configurazione_portale_cache')) {
        invalidate_configurazione_portale_cache();
    }

    flash_set("Configurazione Globale salvata con successo!");
    admin_redirect("testata.php?p_id=$filtro_p");
}
?>

<!-- FRONT-END DELLA PAGINA -->
<?php
$_st = $_GET['sezione'] ?? 'testata';
$_tmap = ['testata'=>'','slideshow'=>'','widgets'=>'','backup'=>''];
$_tmap[array_key_exists($_st, $_tmap) ? $_st : 'testata'] = 'active show';
?>
<div class="d-flex align-items-center mb-3 gap-2">
    <h4 class="fw-bold text-dark mb-0"><i class="fa fa-cog me-2 text-danger"></i>Configurazione Globale</h4>
</div>

<ul class="nav nav-tabs mb-0" id="testataTabs" role="tablist">
    <li class="nav-item"><a class="nav-link <?php echo strpos($_tmap['testata'],'active')!==false?'active':''; ?>" data-bs-toggle="tab" href="#tab-testata" role="tab"><i class="fa fa-heading me-1"></i>Testata &amp; Footer</a></li>
    <li class="nav-item"><a class="nav-link <?php echo strpos($_tmap['slideshow'],'active')!==false?'active':''; ?>" data-bs-toggle="tab" href="#tab-slideshow" role="tab"><i class="fa fa-images me-1"></i>Slideshow</a></li>
    <li class="nav-item"><a class="nav-link <?php echo strpos($_tmap['widgets'],'active')!==false?'active':''; ?>" data-bs-toggle="tab" href="#tab-widgets" role="tab"><i class="fa fa-puzzle-piece me-1"></i>Widget Home</a></li>
    <li class="nav-item"><a class="nav-link <?php echo strpos($_tmap['backup'],'active')!==false?'active':''; ?>" data-bs-toggle="tab" href="#tab-backup" role="tab"><i class="fa fa-database me-1"></i>Backup</a></li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-white shadow-sm p-4">

<!-- ===== TAB: TESTATA & FOOTER ===== -->
<div class="tab-pane fade <?php echo $_tmap['testata']; ?>" id="tab-testata" role="tabpanel">
    <h5 class="fw-bold text-danger border-bottom pb-2 mb-4">Personalizzazione Testata</h5>
    <form method="POST" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <label class="form-label small fw-bold">Nome Portale (es. EventiDiBEST)</label>
                <input type="text" name="nome_portale" class="form-control" value="<?php echo htmlspecialchars($cfg_p['nome_portale'] ?? ''); ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-bold">Sottotitolo Testata</label>
                <input type="text" name="sottotitolo_portale" class="form-control" value="<?php echo htmlspecialchars($cfg_p['sottotitolo_portale'] ?? ''); ?>">
            </div>
            
            <div class="col-md-6">
                <label class="form-label small fw-bold">Upload Logo Principale (PNG/JPG)</label>
                <input type="file" name="logo_file" class="form-control" accept="image/*">
                <?php if(!empty($cfg_p['logo_path'])): ?>
                    <div class="mt-2 p-2 border rounded bg-light d-flex align-items-center gap-3">
                        <img src="../<?php echo $cfg_p['logo_path']; ?>" alt="Logo Corrente" style="height: 40px; object-fit: contain; background: #990000; padding: 5px;">
                        <small class="text-success fw-bold">Logo Attivo</small>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="col-md-6">
                <label class="form-label small fw-bold">Upload Favicon (Icona browser)</label>
                <input type="file" name="favicon_file" class="form-control" accept="image/x-icon,image/png,image/jpeg">
            </div>
            
            <div class="col-md-3">
                <label class="form-label small fw-bold">Sfondo Menu</label>
                <input type="color" name="colore_menu_bg" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars($cfg_p['colore_menu_bg'] ?? '#ffffff'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Testo Menu</label>
                <input type="color" name="colore_menu_testo" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars($cfg_p['colore_menu_testo'] ?? '#334155'); ?>">
            </div>
            
            <div class="col-12">
                <label class="form-label small fw-bold">Descrizione della Testata (Opzionale)</label>
                <textarea name="descrizione_portale" class="form-control editor-html" rows="3"><?php echo htmlspecialchars($cfg_p['descrizione_portale'] ?? ''); ?></textarea>
            </div>
        </div>

        <div class="p-3 bg-light border border-info rounded shadow-sm mb-4">
            <h6 class="fw-bold text-info border-bottom border-info pb-2 mb-3"><i class="fa fa-layer-group me-1"></i> Impostazioni Footer</h6>
            <div class="row g-4">
                <!-- Colonna Sinistra -->
                <div class="col-md-6 border-end">
                    <strong class="d-block mb-2 text-dark">Colonna Sinistra (Dipartimento)</strong>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Nome Dipartimento</label>
                        <input type="text" name="footer_nome_dipartimento" class="form-control" value="<?php echo htmlspecialchars($cfg_p['footer_nome_dipartimento'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Indirizzo Fisico</label>
                        <input type="text" name="footer_indirizzo" class="form-control" value="<?php echo htmlspecialchars($cfg_p['footer_indirizzo'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Email e Contatti</label>
                        <input type="text" name="footer_contatti" class="form-control" value="<?php echo htmlspecialchars($cfg_p['footer_contatti'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Colonna Destra -->
                <div class="col-md-6">
                    <strong class="d-block mb-2 text-dark">Colonna Destra (Crediti)</strong>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Testo "Realizzato da"</label>
                        <input type="text" name="footer_realizzato_da" class="form-control" value="<?php echo htmlspecialchars($cfg_p['footer_realizzato_da'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-secondary">Email Assistenza</label>
                        <input type="text" name="footer_assistenza" class="form-control" value="<?php echo htmlspecialchars($cfg_p['footer_assistenza'] ?? ''); ?>">
                    </div>
                    <div class="mb-3 border-top pt-3">
                        <label class="form-label fw-bold small text-secondary">Testo Copyright (Barra grigia inferiore)</label>
                        <input type="text" name="footer_copyright" class="form-control" value="<?php echo htmlspecialchars($cfg_p['footer_copyright'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="text-end">
            <button type="submit" name="save_portal_header" onclick="tinymce.triggerSave();" class="btn btn-danger fw-bold px-4 py-2">
                <i class="fa fa-save me-1"></i> Salva Configurazione Globale
            </button>
        </div>
    </form>
</div><!-- /tab-testata -->

<!-- ===== TAB: SLIDESHOW ===== -->
<div class="tab-pane fade <?php echo $_tmap['slideshow']; ?>" id="tab-slideshow" role="tabpanel">
    <h5 class="fw-bold text-success border-bottom pb-2 mb-4"><i class="fa fa-images me-2"></i>Slideshow Home — Foto Dipartimento / Eventi</h5>
    <p class="text-secondary small mb-4">Le slide attive appaiono in un carousel in cima alla home page. Dimensione consigliata: <strong>1400×500 px</strong>, formato JPG/PNG/WebP.</p>

    <?php
    $slides = [];
    $res_sl = $conn->query("SELECT * FROM slide_home ORDER BY ordine ASC, id ASC");
    if ($res_sl) while ($s = $res_sl->fetch_assoc()) $slides[] = $s;
    ?>

    <!-- Lista slide esistenti -->
    <?php if (!empty($slides)): ?>
    <form method="POST" class="mb-4">
        <?php csrf_field(); ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle small mb-2">
                <thead class="table-dark">
                    <tr>
                        <th style="width:120px">Anteprima</th>
                        <th>Titolo / Sottotitolo</th>
                        <th style="width:80px">Ordine</th>
                        <th style="width:80px" class="text-center">Attiva</th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slides as $s): ?>
                    <tr class="<?php echo $s['attiva'] ? '' : 'table-secondary opacity-50'; ?>">
                        <td>
                            <?php
                                $abs_img = dirname(__DIR__) . '/' . $s['immagine_path'];
                                $img_ok  = file_exists($abs_img);
                            ?>
                            <?php if ($img_ok): ?>
                            <img src="../<?php echo htmlspecialchars($s['immagine_path']); ?>" alt="" style="height:55px;width:100px;object-fit:cover;border-radius:6px;">
                            <?php else: ?>
                            <div class="text-muted small text-center p-1" style="width:100px;height:55px;background:#e2e8f0;border-radius:6px;display:flex;align-items:center;justify-content:center;"><i class="fa fa-image fs-5"></i></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-bold"><?php echo htmlspecialchars($s['titolo'] ?: '—'); ?></div>
                            <div class="text-muted"><?php echo htmlspecialchars($s['sottotitolo'] ?: ''); ?></div>
                            <?php if (!empty($s['link'])): ?>
                                <div class="small text-primary"><i class="fa fa-link me-1"></i><?php echo htmlspecialchars($s['link']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="number" name="ordini[<?php echo $s['id']; ?>]" value="<?php echo (int)$s['ordine']; ?>" class="form-control form-control-sm text-center" style="width:65px;" min="0">
                        </td>
                        <td class="text-center">
                            <form method="POST" class="d-inline">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="slide_id" value="<?php echo $s['id']; ?>">
                                <button type="submit" name="toggle_slide" class="btn btn-sm <?php echo $s['attiva'] ? 'btn-success' : 'btn-outline-secondary'; ?>" title="<?php echo $s['attiva'] ? 'Attiva' : 'Disattivata'; ?>">
                                    <i class="fa <?php echo $s['attiva'] ? 'fa-eye' : 'fa-eye-slash'; ?>"></i>
                                </button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questa slide?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="slide_id" value="<?php echo $s['id']; ?>">
                                <button type="submit" name="delete_slide" class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button type="submit" name="save_slide_ordini" class="btn btn-outline-primary btn-sm fw-bold">
            <i class="fa fa-sort me-1"></i> Salva Ordine
        </button>
    </form>
    <?php else: ?>
        <div class="alert alert-info"><i class="fa fa-info-circle me-2"></i> Nessuna slide caricata. Aggiungi la prima qui sotto.</div>
    <?php endif; ?>

    <!-- Form aggiunta nuova slide -->
    <div class="p-3 border rounded bg-light shadow-sm">
        <h6 class="fw-bold text-dark mb-3"><i class="fa fa-plus-circle text-success me-1"></i> Aggiungi Nuova Slide</h6>
        <form method="POST" enctype="multipart/form-data">
            <?php csrf_field(); ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">Immagine <span class="text-danger">*</span></label>
                    <input type="file" name="slide_img" class="form-control" accept="image/*" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Titolo (opzionale)</label>
                    <input type="text" name="slide_titolo" class="form-control" placeholder="es. Welcome Week 2026">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">Sottotitolo (opzionale)</label>
                    <input type="text" name="slide_sottotitolo" class="form-control" placeholder="es. Scopri il programma">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">Link (opzionale)</label>
                    <input type="text" name="slide_link" class="form-control" placeholder="es. welcome.php">
                </div>
                <div class="col-12">
                    <button type="submit" name="add_slide" class="btn btn-success fw-bold">
                        <i class="fa fa-upload me-1"></i> Carica Slide
                    </button>
                </div>
            </div>
        </form>
    </div>
</div><!-- /tab-slideshow -->

<!-- ===== TAB: WIDGET HOME ===== -->
<?php
$cfg_w = get_configurazione_portale($conn);
$widgets_cur = get_widgets_home($cfg_w);
?>
<div class="tab-pane fade <?php echo $_tmap['widgets']; ?>" id="tab-widgets" role="tabpanel">
    <h5 class="fw-bold text-primary border-bottom pb-2 mb-4"><i class="fa fa-puzzle-piece me-2"></i>Widget Home Page</h5>
    <p class="text-secondary small mb-4">Scegli quali sezioni mostrare nella home pubblica e <strong>trascinale</strong> <i class="fa fa-grip-vertical"></i> per cambiarne l'ordine. Le modifiche sono visibili appena salvi.</p>

    <form method="POST">
        <?php csrf_field(); ?>
        <div class="row g-4 mb-4">
        <div class="col-lg-6">
        <h6 class="fw-bold text-secondary text-uppercase small mb-2">Sezioni e ordine</h6>
        <div id="widgetSortList" class="d-flex flex-column gap-2">
            <?php
            $widget_defs = [
                'slideshow'        => ['label' => 'Carosello Fotografico', 'desc' => 'Carousel di immagini. Gestisci le slide dalla sezione qui sopra.', 'icon' => 'fa-images', 'col' => 'success'],
                'mia_prenotazione' => ['label' => 'La mia prossima prenotazione', 'desc' => 'Solo per utenti loggati con prenotazioni attive: evento, conto alla rovescia, QR del biglietto.', 'icon' => 'fa-ticket', 'col' => 'danger'],
                'annunci'          => ['label' => 'Bacheca Annunci', 'desc' => 'Riquadro HTML libero: avvisi, comunicazioni, link importanti.', 'icon' => 'fa-bullhorn', 'col' => 'warning'],
                'card_aree'        => ['label' => 'Card Aree di Lavoro', 'desc' => 'Le card principali con le aree di iscrizione. Nasconderle svuota la home.', 'icon' => 'fa-th-large', 'col' => 'dark'],
                'ultimi_posti'     => ['label' => 'Ultimi Posti', 'desc' => 'Fino a 4 eventi quasi pieni (≤10% posti liberi) o con iscrizioni che chiudono entro 48 ore.', 'icon' => 'fa-fire', 'col' => 'danger'],
                'prossimi_eventi'  => ['label' => 'Prossimi Appuntamenti', 'desc' => 'Eventi futuri di tutte le aree, ordinati per data.', 'icon' => 'fa-calendar-day', 'col' => 'primary'],
                'statistiche'     => ['label' => 'Numeri del Dipartimento', 'desc' => 'Contatori: eventi in programma, aree attive, iscrizioni confermate.', 'icon' => 'fa-chart-bar', 'col' => 'info'],
            ];
            foreach ($widgets_cur['ordine'] as $key):
                $def = $widget_defs[$key];
                $checked = !empty($widgets_cur[$key]);
            ?>
            <div class="widget-item border rounded p-3 bg-white <?php echo $checked ? 'border-'.$def['col'] : 'opacity-75'; ?>" data-col="<?php echo $def['col']; ?>">
                <input type="hidden" name="ordine[]" value="<?php echo $key; ?>">
                <div class="d-flex align-items-center gap-3">
                    <span class="widget-handle text-secondary" style="cursor:grab;" title="Trascina per riordinare"><i class="fa fa-grip-vertical fs-5"></i></span>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input widget-toggle" type="checkbox" name="w_<?php echo $key; ?>" id="w_<?php echo $key; ?>" value="1" <?php echo $checked ? 'checked' : ''; ?>>
                    </div>
                    <label for="w_<?php echo $key; ?>" class="flex-grow-1 m-0" style="cursor:pointer;">
                        <span class="fw-bold text-<?php echo $def['col']; ?>"><i class="fa <?php echo $def['icon']; ?> me-1"></i> <?php echo $def['label']; ?></span><br>
                        <small class="text-muted"><?php echo $def['desc']; ?></small>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        </div>

        <div class="col-lg-6">
            <h6 class="fw-bold text-secondary text-uppercase small mb-2">Aspetto</h6>
            <div class="p-3 border rounded bg-light mb-3">
                <div class="fw-bold text-dark mb-2"><i class="fa fa-th-large me-1"></i> Card Aree di Lavoro</div>
                <label class="form-label small fw-bold mb-1">Card per riga (schermi grandi)</label>
                <div class="d-flex gap-2 mb-3" role="radiogroup">
                    <?php foreach ([2, 3, 4] as $nc): ?>
                    <input type="radio" class="btn-check" name="aree_colonne" id="aree_col_<?php echo $nc; ?>" value="<?php echo $nc; ?>" <?php echo $widgets_cur['aree_colonne'] === $nc ? 'checked' : ''; ?>>
                    <label class="btn btn-outline-dark btn-sm px-3 py-2" for="aree_col_<?php echo $nc; ?>" title="<?php echo $nc; ?> per riga">
                        <span class="d-flex gap-1 mb-1"><?php echo str_repeat('<span style="display:inline-block;width:' . (36 / $nc) . 'px;height:14px;background:currentColor;border-radius:2px;opacity:.7;"></span>', $nc); ?></span>
                        <span class="small fw-bold"><?php echo $nc; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <label for="aree_max" class="form-label small fw-bold mb-1">Numero massimo di card visibili</label>
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <input type="number" class="form-control" name="aree_max" id="aree_max" min="0" max="48" value="<?php echo (int)$widgets_cur['aree_max']; ?>">
                    <span class="input-group-text">0 = tutte</span>
                </div>
                <div class="form-text">Le altre aree compaiono con il pulsante "Mostra tutte le aree".</div>
            </div>

            <div class="p-3 border rounded bg-light">
                <div class="fw-bold text-primary mb-2"><i class="fa fa-calendar-day me-1"></i> Prossimi Appuntamenti</div>
                <div class="row g-3">
                    <div class="col-sm-5">
                        <label for="eventi_num" class="form-label small fw-bold mb-1">Eventi mostrati</label>
                        <select name="eventi_num" id="eventi_num" class="form-select form-select-sm">
                            <?php foreach ([4, 8, 12] as $ne): ?>
                            <option value="<?php echo $ne; ?>" <?php echo $widgets_cur['eventi_num'] === $ne ? 'selected' : ''; ?>><?php echo $ne; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-7">
                        <label class="form-label small fw-bold mb-1">Disposizione</label>
                        <div class="btn-group btn-group-sm w-100" role="radiogroup">
                            <input type="radio" class="btn-check" name="eventi_layout" id="ev_lay_scroll" value="scroll" <?php echo $widgets_cur['eventi_layout'] === 'scroll' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="ev_lay_scroll"><i class="fa fa-arrows-left-right me-1"></i> Scorrimento</label>
                            <input type="radio" class="btn-check" name="eventi_layout" id="ev_lay_griglia" value="griglia" <?php echo $widgets_cur['eventi_layout'] === 'griglia' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="ev_lay_griglia"><i class="fa fa-table-cells me-1"></i> Griglia</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>

        <!-- Configurazione Bacheca Annunci (mostrata se attiva) -->
        <div id="annuncio_config" style="<?php echo empty($widgets_cur['annunci']) ? 'display:none;' : ''; ?>">
            <div class="p-3 border rounded bg-light shadow-sm mb-3">
                <h6 class="fw-bold text-warning mb-3"><i class="fa fa-bullhorn me-1"></i> Contenuto Bacheca Annunci</h6>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Stile Riquadro</label>
                        <select name="annuncio_colore" class="form-select form-select-sm">
                            <?php foreach (['info'=>'Blu (info)','success'=>'Verde (successo)','warning'=>'Giallo (attenzione)','danger'=>'Rosso (avviso)','dark'=>'Grigio scuro'] as $v=>$l): ?>
                            <option value="<?php echo $v; ?>" <?php echo ($cfg_w['annuncio_colore'] ?? 'info') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Testo / HTML dell'annuncio</label>
                        <textarea name="annuncio_home" class="form-control editor-html" rows="4" placeholder="Inserisci il testo dell'annuncio. Puoi usare HTML o l'editor visuale."><?php echo htmlspecialchars($cfg_w['annuncio_home'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" name="save_widgets_home" class="btn btn-primary fw-bold px-4">
                <i class="fa fa-save me-1"></i> Salva Configurazione Widget
            </button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script>
document.getElementById('w_annunci').addEventListener('change', function(){
    document.getElementById('annuncio_config').style.display = this.checked ? '' : 'none';
});
// Evidenzia i widget attivi
document.querySelectorAll('.widget-toggle').forEach(function (cb) {
    cb.addEventListener('change', function () {
        var box = this.closest('.widget-item');
        box.classList.toggle('border-' + box.dataset.col, this.checked);
        box.classList.toggle('opacity-75', !this.checked);
    });
});
// Ordine: gli input hidden ordine[] seguono l'ordine nel DOM, salvato con il form
if (window.Sortable) {
    Sortable.create(document.getElementById('widgetSortList'), { handle: '.widget-handle', animation: 150 });
}
</script>
</div><!-- /tab-widgets -->

<!-- ===== TAB: BACKUP ===== -->
<div class="tab-pane fade <?php echo $_tmap['backup']; ?>" id="tab-backup" role="tabpanel">
    <h5 class="fw-bold text-danger border-bottom pb-2 mb-4"><i class="fa fa-database me-2"></i>Backup Automatico</h5>
    <p class="text-secondary small mb-3">
        Esegui un backup completo del <strong>database</strong> e di tutti i <strong>file del sito</strong>.
        I backup vengono salvati nella cartella <code>backups/</code> sul server e vengono mantenuti per <strong>7 giorni</strong>.
        L'operazione può richiedere alcuni secondi.
    </p>

    <div class="row g-3 align-items-center">
        <div class="col-md-8">
            <ul class="list-unstyled text-secondary small mb-0">
                <li><i class="fa fa-check-circle text-success me-2"></i> Export SQL del database (struttura + dati)</li>
                <li><i class="fa fa-check-circle text-success me-2"></i> Archivio ZIP di tutti i file del portale</li>
                <li><i class="fa fa-check-circle text-success me-2"></i> Pulizia automatica dei backup con più di 7 giorni</li>
                <li><i class="fa fa-lock text-danger me-2"></i> La cartella <code>backups/</code> è protetta da accesso diretto via browser</li>
            </ul>
        </div>
        <div class="col-md-4 text-md-end">
            <a href="cron_backup.php" target="_blank"
               class="btn btn-danger fw-bold px-4 py-2 shadow-sm"
               data-confirm="Avviare il backup completo? L\'operazione potrebbe richiedere qualche secondo.">
                <i class="fa fa-download me-2"></i> Avvia Backup Ora
            </a>
        </div>
    </div>

    <?php
    // Mostra lista ultimi backup disponibili sul server
    $backup_dir_check = dirname(__DIR__) . '/backups/';
    $backup_files = glob($backup_dir_check . 'backup_*.*');
    if ($backup_files && count($backup_files) > 0) {
        usort($backup_files, fn($a,$b) => filemtime($b) - filemtime($a));
        $ultimi = array_slice($backup_files, 0, 6);
        echo "<hr class='mt-4'><h6 class='fw-bold text-secondary mb-3'><i class='fa fa-history me-2'></i> Ultimi Backup Disponibili</h6>";
        echo "<div class='table-responsive'><table class='table table-sm table-hover small mb-0'>";
        echo "<thead class='table-light'><tr><th>File</th><th>Tipo</th><th>Dimensione</th><th>Data</th></tr></thead><tbody>";
        foreach ($ultimi as $bf) {
            $nome_bf  = basename($bf);
            $tipo     = strpos($nome_bf, 'backup_DB') !== false ? '<span class="badge bg-primary">SQL</span>' : '<span class="badge bg-secondary">ZIP</span>';
            $dim      = round(filesize($bf) / 1024 / 1024, 2) . ' MB';
            $data_bf  = date('d/m/Y H:i', filemtime($bf));
            echo "<tr><td><code class='small'>$nome_bf</code></td><td>$tipo</td><td>$dim</td><td>$data_bf</td></tr>";
        }
        echo "</tbody></table></div>";
    }
    ?>
</div><!-- /tab-backup -->

</div><!-- /tab-content -->

<?php require_once 'admin_footer.php'; ?>
