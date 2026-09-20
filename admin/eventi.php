<?php
// eventi.php - Gestione Eventi e Turni (RBAC Pulito e Isolamento Eventi)
require_once 'admin_header.php';

if (!$can_manage_eventi) {
    echo "<div class='alert alert-danger fw-bold shadow-sm'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per gestire gli eventi in quest'area.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) { echo "<script>window.location.replace('$url');</script>"; exit; }

if (isset($_POST['add_sottocategoria'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $p_id = (int)($_POST['pagina_id'] ?? $filtro_p);
    $nome_sub = $_POST['nome_sottocategoria'] ?? '';
    $ord_sub = (int)($_POST['ordine_sottocategoria'] ?? 0);
    $stmt = $conn->prepare("INSERT INTO sottocategorie (pagina_id, nome, ordine) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $p_id, $nome_sub, $ord_sub);
    $stmt->execute();
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Creazione Sezione", ["Nome" => $_POST['nome_sottocategoria']]);
    flash_set("Sezione creata con successo!");
    admin_redirect("eventi.php?p_id=$p_id");
}

if (isset($_POST['add_evento'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $sub_id = !empty($_POST['sottocategoria_id']) ? (int)$_POST['sottocategoria_id'] : null;
    $titolo = $_POST['titolo'] ?? '';
    $luogo = $_POST['luogo'] ?? '';
    $desc = $_POST['descrizione'] ?? '';
    $ord = (int)($_POST['ordine_evento'] ?? 0);
    $evid = isset($_POST['is_evidenza']) ? 1 : 0;
    $req_pren = isset($_POST['richiede_prenotazione']) ? 1 : 0;
    $abilita_pres = isset($_POST['abilita_presenze']) ? 1 : 0;
    $ruolo_acc = (int)($_POST['ruolo_accesso_id'] ?? 0);

    $upload_dir = dirname(__DIR__) . '/uploads/';
    $locandina_path = "";
    if (isset($_FILES['locandina_file'])) {
        $fn = secure_upload($_FILES['locandina_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $locandina_path = "uploads/$fn";
    }

    $allegato_pdf = null;
    if (isset($_FILES['allegato_pdf'])) {
        $fn = secure_upload($_FILES['allegato_pdf'], $upload_dir, ['pdf'], ['application/pdf']);
        if ($fn) $allegato_pdf = "uploads/$fn";
    }

    $stmt_ev = $conn->prepare("INSERT INTO eventi (pagina_id, sottocategoria_id, titolo, luogo, descrizione, locandina_path, allegato_pdf, is_evidenza, richiede_prenotazione, abilita_presenze, ruolo_accesso_id, ordine, gestori_utenti_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '')");
    $stmt_ev->bind_param("iisssssiiiiii", $filtro_p, $sub_id, $titolo, $luogo, $desc, $locandina_path, $allegato_pdf, $evid, $req_pren, $abilita_pres, $ruolo_acc, $ord);
    $stmt_ev->execute();
    $ev_id = $conn->insert_id;

    if (!empty($_POST['data_turno']) && !empty($_POST['orario_inizio'])) {
        $data_t = $_POST['data_turno'];
        $in_t = $_POST['orario_inizio'];
        $fi_t = $_POST['orario_fine'];
        $max_p = (int)$_POST['max_posti'];
        $dt_ap = !empty($_POST['data_apertura']) ? $_POST['data_apertura'] : null;
        $dt_ch = !empty($_POST['data_chiusura']) ? $_POST['data_chiusura'] : null;
        $wa_li = isset($_POST['abilita_lista_attesa']) ? 1 : 0;
        $mp_en = isset($_POST['abilita_multi_posto']) ? 1 : 0;
        $req_app = isset($_POST['richiede_approvazione']) ? 1 : 0;
        $stmt_t = $conn->prepare("INSERT INTO turni (evento_id, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura, abilita_lista_attesa, abilita_multi_posto, richiede_approvazione) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_t->bind_param("isssissiii", $ev_id, $data_t, $in_t, $fi_t, $max_p, $dt_ap, $dt_ch, $wa_li, $mp_en, $req_app);
        $stmt_t->execute();
    }
    
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Creazione Evento", ["Evento ID" => $ev_id]);
    flash_set("Evento e turni creati!");
    admin_redirect("eventi.php?p_id=$filtro_p");
}

if (isset($_POST['edit_evento'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['evento_id'];
    $sub_id = !empty($_POST['sottocategoria_id']) ? (int)$_POST['sottocategoria_id'] : null;
    $titolo = $_POST['titolo'] ?? '';
    $luogo = $_POST['luogo'] ?? '';
    $desc = $_POST['descrizione'] ?? '';
    $ord = (int)($_POST['ordine_evento'] ?? 0);
    $evid = isset($_POST['is_evidenza']) ? 1 : 0;
    $req_pren = isset($_POST['richiede_prenotazione']) ? 1 : 0;
    $abilita_pres = isset($_POST['abilita_presenze']) ? 1 : 0;
    $ruolo_acc = (int)($_POST['ruolo_accesso_id'] ?? 0);

    if (isset($_POST['elimina_locandina']) && $_POST['elimina_locandina'] == '1') $conn->query("UPDATE eventi SET locandina_path = NULL WHERE id = $ev_id");
    if (isset($_POST['elimina_pdf']) && $_POST['elimina_pdf'] == '1') $conn->query("UPDATE eventi SET allegato_pdf = NULL WHERE id = $ev_id");

    $upload_dir = dirname(__DIR__) . '/uploads/';
    $new_locandina = null;
    if (isset($_FILES['locandina_file'])) {
        $fn = secure_upload($_FILES['locandina_file'], $upload_dir, ['jpg','jpeg','png','gif','webp'], ['image/jpeg','image/png','image/gif','image/webp']);
        if ($fn) $new_locandina = "uploads/$fn";
    }

    $new_pdf = null;
    if (isset($_FILES['allegato_pdf'])) {
        $fn = secure_upload($_FILES['allegato_pdf'], $upload_dir, ['pdf'], ['application/pdf']);
        if ($fn) $new_pdf = "uploads/$fn";
    }

    $sql_upd = "UPDATE eventi SET sottocategoria_id=?, titolo=?, luogo=?, descrizione=?, ordine=?, is_evidenza=?, richiede_prenotazione=?, abilita_presenze=?, ruolo_accesso_id=?";
    $types = "isssiiiii";
    $params = [$sub_id, $titolo, $luogo, $desc, $ord, $evid, $req_pren, $abilita_pres, $ruolo_acc];
    if ($new_locandina !== null) { $sql_upd .= ", locandina_path=?"; $types .= "s"; $params[] = $new_locandina; }
    if ($new_pdf !== null) { $sql_upd .= ", allegato_pdf=?"; $types .= "s"; $params[] = $new_pdf; }
    $sql_upd .= " WHERE id=?";
    $types .= "i";
    $params[] = $ev_id;
    $stmt_upd = $conn->prepare($sql_upd);
    $stmt_upd->bind_param($types, ...$params);
    $stmt_upd->execute();
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Modifica Evento", ["Evento ID" => $ev_id]);
    flash_set("Evento modificato!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

if (isset($_POST['add_turno'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['evento_id'];
    $data_t = $_POST['data_turno'];
    $in_t = $_POST['orario_inizio'];
    $fi_t = $_POST['orario_fine'];
    $max_p = (int)$_POST['max_posti'];
    $dt_ap = !empty($_POST['data_apertura']) ? $_POST['data_apertura'] : null;
    $dt_ch = !empty($_POST['data_chiusura']) ? $_POST['data_chiusura'] : null;
    $wa_li = isset($_POST['abilita_lista_attesa']) ? 1 : 0;
    $mp_en = isset($_POST['abilita_multi_posto']) ? 1 : 0;
    $req_app = isset($_POST['richiede_approvazione']) ? 1 : 0;
    $stmt_at = $conn->prepare("INSERT INTO turni (evento_id, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura, abilita_lista_attesa, abilita_multi_posto, richiede_approvazione) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt_at->bind_param("isssissiii", $ev_id, $data_t, $in_t, $fi_t, $max_p, $dt_ap, $dt_ch, $wa_li, $mp_en, $req_app);
    $stmt_at->execute();
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Aggiunta Turno", ["Evento ID" => $ev_id, "Data" => $data_t, "Max Posti" => $max_p]);
    flash_set("Turno aggiunto!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

if (isset($_POST['edit_turno'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $t_id = (int)$_POST['turno_id'];
    $data_t = $_POST['data_turno'];
    $in_t = $_POST['orario_inizio'];
    $fi_t = $_POST['orario_fine'];
    $max_p = (int)$_POST['max_posti'];
    $dt_ap = !empty($_POST['data_apertura']) ? $_POST['data_apertura'] : null;
    $dt_ch = !empty($_POST['data_chiusura']) ? $_POST['data_chiusura'] : null;
    $wa_li = isset($_POST['abilita_lista_attesa']) ? 1 : 0;
    $mp_en = isset($_POST['abilita_multi_posto']) ? 1 : 0;
    $req_app = isset($_POST['richiede_approvazione']) ? 1 : 0;

    $stmt_et = $conn->prepare("UPDATE turni SET data_turno=?, orario_inizio=?, orario_fine=?, max_posti=?, data_apertura=?, data_chiusura=?, abilita_lista_attesa=?, abilita_multi_posto=?, richiede_approvazione=? WHERE id=?");
    $stmt_et->bind_param("sssissiiii", $data_t, $in_t, $fi_t, $max_p, $dt_ap, $dt_ch, $wa_li, $mp_en, $req_app, $t_id);
    $stmt_et->execute();
    
    // LOGICA AUTOMATICA PROMOZIONE
    $res_occ = $conn->query("SELECT COALESCE(SUM(num_posti), 0) as tot FROM prenotazioni WHERE turno_id = $t_id AND stato = 'confermata'");
    $occupati = ($res_occ && $row_occ = $res_occ->fetch_assoc()) ? (int)$row_occ['tot'] : 0;
    $posti_liberi = $max_p - $occupati;
    $promossi = 0;
    
    if ($posti_liberi > 0) {
        $res_attesa = $conn->query("SELECT p.*, e.titolo as evento_titolo, e.luogo FROM prenotazioni p JOIN turni t ON p.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE p.turno_id = $t_id AND p.stato = 'in_attesa' ORDER BY p.id ASC");
        if ($res_attesa && $res_attesa->num_rows > 0) {
            if (file_exists(dirname(__DIR__) . '/functions.php')) require_once dirname(__DIR__) . '/functions.php';
            while ($pren = $res_attesa->fetch_assoc()) {
                $posti_richiesti = (int)$pren['num_posti'];
                if ($posti_liberi >= $posti_richiesti) {
                    $conn->query("UPDATE prenotazioni SET stato = 'confermata' WHERE id = {$pren['id']}");
                    $posti_liberi -= $posti_richiesti; $promossi++;
                    if (function_exists('inviaNotificaEmail')) {
                        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                        $link = $proto . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\') . "/stampa_ricevuta.php?code=" . urlencode($pren['codice_prenotazione']);
                        $btn = "<p><a href='$link' style='background:#B80000; color:#fff; padding:10px; border-radius:6px; text-decoration:none;'>Scarica Ricevuta</a></p>";
                        $body = "<p>Gentile " . htmlspecialchars($pren['nome']) . ", la tua prenotazione in lista d'attesa è stata CONFERMATA per l'evento " . htmlspecialchars($pren['evento_titolo']) . ".</p>" . $btn;
                        inviaNotificaEmail($pren['email'], "Posto Confermato: " . $pren['evento_titolo'], $body, $conn);
                    }
                } else break;
            }
        }
    }
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Modifica Turno", ["Turno ID" => $t_id, "Data" => $data_t, "Max Posti" => $max_p]);
    flash_set("Turno aggiornato!" . ($promossi > 0 ? " (Aggiunti $promossi utenti dalla Lista d'Attesa!)" : ""));
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

if (isset($_POST['archivia_conclusi'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $now = date('Y-m-d H:i:s');
    $conn->query("UPDATE eventi e SET archiviato = 1 WHERE pagina_id = $filtro_p AND NOT EXISTS (SELECT 1 FROM turni t WHERE t.evento_id = e.id AND (t.data_turno >= CURDATE() OR (t.data_chiusura IS NOT NULL AND t.data_chiusura >= '$now')))");
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Archiviazione Bulk Eventi", ["Pagina ID" => $filtro_p]);
    flash_set("Eventi passati archiviati.");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['archivia_ev'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $arch_ev_id = (int)$_POST['archivia_ev'];
    $conn->query("UPDATE eventi SET archiviato = 1, blocca_auto_archivio = 0 WHERE id = $arch_ev_id");
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Archiviazione Evento", ["Evento ID" => $arch_ev_id]);
    flash_set("Evento archiviato!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['del_ev'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $ev_id = (int)$_POST['del_ev'];
    $res_ev_info = $conn->query("SELECT titolo FROM eventi WHERE id = $ev_id");
    $ev_titolo_log = ($res_ev_info && $r_log = $res_ev_info->fetch_assoc()) ? $r_log['titolo'] : '';
    $res_t_del = $conn->query("SELECT id FROM turni WHERE evento_id = $ev_id");
    while($t_del = $res_t_del->fetch_assoc()){ $conn->query("DELETE FROM prenotazioni WHERE turno_id = {$t_del['id']}"); }
    $conn->query("DELETE FROM turni WHERE evento_id = $ev_id");
    $conn->query("DELETE FROM campi_form WHERE evento_id = $ev_id");
    $conn->query("DELETE FROM sondaggi WHERE evento_id = $ev_id");
    $conn->query("DELETE FROM eventi WHERE id = $ev_id");
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Evento", ["Evento ID" => $ev_id, "Titolo" => $ev_titolo_log]);
    flash_set("Evento eliminato!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}
if (isset($_POST['del_turno'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $t_id = (int)$_POST['del_turno'];
    $conn->query("DELETE FROM prenotazioni WHERE turno_id = $t_id");
    $conn->query("DELETE FROM turni WHERE id = $t_id");
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Eliminazione Turno", ["Turno ID" => $t_id]);
    flash_set("Turno eliminato!");
    admin_redirect("eventi.php?p_id=$filtro_p&f_ev=$filtro_ev");
}

$sottocategorie = []; 
$res_sub = $conn->query("SELECT * FROM sottocategorie WHERE pagina_id = $filtro_p ORDER BY ordine ASC"); 
if($res_sub) { while($r = $res_sub->fetch_assoc()) $sottocategorie[] = $r; }

$ruoli = [];
$res_ru = $conn->query("SELECT * FROM ruoli ORDER BY id ASC"); 
if ($res_ru) { while($r = $res_ru->fetch_assoc()) $ruoli[] = $r; }

$eventi = []; $tutti_gli_eventi = [];
$filtro_ev = isset($_GET['f_ev']) ? (int)$_GET['f_ev'] : 0;

// ESTRAZIONE CON APPLICAZIONE VARIABILE MAGICA RBAC
$res_ev = $conn->query("SELECT e.*, sc.nome as nome_sottocategoria FROM eventi e LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id WHERE e.pagina_id = $filtro_p AND e.archiviato = 0 $sql_filtro_eventi_rbac ORDER BY sc.ordine ASC, e.ordine ASC, e.id DESC");

if ($res_ev) {
    while($row = $res_ev->fetch_assoc()) {
        $turni = [];
        $res_t = $conn->query("SELECT * FROM turni WHERE evento_id = {$row['id']} ORDER BY data_turno ASC, orario_inizio ASC");
        if($res_t) { while($t = $res_t->fetch_assoc()) $turni[] = $t; }
        $row['turni'] = $turni;
        $tutti_gli_eventi[] = $row;
        if ($filtro_ev === 0 || $filtro_ev == $row['id']) { $eventi[] = $row; }
    }
}
?>

<h4 class="fw-bold text-dark mb-4"><i class="fa fa-calendar-alt text-primary me-2"></i> Gestione Eventi e Turni</h4>

<div class="row">
    <?php if ($can_manage_settings): ?>
    <div class="col-md-3">
        <div class="card mb-4 shadow-sm border-0" style="border-top: 3px solid #0056b3 !important;">
            <div class="card-header bg-white fw-bold text-primary">Sottocategorie / Sezioni</div>
            <div class="card-body">
                <form method="POST" class="row g-2 mb-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="pagina_id" value="<?php echo $filtro_p; ?>">
                    <div class="col-8"><input type="text" name="nome_sottocategoria" class="form-control form-control-sm" placeholder="Nome Sezione..." required></div>
                    <div class="col-4"><input type="number" name="ordine_sottocategoria" class="form-control form-control-sm" value="0" required></div>
                    <div class="col-12"><button type="submit" name="add_sottocategoria" class="btn btn-primary btn-sm w-100 fw-bold">Aggiungi</button></div>
                </form>
                <div class="d-flex flex-column gap-1">
                    <?php foreach($sottocategorie as $sub): ?>
                        <div class="badge bg-light text-dark border p-2 text-start"><strong>[#<?php echo $sub['ordine']; ?>]</strong> <?php echo htmlspecialchars($sub['nome'] ?? ''); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?php echo $can_manage_settings ? 'col-md-9' : 'col-12'; ?>">
        <div class="card shadow-sm border-0 mb-3 bg-white">
            <div class="card-body p-2 d-flex justify-content-between align-items-center flex-wrap gap-2 rounded border">
                <form method="GET" id="formFiltroEv" class="d-flex align-items-center gap-2 m-0 w-75">
                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                    <i class="fa fa-filter text-secondary ms-2"></i>
                    <select name="f_ev" class="form-select form-select-sm fw-bold border-danger text-danger shadow-sm" onchange="document.getElementById('formFiltroEv').submit();">
                        <option value="0">Tutti i tuoi Eventi</option>
                        <?php foreach($tutti_gli_eventi as $e_opt): ?>
                            <option value="<?php echo $e_opt['id']; ?>" <?php echo $filtro_ev == $e_opt['id'] ? 'selected' : ''; ?>>
                                🎯 <?php echo htmlspecialchars($e_opt['titolo']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <div class="d-flex gap-2">
                    <?php if ($can_manage_settings): ?>
                        <form method="POST" class="m-0">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                            <button type="submit" name="archivia_conclusi" class="btn btn-outline-dark btn-sm fw-bold shadow-sm" data-confirm="Archiviare tutti gli eventi passati?"><i class="fa fa-archive"></i></button>
                        </form>
                    <?php endif; ?>
                    <?php if ($can_manage_eventi && $is_full_admin): ?>
                        <button type="button" class="btn btn-danger btn-sm fw-bold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modCreaEvento" style="background-color: #990000; border:none;"><i class="fa fa-plus-circle me-1"></i> Crea Evento</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle m-0">
                        <thead class="table-dark"><tr><th>Info Evento</th><th>Gestione Turni</th><th class="text-end">Azione</th></tr></thead>
                        <tbody>
                            <?php if(empty($eventi)): ?>
                                <tr><td colspan="3" class="text-center p-5 text-muted"><i class="fa fa-folder-open fs-2 mb-2 d-block"></i>Nessun evento trovato.</td></tr>
                            <?php else: ?>
                                <?php foreach($eventi as $ev): ?>
                                    <tr>
                                        <td style="width: 35%;">
                                            <div class="fw-bold text-danger fs-6"><?php if($ev['is_evidenza']): ?><span class="badge bg-warning text-dark me-1">⭐</span><?php endif; ?><?php echo htmlspecialchars($ev['titolo'] ?? ''); ?></div>
                                            <div class="small text-muted mb-1">📁 <?php echo $ev['nome_sottocategoria'] ? htmlspecialchars($ev['nome_sottocategoria']) : 'Nessuna Sezione'; ?> | 📍 <?php echo htmlspecialchars($ev['luogo'] ?? ''); ?></div>
                                            <?php if($ev['richiede_prenotazione'] == 0): ?><span class="badge bg-success me-1 mb-1">🔓 Libero</span><?php endif; ?>
                                            <?php if(!empty($ev['locandina_path'])): ?><span class="badge bg-info text-dark me-1 mb-1">🖼️ Locandina</span><?php endif; ?>
                                            <?php if(!empty($ev['allegato_pdf'])): ?><span class="badge bg-secondary me-1 mb-1">📄 PDF</span><?php endif; ?>
                                            <?php if(($ev['abilita_presenze'] ?? 1) == 1): ?><span class="badge bg-primary me-1 mb-1">✅ Check-in</span><?php endif; ?>

                                            <div class="mt-2 d-flex flex-wrap gap-1">
                                                <button type="button" class="btn btn-outline-primary btn-sm py-0" data-bs-toggle="modal" data-bs-target="#modEv<?php echo $ev['id']; ?>"><i class="fa fa-edit"></i> Modifica</button>
                                                <?php if ($can_manage_settings): ?>
                                                    <form method="POST" class="d-inline">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="archivia_ev" value="<?php echo $ev['id']; ?>">
                                                        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                        <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                                        <button type="submit" class="btn btn-outline-warning btn-sm py-0 text-dark" data-confirm="Archiviare questo evento?"><i class="fa fa-archive"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td style="width: 55%;">
                                            <form method="POST" class="bg-light p-2 rounded border mb-2">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="evento_id" value="<?php echo $ev['id']; ?>">
                                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                                <div class="row g-1 align-items-center">
                                                    <div class="col-md-2"><label class="small fw-bold text-muted d-block" style="font-size:0.75rem;">Data</label><input type="date" name="data_turno" class="form-control form-control-sm" required></div>
                                                    <div class="col-md-2"><label class="small fw-bold text-muted d-block" style="font-size:0.75rem;">Inizio</label><input type="time" name="orario_inizio" class="form-control form-control-sm" required></div>
                                                    <div class="col-md-2"><label class="small fw-bold text-muted d-block" style="font-size:0.75rem;">Fine</label><input type="time" name="orario_fine" class="form-control form-control-sm" required></div>
                                                    <div class="col-md-2"><label class="small fw-bold text-muted d-block" style="font-size:0.75rem;">Posti</label><input type="number" name="max_posti" class="form-control form-control-sm" value="30" required></div>
                                                    <div class="col-md-2"><label class="small fw-bold text-muted d-block" style="font-size:0.75rem;">Ap. Pren.</label><input type="datetime-local" name="data_apertura" class="form-control form-control-sm"></div>
                                                    <div class="col-md-2"><label class="small fw-bold text-muted d-block" style="font-size:0.75rem;">Ch. Pren.</label><input type="datetime-local" name="data_chiusura" class="form-control form-control-sm"></div>
                                                    <div class="col-12 mt-1">
                                                        <div class="form-check form-switch d-inline-block me-3"><input class="form-check-input" type="checkbox" name="abilita_lista_attesa" id="waAdd<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold text-warning" for="waAdd<?php echo $ev['id']; ?>">Lista Attesa</label></div>
                                                        <div class="form-check form-switch d-inline-block me-3"><input class="form-check-input" type="checkbox" name="abilita_multi_posto" id="mpAdd<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold text-info" for="mpAdd<?php echo $ev['id']; ?>">Multi-Posto</label></div>
                                                        <div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" name="richiede_approvazione" id="apprAdd<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold text-danger" for="apprAdd<?php echo $ev['id']; ?>">Approvazione</label></div>
                                                        <button type="submit" name="add_turno" class="btn btn-success btn-sm fw-bold px-3 float-end">+ Aggiungi Turno</button>
                                                    </div>
                                                </div>
                                            </form>

                                            <?php foreach($ev['turni'] as $t): ?>
    <div class="badge bg-light text-dark border p-2 me-1 mb-1 d-block text-start shadow-sm">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1">
            <span>
                <strong>📅 <?php echo date('d/m/Y', strtotime($t['data_turno'])); ?> | 🕒 <?php echo substr($t['orario_inizio'],0,5); ?>-<?php echo substr($t['orario_fine'],0,5); ?> (Posti: <?php echo $t['max_posti']; ?>)</strong>
            </span>
            
            <!-- INIZIO BLOCCO SOSTITUITO CON IL TASTO QR -->
            <div class="d-flex align-items-center gap-2">
                <a href="stampa_qr_aula.php?t_id=<?php echo $t['id']; ?>&p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-success btn-sm py-0 px-2 fw-bold" title="Stampa QR Code da appendere in Aula"><i class="fa fa-qrcode me-1"></i> QR Aula</a>
                
                <button type="button" class="btn btn-link btn-sm p-0 ms-1" data-bs-toggle="modal" data-bs-target="#modTurno<?php echo $t['id']; ?>"><i class="fa fa-edit text-primary"></i></button>
                <form method="POST" class="d-inline">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="del_turno" value="<?php echo $t['id']; ?>">
                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                    <button type="submit" class="btn btn-link btn-sm p-0 ms-1 text-danger" data-confirm="Eliminare questo turno?" title="Elimina Turno">&times;</button>
                </form>
            </div>
            <!-- FINE BLOCCO SOSTITUITO -->

        </div>
    </div>
<?php endforeach; ?>
                                        </td>

                                        <td class="text-end">
                                            <?php if ($can_manage_settings): ?>
                                                <form method="POST" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="del_ev" value="<?php echo $ev['id']; ?>">
                                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Eliminare questo evento e tutti i suoi turni e prenotazioni?"><i class="fa fa-trash"></i> Elimina</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modale CREA EVENTO -->
<div class="modal fade" id="modCreaEvento" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content shadow-lg border-0">
            <form method="POST" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="hidden" name="pagina_id" value="<?php echo $filtro_p; ?>">
                <div class="modal-header py-3 bg-danger text-white border-bottom-0">
                    <h6 class="modal-title fw-bold fs-5"><i class="fa fa-plus-circle me-2"></i> Crea Nuovo Evento in: <?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?></h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light text-start">
                    <div class="row bg-white p-3 rounded mb-4 shadow-sm border border-secondary">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">Informazioni Generali Evento</h6>
                        <div class="col-md-3 mb-2"><label class="form-label small fw-bold">Sottocategoria</label>
                            <select name="sottocategoria_id" class="form-select form-select-sm border-primary">
                                <option value="">-- Nessuna --</option>
                                <?php foreach ($sottocategorie as $sub): ?><option value="<?php echo $sub['id']; ?>"><?php echo htmlspecialchars($sub['nome'] ?? ''); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2"><label class="form-label small fw-bold">Titolo Evento <span class="text-danger">*</span></label><input type="text" name="titolo" class="form-control form-control-sm" required></div>
                        <div class="col-md-3 mb-2"><label class="form-label small fw-bold">Luogo / Aula</label><input type="text" name="luogo" class="form-control form-control-sm"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Ordine</label><input type="number" name="ordine_evento" class="form-control form-control-sm" value="0" required></div>
                        
                        <div class="col-md-4 mb-2">
                            <label class="form-label small fw-bold text-primary">Prenotabile da:</label>
                            <select name="ruolo_accesso_id" class="form-select form-select-sm">
                                <option value="0">🌐 Tutti (Pubblico / Accesso Libero)</option>
                                <option value="-1">🔑 Tutti gli Utenti Autenticati (SSO Unical)</option>
                                <?php foreach ($ruoli as $r): ?>
                                    <option value="<?php echo $r['id']; ?>">🔒 Solo: <?php echo htmlspecialchars($r['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-2"><label class="form-label small fw-bold text-danger">Immagine Locandina</label><input type="file" name="locandina_file" class="form-control form-control-sm" accept="image/png, image/jpeg, image/jpg"></div>
                        <div class="col-md-4 mb-2"><label class="form-label small fw-bold text-dark">Programma / Allegato (PDF)</label><input type="file" name="allegato_pdf" class="form-control form-control-sm" accept="application/pdf"></div>

                        <div class="col-md-4 mb-2 pt-2 border-top"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="richiede_prenotazione" id="reqPren" value="1" checked><label class="form-check-label small fw-bold text-primary" for="reqPren">Richiede Prenotazione</label></div></div>
                        <div class="col-md-4 mb-2 pt-2 border-top"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_evidenza" id="checkEvid" value="1"><label class="form-check-label small fw-bold text-danger" for="checkEvid">⭐ In EVIDENZA</label></div></div>
                        <div class="col-md-4 mb-2 pt-2 border-top"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="abilita_presenze" id="checkPres" value="1" checked><label class="form-check-label small fw-bold text-success" for="checkPres">Check-in / Scanner QR</label></div></div>
                        
                        <div class="col-12 mt-2 mb-2"><label class="form-label small fw-bold">Descrizione Evento</label><textarea name="descrizione" class="form-control form-control-sm editor-html" rows="3"></textarea></div>
                    </div>

                    <div class="row bg-white p-3 rounded border border-warning shadow-sm">
                        <h6 class="fw-bold text-warning border-bottom pb-2 mb-3" style="color:#b37700!important;">Configurazione Primo Turno (Opzionale)</h6>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Data Turno</label><input type="date" name="data_turno" class="form-control form-control-sm"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Ora Inizio</label><input type="time" name="orario_inizio" class="form-control form-control-sm"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Ora Fine</label><input type="time" name="orario_fine" class="form-control form-control-sm"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Capienza</label><input type="number" name="max_posti" class="form-control form-control-sm" value="30"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Ap. Prenotazioni</label><input type="datetime-local" name="data_apertura" class="form-control form-control-sm"></div>
                        <div class="col-md-2 mb-2"><label class="form-label small fw-bold">Ch. Prenotazioni</label><input type="datetime-local" name="data_chiusura" class="form-control form-control-sm"></div>
                    </div>
                </div>
                <div class="modal-footer py-2 bg-white">
                    <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="add_evento" onclick="tinymce.triggerSave();" class="btn btn-danger px-4 fw-bold shadow-sm" style="background-color: #990000;">Salva e Crea Evento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modali EDIT EVENTO e EDIT TURNO -->
<?php foreach($eventi as $ev): ?>
    <div class="modal fade" id="modEv<?php echo $ev['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="evento_id" value="<?php echo $ev['id']; ?>">
                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                    <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">

                    <div class="modal-header py-2 bg-primary text-white">
                        <h6 class="modal-title fw-bold"><i class="fa fa-edit me-1"></i> Modifica Evento: <?php echo htmlspecialchars($ev['titolo']); ?></h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body text-start bg-light">
                        <div class="row g-2 mb-3 bg-white p-2 rounded shadow-sm border border-secondary">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Sottocategoria / Sezione</label>
                                <select name="sottocategoria_id" class="form-select form-select-sm" <?php echo !$can_manage_settings ? 'disabled' : ''; ?>>
                                    <option value="">-- Nessuna --</option>
                                    <?php foreach ($sottocategorie as $sub): ?><option value="<?php echo $sub['id']; ?>" <?php echo ($ev['sottocategoria_id'] == $sub['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sub['nome']); ?></option><?php endforeach; ?>
                                </select>
                                <?php if (!$can_manage_settings && !empty($ev['sottocategoria_id'])): ?><input type="hidden" name="sottocategoria_id" value="<?php echo $ev['sottocategoria_id']; ?>"><?php endif; ?>
                            </div>
                            <div class="col-md-5"><label class="form-label small fw-bold">Titolo Evento</label><input type="text" name="titolo" class="form-control form-control-sm" value="<?php echo htmlspecialchars($ev['titolo'] ?? ''); ?>" required></div>
                            <div class="col-md-3"><label class="form-label small fw-bold">Luogo / Aula</label><input type="text" name="luogo" class="form-control form-control-sm" value="<?php echo htmlspecialchars($ev['luogo'] ?? ''); ?>"></div>
                        </div>

                        <div class="row g-2 mb-3 align-items-center bg-white p-2 rounded border shadow-sm">
                            <div class="col-md-2"><label class="form-label small fw-bold">Ordine</label><input type="number" name="ordine_evento" class="form-control form-control-sm" value="<?php echo (int)($ev['ordine'] ?? 0); ?>" required <?php echo !$can_manage_settings ? 'readonly' : ''; ?>></div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-primary">Prenotabile da:</label>
                                <select name="ruolo_accesso_id" class="form-select form-select-sm">
                                    <option value="0">🌐 Tutti</option>
                                    <option value="-1" <?php echo ($ev['ruolo_accesso_id'] == -1) ? 'selected' : ''; ?>>🔑 Solo Autenticati</option>
                                    <?php foreach ($ruoli as $r): ?><option value="<?php echo $r['id']; ?>" <?php echo ($ev['ruolo_accesso_id'] == $r['id']) ? 'selected' : ''; ?>>🔒 Solo: <?php echo htmlspecialchars($r['nome']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 pt-4 d-flex justify-content-end gap-3">
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="richiede_prenotazione" id="editReqPren<?php echo $ev['id']; ?>" value="1" <?php echo ($ev['richiede_prenotazione'] ?? 1) == 1 ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-primary" for="editReqPren<?php echo $ev['id']; ?>">Prenotazione</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="abilita_presenze" id="editPres<?php echo $ev['id']; ?>" value="1" <?php echo ($ev['abilita_presenze'] ?? 1) == 1 ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-success" for="editPres<?php echo $ev['id']; ?>">Scanner</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_evidenza" id="editEvid<?php echo $ev['id']; ?>" value="1" <?php echo ($ev['is_evidenza'] ?? 0) == 1 ? 'checked' : ''; ?> <?php echo !$can_manage_settings ? 'disabled' : ''; ?>><label class="form-check-label small fw-bold text-danger" for="editEvid<?php echo $ev['id']; ?>">⭐ Evidenza</label></div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3 bg-white p-2 rounded shadow-sm border border-secondary">
                            <div class="col-md-6 border-end">
                                <label class="form-label small fw-bold text-danger">Modifica Locandina (JPG/PNG)</label>
                                <input type="file" name="locandina_file" class="form-control form-control-sm" accept="image/png, image/jpeg, image/jpg">
                                <?php if(!empty($ev['locandina_path'])): ?><div class="mt-2 text-danger"><input type="checkbox" class="form-check-input" name="elimina_locandina" id="delLoc<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold" for="delLoc<?php echo $ev['id']; ?>"><i class="fa fa-trash"></i> Elimina locandina</label></div><?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark">Programma / Allegato (PDF)</label>
                                <input type="file" name="allegato_pdf" class="form-control form-control-sm" accept="application/pdf">
                                <?php if(!empty($ev['allegato_pdf'])): ?><div class="mt-2 d-flex align-items-center gap-2"><a href="../<?php echo htmlspecialchars($ev['allegato_pdf']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0"><i class="fa fa-eye"></i> Vedi</a><div class="form-check m-0"><input type="checkbox" class="form-check-input" name="elimina_pdf" id="delPdf<?php echo $ev['id']; ?>" value="1"><label class="form-check-label small fw-bold text-danger" for="delPdf<?php echo $ev['id']; ?>">Elimina PDF</label></div></div><?php endif; ?>
                            </div>
                        </div>

                        <div class="mb-2 bg-white p-2 rounded border"><label class="form-label small fw-bold">Descrizione Evento</label><textarea name="descrizione" class="form-control form-control-sm editor-html" rows="4"><?php echo htmlspecialchars($ev['descrizione'] ?? ''); ?></textarea></div>
                    </div>
                    <div class="modal-footer py-2 bg-white border-top">
                        <button type="button" class="btn btn-secondary btn-sm fw-bold" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" name="edit_evento" onclick="tinymce.triggerSave();" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-save"></i> Salva Modifiche Evento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach($ev['turni'] as $t): ?>
    <div class="modal fade" id="modTurno<?php echo $t['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <?php csrf_field(); ?>
                    <div class="modal-header py-2 bg-light">
                        <h6 class="modal-title fw-bold text-primary"><i class="fa fa-clock me-1"></i> Modifica Turno</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-start row g-2">
                        <input type="hidden" name="turno_id" value="<?php echo $t['id']; ?>">
                        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                        <input type="hidden" name="f_ev" value="<?php echo $filtro_ev; ?>">
                        
                        <div class="col-12"><label class="small fw-bold">Data Turno</label><input type="date" name="data_turno" class="form-control form-control-sm" value="<?php echo $t['data_turno']; ?>" required></div>
                        <div class="col-6"><label class="small fw-bold">Ora Inizio</label><input type="time" name="orario_inizio" class="form-control form-control-sm" value="<?php echo $t['orario_inizio']; ?>" required></div>
                        <div class="col-6"><label class="small fw-bold">Ora Fine</label><input type="time" name="orario_fine" class="form-control form-control-sm" value="<?php echo $t['orario_fine']; ?>" required></div>
                        <div class="col-12"><label class="small fw-bold">Capienza Posti</label><input type="number" name="max_posti" class="form-control form-control-sm" value="<?php echo $t['max_posti']; ?>" required></div>
                        
                        <div class="col-12 mt-3"><label class="small fw-bold text-primary">Ap. Prenotazioni</label><input type="datetime-local" name="data_apertura" class="form-control form-control-sm" value="<?php echo !empty($t['data_apertura']) ? date('Y-m-d\TH:i', strtotime($t['data_apertura'])) : ''; ?>"></div>
                        <div class="col-12"><label class="small fw-bold text-danger">Ch. Prenotazioni</label><input type="datetime-local" name="data_chiusura" class="form-control form-control-sm" value="<?php echo !empty($t['data_chiusura']) ? date('Y-m-d\TH:i', strtotime($t['data_chiusura'])) : ''; ?>"></div>
                        
                        <div class="col-12 mt-2">
                            <div class="border p-2 bg-light rounded d-flex gap-3 flex-wrap">
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="abilita_lista_attesa" id="waEdit<?php echo $t['id']; ?>" value="1" <?php echo (isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa']==1) ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-warning" for="waEdit<?php echo $t['id']; ?>">L. Attesa</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="abilita_multi_posto" id="mpEdit<?php echo $t['id']; ?>" value="1" <?php echo (isset($t['abilita_multi_posto']) && $t['abilita_multi_posto']==1) ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-info" for="mpEdit<?php echo $t['id']; ?>">Multi-Posto</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="richiede_approvazione" id="apprEdit<?php echo $t['id']; ?>" value="1" <?php echo (isset($t['richiede_approvazione']) && $t['richiede_approvazione']==1) ? 'checked' : ''; ?>><label class="form-check-label small fw-bold text-danger" for="apprEdit<?php echo $t['id']; ?>">Approvazione</label></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer py-2"><button type="submit" name="edit_turno" class="btn btn-primary btn-sm w-100 fw-bold">Salva Turno</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<?php require_once 'admin_footer.php'; ?>
