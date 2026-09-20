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
<h4 class="fw-bold text-dark mb-4"><i class="fa fa-image text-danger me-2"></i> Configurazione Globale</h4>

<div class="card shadow-sm border-0 p-4">
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
</div>

<!-- =========================================================================
     SEZIONE BACKUP (Fase 4 fix: era presente in versioni precedenti di testata.php)
     ========================================================================= -->
<div class="card shadow-sm border-0 p-4 mt-4">
    <h5 class="fw-bold text-danger border-bottom pb-2 mb-4">
        <i class="fa fa-database me-2"></i> Backup Automatico
    </h5>
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
</div>

<?php require_once 'admin_footer.php'; ?>
