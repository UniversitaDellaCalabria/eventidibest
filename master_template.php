<?php
// master_template.php - Motore Grafico Multi-Layout
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'config.php';
require_once 'functions.php';

sync_sso_user($conn);

$current_filename = $page_slug ?? basename($_SERVER['PHP_SELF'], '.php');
$utente_logged = !empty($_SESSION['utente_id']);
$utente_ruolo_id = (int)($_SESSION['utente_ruolo_id'] ?? 5);

$stmt_p = $conn->prepare("SELECT * FROM pagine_eventi WHERE slug = ? LIMIT 1");
$stmt_p->bind_param("s", $current_filename);
$stmt_p->execute();
$res_p = $stmt_p->get_result();
if (!$res_p || $res_p->num_rows == 0) { $res_p = $conn->query("SELECT * FROM pagine_eventi ORDER BY id ASC LIMIT 1"); }
$page_cfg = ($res_p && $res_p->num_rows > 0) ? $res_p->fetch_assoc() : [];
$p_id = (int)($page_cfg['id'] ?? 1);

// CONTROLLO MANUTENZIONE E GESTORI
$is_visibile = (int)($page_cfg['visibile'] ?? 1);
$is_gestore_o_admin = false;

if ($utente_logged) {
    $sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
    if ($utente_ruolo_id === 1 || in_array('1', $sec_roles)) { $is_gestore_o_admin = true; } 
    else {
        $gestori_arr = array_filter(explode(',', $page_cfg['gestori_utenti_ids'] ?? ($page_cfg['gestore_utente_id'] ? (string)$page_cfg['gestore_utente_id'] : '')));
        if (in_array((string)$_SESSION['utente_id'], $gestori_arr) || $page_cfg['gestore_utente_id'] == $_SESSION['utente_id']) { $is_gestore_o_admin = true; }
    }
}

$banner_manutenzione_admin = "";
if ($is_visibile === 0) {
    if (!$is_gestore_o_admin) {
        require_once 'header.php';
        echo '<div class="container my-5 text-center" style="max-width: 600px;">
                <div class="card shadow-sm p-5 border-top border-warning border-4" style="border-radius: 12px; margin-top: 80px;">
                    <i class="fa fa-tools text-warning mb-3" style="font-size: 4rem;"></i>
                    <h3 class="fw-bold text-dark">Pagina in Manutenzione</h3>
                    <p class="text-secondary mt-2 fs-5">L\'area è temporaneamente non disponibile.<br>Riprova più tardi.</p>
                </div>
              </div>';
        require_once 'footer.php'; exit;
    } else {
        $banner_manutenzione_admin = "<div class='alert alert-warning alert-dismissible fade show fw-bold text-center my-3 shadow-sm border-warning' style='border-radius: 8px;' role='alert'><i class='fa fa-exclamation-triangle me-2 fs-5 align-middle'></i> <strong>AVVISO AMMINISTRATORE:</strong> Questa pagina è IN MANUTENZIONE / NASCOSTA agli utenti pubblici.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
}

$logged_u_info = null; $val_nome = ''; $val_cognome = ''; $val_email = ''; $val_matricola = '';
if ($utente_logged) {
    $u_id_logged = (int)$_SESSION['utente_id'];
    $stmt_u = $conn->prepare("SELECT * FROM utenti WHERE id = ? LIMIT 1");
    $stmt_u->bind_param("i", $u_id_logged); $stmt_u->execute(); $res_curr_u = $stmt_u->get_result();
    if ($res_curr_u && $res_curr_u->num_rows > 0) {
        $logged_u_info = $res_curr_u->fetch_assoc();
        $val_nome = $logged_u_info['nome']; $val_cognome = $logged_u_info['cognome']; $val_email = $logged_u_info['email'];
        $val_matricola = !empty($logged_u_info['matricola_studente']) ? $logged_u_info['matricola_studente'] : (!empty($logged_u_info['matricola_dipendente']) ? $logged_u_info['matricola_dipendente'] : ($logged_u_info['matricola'] ?? ''));
    }
}

// ELABORAZIONE POST PRENOTAZIONE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invia_prenotazione'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $turno_id = (int)$_POST['turno_id']; $nome = trim($_POST['nome'] ?? ''); $cognome = trim($_POST['cognome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? '')); $matricola = trim($_POST['matricola'] ?? '');
    $num_posti = isset($_POST['num_posti']) ? max(1, (int)$_POST['num_posti']) : 1;
    $u_id_bind = $utente_logged ? (int)$_SESSION['utente_id'] : null;

    if (empty($nome) || empty($cognome) || empty($email)) { header("Location: {$current_filename}.php?status=error"); exit; }

    // CONTROLLO DUPLICATI (Email o Matricola)
    $stmt_dup = $conn->prepare("SELECT id FROM prenotazioni WHERE turno_id = ? AND (email = ? OR (matricola != '' AND matricola = ?))");
    $stmt_dup->bind_param("iss", $turno_id, $email, $matricola); 
    $stmt_dup->execute();
    if ($stmt_dup->get_result()->num_rows > 0) { header("Location: {$current_filename}.php?status=dup"); exit; }

    $stmt_t = $conn->prepare("SELECT t.*, e.titolo as evento_titolo, e.luogo, e.pagina_id FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = ? LIMIT 1");
    $stmt_t->bind_param("i", $turno_id); $stmt_t->execute(); $res_t = $stmt_t->get_result();

    if ($res_t && $t_info = $res_t->fetch_assoc()) {
        $now = date('Y-m-d H:i:s');
        $apertura_ok = empty($t_info['data_apertura']) || ($now >= $t_info['data_apertura']);
        $chiusura_ok = empty($t_info['data_chiusura']) || ($now <= $t_info['data_chiusura']);

        if (!$apertura_ok) { header("Location: {$current_filename}.php?status=notopened"); exit; } 
        elseif (!$chiusura_ok) { header("Location: {$current_filename}.php?status=closed"); exit; } 

        $sigla = strtoupper(substr($current_filename, 0, 2));
        $codice_p = $sigla . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        
        $custom_data = [];
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'custom_') === 0) { $custom_data[str_replace('custom_', '', $k)] = is_array($v) ? implode(', ', $v) : trim($v); }
        }

        if (!empty($_FILES)) {
            $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx'];

            foreach ($_FILES as $k => $f) {
                if (strpos($k, 'custom_') === 0) {
                    $field_name = str_replace('custom_', '', $k); $file_paths = [];
                    $upload_dir = __DIR__ . '/uploads/allegati_prenotazioni/';
                    if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

                    $count_files = is_array($f['name']) ? count($f['name']) : 1;
                    for ($i = 0; $i < $count_files; $i++) {
                        $tmp_name = is_array($f['tmp_name']) ? $f['tmp_name'][$i] : $f['tmp_name'];
                        $name = is_array($f['name']) ? $f['name'][$i] : $f['name'];
                        $error = is_array($f['error']) ? $f['error'][$i] : $f['error'];

                        if (!empty($name) && $error === UPLOAD_ERR_OK) {
                            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                            $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($finfo, $tmp_name); finfo_close($finfo);
                            if (in_array($ext, $allowed_exts) && in_array($mime, $allowed_mimes)) {
                                $filename = bin2hex(random_bytes(16)) . '.' . $ext;
                                if (move_uploaded_file($tmp_name, $upload_dir . $filename)) { $file_paths[] = 'uploads/allegati_prenotazioni/' . $filename; }
                            }
                        }
                    }
                    if (!empty($file_paths)) { $custom_data[$field_name] = implode(', ', $file_paths); }
                }
            }
        }

        $json_custom_bind = !empty($custom_data) ? json_encode($custom_data, JSON_UNESCAPED_UNICODE) : null;

        // =====================================================================
        // FASE 2: SEZIONE CRITICA - transazione + lock pessimistico anti-overbooking
        // Il lock FOR UPDATE tiene in coda le richieste concorrenti sullo stesso
        // turno finché questa transazione non fa commit/rollback, così il conteggio
        // posti letto qui dentro è sempre quello reale, mai "vecchio".
        // =====================================================================
        $conn->begin_transaction();
        $insert_ok = false;
        try {
            $occupati = getPostiOccupati($conn, $turno_id, true);

            $stato_prenotazione = 'confermata';
            if (isset($t_info['richiede_approvazione']) && $t_info['richiede_approvazione'] == 1) {
                $stato_prenotazione = 'da_approvare';
            } elseif (($occupati + $num_posti) > $t_info['max_posti']) {
                if (isset($t_info['abilita_lista_attesa']) && $t_info['abilita_lista_attesa'] == 1) {
                    $stato_prenotazione = 'in_attesa';
                } else {
                    $conn->rollback();
                    header("Location: {$current_filename}.php?status=full"); exit;
                }
            }

            $stmt_ins = $conn->prepare("INSERT INTO prenotazioni (turno_id, utente_id, codice_prenotazione, stato, num_posti, nome, cognome, email, matricola, dati_custom_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->bind_param("iississsss", $turno_id, $u_id_bind, $codice_p, $stato_prenotazione, $num_posti, $nome, $cognome, $email, $matricola, $json_custom_bind);
            $insert_ok = $stmt_ins->execute();

            if (!$insert_ok) {
                throw new Exception($conn->error ?: 'Errore sconosciuto in fase di inserimento prenotazione');
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log("[Prenotazione][turno_id=$turno_id] Transazione fallita: " . $e->getMessage());
            header("Location: {$current_filename}.php?status=error"); exit;
        }
        // ================= FINE SEZIONE CRITICA =================
        // Da qui in poi: email/notifiche, FUORI dalla transazione (non tengono bloccata la riga).
        
        if ($insert_ok) {
            $data_formatted = date('d/m/Y', strtotime($t_info['data_turno']));
            $ora_formatted = substr($t_info['orario_inizio'], 0, 5) . ' - ' . substr($t_info['orario_fine'], 0, 5);
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            $link_ricevuta_url = $proto . $domain . $base_dir . "/stampa_ricevuta.php?code=" . urlencode($codice_p);
            $btn_ricevuta_html = "<p style='margin-top:15px;'><a href='$link_ricevuta_url' target='_blank' style='background:#B30000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>";
            $gcal_link = getGoogleCalendarUrl($t_info['evento_titolo'], $t_info['data_turno'], $t_info['orario_inizio'], $t_info['orario_fine'], $t_info['luogo'], "Prenotazione $codice_p ($num_posti posti)");
            $ics_link = $proto . $domain . $base_dir . "/genera_ics.php?t_id=" . $t_info['id'];
            $cal_html_buttons = "<p style='margin-top:15px;'><a href='$gcal_link' target='_blank' style='background:#4285F4; color:#fff; padding:8px 14px; text-decoration:none; border-radius:4px; font-weight:bold;'>📅 Aggiungi a Google Calendar</a> <a href='$ics_link' style='background:#334155; color:#fff; padding:8px 14px; text-decoration:none; border-radius:4px; font-weight:bold;'>📥 Scarica File .ics</a></p>";
            $r_find = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}', '{LINK_RICEVUTA}'];
            $r_repl = [$nome, $cognome, $matricola, $t_info['evento_titolo'], $data_formatted, $ora_formatted, $t_info['luogo'], $codice_p, $btn_ricevuta_html];
            $sys_email = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();

            if ($stato_prenotazione === 'da_approvare') {
                $obj_tpl = "Richiesta Ricevuta (In valutazione): " . $t_info['evento_titolo'];
                $body_tpl = "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>La tua richiesta per <strong>$num_posti posti</strong> all'evento <strong>{TITOLO_EVENTO}</strong> è in fase di valutazione.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>{LINK_RICEVUTA}";
            } elseif ($stato_prenotazione === 'in_attesa') {
                $obj_tpl = "Lista d'Attesa: " . $t_info['evento_titolo'];
                $body_tpl = "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Sei stato inserito in <strong>lista d'attesa</strong> per l'evento <strong>{TITOLO_EVENTO}</strong>.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>{LINK_RICEVUTA}" . $cal_html_buttons;
            } else {
                $obj_tpl  = $sys_email['email_conferma_oggetto'] ?: 'Conferma Prenotazione Eventi';
                $body_tpl = ($sys_email['email_conferma_corpo'] ?: "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Prenotazione confermata per <strong>{TITOLO_EVENTO}</strong>.</p><p>📅 {DATA_TURNO} | 🕒 {ORARIO_TURNO}<br>🎟️ Codice: <strong>{CODICE_PRENOTAZIONE}</strong></p>{LINK_RICEVUTA}") . $cal_html_buttons;
            }
            
            inviaNotificaEmail($email, str_replace($r_find, $r_repl, $obj_tpl), str_replace($r_find, $r_repl, $body_tpl), $conn);

            $res_p_gest = $conn->query("SELECT gestore_utente_id, gestori_utenti_ids FROM pagine_eventi WHERE id = {$t_info['pagina_id']} LIMIT 1");
            if ($res_p_gest && $row_p_gest = $res_p_gest->fetch_assoc()) {
                $ids_gest = array_filter(explode(',', $row_p_gest['gestori_utenti_ids'] ?? ''));
                if ((int)$row_p_gest['gestore_utente_id'] > 0) { $ids_gest[] = (int)$row_p_gest['gestore_utente_id']; }
                if (!empty($ids_gest)) {
                    $res_u_gest = $conn->query("SELECT email FROM utenti WHERE id IN (".implode(',', array_unique(array_map('intval', $ids_gest))).") AND email IS NOT NULL AND email != ''");
                    if ($res_u_gest && $res_u_gest->num_rows > 0) {
                        $obj_gest = "Nuova Registrazione ($num_posti posti): " . $t_info['evento_titolo'];
                        $body_gest = "<p>È stata registrata una nuova prenotazione per l'evento <strong>" . htmlspecialchars($t_info['evento_titolo']) . "</strong>.</p><p>👤 " . htmlspecialchars($nome . ' ' . $cognome) . "<br>📧 " . htmlspecialchars($email) . "</p>";
                        while ($u_gest = $res_u_gest->fetch_assoc()) { inviaNotificaEmail($u_gest['email'], $obj_gest, $body_gest, $conn); }
                    }
                }
            }

            $param_stato = ($stato_prenotazione === 'in_attesa') ? "&st_tipo=attesa" : (($stato_prenotazione === 'da_approvare') ? "&st_tipo=approvare" : "");
            header("Location: {$current_filename}.php?status=success&code=" . urlencode($codice_p) . $param_stato); exit;
        } else { header("Location: {$current_filename}.php?status=error"); exit; }
    }
    header("Location: {$current_filename}.php"); exit;
}

// RENDER MESSAGGI
$messaggio_prenotazione = "";
if (isset($_GET['status'])) {
    $st = $_GET['status'];
    if ($st === 'success' && isset($_GET['code'])) {
        $codice_p = htmlspecialchars($_GET['code']);
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $link_btn = $proto . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/stampa_ricevuta.php?code=" . urlencode($codice_p);
        $btn_scarica_pdf = "<a href='$link_btn' target='_blank' class='btn btn-danger btn-sm fw-bold ms-3'><i class='fa fa-file-pdf me-1'></i> Stampa Ricevuta</a>";
        if (isset($_GET['st_tipo']) && $_GET['st_tipo'] === 'approvare') { $messaggio_prenotazione = "<div class='alert alert-info fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-info'><i class='fa fa-hourglass-half me-2'></i> Richiesta in approvazione! Codice: <span class='badge bg-info text-dark ms-2'>$codice_p</span> $btn_scarica_pdf</div>"; } 
        elseif (isset($_GET['st_tipo']) && $_GET['st_tipo'] === 'attesa') { $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-warning'><i class='fa fa-clock me-2'></i> In Lista d'Attesa! Codice: <span class='badge bg-warning text-dark ms-2'>$codice_p</span> $btn_scarica_pdf</div>"; } 
        else { $messaggio_prenotazione = "<div class='alert alert-success fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-success'><i class='fa fa-check-circle me-2'></i> Prenotazione confermata! Codice: <span class='badge bg-success ms-2'>$codice_p</span> $btn_scarica_pdf</div>"; }
    } elseif ($st === 'dup') { $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-exclamation-triangle me-2'></i> Prenotazione già esistente per questo turno con la stessa email.</div>"; } 
    elseif ($st === 'full') { $messaggio_prenotazione = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'><i class='fa fa-exclamation-circle me-2'></i> Posti esauriti per questo turno.</div>"; } 
    elseif ($st === 'closed') { $messaggio_prenotazione = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'><i class='fa fa-times-circle me-2'></i> Le prenotazioni sono chiuse.</div>"; } 
    elseif ($st === 'notopened') { $messaggio_prenotazione = "<div class='alert alert-warning fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-warning'><i class='fa fa-clock me-2'></i> Le prenotazioni non sono ancora aperte.</div>"; } 
    elseif ($st === 'error') { $messaggio_prenotazione = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm border-0 border-start border-4 border-danger'>Errore di registrazione. Compilare tutti i campi obbligatori.</div>"; }
}

// =========================================================================
// INIZIALIZZAZIONE VARIABILI DI LAYOUT E GRIGLIA
// =========================================================================
$col_primaria     = $page_cfg['colore_primario'] ?? '#0056b3';
$layout_template  = $page_cfg['layout_template'] ?? 'list';
$chiedi_matricola = (int)($page_cfg['chiedi_matricola'] ?? 1);

// Variabili per il rendering della griglia
$num_colonne = (int)($page_cfg['num_colonne'] ?? 2);
if ($num_colonne == 1) { $col_class = "col-12"; } 
elseif ($num_colonne == 3) { $col_class = "col-lg-4 col-md-6"; } 
else { $col_class = "col-md-6"; }

$nomi_ruoli = [-1 => 'Utenti Autenticati', 1 => 'Amministratore', 2 => 'Gestore Prenotazioni', 3 => 'Studenti', 4 => 'Dipendenti', 5 => 'Esterni'];
$etichette_riservato = [-1 => 'Riservato Utenti Autenticati', 1 => 'Riservato Amministratori', 2 => 'Riservato Gestori', 3 => 'Riservato Studenti', 4 => 'Riservato Dipendenti', 5 => 'Riservato Esterni'];

// ESTRAZIONE DATI
$sottocategorie = []; $categorie_nomi = [];
$res_sub = $conn->query("SELECT * FROM sottocategorie WHERE pagina_id = $p_id ORDER BY ordine ASC, id ASC");
if ($res_sub) { while ($sub = $res_sub->fetch_assoc()) { $sottocategorie[] = $sub; $categorie_nomi[] = $sub['nome']; } }

$evento_evidenza = null; 
$all_turni_flat = []; 
$eventi_per_data = []; 
$json_events_calendar = []; 
$sezioni_superiori = []; 
$eventi_macro = [];

$sql_ev = "SELECT e.*, sc.nome as nome_sottocategoria FROM eventi e LEFT JOIN sottocategorie sc ON e.sottocategoria_id = sc.id WHERE e.pagina_id = $p_id AND e.archiviato = 0 ORDER BY e.is_evidenza DESC, sc.ordine ASC, e.ordine ASC, e.id DESC";
$res_ev = $conn->query($sql_ev);
if ($res_ev) {
    while ($ev = $res_ev->fetch_assoc()) {
        $turni = [];
        $res_t = $conn->query("SELECT * FROM turni WHERE evento_id = {$ev['id']} ORDER BY data_turno ASC, orario_inizio ASC");
        while ($t = $res_t->fetch_assoc()) {
            $turni[] = $t;
            
            // Popolamento lista Piatta (Flat)
            $t_flat = $t;
            $t_flat['evento_titolo'] = $ev['titolo'];
            $t_flat['evento_luogo'] = $ev['luogo'];
            $t_flat['evento_descrizione'] = $ev['descrizione'];
            $t_flat['evento_locandina'] = $ev['locandina_path'];
            $t_flat['richiede_prenotazione'] = $ev['richiede_prenotazione'];
            $t_flat['ruolo_accesso_id'] = $ev['ruolo_accesso_id'];
            $t_flat['categoria'] = $ev['nome_sottocategoria'] ?: 'Altre Attività';
            $t_flat['evento_id'] = $ev['id'];
            $t_flat['is_evidenza'] = $ev['is_evidenza'];
            $all_turni_flat[] = $t_flat;

            // Popolamento Eventi per FullCalendar
            $start_dt = $t['data_turno'] . 'T' . $t['orario_inizio'];
            $end_dt = $t['data_turno'] . 'T' . $t['orario_fine'];
            $json_events_calendar[] = [
                'id' => 'turno_' . $t['id'],
                'title' => htmlspecialchars_decode($ev['titolo']),
                'start' => $start_dt,
                'end' => $end_dt,
                'color' => $col_primaria,
                'extendedProps' => ['turno_id' => $t['id'], 'luogo' => htmlspecialchars_decode($ev['luogo'])]
            ];
        }
        $ev['turni'] = $turni;
        
        if ((int)$ev['is_evidenza'] === 1 && $evento_evidenza === null) { 
            $evento_evidenza = $ev; 
            continue; 
        }

        $sezione = $ev['nome_sottocategoria'] ? $ev['nome_sottocategoria'] : 'Altre Attività';
        $e_superiore = (stripos($sezione, 'Online') !== false || stripos($sezione, 'Generali') !== false || stripos($sezione, 'Speciali') !== false || stripos($sezione, 'Conclusive') !== false);
        if ($e_superiore) {
            $sezioni_superiori[$sezione][] = $ev;
        } else {
            $eventi_macro[] = $ev;
        }

        if (!empty($turni)) {
            foreach ($turni as $t) {
                $d = !empty($t['data_turno']) ? $t['data_turno'] : '9999-12-31';
                $ev_giorno = $ev; $ev_giorno['turni'] = [$t]; 
                $eventi_per_data[$d][] = $ev_giorno;
            }
        } else {
            $ev_giorno = $ev; $ev_giorno['turni'] = []; 
            $eventi_per_data['9999-12-31'][] = $ev_giorno;
        }
    }
}
ksort($eventi_per_data);

usort($all_turni_flat, function($a, $b) {
    $dateA = $a['data_turno'] . ' ' . $a['orario_inizio'];
    $dateB = $b['data_turno'] . ' ' . $b['orario_inizio'];
    return strcmp($dateA, $dateB);
});

// =========================================================================
// FUNZIONI DI RENDER (MODALE e CARD)
// =========================================================================
if (!function_exists('renderCardUniversal')) {
    function renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola = 1) {
        $ruolo_richiesto = (int)($ev['ruolo_accesso_id'] ?? 0);
        $sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
        $is_admin = ($utente_ruolo_id === 1 || in_array('1', $sec_roles));
        
        if ($ruolo_richiesto === 0) { $ruolo_ok = true; } 
        elseif ($ruolo_richiesto === -1) { $ruolo_ok = $utente_logged; } 
        else { $ruolo_ok = ($utente_logged && ($utente_ruolo_id === $ruolo_richiesto || in_array((string)$ruolo_richiesto, $sec_roles) || $is_admin)); }

        $req_prenotazione = (int)($ev['richiede_prenotazione'] ?? 1);
        $first_turno_id = !empty($ev['turni'][0]['id']) ? $ev['turni'][0]['id'] : rand(100, 999);
        $collapse_id = "colTurni_" . $ev['id'] . "_" . $first_turno_id;

        echo '<div class="card shadow-sm border-0 h-100 d-flex flex-column" style="border-radius: 8px; overflow: hidden; border-top: 4px solid '.$col_primaria.' !important; background: #ffffff;">';
        
        if (!empty($ev['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev['locandina_path'])) {
            echo '<img src="'.htmlspecialchars($ev['locandina_path']).'" class="card-img-top border-bottom" alt="Locandina" style="max-height: 250px; object-fit: cover;">';
        }
        
        echo '<div class="card-body p-4 d-flex flex-column">';
        echo '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">';
        echo '<h5 class="fw-bold fs-5 m-0" style="color: '.$col_primaria.';">'.htmlspecialchars($ev['titolo']).'</h5>';
        if ($ruolo_richiesto === -1) echo '<span class="badge bg-warning text-dark">🔑 Solo Autenticati</span>';
        elseif ($ruolo_richiesto > 0) echo '<span class="badge bg-dark">🔒 Solo '.htmlspecialchars($nomi_ruoli[$ruolo_richiesto] ?? '').'</span>';
        echo '</div>';
        
        if (!empty($ev['descrizione'])) echo '<div class="text-secondary mb-3 flex-grow-1" style="font-size: 0.9rem; line-height: 1.5;">'.$ev['descrizione'].'</div>';
        
        if (!empty($ev['allegato_pdf'])) {
            echo '<div class="mb-3 p-3 rounded" style="background-color: #f8f9fa; border-left: 4px solid #B30000; font-size:0.9rem;">
                    <strong class="d-block mb-1 text-dark">Materiale Informativo:</strong>
                    <a href="'.htmlspecialchars($ev['allegato_pdf']).'" target="_blank" class="btn btn-outline-danger btn-sm fw-bold">
                        <i class="fa fa-file-pdf me-1"></i> Visualizza / Scarica Programma
                    </a>
                  </div>';
        }

        if (!empty($ev['luogo'])) {
            echo '<div class="bg-light p-2 rounded mt-2 text-dark shadow-sm" style="border-left: 4px solid #990000; font-size: 1rem;">
                    <i class="fa fa-map-pin text-danger me-2"></i> <strong class="text-primary">Luogo:</strong> '.htmlspecialchars($ev['luogo']).'
                  </div>';
        }
        
        echo '</div>';

        if ($req_prenotazione == 0) {
            $t0 = $ev['turni'][0] ?? null;
            $ora_str = $t0 ? "Ore " . substr($t0['orario_inizio'], 0, 5) . " - " . substr($t0['orario_fine'], 0, 5) : "Orario da definire";
            echo '<div class="card-footer border-top-0 d-flex justify-content-between align-items-center p-3 flex-wrap gap-2" style="background-color: #f4fbf7 !important; border-top: 1px solid #e2e8f0 !important;">';
            echo '<div class="d-flex align-items-center gap-3 flex-wrap"><span class="fw-bold text-dark d-flex align-items-center gap-2" style="font-size: 0.9rem;"><i class="fa-regular fa-clock text-secondary"></i> <strong>'.$ora_str.'</strong></span><span class="border-start ps-3 fw-bold text-success d-flex align-items-center gap-1" style="font-size: 0.9rem; color: #198754 !important;">🔓 <strong>Ingresso Libero</strong></span></div>';
            echo '<div><button class="btn btn-danger btn-sm fw-bold px-3 py-2 shadow-sm" style="background-color: #b04242; border: none; border-radius: 6px; font-size: 0.85rem;" disabled>Senza Prenotazione</button></div>';
            echo '</div>';
        } elseif (!empty($ev['turni'])) {
            echo '<div class="card-footer bg-light border-top-0 p-2">';
            echo '<button class="btn btn-light w-100 d-flex justify-content-between align-items-center fw-bold py-2 px-3 text-primary border-0 collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#'.$collapse_id.'" aria-expanded="false">';
            echo '<span style="font-size: 0.95rem; letter-spacing: 0.5px; color: '.$col_primaria.';">📅 TURNI DISPONIBILI ('.count($ev['turni']).')</span><i class="fa fa-chevron-down" style="color: '.$col_primaria.';"></i></button>';
            echo '<div class="collapse mt-2" id="'.$collapse_id.'"><div class="d-flex flex-column gap-3 p-2">';
            
            foreach ($ev['turni'] as $t) {
                $occ = getPostiOccupati($conn, $t['id']);
                $disponibili = $t['max_posti'] - $occ;
                $soldout = ($disponibili <= 0);
                $is_waitlist = ($soldout && isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa'] == 1);
                
                $now = date('Y-m-d H:i:s');
                
                // CALCOLO EVENTO CONCLUSO
                $datetime_fine = $t['data_turno'] . ' ' . (!empty($t['orario_fine']) ? substr($t['orario_fine'], 0, 8) : '23:59:59');
                $evento_concluso = ($now > $datetime_fine);

                $prenotazioni_aperte = true;
                $msg_scadenza = "";
                $colore_bg = "#fff3cd"; $colore_testo = "#856404";
                
                if ($evento_concluso) {
                    $msg_scadenza = "L'evento è terminato";
                    $colore_bg = "#e2e8f0"; $colore_testo = "#475569";
                } elseif (!empty($t['data_apertura']) && $now < $t['data_apertura']) {
                    $prenotazioni_aperte = false; $msg_scadenza = "Apertura: " . date('d/m/Y \a\l\l\e H:i', strtotime($t['data_apertura']));
                    $colore_bg = "#e2e3e5"; $colore_testo = "#383d41";
                } elseif (!empty($t['data_chiusura'])) {
                    if ($now > $t['data_chiusura']) {
                        $prenotazioni_aperte = false; $msg_scadenza = "Prenotazioni Chiuse";
                        $colore_bg = "#f8d7da"; $colore_testo = "#721c24";
                    } else {
                        $msg_scadenza = "Scade il: " . date('d/m/Y \a\l\l\e H:i', strtotime($t['data_chiusura']));
                    }
                }
                
                $gcal_url = getGoogleCalendarUrl($ev['titolo'], $t['data_turno'], $t['orario_inizio'], $t['orario_fine'], $ev['luogo'], "Prenotazione " . $ev['titolo']);
                
                echo '<div class="p-3 bg-white border rounded shadow-sm">';
                if ($msg_scadenza) echo '<div style="background-color: '.$colore_bg.'; color: '.$colore_testo.'; padding: 4px 10px; border-radius: 4px; font-size: 0.78rem; font-weight: bold; margin-bottom: 12px; display: inline-block; border: 1px solid rgba(0,0,0,0.05);">⏳ '.$msg_scadenza.'</div>';
                
                echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">';
                echo '<div style="font-size: 0.95rem;"><strong>🕒 '.formattaDataItaliano($t['data_turno']).' ('.substr($t['orario_inizio'], 0, 5).' - '.substr($t['orario_fine'], 0, 5).')</strong>';
                if($is_waitlist) echo '<span class="badge bg-warning text-dark ms-2">Lista d\'Attesa Attiva</span>';
                else echo '<span class="text-muted ms-2" style="font-size: 0.85rem;">👥 '.max(0, $disponibili).' posti liberi su '.$t['max_posti'].'</span>';
                echo '</div>'; 
                
                echo '<div class="d-flex gap-1 align-items-center">';
                echo '<div class="dropdown d-inline-block me-1"><button class="btn btn-outline-secondary btn-sm dropdown-toggle py-1 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Aggiungi al Calendario" style="font-size:0.8rem;">📅 <span class="d-none d-sm-inline">Calendario</span></button><ul class="dropdown-menu dropdown-menu-end shadow-sm p-1" style="font-size:0.85rem;"><li><a class="dropdown-item py-1" href="'.$gcal_url.'" target="_blank"><i class="fa-fab fa-google text-primary me-2"></i> Google Calendar</a></li><li><a class="dropdown-item py-1" href="genera_ics.php?t_id='.$t['id'].'"><i class="fa fa-calendar-alt text-dark me-2"></i> Outlook / Apple (.ics)</a></li></ul></div>';
                
                if ($evento_concluso) { echo '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled style="background: #e2e8f0; color: #475569; border: 1px solid #cbd5e1;"><i class="fa fa-flag-checkered me-1"></i> Evento Concluso</button>'; }
                elseif (!$prenotazioni_aperte) { echo '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled style="background: #e9ecef; color: #6c757d; border: 1px solid #ced4da;">Non Prenotabile</button>'; } 
                elseif ($soldout && !$is_waitlist) { echo '<button class="btn btn-danger btn-sm fw-bold py-1 px-3 shadow-sm" disabled style="background: #dc3545; color: white;">Sold Out</button>'; } 
                elseif (($ruolo_richiesto > 0 || $ruolo_richiesto === -1) && !$utente_logged) { echo '<a href="saml_login.php" class="btn btn-primary btn-sm fw-bold py-1 px-3 shadow-sm" style="background-color: '.$col_primaria.'; border: none;"><i class="fa fa-key me-1"></i> Accedi</a>'; } 
                elseif (($ruolo_richiesto > 0 || $ruolo_richiesto === -1) && $utente_logged && !$ruolo_ok) { echo '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3" disabled style="background: #e2e8f0; color: #475569; border: 1px solid #cbd5e1;">🔒 '.htmlspecialchars($etichette_riservato[$ruolo_richiesto] ?? 'Riservato').'</button>'; } 
                elseif ($is_waitlist) { echo '<button type="button" class="btn btn-warning btn-sm fw-bold text-dark py-1 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modPrenota'.$t['id'].'">Lista d\'Attesa</button>'; } 
                else { echo '<button type="button" class="btn btn-primary btn-sm fw-bold py-1 px-4 shadow-sm" style="background-color: '.$col_primaria.'; border: none;" data-bs-toggle="modal" data-bs-target="#modPrenota'.$t['id'].'">Prenota Ora</button>'; }
                
                echo '</div></div></div>'; 
            }
            echo '</div></div></div>'; 
        }
        echo '</div>'; 
    }
}

function printModalPrenotazione($t, $col_primaria, $utente_logged, $val_nome, $val_cognome, $val_email, $val_matricola, $chiedi_matricola, $conn, $p_id) {
    $read_nome = ($utente_logged && !empty($val_nome)) ? 'readonly' : '';
    $read_cognome = ($utente_logged && !empty($val_cognome)) ? 'readonly' : '';
    $read_email = ($utente_logged && !empty($val_email)) ? 'readonly' : '';
    $read_matricola = ($utente_logged && !empty($val_matricola)) ? 'readonly' : '';

    $occ = getPostiOccupati($conn, $t['id']);
    $disponibili = $t['max_posti'] - $occ;
    $soldout = ($disponibili <= 0);
    $is_waitlist = ($soldout && isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa'] == 1);
    ?>
    <div class="modal fade" id="modPrenota<?php echo $t['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content shadow-lg border-0" style="border-radius: 12px;">
                <form method="POST" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="turno_id" value="<?php echo $t['id']; ?>">
                    <div class="modal-header py-2 bg-light border-bottom-0">
                        <h6 class="modal-title fw-bold text-dark"><i class="fa fa-ticket-alt me-1" style="color:<?php echo $col_primaria; ?>;"></i> Prenotazione: <?php echo htmlspecialchars($t['evento_titolo']); ?></h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-start">
                        <div class="alert alert-light border shadow-sm mb-3">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-dark">📅 <?php echo date('d/m/Y', strtotime($t['data_turno'])); ?></span>
                                <span class="badge bg-secondary">🕒 <?php echo substr($t['orario_inizio'], 0, 5); ?> - <?php echo substr($t['orario_fine'], 0, 5); ?></span>
                            </div>
                            <?php if(!empty($t['evento_luogo'])): ?><small class="text-muted fw-bold d-block"><i class="fa fa-map-marker-alt text-danger me-1"></i> <?php echo htmlspecialchars($t['evento_luogo']); ?></small><?php endif; ?>
                        </div>
                        
                        <?php if (isset($t['abilita_multi_posto']) && $t['abilita_multi_posto'] == 1): ?>
                            <div class="mb-3 p-2 bg-light rounded border border-primary">
                                <label class="form-label small fw-bold text-primary mb-1"><i class="fa fa-users me-1"></i> Posti da Prenotare <span class="text-danger">*</span></label>
                                <input type="number" name="num_posti" class="form-control form-control-sm fw-bold text-primary" value="1" min="1" max="<?php echo max(1, $disponibili); ?>" required>
                            </div>
                        <?php endif; ?>

                        <!-- DATI ANAGRAFICI A DUE A DUE -->
                        <div class="row g-3 mb-2">
                            <div class="col-md-6"><label class="form-label small fw-bold">Nome <span class="text-danger">*</span></label><input type="text" name="nome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_nome); ?>" required <?php echo $read_nome; ?>></div>
                            <div class="col-md-6"><label class="form-label small fw-bold">Cognome <span class="text-danger">*</span></label><input type="text" name="cognome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_cognome); ?>" required <?php echo $read_cognome; ?>></div>
                            
                            <?php if ($chiedi_matricola == 1): ?>
                                <div class="col-md-6"><label class="form-label small fw-bold">Matricola <span class="text-muted fw-normal">(Opzionale)</span></label><input type="text" name="matricola" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_matricola); ?>" <?php echo $read_matricola; ?>></div>
                                <div class="col-md-6"><label class="form-label small fw-bold">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_email); ?>" required <?php echo $read_email; ?>></div>
                            <?php else: ?>
                                <div class="col-md-12"><label class="form-label small fw-bold">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control form-control-sm" value="<?php echo htmlspecialchars($val_email); ?>" required <?php echo $read_email; ?>></div>
                            <?php endif; ?>
                        </div>

                        <!-- CAMPI CUSTOM A DUE A DUE -->
                        <?php 
                            $res_cf = $conn->query("SELECT * FROM campi_form WHERE (pagina_id = $p_id AND (evento_id IS NULL OR evento_id = 0)) OR evento_id = {$t['evento_id']} ORDER BY ordine ASC, id ASC");
                            if ($res_cf && $res_cf->num_rows > 0):
                        ?>
                            <div class="border-top pt-2 mt-2">
                                <div class="fw-bold small text-primary mb-2"><i class="fa fa-list-check me-1"></i> Informazioni Aggiuntive:</div>
                                <div class="row g-3">
                                <?php while ($cf = $res_cf->fetch_assoc()): ?>
                                    <?php 
                                        $req_attr = $cf['obbligatorio'] ? 'required' : ''; $asterisk = $cf['obbligatorio'] ? ' <span class="text-danger">*</span>' : '';
                                        $input_name = 'custom_' . htmlspecialchars($cf['nome_campo']); $type = $cf['tipo_campo'];
                                        $opts = !empty($cf['opzioni_select']) ? array_map('trim', explode(',', $cf['opzioni_select'])) : [];
                                    ?>
                                    <div class="col-md-6 mb-1">
                                        <label class="form-label small fw-bold mb-1"><?php echo htmlspecialchars($cf['etichetta']) . $asterisk; ?></label>
                                        <?php if ($type === 'file'): ?>
                                            <input type="file" name="<?php echo $input_name; ?>[]" class="form-control form-control-sm" multiple <?php echo $req_attr; ?>>
                                        <?php elseif ($type === 'select'): ?>
                                            <select name="<?php echo $input_name; ?>" class="form-select form-select-sm" <?php echo $req_attr; ?>>
                                                <option value="">-- Seleziona --</option>
                                                <?php foreach ($opts as $opt): ?><option value="<?php echo htmlspecialchars($opt); ?>"><?php echo htmlspecialchars($opt); ?></option><?php endforeach; ?>
                                            </select>
                                        <?php elseif ($type === 'radio'): ?>
                                            <div class="d-flex flex-wrap gap-2 mt-1">
                                                <?php foreach ($opts as $idx => $opt): ?>
                                                    <div class="form-check form-check-inline m-0 me-2">
                                                        <input class="form-check-input" type="radio" name="<?php echo $input_name; ?>" id="<?php echo $input_name . '_' . $idx; ?>" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $req_attr; ?>>
                                                        <label class="form-check-label small" for="<?php echo $input_name . '_' . $idx; ?>"><?php echo htmlspecialchars($opt); ?></label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($type === 'checkbox'): ?>
                                            <div class="form-check mt-1">
                                                <input class="form-check-input" type="checkbox" name="<?php echo $input_name; ?>" id="<?php echo $input_name; ?>" value="Accettato / Sì" <?php echo $req_attr; ?>>
                                                <label class="form-check-label small" for="<?php echo $input_name; ?>"><?php echo htmlspecialchars($cf['etichetta']); ?></label>
                                            </div>
                                        <?php elseif ($type === 'textarea'): ?>
                                            <textarea name="<?php echo $input_name; ?>" class="form-control form-control-sm" rows="2" <?php echo $req_attr; ?>></textarea>
                                        <?php elseif ($type === 'date'): ?>
                                            <input type="date" name="<?php echo $input_name; ?>" class="form-control form-control-sm" <?php echo $req_attr; ?>>
                                        <?php elseif ($type === 'number'): ?>
                                            <input type="number" name="<?php echo $input_name; ?>" class="form-control form-control-sm" <?php echo $req_attr; ?>>
                                        <?php else: ?>
                                            <input type="text" name="<?php echo $input_name; ?>" class="form-control form-control-sm" <?php echo $req_attr; ?>>
                                        <?php endif; ?>
                                    </div>
                                <?php endwhile; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- CHECKBOX PRIVACY OBBLIGATORIO -->
                        <div class="form-check mt-3 mb-1 p-3 bg-light rounded border border-secondary shadow-sm">
                            <input class="form-check-input border-secondary" type="checkbox" name="accetta_privacy" id="privacyCheck_<?php echo $t['id']; ?>" required>
                            <label class="form-check-label text-dark" for="privacyCheck_<?php echo $t['id']; ?>" style="font-size: 0.85rem; line-height: 1.4;">
                                Ho letto e accetto l'<a href="privacy.php" target="_blank" class="fw-bold" style="color: #B80000; text-decoration: underline;">Informativa sulla Privacy</a> e acconsento al trattamento dei dati personali.
                            </label>
                        </div>
                        
                    </div>
                    <div class="modal-footer py-2 bg-light border-top-0">
                        <?php if($is_waitlist): ?>
                            <button type="submit" name="invia_prenotazione" class="btn btn-warning btn-sm fw-bold w-100 text-dark border-0">Aggiungimi in Lista d'Attesa</button>
                        <?php else: ?>
                            <button type="submit" name="invia_prenotazione" class="btn btn-primary btn-sm fw-bold w-100 shadow-sm" style="background-color: <?php echo $col_primaria; ?>; border: none;">Conferma e Prenota</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function getPulsanteAzione($t, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli) {
    $ruolo_richiesto = (int)($t['ruolo_accesso_id'] ?? 0);
    $sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
    $is_admin = ($utente_ruolo_id === 1 || in_array('1', $sec_roles));
    
    if ($ruolo_richiesto === 0) { $ruolo_ok = true; } 
    elseif ($ruolo_richiesto === -1) { $ruolo_ok = $utente_logged; } 
    else { $ruolo_ok = ($utente_logged && ($utente_ruolo_id === $ruolo_richiesto || in_array((string)$ruolo_richiesto, $sec_roles) || $is_admin)); }

    if ((int)$t['richiede_prenotazione'] == 0) {
        return '<span class="badge bg-success py-2 px-3 shadow-sm fs-6"><i class="fa fa-unlock me-1"></i> Libero</span>';
    }

    $occ = getPostiOccupati($conn, $t['id']);
    $disponibili = $t['max_posti'] - $occ;
    $soldout = ($disponibili <= 0);
    $is_waitlist = ($soldout && isset($t['abilita_lista_attesa']) && $t['abilita_lista_attesa'] == 1);
    
    $now = date('Y-m-d H:i:s');
    $datetime_fine = $t['data_turno'] . ' ' . (!empty($t['orario_fine']) ? substr($t['orario_fine'], 0, 8) : '23:59:59');
    
    if ($now > $datetime_fine) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled><i class="fa fa-flag-checkered me-1"></i> Evento Concluso</button>';
    }

    if (!empty($t['data_apertura']) && $now < $t['data_apertura']) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled>Apertura: '.date('d/m H:i', strtotime($t['data_apertura'])).'</button>';
    } elseif (!empty($t['data_chiusura']) && $now > $t['data_chiusura']) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3 shadow-sm" disabled>Prenotazioni Chiuse</button>';
    }

    if ($soldout && !$is_waitlist) {
        return '<button class="btn btn-danger btn-sm fw-bold py-1 px-3 shadow-sm" disabled>Sold Out</button>';
    } elseif (($ruolo_richiesto > 0 || $ruolo_richiesto === -1) && !$utente_logged) {
        return '<a href="saml_login.php" class="btn btn-primary btn-sm fw-bold py-1 px-3 shadow-sm" style="background-color: '.$col_primaria.'; border: none;"><i class="fa fa-key me-1"></i> Accedi</a>';
    } elseif (($ruolo_richiesto > 0 || $ruolo_richiesto === -1) && $utente_logged && !$ruolo_ok) {
        return '<button class="btn btn-secondary btn-sm fw-bold py-1 px-3" disabled>🔒 Riservato</button>';
    } elseif ($is_waitlist) {
        return '<button type="button" class="btn btn-warning btn-sm fw-bold text-dark py-1 px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modPrenota'.$t['id'].'">Lista d\'Attesa</button>';
    } else {
        return '<button type="button" class="btn btn-primary btn-sm fw-bold py-1 px-4 shadow-sm" style="background-color: '.$col_primaria.'; border: none;" data-bs-toggle="modal" data-bs-target="#modPrenota'.$t['id'].'">Prenota Ora</button>';
    }
}

// HTML HEADER
require_once 'header.php'; 
?>
<style>
    /* FIX STICKY: Rimuove l'overflow nascosto che blocca lo scrolling della sidebar nel framework AGID */
    html, body { overflow-x: visible !important; overflow-y: visible !important;}
    .it-header-wrapper { overflow-x: clip !important; } /* Limitiamo l'overflow-x nascosto solo all'header */
    main, .container-fluid { overflow: visible !important; }
    
    .sidebar-sticky-fix {
        position: -webkit-sticky !important;
        position: sticky !important;
        top: 110px !important; /* Calcolato per non sovrapporsi con l'header fisso AGID */
        z-index: 1020;
    }
</style>

<!-- BANNER MESSAGGI E TASTO CALENDARIO RAPIDO (Per tutti tranne Calendar Layout) -->
<?php if ($layout_template !== 'calendar'): ?>
<div class="container mt-3" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
    <?php echo $banner_manutenzione_admin; ?>
    <?php echo $messaggio_prenotazione; ?>
        
</div>
<?php endif; ?>

<!-- ======================================================= -->
<!-- LAYOUT 1: CALENDARIO INTERATTIVO A TUTTO SCHERMO -->
<!-- ======================================================= -->
<?php if ($layout_template === 'calendar'): ?>
    <div class="container mb-5 mt-4" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        <?php echo $banner_manutenzione_admin; ?>
        <?php echo $messaggio_prenotazione; ?>
        
        <div class="card shadow-lg border-0 rounded-4 overflow-hidden mt-3">
            <div class="card-header text-white p-4" style="background-color: <?php echo $col_primaria; ?>;">
                <h2 class="fw-bold m-0"><i class="fa fa-calendar-days me-2"></i> <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'Calendario Eventi'); ?></h2>
                <p class="m-0 mt-1 opacity-75">Clicca su un evento nel calendario per visualizzare i dettagli e prenotarti.</p>
            </div>
            <div class="card-body p-4 bg-white">
                <div id="fullCalendarDiv"></div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('fullCalendarDiv');
        var calendar = new FullCalendar.Calendar(calendarEl, {
          initialView: 'dayGridMonth', locale: 'it',
          headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,listWeek' },
          events: <?php echo json_encode($json_events_calendar); ?>,
          eventClick: function(info) {
              info.jsEvent.preventDefault();
              var myModal = new bootstrap.Modal(document.getElementById('modPrenota' + info.event.extendedProps.turno_id));
              myModal.show();
          },
          eventContent: function(arg) {
            let loc = arg.event.extendedProps.luogo ? `<br><small>📍 ${arg.event.extendedProps.luogo}</small>` : '';
            return { html: `<div class="p-1" style="white-space:normal; font-size:0.8rem; font-weight:bold; overflow:hidden;">${arg.event.title}${loc}</div>` };
          }
        });
        calendar.render();
      });
    </script>

<!-- ======================================================= -->
<!-- LAYOUT 2: ELENCO AVANZATO CON RICERCA -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'advanced_list'): ?>
    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        
        <!-- HERO / TESTATA EVENTO -->
        <div class="card shadow-sm border-0 mb-4 p-4 text-center" style="background-color: #fdfbfb; border-bottom: 5px solid <?php echo $col_primaria; ?> !important; border-radius: 12px;">
            <h1 class="fw-black display-5 m-0 mb-2" style="color: <?php echo $col_primaria; ?>; font-weight: 900; letter-spacing: -1px;">
                <i class="fa fa-calendar-check me-2"></i> <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'EVENTI'); ?>
            </h1>
            <div class="row justify-content-center border-top border-bottom py-3 my-3">
                <div class="col-md-3 border-end">
                    <span class="text-muted small fw-bold text-uppercase d-block">Da</span>
                    <strong class="fs-5"><?php echo count($all_turni_flat) > 0 ? date('d/m/Y', strtotime($all_turni_flat[0]['data_turno'])) : '-'; ?></strong>
                </div>
                <div class="col-md-3 border-end">
                    <span class="text-muted small fw-bold text-uppercase d-block">A</span>
                    <strong class="fs-5"><?php echo count($all_turni_flat) > 0 ? date('d/m/Y', strtotime(end($all_turni_flat)['data_turno'])) : '-'; ?></strong>
                </div>
                <div class="col-md-3">
                    <span class="text-muted small fw-bold text-uppercase d-block">Accesso</span>
                    <strong class="fs-5 text-success">Prenotazione Libera</strong>
                </div>
            </div>
            <p class="text-secondary mb-0 mx-auto" style="max-width: 800px; line-height: 1.6;">
                <?php echo !empty($page_cfg['hero_descrizione']) ? $page_cfg['hero_descrizione'] : 'Scopri il programma completo ed iscriviti alle attività di tuo interesse.'; ?>
            </p>
        </div>

        <div class="row g-4 align-items-start">
            <!-- SIDEBAR RICERCA E DOCUMENTI (LEFT) -->
            <div class="col-lg-3">
                <div class="card shadow-sm border-0 rounded-3 sidebar-sticky-fix">
                    <div class="card-header text-white fw-bold py-3" style="background-color: #1e293b;">
                        <i class="fa fa-search me-1"></i> Ricerca
                    </div>
                    <div class="card-body p-4 bg-white">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-font me-1"></i> Ricerca testo</label>
                            <input type="text" id="advSearchText" class="form-control form-control-sm" placeholder="Cerca...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-folder-open me-1"></i> Sezione / Categoria</label>
                            <select id="advSearchCat" class="form-select form-select-sm">
                                <option value="">Tutte le sezioni</option>
                                <?php foreach(array_unique($categorie_nomi) as $cat_n): ?>
                                    <?php if(!empty($cat_n)): ?><option value="<?php echo htmlspecialchars(strtolower($cat_n)); ?>"><?php echo htmlspecialchars($cat_n); ?></option><?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-calendar-day me-1"></i> Da data</label>
                            <input type="date" id="advSearchDateFrom" class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-calendar-day me-1"></i> A data</label>
                            <input type="date" id="advSearchDateTo" class="form-control form-control-sm">
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold text-secondary"><i class="fa fa-users-slash me-1"></i> Mostra Soldout</label>
                            <select id="advSearchSoldout" class="form-select form-select-sm">
                                <option value="1">Includi soldout (Tutti)</option>
                                <option value="0">Nascondi soldout (Solo posti liberi)</option>
                            </select>
                        </div>
                        
                        <!-- DOCUMENTI SIDEBAR SPOSTATI IN BASSO -->
                        <?php if (!empty($page_cfg['allegati_sidebar'])): ?>
                            <div class="d-flex flex-column gap-2 mt-3 pt-3 border-top">
                                <?php 
                                    $allegati_sb = explode(',', $page_cfg['allegati_sidebar']);
                                    foreach($allegati_sb as $asb): 
                                        $asb = trim($asb);
                                        if(empty($asb)) continue;
                                ?>
                                    <a href="<?php echo htmlspecialchars($asb); ?>" target="_blank" class="btn btn-outline-danger fw-bold shadow-sm py-2">
                                        <i class="fa fa-file-pdf me-1"></i> Scarica Guida
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RISULTATI LISTA (RIGHT) -->
            <div class="col-lg-9">
                <div id="advEventsContainer" class="d-flex flex-column gap-3">
                    <?php if (empty($all_turni_flat)): ?>
                        <div class="alert alert-light text-center border p-5 shadow-sm">Nessun evento in programma.</div>
                    <?php else: ?>
                        <?php foreach($all_turni_flat as $t_flat): ?>
                            <?php 
                                $occ = getPostiOccupati($conn, $t_flat['id']);
                                $disponibili = max(0, $t_flat['max_posti'] - $occ);
                                $is_soldout_flag = ($disponibili <= 0) ? '1' : '0';
                                
                                $data_text = strtolower($t_flat['evento_titolo'] . ' ' . $t_flat['evento_luogo'] . ' ' . $t_flat['categoria']);
                                $data_cat  = strtolower($t_flat['categoria']);
                                $data_date = $t_flat['data_turno'];
                            ?>
                            <div class="card shadow-sm border-0 adv-event-item" style="border-radius: 12px; overflow: hidden;" data-text="<?php echo htmlspecialchars($data_text); ?>" data-cat="<?php echo htmlspecialchars($data_cat); ?>" data-date="<?php echo $data_date; ?>" data-soldout="<?php echo $is_soldout_flag; ?>">
                                <div class="row g-0">
                                    <div class="col-md-3 d-flex flex-column align-items-center justify-content-center text-white p-3" style="background-color: <?php echo $col_primaria; ?>; min-height: 140px;">
                                        <?php if (!empty($t_flat['evento_locandina']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $t_flat['evento_locandina'])): ?>
                                            <img src="<?php echo htmlspecialchars($t_flat['evento_locandina']); ?>" class="img-fluid rounded shadow-sm mb-2" style="max-height: 80px;" alt="Logo Evento">
                                        <?php else: ?>
                                            <i class="fa fa-tag fs-2 mb-2 opacity-75"></i>
                                            <h6 class="fw-bold m-0 text-center text-uppercase lh-base" style="letter-spacing: 1px;">
                                                <?php echo htmlspecialchars($t_flat['categoria']); ?>
                                            </h6>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-9 p-4 d-flex flex-column justify-content-between bg-white">
                                        <div>
                                            <div class="small fw-bold mb-1" style="color: #64748b;"><i class="fa fa-folder-open me-1"></i> <?php echo htmlspecialchars($t_flat['categoria']); ?></div>
                                            <h4 class="fw-bold text-dark mb-2" style="color: <?php echo $col_primaria; ?> !important;"><?php echo htmlspecialchars($t_flat['evento_titolo']); ?></h4>
                                            
                                            <!-- --- AGGIORNAMENTO UI: BOX LUOGO INGRANDITO (LISTA AVANZATA) --- -->
                                            <?php if(!empty($t_flat['evento_luogo'])): ?>
                                            <div class="bg-light p-2 rounded mb-3 text-dark border-start border-3 border-danger shadow-sm" style="font-size: 1rem;">
                                                <i class="fa fa-map-marker-alt text-danger me-1"></i> <strong class="text-primary">Luogo:</strong> <?php echo htmlspecialchars($t_flat['evento_luogo']); ?>
                                            </div>
                                            <?php endif; ?>

                                            <div class="d-flex flex-wrap gap-3 mb-3 text-secondary small fw-semibold">
                                                <span><i class="fa fa-calendar-alt text-danger me-1"></i> <?php echo date('d/m/Y', strtotime($t_flat['data_turno'])); ?></span>
                                                <span><i class="fa fa-clock text-primary me-1"></i> <?php echo substr($t_flat['orario_inizio'],0,5); ?> - <?php echo substr($t_flat['orario_fine'],0,5); ?></span>
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center mt-auto border-top pt-3">
                                            <span class="badge bg-light text-dark border fs-6 px-3 py-2">
                                                <i class="fa fa-users text-secondary me-1"></i> Posti occupati: <strong><?php echo $occ; ?>/<?php echo $t_flat['max_posti']; ?></strong>
                                            </span>
                                            <div><?php echo getPulsanteAzione($t_flat, $col_primaria, $utente_logged, $utente_ruolo_id, $conn, $nomi_ruoli); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Script Ricerca Avanzata -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('advSearchText');
            const catSelect = document.getElementById('advSearchCat');
            const dateFrom = document.getElementById('advSearchDateFrom');
            const dateTo = document.getElementById('advSearchDateTo');
            const soldoutSelect = document.getElementById('advSearchSoldout');
            
            function filterEvents() {
                const textVal = searchInput.value.toLowerCase();
                const catVal = catSelect.value.toLowerCase();
                const fromVal = dateFrom.value;
                const toVal = dateTo.value;
                const soldoutVal = soldoutSelect.value;
                
                document.querySelectorAll('.adv-event-item').forEach(el => {
                    let show = true;
                    if (textVal && !el.dataset.text.includes(textVal)) show = false;
                    if (catVal && el.dataset.cat !== catVal) show = false;
                    if (fromVal && el.dataset.date < fromVal) show = false;
                    if (toVal && el.dataset.date > toVal) show = false;
                    if (soldoutVal === '0' && el.dataset.soldout === '1') show = false;
                    
                    if(show) { el.classList.remove('d-none'); } else { el.classList.add('d-none'); }
                });
            }

            if(searchInput) {
                searchInput.addEventListener('input', filterEvents);
                catSelect.addEventListener('change', filterEvents);
                dateFrom.addEventListener('change', filterEvents);
                dateTo.addEventListener('change', filterEvents);
                soldoutSelect.addEventListener('change', filterEvents);
            }
        });
    </script>


<!-- ======================================================= -->
<!-- LAYOUT 5: TIMELINE VERTICALE -->
<!-- ======================================================= -->
<?php elseif ($layout_template === 'timeline'): ?>
    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        
        <!-- Hero Testata Timeline -->
        <div class="text-center mb-5 mt-4">
            <h1 class="fw-black display-5 m-0 my-2" style="color: <?php echo $col_primaria; ?>; font-weight: 900; letter-spacing: -1px;">
                <?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'PROGRAMMA EVENTI'); ?>
            </h1>
            <p class="text-secondary fs-5 mx-auto" style="max-width: 700px;">
                <?php echo !empty($page_cfg['hero_descrizione']) ? $page_cfg['hero_descrizione'] : 'Segui il programma cronologico delle attività.'; ?>
            </p>
        </div>

        <div class="timeline-container mx-auto" style="max-width: 900px; position: relative; padding-left: 3rem; border-left: 4px solid <?php echo $col_primaria; ?>;">
            <?php if (empty($eventi_per_data)): ?>
                <div class="alert alert-light text-center border p-4 shadow-sm">Nessun evento in programma.</div>
            <?php else: ?>
                <?php foreach ($eventi_per_data as $data_giorno => $lista_ev_giorno): ?>
                    <div class="timeline-group mb-5 position-relative">
                        <!-- Nodo Data -->
                        <div class="timeline-badge shadow-sm" style="position: absolute; left: -4.3rem; top: 0; width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; background-color: <?php echo $col_primaria; ?>; border: 4px solid #fff; z-index: 2;">
                            <i class="fa fa-calendar-day fs-5"></i>
                        </div>
                        
                        <h3 class="fw-bold mb-4 ms-2 text-dark pt-2" style="color: <?php echo $col_primaria; ?> !important;">
                            <?php echo formattaDataItaliano($data_giorno); ?>
                        </h3>
                        
                        <div class="row g-4 ms-1">
                            <?php foreach ($lista_ev_giorno as $ev): ?>
                                <div class="col-12 position-relative">
                                    <!-- Linea orizzontale di collegamento -->
                                    <div style="position: absolute; left: -2rem; top: 2rem; width: 2rem; height: 3px; background-color: <?php echo $col_primaria; ?>; opacity: 0.3; z-index: 1;"></div>
                                    <?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>


<!-- ======================================================= -->
<!-- LAYOUT 3 e 4: GRID O LIST SEMPLICE (I Vecchi Layout) -->
<!-- ======================================================= -->
<?php else: ?>
    <div class="container mb-5" style="max-width: <?php echo htmlspecialchars($page_cfg['larghezza_contenitore'] ?? '85%'); ?>;">
        
        <!-- RIGA 1: TESTO BENVENUTO E BANNER -->
        <div class="row align-items-center mb-4 g-4">
            <div class="col-lg-7">
                <div class="card shadow-sm border-0 p-4" style="border-left: 5px solid <?php echo $col_primaria; ?> !important; background: #ffffff; border-radius: 12px;">
                    <div class="text-dark" style="font-size: 0.95rem; line-height: 1.6;">
                        <?php if (!empty($page_cfg['hero_descrizione'])): echo $page_cfg['hero_descrizione']; else: ?><strong style="color: #000;">Benvenuto/a:</strong> scopri il programma ed iscriviti alle attività di tuo interesse.<br><br><strong style="color: <?php echo $col_primaria; ?>;">I posti sono limitati!</strong><?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm border-0 text-center p-4 w-100 d-flex flex-column justify-content-center align-items-center position-relative overflow-hidden" style="border: 2px solid <?php echo $col_primaria; ?> !important; border-radius: 14px; background: #fdfbfb;">
                    <div>
                        <?php if (!empty($page_cfg['sidebar_intervallo_date'])): ?><div class="d-inline-block badge bg-warning text-dark fw-bold px-3 py-1 fs-6 rounded-pill mb-2 border shadow-sm">📝 <?php echo htmlspecialchars($page_cfg['sidebar_intervallo_date']); ?></div><?php endif; ?>
                        <h1 class="fw-bold display-5 m-0 my-1" style="color: <?php echo $col_primaria; ?>; font-weight: 900; letter-spacing: -1px;"><?php echo htmlspecialchars(!empty($page_cfg['titolo']) ? $page_cfg['titolo'] : 'EVENTI'); ?></h1>
                        <h2 class="fw-bold fs-3 text-danger mb-0" style="color: #B30000 !important; letter-spacing: 2px;"><?php echo htmlspecialchars(!empty($page_cfg['sottotitolo']) ? $page_cfg['sottotitolo'] : 'DiBEST'); ?></h2>
                    </div>
                    <div class="mt-auto pt-3 w-100 text-center" style="color: <?php echo $col_primaria; ?>;">
                        <?php if (!empty($page_cfg['hero_banner_path'])): ?><img src="uploads/<?php echo htmlspecialchars(basename($page_cfg['hero_banner_path'])); ?>" class="img-fluid mt-auto" style="max-height: 100px; object-fit: contain;" alt="Banner Custom"><?php else: ?><svg viewBox="0 0 500 80" class="w-100 mt-auto" style="max-height: 60px;" fill="currentColor"><path d="M10,80 L35,40 L60,80 Z M30,50 L40,80 Z"></path><circle cx="48" cy="42" r="3"></circle><path d="M70,80 C70,60 85,50 100,50 C115,50 120,60 120,80 Z"></path><path d="M130,80 C130,55 145,40 160,40 C175,40 190,55 190,80 Z"></path><circle cx="210" cy="50" r="4"></circle><path d="M210,55 L210,75 M203,62 L217,62 M205,80 L210,75 L215,80"></path><circle cx="225" cy="45" r="4"></circle><path d="M225,50 L225,72 M218,55 L232,55 M220,80 L225,72 L230,80"></path><circle cx="240" cy="48" r="4"></circle><path d="M240,53 L240,75 M233,60 L247,60 M235,80 L240,75 L245,80"></path><circle cx="255" cy="42" r="4"></circle><path d="M255,47 L255,70 M248,52 L262,52 M250,80 L255,70 L260,80"></path><path d="M320,80 L320,50 L360,50 L360,80 Z M330,80 L330,60 L350,60 L350,80 Z M340,40 L320,50 L360,50 Z"></path></svg><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGA 2: EVENTO IN EVIDENZA -->
        <?php if ($evento_evidenza): ?>
            <?php $data_head_ev = !empty($evento_evidenza['turni'][0]['data_turno']) ? $evento_evidenza['turni'][0]['data_turno'] : ''; ?>
            
            <div class="mb-5 shadow-sm rounded-4" style="border: 3px solid <?php echo $col_primaria; ?>; background-color: #fff; overflow: hidden;">
                
                <div class="text-white px-3 py-2 d-flex align-items-center flex-wrap gap-3" style="background-color: <?php echo $col_primaria; ?>;">
                    <span class="badge bg-warning text-dark fw-bold px-3 py-2 text-uppercase d-flex align-items-center gap-2 shadow-sm" style="letter-spacing: 1px; font-size: 0.9rem;">
                        <i class="fa fa-star text-dark fs-6"></i> IN EVIDENZA
                    </span>
                    
                    <?php if ($data_head_ev): ?>
                        <span class="fs-4 d-flex align-items-center gap-2 ms-md-2 date-white-force">
                            <i class="fa fa-calendar-alt fs-5"></i> <?php echo formattaDataItaliano($data_head_ev); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div class="p-2 p-md-3 bg-white box-evidenza">
                    <style>
                        .box-evidenza .card { box-shadow: none !important; margin-bottom: 0 !important; border: none !important; }
                        .date-white-force, .date-white-force * { color: #ffffff !important; font-weight: 700 !important; }
                    </style>
                    <?php renderCardUniversal($evento_evidenza, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                </div>
                
            </div>
        <?php endif; ?>

        <?php if (!empty($page_cfg['box_info_html']) || !empty($page_cfg['allegati_box_info'])): ?>
            <div class="card shadow-sm border-0 p-4 mb-4" style="border-left: 5px solid #B30000 !important; background: #ffffff; border-radius: 8px;">
                <?php if (!empty($page_cfg['box_info_html'])): ?>
                    <div class="fs-6 text-dark" style="line-height: 1.7;"><?php echo $page_cfg['box_info_html']; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($page_cfg['allegati_box_info'])): ?>
                    <div class="mt-3 pt-3 border-top d-flex flex-wrap gap-2">
                        <?php 
                            $allegati = explode(',', $page_cfg['allegati_box_info']);
                            foreach($allegati as $all): 
                                $all = trim($all);
                                if(empty($all)) continue;
                        ?>
                            <a href="<?php echo htmlspecialchars($all); ?>" target="_blank" class="btn btn-outline-danger btn-sm fw-bold shadow-sm">
                                <i class="fa fa-file-pdf me-1"></i> Guida / Allegato (PDF)
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- CONTROLLO SIDEBAR PER GRID E LIST -->
        <?php if (!empty($page_cfg['mostra_sidebar']) && $page_cfg['mostra_sidebar'] == 1): ?>
            <div class="row g-4 mt-1 align-items-start">
                <div class="col-lg-3">
                    <div class="card shadow-sm border-0 p-4 text-center sidebar-sticky-fix" style="border-top: 4px solid <?php echo $col_primaria; ?> !important; border-radius: 8px; background: #ffffff;">
                        <h4 class="fw-bold mb-2" style="color: <?php echo $col_primaria; ?>;"><?php echo htmlspecialchars(!empty($page_cfg['sidebar_titolo']) ? $page_cfg['sidebar_titolo'] : 'Scegli il tuo evento'); ?></h4>
                        <?php if (!empty($page_cfg['sidebar_intervallo_date'])): ?>
                            <div class="badge bg-danger p-2 fs-6 mb-3">📅 <?php echo htmlspecialchars($page_cfg['sidebar_intervallo_date']); ?></div>
                        <?php endif; ?>
                        
                        <div class="text-secondary small text-start mt-2 mb-4" style="line-height: 1.6;"><?php echo !empty($page_cfg['sidebar_testo']) ? $page_cfg['sidebar_testo'] : 'Naviga il calendario qui a fianco per scoprire tutte le iniziative previste.'; ?></div>
                        
                        <!-- DOCUMENTI SIDEBAR SPOSTATI IN BASSO - GRID/LIST -->
                        <?php if (!empty($page_cfg['allegati_sidebar'])): ?>
                            <div class="d-flex flex-column gap-2 mt-auto border-top pt-3">
                                <?php 
                                    $allegati_sb = explode(',', $page_cfg['allegati_sidebar']);
                                    foreach($allegati_sb as $asb): 
                                        $asb = trim($asb);
                                        if(empty($asb)) continue;
                                ?>
                                    <a href="<?php echo htmlspecialchars($asb); ?>" target="_blank" class="btn btn-outline-danger fw-bold shadow-sm py-2">
                                        <i class="fa fa-file-pdf me-1"></i> Scarica Guida
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-lg-9">
                    <?php if ($layout_template == 'list'): ?>
                        <?php foreach ($eventi_per_data as $data_giorno => $lista_ev_giorno): ?>
                            <h3 class="fw-bold mb-3 fs-3 text-dark border-bottom pb-2"><?php echo formattaDataItaliano($data_giorno); ?></h3>
                            <div class="row g-4 mb-5">
                                <?php $dinamic_col = (count($lista_ev_giorno) == 1) ? 'col-12' : $col_class; ?>
                                <?php foreach ($lista_ev_giorno as $ev): ?>
                                    <div class="<?php echo $dinamic_col; ?>">
                                        <?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- GRIGLIA -->
                        <div class="row g-4 mb-4 align-items-start">
                            <?php foreach ($sezioni_superiori as $nome_sezione => $lista_ev_sup): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="p-3 mb-3 rounded shadow-sm text-center text-white text-uppercase fw-bold fs-6" style="background-color: <?php echo $col_primaria; ?>; letter-spacing: 1px;"><?php echo htmlspecialchars($nome_sezione); ?></div>
                                    <div class="row g-4">
                                        <?php foreach ($lista_ev_sup as $ev): ?><div class="col-12"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!empty($eventi_macro)): ?>
                            <div class="p-3 my-4 rounded shadow-sm text-center text-white text-uppercase fw-bold fs-5" style="background-color: <?php echo $col_primaria; ?>; letter-spacing: 1px;">MODULI DIDATTICI DELLE MACRO-AREE</div>
                            <div class="row g-4 mb-4">
                                <?php foreach ($eventi_macro as $ev): ?><div class="<?php echo $col_class; ?>"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- SENZA SIDEBAR -->
            <?php if ($layout_template == 'list'): ?>
                <?php foreach ($eventi_per_data as $data_giorno => $lista_ev_giorno): ?>
                    <h3 class="fw-bold mb-3 fs-3 text-dark border-bottom pb-2"><?php echo formattaDataItaliano($data_giorno); ?></h3>
                    <div class="row g-4 mb-5">
                        <?php $dinamic_col = (count($lista_ev_giorno) == 1) ? 'col-12' : $col_class; ?>
                        <?php foreach ($lista_ev_giorno as $ev): ?>
                            <div class="<?php echo $dinamic_col; ?>">
                                <?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- GRIGLIA -->
                <div class="row g-4 mb-4 align-items-start">
                    <?php foreach ($sezioni_superiori as $nome_sezione => $lista_ev_sup): ?>
                        <div class="col-md-6 mb-2">
                            <div class="p-3 mb-3 rounded shadow-sm text-center text-white text-uppercase fw-bold fs-6" style="background-color: <?php echo $col_primaria; ?>; letter-spacing: 1px;"><?php echo htmlspecialchars($nome_sezione); ?></div>
                            <div class="row g-4">
                                <?php foreach ($lista_ev_sup as $ev): ?><div class="col-12"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($eventi_macro)): ?>
                    <div class="p-3 my-4 rounded shadow-sm text-center text-white text-uppercase fw-bold fs-5" style="background-color: <?php echo $col_primaria; ?>; letter-spacing: 1px;">MODULI DIDATTICI DELLE MACRO-AREE</div>
                    <div class="row g-4 mb-4">
                        <?php foreach ($eventi_macro as $ev): ?><div class="<?php echo $col_class; ?>"><?php renderCardUniversal($ev, $col_primaria, $utente_logged, $utente_ruolo_id, $nomi_ruoli, $etichette_riservato, $val_nome, $val_cognome, $val_email, $val_matricola, $conn, $chiedi_matricola); ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach($all_turni_flat as $t_flat): ?>
    <?php printModalPrenotazione($t_flat, $col_primaria, $utente_logged, $val_nome, $val_cognome, $val_email, $val_matricola, $chiedi_matricola, $conn, $p_id); ?>
<?php endforeach; ?>

<script>
    if (window.history.replaceState) {
        const url = new URL(window.location);
        if (url.searchParams.has('status')) { url.search = ''; window.history.replaceState({path:url.href}, '', url.href); }
    }
</script>
<?php require_once 'footer.php'; ?>
