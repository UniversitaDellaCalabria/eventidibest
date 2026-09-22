<?php
// iscritti.php - Gestione Iscritti, Check-in, Email e Statistiche

$is_archivio = (isset($_GET['archivio']) && $_GET['archivio'] == 1) || (isset($_POST['archivio']) && $_POST['archivio'] == 1) ? 1 : 0;

// ==============================================================================
// 1. MOTORE AJAX EMAIL MASSIVE (Deve stare in CIMA per non caricare la grafica HTML)
// ==============================================================================
if (isset($_POST['ajax_action'])) {
    require_once '../config.php';
    require_once '../functions.php';

    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // CSRF: double-submit cookie (indipendente dalla sessione SimpleSAML/PHPSESSID)
    $csrf_cookie = $_COOKIE['_ev_csrf'] ?? '';
    $csrf_post   = $_POST['csrf_token'] ?? '';
    if (empty($csrf_cookie) || empty($csrf_post) || !hash_equals($csrf_cookie, $csrf_post)) {
        echo json_encode(['status' => 'error', 'msg' => 'Token CSRF non valido.']); exit;
    }

    // Ruolo: inizializza la sessione corretta (SimpleSAML o nativa) tramite sync_sso_user
    sync_sso_user($conn);
    $role_ajax_id   = (int)($_SESSION['utente_ruolo_id'] ?? 5);
    $sec_roles_ajax = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
    if ($role_ajax_id !== 1 && $role_ajax_id !== 2 && !in_array('1', $sec_roles_ajax) && !in_array('2', $sec_roles_ajax)) {
        echo json_encode(['status' => 'error', 'msg' => 'Accesso negato.']); exit;
    }

    if ($action === 'start') {
        $p_id_ajax = (int)$_POST['p_id'];
        $turno_id_ajax = (int)($_POST['turno_id'] ?? 0);
        $oggetto = trim($_POST['oggetto'] ?? '');
        $messaggio = trim($_POST['messaggio'] ?? '');

        $destinatari = get_destinatari_email_massiva($conn, $p_id_ajax, $turno_id_ajax);

        $_SESSION['mass_mail_queue'] = [
            'destinatari' => $destinatari, 'totale' => count($destinatari), 'inviate' => 0, 'oggetto' => $oggetto, 'messaggio' => $messaggio
        ];

        echo json_encode(['status' => 'ok', 'total' => count($destinatari)]); exit;
    }

    if ($action === 'process') {
        if (!isset($_SESSION['mass_mail_queue'])) { echo json_encode(['status' => 'error', 'msg' => 'Sessione scaduta o inesistente.']); exit; }

        $coda = &$_SESSION['mass_mail_queue'];
        $limite_batch = 10; 
        $processate_ora = 0;

        while ($processate_ora < $limite_batch && $coda['inviate'] < $coda['totale']) {
            $idx = $coda['inviate'];
            $utente = $coda['destinatari'][$idx];
            inviaNotificaEmail($utente['email'], $coda['oggetto'], $coda['messaggio'], $conn);
            $coda['inviate']++; $processate_ora++;
        }

        $completato = ($coda['inviate'] >= $coda['totale']);
        $inviate_tot = $coda['inviate'];
        $totale = $coda['totale'];

        if ($completato) { unset($_SESSION['mass_mail_queue']); }
        echo json_encode(['status' => 'ok', 'sent' => $inviate_tot, 'total' => $totale, 'done' => $completato]); exit;
    }
}

// 2. CARICAMENTO NORMALE DELLA PAGINA ADMIN
require_once 'admin_header.php';

// Cookie CSRF per gli endpoint AJAX (double-submit pattern, indipendente dalla sessione)
if (empty($_COOKIE['_ev_csrf'])) {
    $_ev_csrf_val = bin2hex(random_bytes(16));
    setcookie('_ev_csrf', $_ev_csrf_val, 0, '/', '', !empty($_SERVER['HTTPS']), false);
    $_COOKIE['_ev_csrf'] = $_ev_csrf_val;
} else {
    $_ev_csrf_val = $_COOKIE['_ev_csrf'];
}

if (!$can_manage_iscritti) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato.</div>";
    require_once 'admin_footer.php'; exit;
}

function admin_redirect($url) { echo "<script>window.location.replace('$url');</script>"; exit; }

$filtro_turno = isset($_GET['f_turno']) ? (int)$_GET['f_turno'] : 0;
$_stati_consentiti = ['confermata', 'in_attesa', 'da_approvare', 'annullata', 'rifiutata', 'scaduta'];
$filtro_stato = (isset($_GET['f_stato']) && in_array($_GET['f_stato'], $_stati_consentiti, true)) ? $_GET['f_stato'] : '';
$filtro_cerca = trim($_GET['f_cerca'] ?? '');
$filtro_data_da   = (isset($_GET['f_data_da'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['f_data_da']))   ? $_GET['f_data_da']   : '';
$filtro_data_fine = (isset($_GET['f_data_fine']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['f_data_fine'])) ? $_GET['f_data_fine'] : '';
$url_suffix = $is_archivio ? "&archivio=1" : "";
$url_suffix .= !empty($filtro_data_da)   ? "&f_data_da="   . urlencode($filtro_data_da)   : "";
$url_suffix .= !empty($filtro_data_fine) ? "&f_data_fine=" . urlencode($filtro_data_fine) : "";

// ==============================================================================
// BLOCCO AZIONI BACKEND (Eseguite solo se NON archiviato)
// ==============================================================================
if (!$is_archivio) {
    if (isset($_POST['toggle_presenza'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['toggle_presenza']; $val = (int)$_POST['val'];
        $conn->query("UPDATE prenotazioni SET presente = $val WHERE id = $pr_id");
        if ($val === 1) invia_email_attestato_se_concluso($conn, $pr_id);
        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Modifica Presenza Check-in", ["ID Prenotazione" => $pr_id]);
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['approva_pren'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['approva_pren'];
        $p_data = get_prenotazione_con_turno_evento($conn, $pr_id);
        if ($p_data) {
            $conn->query("UPDATE prenotazioni SET stato = 'confermata' WHERE id = $pr_id");
            $data_formatted = date('d/m/Y', strtotime($p_data['data_turno']));
            $ora_formatted = substr($p_data['orario_inizio'], 0, 5) . ' - ' . substr($p_data['orario_fine'], 0, 5);
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_dir = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
            $link_ricevuta_url = $proto . $domain . $base_dir . "/stampa_ricevuta.php?code=" . urlencode($p_data['codice_prenotazione']);
            $btn_ricevuta_html = "<p style='margin-top:15px;'><a href='$link_ricevuta_url' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica Ricevuta PDF</a></p>";
            
            $obj_tpl = "Prenotazione Approvata: " . $p_data['evento_titolo'];
            $body_tpl = "<p>La tua richiesta per l'evento <strong>{$p_data['evento_titolo']}</strong> è stata <strong>APPROVATA</strong>!</p>$btn_ricevuta_html";
            inviaNotificaEmail($p_data['email'], $obj_tpl, $body_tpl, $conn);
        }
        flash_set("✅ Prenotazione approvata!");
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['rifiuta_pren'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['rifiuta_pren'];
        $p_data = get_prenotazione_con_turno_evento($conn, $pr_id);
        if ($p_data) {
            $conn->query("UPDATE prenotazioni SET stato = 'rifiutata' WHERE id = $pr_id");
            $oggetto = "Aggiornamento Prenotazione: " . $p_data['evento_titolo'];
            $corpo = "<p>Siamo spiacenti di informarti che la tua richiesta per l'evento <strong>{$p_data['evento_titolo']}</strong> non è stata accolta.</p>";
            inviaNotificaEmail($p_data['email'], $oggetto, $corpo, $conn);
        }
        flash_set("❌ Prenotazione rifiutata.", 'danger');
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['annulla_pren'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['annulla_pren'];
        $p_data = get_prenotazione_con_turno_evento($conn, $pr_id);
        if ($p_data) {
            $upd_ok = $conn->query("UPDATE prenotazioni SET stato = 'annullata' WHERE id = $pr_id");
            if ($upd_ok && $conn->affected_rows > 0) {
                if (!empty($p_data['email'])) { inviaNotificaEmail($p_data['email'], "Prenotazione Annullata: " . $p_data['evento_titolo'], "La tua prenotazione è stata annullata dall'amministrazione.", $conn); }
                if ($p_data['stato'] === 'confermata' || $p_data['stato'] === 'richiesta_conferma') { promuovi_lista_attesa($conn, $p_data['turno_id']); }
                flash_set("🚫 Prenotazione annullata!");
                admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=annullata$url_suffix");
            } else {
                error_log("[iscritti] UPDATE annulla_pren fallito pr_id=$pr_id errno=" . $conn->errno . " err=" . $conn->error);
                flash_set("⚠️ Errore DB nell'annullamento (codice: " . $conn->errno . " — " . htmlspecialchars($conn->error) . "). Segnalare all'amministratore.", 'danger');
                admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
            }
        } else {
            flash_set("⚠️ Prenotazione non trovata (id=$pr_id).", 'danger');
            admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
        }
    }

    if (isset($_POST['del_pren'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_del_id = (int)$_POST['del_pren'];
        $p_data = get_prenotazione_con_turno_evento($conn, $pr_del_id);
        if ($p_data) {
            $conn->query("DELETE FROM prenotazioni WHERE id = $pr_del_id");
            if (!empty($p_data['email'])) { inviaNotificaEmail($p_data['email'], "Cancellazione Prenotazione", "La tua prenotazione per <strong>{$p_data['evento_titolo']}</strong> è stata cancellata.", $conn); }
            if ($p_data['stato'] === 'confermata' || $p_data['stato'] === 'richiesta_conferma') { promuovi_lista_attesa($conn, $p_data['turno_id']); }
        }
        flash_set("Prenotazione eliminata!");
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['invia_messaggio_singolo'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['prenotazione_id'];
        $email_dest = trim($_POST['email_destinatario']);
        $ev_titolo = trim($_POST['evento_titolo']);
        $messaggio_html = trim($_POST['corpo_messaggio']);
        $admin_id = $_SESSION['utente_id'] ?? 0;

        if ($pr_id > 0 && !empty($messaggio_html)) {
            $stmt_msg = $conn->prepare("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio) VALUES (?, 'admin', ?, ?)");
            $stmt_msg->bind_param("iis", $pr_id, $admin_id, $messaggio_html);
            $stmt_msg->execute();
            if (!empty($email_dest)) {
                $nome_operatore = 'Segreteria DiBEST';
                $stmt_op = $conn->prepare("SELECT nome, cognome FROM utenti WHERE id = ? LIMIT 1");
                $stmt_op->bind_param("i", $admin_id);
                $stmt_op->execute();
                $res_op = $stmt_op->get_result();
                if ($res_op && $op = $res_op->fetch_assoc()) {
                    $nome_operatore = trim($op['nome'] . ' ' . $op['cognome']);
                }
                $url_area  = "https://dibest2.unical.it/eventi/area_personale.php";
                $oggetto   = "Nuovo messaggio da " . $nome_operatore . " – " . $ev_titolo . " [" . date('d/m H:i') . "]";
                $body_mail = "
                    <p>Hai ricevuto un nuovo messaggio da <strong>" . htmlspecialchars($nome_operatore) . "</strong>
                    riguardante l'evento <strong>" . htmlspecialchars($ev_titolo) . "</strong>:</p>
                    <div style='background:#f8fafc; padding:15px; border-left:4px solid #B80000; margin:15px 0; font-style:italic;'>
                        $messaggio_html
                    </div>
                    <p>Accedi alla tua Area Personale per leggere il messaggio completo e rispondere:</p>
                    <p>
                        <a href='$url_area' style='background-color:#B80000; color:#ffffff; padding:12px 25px;
                           text-decoration:none; border-radius:6px; display:inline-block;
                           font-weight:bold; font-family:sans-serif;'>
                            Vai all'Area Personale per rispondere
                        </a>
                    </p>
                    <p style='color:#6c757d; font-size:0.9em;'>Cordiali saluti,<br>" . htmlspecialchars($nome_operatore) . "<br>Segreteria DiBEST</p>";
                inviaNotificaEmail($email_dest, $oggetto, $body_mail, $conn);
            }
            flash_set("✅ Messaggio inviato.");
        }
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['add_prenotazione_manuale'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $turno_id = (int)$_POST['turno_id'];
        $nome = trim($_POST['nome'] ?? '');
        $cognome = trim($_POST['cognome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $matricola = trim($_POST['matricola'] ?? '');
        $num_posti = isset($_POST['num_posti']) ? max(1, (int)$_POST['num_posti']) : 1;

        $t_info = get_turno_admin($conn, $turno_id);
        if ($t_info) {
            $codice_p = strtoupper(substr($t_info['slug'] ?: 'EV', 0, 2)) . '-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
            $stmt_man = $conn->prepare("INSERT INTO prenotazioni (turno_id, codice_prenotazione, stato, num_posti, nome, cognome, email, matricola) VALUES (?, ?, 'confermata', ?, ?, ?, ?, ?)");
            $stmt_man->bind_param("isisss" . "s", $turno_id, $codice_p, $num_posti, $nome, $cognome, $email, $matricola);
            if ($stmt_man->execute()) {
                flash_set("✅ Prenotazione manuale inserita! Codice: <strong>$codice_p</strong>");
                if (!empty($email)) {
                    $body_conf = "<p>Gentile <strong>" . htmlspecialchars($nome . ' ' . $cognome) . "</strong>,</p>"
                        . "<p>La tua prenotazione per l'evento <strong>" . htmlspecialchars($t_info['evento_titolo']) . "</strong> è stata inserita dalla segreteria.</p>"
                        . "<p><strong>Codice prenotazione:</strong> <span style='font-family:monospace;font-size:1.2em;color:#B80000;'>$codice_p</span></p>"
                        . "<p>Conserva questo codice: ti servirà per il check-in il giorno dell'evento.</p>"
                        . "<p>Cordiali saluti,<br>Segreteria DiBEST</p>";
                    inviaNotificaEmail($email, "Conferma Prenotazione: " . $t_info['evento_titolo'], $body_conf, $conn);
                }
            }
        }
        admin_redirect("iscritti.php?p_id=$filtro_p$url_suffix");
    }

    if (isset($_POST['edit_prenotazione'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['prenotazione_id'];
        $nuovo_turno_id = (int)$_POST['nuovo_turno_id'];
        $nome = trim($_POST['nome'] ?? '');
        $cognome = trim($_POST['cognome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $matricola = trim($_POST['matricola'] ?? '');

        $custom_data = [];
        foreach ($_POST as $k => $v) { if (strpos($k, 'custom_') === 0) { $custom_data[str_replace('custom_', '', $k)] = is_array($v) ? implode(', ', $v) : trim($v); } }
        $json_custom_val = !empty($custom_data) ? json_encode($custom_data, JSON_UNESCAPED_UNICODE) : null;

        $stmt_ep = $conn->prepare("UPDATE prenotazioni SET turno_id=?, nome=?, cognome=?, email=?, matricola=?, dati_custom_json=? WHERE id=?");
        $stmt_ep->bind_param("isssssi", $nuovo_turno_id, $nome, $cognome, $email, $matricola, $json_custom_val, $pr_id);
        $stmt_ep->execute();
        flash_set("Dati aggiornati!");
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }
} // Fine blocco if (!$is_archivio)

// 6. ESPORTAZIONI XLS/CSV (Sempre consentite)
if (isset($_POST['export_xls']) || isset($_POST['export_csv'])) {
    $p_export = (int)($_POST['p_id'] ?? $filtro_p);
    $f_turno_exp = (int)($_POST['f_turno_export'] ?? 0);
    $_f_stato_raw = $_POST['f_stato_export'] ?? '';
    $f_stato_exp = in_array($_f_stato_raw, $_stati_consentiti, true) ? $_f_stato_raw : '';
    
    $cond_turno_exp = $f_turno_exp > 0 ? " AND t.id = $f_turno_exp" : "";
    $cond_stato_exp = !empty($f_stato_exp) ? " AND IFNULL(pr.stato, 'confermata') = '$f_stato_exp'" : "";
    
    $custom_cols = get_campi_custom_export($conn, $p_export);

    $sql_export = "SELECT pr.codice_prenotazione, pr.presente, IFNULL(pr.stato, 'confermata') as stato, COALESCE(pr.num_posti, 1) as num_posti, pr.nome, pr.cognome, COALESCE(NULLIF(pr.matricola, ''), u.matricola_studente, u.matricola_dipendente, u.matricola, '') as matricola_effettiva, pr.email, e.titolo as evento, t.data_turno, t.orario_inizio, pr.dati_custom_json, pr.data_prenotazione 
                FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id LEFT JOIN utenti u ON pr.utente_id = u.id
                WHERE e.pagina_id = $p_export $cond_turno_exp $cond_stato_exp ORDER BY t.data_turno ASC, pr.data_prenotazione DESC";
    $res_export = $conn->query($sql_export);

    ob_end_clean(); 
    if (isset($_POST['export_xls'])) {
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=iscritti_pagina_{$p_export}.xls");
        header("Pragma: no-cache"); header("Expires: 0");
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body><table border="1">';
        echo '<tr><th>Codice</th><th>Stato</th><th>Presenza</th><th>Posti</th><th>Nome</th><th>Cognome</th><th>Matricola</th><th>Email</th><th>Evento</th><th>Data</th><th>Ora</th>';
        foreach ($custom_cols as $key => $label) echo '<th>' . htmlspecialchars($label) . '</th>';
        echo '<th>Data Registrazione</th></tr>';
        while($row = $res_export->fetch_assoc()) { 
            $json = json_decode($row['dati_custom_json'] ?? '', true) ?: [];
            echo '<tr><td>'.htmlspecialchars($row['codice_prenotazione']).'</td><td>'.htmlspecialchars($row['stato']).'</td><td>'.($row['presente'] == 1 ? 'SI' : 'NO').'</td><td>'.htmlspecialchars($row['num_posti']).'</td><td>'.htmlspecialchars($row['nome']).'</td><td>'.htmlspecialchars($row['cognome']).'</td><td>'.htmlspecialchars($row['matricola_effettiva']).'</td><td>'.htmlspecialchars($row['email']).'</td><td>'.htmlspecialchars($row['evento']).'</td><td>'.htmlspecialchars($row['data_turno']).'</td><td>'.htmlspecialchars($row['orario_inizio']).'</td>';
            foreach ($custom_cols as $key => $label) {
                $val_c = $json[$key] ?? '';
                if ($val_c === '') { foreach ($json as $jk => $jv) { if (strtolower($jk) === strtolower($key) || strtolower($jk) === strtolower(str_replace(' ', '_', $label))) { $val_c = $jv; break; } } }
                echo '<td>' . htmlspecialchars($val_c) . '</td>';
            }
            echo '<td>' . htmlspecialchars($row['data_prenotazione']) . '</td></tr>';
        }
        echo '</table></body></html>'; exit;
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=iscritti_pagina_' . $p_export . '.csv');
        $output = fopen('php://output', 'w');
        $headers = ['Codice', 'Stato', 'Presenza', 'Posti', 'Nome', 'Cognome', 'Matricola', 'Email', 'Evento', 'Data', 'Ora'];
        foreach ($custom_cols as $key => $label) $headers[] = $label;
        $headers[] = 'Data Registrazione';
        fputcsv($output, $headers);
        while($row = $res_export->fetch_assoc()) { 
            $json = json_decode($row['dati_custom_json'] ?? '', true) ?: [];
            $line = [$row['codice_prenotazione'], $row['stato'], ($row['presente'] == 1 ? 'SI' : 'NO'), $row['num_posti'], $row['nome'], $row['cognome'], $row['matricola_effettiva'], $row['email'], $row['evento'], $row['data_turno'], $row['orario_inizio']];
            foreach ($custom_cols as $key => $label) {
                $val_c = $json[$key] ?? '';
                if ($val_c === '') { foreach ($json as $jk => $jv) { if (strtolower($jk) === strtolower($key) || strtolower($jk) === strtolower(str_replace(' ', '_', $label))) { $val_c = $jv; break; } } }
                $line[] = $val_c;
            }
            $line[] = $row['data_prenotazione'];
            fputcsv($output, $line); 
        }
        fclose($output); exit;
    }
}

// ==============================================================================
// PREPARAZIONE DATI FRONT-END
// ==============================================================================

$tutti_gli_eventi = get_eventi_con_turni_admin($conn, $filtro_p, $is_archivio, $sql_filtro_eventi_rbac);

$prenotazioni = [];
$per_page = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$cond_turno_pr = $filtro_turno > 0 ? " AND t.id = $filtro_turno" : "";
$cond_stato_pr = !empty($filtro_stato) ? " AND IFNULL(pr.stato, 'confermata') = '$filtro_stato'" : "";
$cond_cerca_pr = '';
if (!empty($filtro_cerca)) {
    $cerca_esc = $conn->real_escape_string($filtro_cerca);
    $cond_cerca_pr = " AND (pr.nome LIKE '%$cerca_esc%' OR pr.cognome LIKE '%$cerca_esc%' OR pr.email LIKE '%$cerca_esc%' OR pr.codice_prenotazione LIKE '%$cerca_esc%')";
}
$cond_data_pr = '';
if (!empty($filtro_data_da) && !empty($filtro_data_fine)) {
    $cond_data_pr = " AND t.data_turno BETWEEN '$filtro_data_da' AND '$filtro_data_fine'";
} elseif (!empty($filtro_data_da)) {
    $cond_data_pr = " AND t.data_turno >= '$filtro_data_da'";
} elseif (!empty($filtro_data_fine)) {
    $cond_data_pr = " AND t.data_turno <= '$filtro_data_fine'";
}
$where_pr = "WHERE e.pagina_id = $filtro_p AND e.archiviato = $is_archivio $cond_turno_pr $cond_stato_pr $cond_cerca_pr $cond_data_pr $sql_filtro_eventi_rbac";
$res_count = $conn->query("SELECT COUNT(*) as tot FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id LEFT JOIN utenti u ON pr.utente_id = u.id $where_pr");
$total_count = ($res_count && $r_cnt = $res_count->fetch_assoc()) ? (int)$r_cnt['tot'] : 0;
$total_pages = max(1, (int)ceil($total_count / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$sql_pr = "SELECT pr.*, COALESCE(NULLIF(pr.matricola, ''), u.matricola_studente, u.matricola_dipendente, u.matricola) as matricola_effettiva,
           t.data_turno, t.orario_inizio, t.orario_fine, t.evento_id, e.titolo as evento_titolo, e.luogo as evento_luogo, e.abilita_presenze
           FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id LEFT JOIN utenti u ON pr.utente_id = u.id
           $where_pr ORDER BY pr.data_prenotazione DESC LIMIT $per_page OFFSET $offset";
$res_pr = $conn->query($sql_pr);
if($res_pr) while($r = $res_pr->fetch_assoc()) $prenotazioni[] = $r;

$pr_ids = array_column($prenotazioni, 'id');
$messaggi_per_pr = get_messaggi_per_prenotazioni($conn, $pr_ids);
?>

<!-- FRONT-END DELLA PAGINA -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-dark m-0">
        <?php if ($is_archivio): ?>
            <i class="fa fa-archive text-secondary me-2"></i> Archivio Iscritti
        <?php else: ?>
            <i class="fa fa-users text-primary me-2"></i> Iscritti & Check-in
        <?php endif; ?>
    </h4>
    <?php if ($is_archivio): ?>
        <a href="archivio.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-secondary btn-sm fw-bold shadow-sm"><i class="fa fa-arrow-left me-1"></i> Torna all'Archivio</a>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex flex-column gap-3">
        
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <span class="fw-bold text-primary fs-5">
                Iscritti <?php echo $is_archivio ? 'Archiviati ' : ''; ?>alla Pagina: <?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?>
            </span>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <?php if (!$is_archivio): ?>
                    <a href="../checkin.php" target="_blank" class="btn btn-outline-dark btn-sm fw-bold shadow-sm">
                        <i class="fa fa-qrcode me-1"></i> Apri Scanner QR
                    </a>
                <?php endif; ?>
                <a href="stampa_lista_iscritti.php?p_id=<?php echo $filtro_p; ?>&f_turno=<?php echo $filtro_turno; ?>&f_stato=<?php echo urlencode($filtro_stato); ?>&f_cerca=<?php echo urlencode($filtro_cerca); ?>&f_data_da=<?php echo urlencode($filtro_data_da); ?>&f_data_fine=<?php echo urlencode($filtro_data_fine); ?><?php echo $is_archivio ? '&archivio=1' : ''; ?>" target="_blank" class="btn btn-outline-secondary btn-sm fw-bold shadow-sm">
                    <i class="fa fa-print me-1"></i> Stampa Lista
                </a>
                <?php if (!$is_archivio): ?>
                    <a href="../cron_attestati.php" class="btn btn-success btn-sm fw-bold text-white shadow-sm" data-confirm="Vuoi scansionare tutti gli eventi terminati e inviare le email agli studenti presenti?">
                        <i class="fa fa-graduation-cap me-1"></i> Invia Attestati Ora
                    </a>
                    <button type="button" class="btn btn-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modPrenotazioneManuale">
                        <i class="fa fa-user-plus me-1"></i> + Prenotazione Manuale
                    </button>
                <?php else: ?>
                    <span class="badge bg-secondary p-2 shadow-sm"><i class="fa fa-lock me-1"></i> Sola Lettura</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 bg-light p-2 rounded border">
            <form method="GET" class="d-inline-flex gap-2 m-0 align-items-center flex-wrap" id="formFiltroTurno">
                <i class="fa fa-filter text-secondary"></i>
                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                <?php if ($is_archivio): ?><input type="hidden" name="archivio" value="1"><?php endif; ?>
                
                <select name="f_turno" class="form-select form-select-sm fw-bold border-primary text-primary shadow-sm" onchange="document.getElementById('formFiltroTurno').submit();" style="min-width: 200px;">
                    <option value="0">Tutti gli Eventi / Turni</option>
                    <?php foreach ($tutti_gli_eventi as $ev_m): ?>
                        <optgroup label="<?php echo mb_strimwidth(htmlspecialchars($ev_m['titolo']), 0, 40, '...'); ?>">
                            <?php foreach ($ev_m['turni'] as $t_m): ?>
                                <option value="<?php echo $t_m['id']; ?>" <?php echo $filtro_turno == $t_m['id'] ? 'selected' : ''; ?>>
                                    📅 <?php echo date('d/m/Y', strtotime($t_m['data_turno'])); ?> (Ore <?php echo substr($t_m['orario_inizio'],0,5); ?>)
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>

                <select name="f_stato" class="form-select form-select-sm fw-bold border-info text-dark shadow-sm" onchange="document.getElementById('formFiltroTurno').submit();" style="max-width: 180px;">
                    <option value="">Tutti gli Stati</option>
                    <option value="confermata" <?php echo $filtro_stato === 'confermata' ? 'selected' : ''; ?>>✅ Confermata</option>
                    <option value="in_attesa" <?php echo $filtro_stato === 'in_attesa' ? 'selected' : ''; ?>>🕒 In Attesa</option>
                    <option value="da_approvare" <?php echo $filtro_stato === 'da_approvare' ? 'selected' : ''; ?>>⏳ Da Approvare</option>
                    <option value="annullata" <?php echo $filtro_stato === 'annullata' ? 'selected' : ''; ?>>🚫 Annullata</option>
                    <option value="rifiutata" <?php echo $filtro_stato === 'rifiutata' ? 'selected' : ''; ?>>❌ Rifiutata</option>
                </select>

                <div class="d-flex align-items-center gap-1">
                    <i class="fa fa-calendar-alt text-secondary" title="Range date turno"></i>
                    <input type="date" name="f_data_da" class="form-control form-control-sm" style="max-width:140px;" value="<?php echo htmlspecialchars($filtro_data_da); ?>" title="Data turno dal">
                    <span class="text-muted small">—</span>
                    <input type="date" name="f_data_fine" class="form-control form-control-sm" style="max-width:140px;" value="<?php echo htmlspecialchars($filtro_data_fine); ?>" title="Data turno al">
                    <button type="submit" class="btn btn-outline-primary btn-sm" title="Applica filtro date"><i class="fa fa-calendar-check"></i></button>
                    <?php if (!empty($filtro_data_da) || !empty($filtro_data_fine)): ?>
                        <a href="iscritti.php?p_id=<?php echo $filtro_p; ?>&f_turno=<?php echo $filtro_turno; ?>&f_stato=<?php echo urlencode($filtro_stato); ?>&f_cerca=<?php echo urlencode($filtro_cerca); ?><?php echo $is_archivio ? '&archivio=1' : ''; ?>" class="btn btn-outline-danger btn-sm" title="Cancella filtro date"><i class="fa fa-times"></i></a>
                    <?php endif; ?>
                </div>

                <div class="input-group input-group-sm" style="max-width: 240px;">
                    <input type="text" name="f_cerca" class="form-control form-control-sm" placeholder="Nome, email, codice..." value="<?php echo htmlspecialchars($filtro_cerca); ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="fa fa-search"></i></button>
                    <?php if (!empty($filtro_cerca)): ?>
                        <a href="iscritti.php?p_id=<?php echo $filtro_p; ?>&f_turno=<?php echo $filtro_turno; ?>&f_stato=<?php echo urlencode($filtro_stato); ?><?php echo $url_suffix; ?>" class="btn btn-outline-danger btn-sm" title="Cancella ricerca"><i class="fa fa-times"></i></a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="d-flex gap-2">
                <?php if (!$is_archivio): ?>
                    <button type="button" class="btn btn-warning btn-sm fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#modMailMassiva">
                        <i class="fa fa-paper-plane me-1"></i> Invia Mail agli Iscritti
                    </button>
                <?php endif; ?>

                <form method="POST" class="m-0 d-flex gap-2">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                    <?php if ($is_archivio): ?><input type="hidden" name="archivio" value="1"><?php endif; ?>
                    <input type="hidden" name="f_turno_export" value="<?php echo $filtro_turno; ?>">
                    <input type="hidden" name="f_stato_export" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                    <input type="hidden" name="f_data_da_export" value="<?php echo htmlspecialchars($filtro_data_da); ?>">
                    <input type="hidden" name="f_data_fine_export" value="<?php echo htmlspecialchars($filtro_data_fine); ?>">
                    <button type="submit" name="export_xls" class="btn btn-success btn-sm fw-bold shadow-sm" title="Esporta solo la selezione attuale"><i class="fa fa-file-excel me-1"></i> Excel</button>
                    <button type="submit" name="export_csv" class="btn btn-secondary btn-sm fw-bold shadow-sm" title="Esporta solo la selezione attuale"><i class="fa fa-file-csv me-1"></i> CSV</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card-body p-3">
        <div class="table-responsive">
            <table id="tabellaIscritti" class="table table-hover align-middle w-100">
                <thead class="table-dark">
                    <tr><th>Codice</th><th>Stato</th><th>Presenza</th><th>Partecipante</th><th>Posti</th><th>Matricola</th><th>Email</th><th>Attività / Turno</th><th>Data Reg.</th><th class="text-end">Azione</th></tr>
                </thead>
                <tbody>
                    <?php if(!empty($prenotazioni)): ?>
                        <?php foreach($prenotazioni as $pr): ?>
                            <?php 
                                $json_c = json_decode($pr['dati_custom_json'] ?? '', true) ?: []; 
                                $st_val = $pr['stato'] ?? 'confermata';
                                $ev_chk_attivo = (int)($pr['abilita_presenze'] ?? 1);
                            ?>
                            <tr class="<?php echo ($st_val === 'in_attesa') ? 'table-warning' : (($st_val === 'da_approvare') ? 'table-info' : (($st_val === 'rifiutata' || $st_val === 'annullata') ? 'table-secondary text-muted' : '')); ?>">
                                <td><span class="badge bg-dark fw-bold fs-6"><?php echo $pr['codice_prenotazione']; ?></span></td>
                                
                                <td>
                                    <?php if($st_val === 'in_attesa'): ?>
                                        <span class="badge bg-warning text-dark border border-warning shadow-sm"><i class="fa fa-clock me-1"></i> In Attesa</span>
                                    <?php elseif($st_val === 'da_approvare'): ?>
                                        <span class="badge bg-info text-dark border border-info shadow-sm"><i class="fa fa-hourglass-half me-1"></i> Da Approvare</span>
                                    <?php elseif($st_val === 'annullata'): ?>
                                        <span class="badge bg-dark shadow-sm"><i class="fa fa-ban me-1"></i> Annullata</span>
                                    <?php elseif($st_val === 'rifiutata'): ?>
                                        <span class="badge bg-secondary shadow-sm"><i class="fa fa-times me-1"></i> Rifiutata</span>
                                    <?php else: ?>
                                        <span class="badge bg-success shadow-sm"><i class="fa fa-check me-1"></i> Confermata</span>
                                    <?php endif; ?>
                                </td>

                                <td class="text-center">
                                    <?php if ($is_archivio): ?>
                                        <?php if (($pr['presente'] ?? 0) == 1): ?>
                                            <span class="badge bg-success shadow-sm"><i class="fa fa-check me-1"></i> Presente</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border shadow-sm"><i class="fa fa-minus me-1"></i> Assente</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if($ev_chk_attivo === 1): ?>
                                            <?php if (($pr['presente'] ?? 0) == 1): ?>
                                                <form method="POST" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="toggle_presenza" value="<?php echo $pr['id']; ?>">
                                                    <input type="hidden" name="val" value="0">
                                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                    <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                                    <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                                    <button type="submit" class="badge bg-success border-0 shadow-sm" title="Clicca per rimuovere la presenza" style="cursor:pointer;"><i class="fa fa-check me-1"></i> Presente</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="toggle_presenza" value="<?php echo $pr['id']; ?>">
                                                    <input type="hidden" name="val" value="1">
                                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                    <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                                    <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                                    <button type="submit" class="badge bg-light text-muted border shadow-sm" title="Clicca per segnare come presente" style="cursor:pointer;"><i class="fa fa-minus me-1"></i> Assente</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted" title="Check-in non richiesto per questo evento">- N/A -</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <td><strong><?php echo htmlspecialchars($pr['nome'] . ' ' . $pr['cognome']); ?></strong></td>
                                <td><span class="badge bg-secondary"><?php echo $pr['num_posti'] ?? 1; ?></span></td>
                                <td><span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($pr['matricola_effettiva'] ?: 'N/D'); ?></span></td>
                                <td><div style="word-break: break-all; max-width: 180px; font-size: 0.85rem;"><?php echo htmlspecialchars($pr['email']); ?></div></td>
                                <td><div style="max-width: 250px;"><strong><?php echo htmlspecialchars($pr['evento_titolo']); ?></strong><br><small class="text-secondary">📅 <?php echo date('d/m/Y', strtotime($pr['data_turno'])); ?> - ore <?php echo substr($pr['orario_inizio'],0,5); ?></small></div></td>
                                <td><small><?php echo date('Y/m/d H:i', strtotime($pr['data_prenotazione'])); ?></small></td>
                                
                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-1" style="max-width: 140px; margin-left: auto;">
                                        
                                        <a href="../stampa_ricevuta.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="btn btn-outline-dark btn-sm fw-bold" title="Stampa Ricevuta PDF">
                                            <i class="fa fa-file-pdf"></i>
                                        </a>
                                        
                                        <?php if ($ev_chk_attivo === 1 && (int)($pr['presente'] ?? 0) === 1 && $st_val === 'confermata'): ?>
                                            <a href="../stampa_attestato.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="btn btn-success btn-sm fw-bold shadow-sm" title="Stampa Attestato PDF">
                                                <i class="fa fa-graduation-cap"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if (!$is_archivio): ?>
                                            <?php if($st_val === 'da_approvare'): ?>
                                                <form method="POST" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="approva_pren" value="<?php echo $pr['id']; ?>">
                                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                    <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                                    <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                                    <button type="submit" class="btn btn-success btn-sm fw-bold" data-confirm="Approvare questa prenotazione? Verrà inviata un\'email di conferma all\'utente." title="Approva"><i class="fa fa-check"></i></button>
                                                </form>
                                                <form method="POST" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="rifiuta_pren" value="<?php echo $pr['id']; ?>">
                                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                    <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                                    <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                                    <button type="submit" class="btn btn-warning btn-sm text-dark fw-bold" data-confirm="Rifiutare questa prenotazione? L\'utente riceverà una mail di avviso." title="Rifiuta"><i class="fa fa-times"></i></button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <?php if(!empty($pr['email'])): ?>
                                                <button type="button" class="btn btn-outline-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modChat<?php echo $pr['id']; ?>" title="Chat / Messaggi">
                                                    <i class="fa fa-comments"></i>
                                                </button>
                                            <?php endif; ?>

                                            <button type="button" class="btn btn-outline-info btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modEditPren<?php echo $pr['id']; ?>" title="Dettagli">
                                                <i class="fa fa-search-plus"></i>
                                            </button>
                                            
                                            <form method="POST" class="d-inline">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="annulla_pren" value="<?php echo $pr['id']; ?>">
                                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                                <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                                <button type="submit" class="btn btn-outline-warning btn-sm text-dark fw-bold" data-confirm="Vuoi annullare questa prenotazione? Il posto verrà liberato ma i dati dell\'utente resteranno in tabella." title="Annulla Prenotazione (Mantieni Traccia)"><i class="fa fa-ban"></i></button>
                                            </form>
                                            <form method="POST" class="d-inline">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="del_pren" value="<?php echo $pr['id']; ?>">
                                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                                <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Cancellare definitivamente la prenotazione?" title="Elimina Definitivamente"><i class="fa fa-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <?php if (!$is_archivio && !empty($pr['email'])): ?>
                            <!-- MODALE CHAT / MESSAGGI (Solo Attivi) -->
                            <div class="modal fade" id="modChat<?php echo $pr['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content shadow-lg border-0">
                                        <div class="modal-header py-3 bg-primary text-white">
                                            <h6 class="modal-title fw-bold"><i class="fa fa-comments me-2"></i> Chat / Comunicazioni con: <?php echo htmlspecialchars($pr['nome'] . ' ' . $pr['cognome']); ?></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-0 bg-light">
                                            <div class="p-4" style="max-height: 400px; overflow-y: auto; background: #f8fafc;">
                                                <?php 
                                                $chat_msgs = $messaggi_per_pr[$pr['id']] ?? [];
                                                if(empty($chat_msgs)): 
                                                ?>
                                                    <div class="text-center text-muted my-4 small"><i class="fa fa-comment-slash fs-3 mb-2 opacity-50 d-block"></i>Nessun messaggio.</div>
                                                <?php else: ?>
                                                    <?php foreach($chat_msgs as $msg): ?>
                                                        <?php if($msg['mittente_tipo'] === 'admin'): ?>
                                                            <div class="d-flex justify-content-end mb-3">
                                                                <div style="max-width: 80%;">
                                                                    <div class="small text-muted text-end mb-1" style="font-size: 0.7rem;">Tu (Admin) - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                    <div class="p-2 rounded-3 text-white shadow-sm" style="background-color: #0056b3; border-bottom-right-radius: 0 !important;"><?php echo strip_tags($msg['messaggio'], '<b><strong><i><em><u><br><p><ul><ol><li><span>'); ?></div>
                                                                </div>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="d-flex justify-content-start mb-3">
                                                                <div style="max-width: 80%;">
                                                                    <div class="small text-muted mb-1" style="font-size: 0.7rem;"><?php echo htmlspecialchars($pr['nome']); ?> - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                    <div class="p-2 rounded-3 text-dark shadow-sm bg-white border" style="border-bottom-left-radius: 0 !important;"><?php echo nl2br(htmlspecialchars($msg['messaggio'])); ?></div>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                            <form method="POST" class="border-top p-3 bg-white">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="prenotazione_id" value="<?php echo $pr['id']; ?>">
                                                <input type="hidden" name="email_destinatario" value="<?php echo htmlspecialchars($pr['email']); ?>">
                                                <input type="hidden" name="evento_titolo" value="<?php echo htmlspecialchars($pr['evento_titolo']); ?>">
                                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                <label class="form-label small fw-bold text-primary">Nuovo Messaggio</label>
                                                <textarea name="corpo_messaggio" class="form-control editor-html" rows="3"></textarea>
                                                <div class="text-end mt-3"><button type="submit" name="invia_messaggio_singolo" onclick="tinymce.triggerSave();" class="btn btn-primary fw-bold px-4 shadow-sm"><i class="fa fa-paper-plane me-1"></i> Invia Risposta</button></div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!$is_archivio): ?>
                            <!-- MODALE DETTAGLI PRENOTAZIONE (Solo Attivi) -->
                            <div class="modal fade" id="modEditPren<?php echo $pr['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="prenotazione_id" value="<?php echo $pr['id']; ?>">
                                            <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                            <div class="modal-header py-2 bg-info text-white">
                                                <h6 class="modal-title fw-bold"><i class="fa fa-user-edit me-1"></i> Scheda Dettagli Prenotazione: <?php echo htmlspecialchars($pr['codice_prenotazione']); ?></h6>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body text-start">
                                                <div class="mb-3 p-2 border rounded bg-light border-primary">
                                                    <label class="form-label small fw-bold text-primary"><i class="fa fa-exchange-alt me-1"></i> Sposta al Turno:</label>
                                                    <select name="nuovo_turno_id" class="form-select form-select-sm fw-bold">
                                                        <?php
                                                        foreach ($tutti_gli_eventi as $ev_m) {
                                                            if ($ev_m['id'] == $pr['evento_id']) {
                                                                foreach ($ev_m['turni'] as $t_m) {
                                                                    $sel = ($t_m['id'] == $pr['turno_id']) ? 'selected' : '';
                                                                    $d_t = date('d/m/Y', strtotime($t_m['data_turno']));
                                                                    $o_i = substr($t_m['orario_inizio'],0,5);
                                                                    $o_f = substr($t_m['orario_fine'],0,5);
                                                                    echo "<option value='{$t_m['id']}' $sel>📅 $d_t (Ore $o_i - $o_f)</option>";
                                                                }
                                                            }
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <div class="row g-2 mb-3">
                                                    <div class="col-md-6"><label class="form-label small fw-bold">Nome</label><input type="text" name="nome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pr['nome']); ?>" required></div>
                                                    <div class="col-md-6"><label class="form-label small fw-bold">Cognome</label><input type="text" name="cognome" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pr['cognome']); ?>" required></div>
                                                    <div class="col-md-6"><label class="form-label small fw-bold">Email</label><input type="email" name="email" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pr['email']); ?>" required></div>
                                                    <div class="col-md-6"><label class="form-label small fw-bold">Matricola</label><input type="text" name="matricola" class="form-control form-control-sm" value="<?php echo htmlspecialchars($pr['matricola']); ?>"></div>
                                                </div>
                                            </div>
                                            <div class="modal-footer py-2">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                                                <button type="submit" name="edit_prenotazione" class="btn btn-info btn-sm text-white fw-bold"><i class="fa fa-save me-1"></i> Salva Modifiche Scheda</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-2 px-3 flex-wrap gap-2">
        <small class="text-muted">
            <?php echo number_format($total_count); ?> iscritti &mdash; pagina <?php echo $page; ?> di <?php echo $total_pages; ?>
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $qs_base = http_build_query(array_filter([
                    'p_id'     => $filtro_p,
                    'f_turno'  => $filtro_turno ?: null,
                    'f_stato'  => $filtro_stato ?: null,
                    'archivio' => $is_archivio ?: null,
                ]));
                $prev_page = max(1, $page - 1);
                $next_page = min($total_pages, $page + 1);
                ?>
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo $qs_base; ?>&page=<?php echo $prev_page; ?>">‹</a>
                </li>
                <?php
                $win_start = max(1, $page - 2);
                $win_end   = min($total_pages, $page + 2);
                if ($win_start > 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                for ($i = $win_start; $i <= $win_end; $i++):
                ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo $qs_base; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($win_end < $total_pages) echo '<li class="page-item disabled"><span class="page-link">…</span></li>'; ?>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?<?php echo $qs_base; ?>&page=<?php echo $next_page; ?>">›</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php if (!$is_archivio): ?>
<!-- MODALE INVIO EMAIL MASSIVA AJAX -->
<div class="modal fade" id="modMailMassiva" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-warning">
            <form id="formMailMassiva">
                <?php csrf_field(); ?>
                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                <input type="hidden" name="f_turno_nascosto" value="<?php echo $filtro_turno; ?>">
                
                <div class="modal-header py-2 bg-warning text-dark border-bottom-0">
                    <h6 class="modal-title fw-bold"><i class="fa fa-bullhorn me-2"></i> Invia Comunicazione di Servizio Massiva</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start bg-light">
                    <p class="mb-3 text-secondary">
                        <i class="fa fa-info-circle me-1"></i> Stai per inviare questa comunicazione di servizio a 
                        <strong><?php echo $filtro_turno == 0 ? "TUTTI gli iscritti confermati di questa Area" : "gli iscritti confermati del Turno selezionato"; ?></strong>.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark">Oggetto dell'email <span class="text-danger">*</span></label>
                        <input type="text" name="oggetto_email_massiva" class="form-control" required placeholder="Es. Comunicazione importante sull'evento di oggi">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-dark">Testo del Messaggio <span class="text-danger">*</span></label>
                        <textarea name="corpo_email_massiva" class="form-control editor-html" rows="6"><p>Gentile studente,</p><p>ti informiamo che...</p></textarea>
                    </div>

                    <!-- BARRA DI PROGRESSO -->
                    <div id="progressContainer" class="d-none mt-3">
                        <p class="small fw-bold mb-1 text-primary" id="progressText">Preparazione invio in corso...</p>
                        <div class="progress" style="height: 22px;">
                            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success fw-bold" role="progressbar" style="width: 0%;">0%</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" id="btnInviaMassiva" class="btn btn-warning fw-bold text-dark shadow-sm">
                        <i class="fa fa-paper-plane me-1"></i> INVIA ORA A TUTTI
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODALE INSERIMENTO PRENOTAZIONE MANUALE -->
<div class="modal fade" id="modPrenotazioneManuale" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                <div class="modal-header py-2 bg-primary text-white">
                    <h6 class="modal-title fw-bold"><i class="fa fa-user-plus me-1"></i> Inserisci Prenotazione Manuale</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Seleziona Evento e Turno</label>
                        <select name="turno_id" class="form-select border-primary fw-bold" required>
                            <option value="">-- Seleziona un Turno --</option>
                            <?php foreach ($tutti_gli_eventi as $ev_m): ?>
                                <?php foreach ($ev_m['turni'] as $t_m): ?>
                                    <option value="<?php echo $t_m['id']; ?>">
                                        🎯 <?php echo htmlspecialchars($ev_m['titolo']); ?> — 📅 <?php echo date('d/m/Y', strtotime($t_m['data_turno'])); ?> (Ore <?php echo substr($t_m['orario_inizio'],0,5); ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><label class="form-label small fw-bold">Nome Utente</label><input type="text" name="nome" class="form-control form-control-sm" required placeholder="Es. Mario"></div>
                        <div class="col-md-4"><label class="form-label small fw-bold">Cognome Utente</label><input type="text" name="cognome" class="form-control form-control-sm" required placeholder="Es. Rossi"></div>
                        <div class="col-md-4"><label class="form-label small fw-bold">Numero Posti</label><input type="number" name="num_posti" class="form-control form-control-sm" value="1" min="1" required></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Email Utente</label><input type="email" name="email" class="form-control form-control-sm" required placeholder="mario.rossi@unical.it"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Matricola (Opzionale)</label><input type="text" name="matricola" class="form-control form-control-sm" placeholder="Es. 210000"></div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="add_prenotazione_manuale" class="btn btn-primary btn-sm fw-bold"><i class="fa fa-save me-1"></i> Inserisci e Invia Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    var _csrfToken = '<?php echo htmlspecialchars($_ev_csrf_val, ENT_QUOTES); ?>';
    document.addEventListener('DOMContentLoaded', function() {
        $('#formMailMassiva').on('submit', function(e) {
            e.preventDefault();
            tinymce.triggerSave();

            if(!confirm('Sicuro di voler inviare la comunicazione a questa lista filtrata?')) return;
            
            let btn = $('#btnInviaMassiva');
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin me-1"></i> Preparazione...');
            $('#progressContainer').removeClass('d-none');
            
            let formData = {
                ajax_action: 'start',
                csrf_token: _csrfToken,
                p_id: $('input[name="p_id"]').val(),
                turno_id: $('input[name="f_turno_nascosto"]').val() || 0,
                oggetto: $('input[name="oggetto_email_massiva"]').val(),
                messaggio: $('textarea[name="corpo_email_massiva"]').val()
            };
            
            $.post('iscritti.php', formData, function(res) {
                if(res.status === 'ok') {
                    if(res.total === 0) {
                        alert('Nessun iscritto confermato trovato per questa selezione.');
                        btn.prop('disabled', false).html('<i class="fa fa-paper-plane me-1"></i> INVIA ORA A TUTTI');
                        $('#progressContainer').addClass('d-none');
                        return;
                    }
                    processBatch();
                } else {
                    alert('Errore: ' + (res.msg || 'Inizializzazione fallita'));
                }
            }, 'json').fail(function(){ alert('Errore di rete. Riprova.'); btn.prop('disabled', false); });
        });
        
        function processBatch() {
            $.post('iscritti.php', {ajax_action: 'process', csrf_token: _csrfToken}, function(res) {
                if(res.status === 'ok') {
                    let perc = Math.round((res.sent / res.total) * 100);
                    $('#progressBar').css('width', perc + '%').text(perc + '%');
                    $('#progressText').text('Invio in corso: ' + res.sent + ' / ' + res.total);
                    
                    if(res.done) {
                        $('#btnInviaMassiva').html('<i class="fa fa-check me-1"></i> Inviate!');
                        alert('Completato! ' + res.sent + ' email inviate con successo.');
                        window.location.reload();
                    } else {
                        processBatch();
                    }
                } else {
                    alert('Errore durante l\'invio: ' + (res.msg || 'Sconosciuto'));
                }
            }, 'json').fail(function(){
                setTimeout(processBatch, 2000);
            });
        }
    });
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    $('#tabellaIscritti').DataTable({
        paging:  false,
        info:    false,
        language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/it-IT.json' },
        order: [[8, "desc"]],
        columnDefs: [ { orderable: false, targets: 9 } ]
    });
});
</script>

<?php require_once 'admin_footer.php'; ?>
