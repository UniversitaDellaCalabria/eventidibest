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

// ==============================================================================
// AJAX: ricerca utenti registrati per prenotazione manuale
// ==============================================================================
if (isset($_GET['ajax_cerca_utenti'])) {
    require_once '../config.php';
    require_once '../functions.php';
    header('Content-Type: application/json');
    sync_sso_user($conn);
    $role_id   = (int)($_SESSION['utente_ruolo_id'] ?? 5);
    $sec_roles = isset($_SESSION['utente_ruoli_secondari']) ? explode(',', $_SESSION['utente_ruoli_secondari']) : [];
    if ($role_id !== 1 && $role_id !== 2 && !in_array('1', $sec_roles) && !in_array('2', $sec_roles)) {
        echo json_encode([]); exit;
    }
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $stmt_u = $conn->prepare("SELECT id, nome, cognome, email, COALESCE(NULLIF(matricola_studente,''), NULLIF(matricola_dipendente,''), NULLIF(matricola,''), '') AS matricola FROM utenti WHERE (nome LIKE ? OR cognome LIKE ? OR email LIKE ? OR matricola_studente LIKE ? OR matricola_dipendente LIKE ? OR matricola LIKE ?) ORDER BY cognome, nome LIMIT 20");
    $stmt_u->bind_param("ssssss", $q, $q, $q, $q, $q, $q);
    $stmt_u->execute();
    $res_u = $stmt_u->get_result();
    $out = [];
    while ($row = $res_u->fetch_assoc()) {
        $out[] = ['id' => $row['id'], 'nome' => $row['nome'], 'cognome' => $row['cognome'], 'email' => $row['email'], 'matricola' => $row['matricola']];
    }
    echo json_encode($out); exit;
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
// RBAC: prenotazione/turno dell'area corrente e di un evento visibile al gestore
function turno_isc_autorizzato($conn, int $t_id, int $p_id, string $rbac): bool {
    $r = $conn->query("SELECT 1 FROM turni t JOIN eventi e ON t.evento_id = e.id WHERE t.id = $t_id AND e.pagina_id = $p_id $rbac LIMIT 1");
    return $r && $r->num_rows > 0;
}
function nega_accesso_isc(): void { http_response_code(403); die("Accesso negato."); }

// Email "posto confermato" con ricevuta (approvazione o promozione manuale)
function email_conferma_da_admin($conn, array $p_data, string $motivo): void {
    if (empty($p_data['email'])) return;
    $link = url_base_sito() . "/stampa_ricevuta.php?code=" . urlencode($p_data['codice_prenotazione']);
    $btn  = "<p style='margin-top:15px;'><a href='$link' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica Ricevuta PDF</a></p>";
    $testo = $motivo === 'promossa'
        ? "Si è liberato un posto: la tua prenotazione in lista d'attesa per <strong>" . htmlspecialchars($p_data['evento_titolo']) . "</strong> (" . htmlspecialchars(etichetta_turno($p_data)) . ") è stata <strong>CONFERMATA</strong>."
        : "La tua richiesta per l'evento <strong>" . htmlspecialchars($p_data['evento_titolo']) . "</strong> (" . htmlspecialchars(etichetta_turno($p_data)) . ") è stata <strong>APPROVATA</strong>!";
    $oggetto = ($motivo === 'promossa' ? "Posto Confermato: " : "Prenotazione Approvata: ") . $p_data['evento_titolo'];
    inviaNotificaEmail($p_data['email'], $oggetto, "<p>$testo</p>$btn", $conn, colore_area_turno($conn, $p_data['turno_id']));
}

if (!$is_archivio) {
    // ==========================================================================
    // AZIONI DI MASSA sulle prenotazioni selezionate
    // ==========================================================================
    if (isset($_POST['bulk_azione'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $azione = (string)$_POST['bulk_azione'];
        $ids = isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids']) ? array_values(array_unique(array_map('intval', $_POST['bulk_ids']))) : [];
        $etichette = ['presente' => 'segnate presenti', 'assente' => 'segnate assenti', 'approva' => 'approvate',
                      'promuovi' => 'promosse dalla lista d\'attesa', 'annulla' => 'annullate'];
        if (!isset($etichette[$azione]) || !$ids) {
            flash_set("Seleziona almeno un iscritto e un'azione.", 'warning');
            admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
        }

        $fatte = 0; $saltate = 0; $oltre_capienza = 0; $turni_liberati = [];
        foreach ($ids as $pr_id) {
            if (!pren_autorizzata($conn, $pr_id, $filtro_p, $sql_filtro_eventi_rbac)) { $saltate++; continue; }
            $p_data = get_prenotazione_con_turno_evento($conn, $pr_id);
            $st = $p_data['stato'] ?? '';
            $ok = false;
            switch ($azione) {
                case 'presente':
                    if ($st === 'confermata') {
                        $conn->query("UPDATE prenotazioni SET presente = 1, data_presenza = NOW() WHERE id = $pr_id AND presente = 0");
                        if ($conn->affected_rows > 0) invia_email_attestato_se_concluso($conn, $pr_id);
                        $ok = true;
                    }
                    break;
                case 'assente':
                    $conn->query("UPDATE prenotazioni SET presente = 0 WHERE id = $pr_id");
                    $ok = true;
                    break;
                case 'approva':
                case 'promuovi':
                    $da = $azione === 'approva' ? 'da_approvare' : 'in_attesa';
                    if ($st === $da) {
                        $conn->query("UPDATE prenotazioni SET stato = 'confermata' WHERE id = $pr_id AND stato = '$da'");
                        if ($conn->affected_rows > 0) {
                            decadi_attese_vincolate($conn, $pr_id);
                            email_conferma_da_admin($conn, $p_data, $azione === 'approva' ? 'approvata' : 'promossa');
                            $t_info = get_turno_admin($conn, (int)$p_data['turno_id']);
                            if ($t_info && getPostiOccupati($conn, (int)$p_data['turno_id']) > (int)$t_info['max_posti']) $oltre_capienza++;
                            $ok = true;
                        }
                    }
                    break;
                case 'annulla':
                    if (in_array($st, ['confermata', 'in_attesa', 'da_approvare', 'richiesta_conferma'], true)) {
                        $conn->query("UPDATE prenotazioni SET stato = 'annullata' WHERE id = $pr_id");
                        if (!empty($p_data['email'])) {
                            inviaNotificaEmail($p_data['email'], "Prenotazione Annullata: " . $p_data['evento_titolo'],
                                "La tua prenotazione per <strong>" . htmlspecialchars($p_data['evento_titolo']) . "</strong> è stata annullata dall'amministrazione.",
                                $conn, colore_area_turno($conn, $p_data['turno_id']));
                        }
                        if ($st !== 'in_attesa') $turni_liberati[(int)$p_data['turno_id']] = true;
                        $ok = true;
                    }
                    break;
            }
            $ok ? $fatte++ : $saltate++;
        }
        // Posti liberati dagli annullamenti: una sola promozione per turno, a fine elaborazione
        foreach (array_keys($turni_liberati) as $tid) promuovi_lista_attesa($conn, $tid);

        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Azione di massa iscritti", ["Azione" => $azione, "Eseguite" => $fatte, "Saltate" => $saltate]);
        $msg = "$fatte prenotazioni " . $etichette[$azione] . ".";
        if ($saltate > 0) $msg .= " $saltate saltate (stato non compatibile con l'azione o permessi mancanti).";
        if ($oltre_capienza > 0) $msg .= " Attenzione: $oltre_capienza promozioni superano la capienza del turno.";
        flash_set($msg, $saltate > 0 || $oltre_capienza > 0 ? 'warning' : 'success');
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['toggle_presenza'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['toggle_presenza']; $val = (int)$_POST['val'];
        if (!pren_autorizzata($conn, $pr_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso_isc();
        $conn->query("UPDATE prenotazioni SET presente = $val WHERE id = $pr_id");
        if ($val === 1) invia_email_attestato_se_concluso($conn, $pr_id);
        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Modifica Presenza Check-in", ["ID Prenotazione" => $pr_id]);
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['approva_pren'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['approva_pren'];
        if (!pren_autorizzata($conn, $pr_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso_isc();
        $p_data = get_prenotazione_con_turno_evento($conn, $pr_id);
        if ($p_data) {
            $conn->query("UPDATE prenotazioni SET stato = 'confermata' WHERE id = $pr_id");
            decadi_attese_vincolate($conn, $pr_id);
            $data_formatted = implode(' · ', array_filter([$p_data['nome_turno'] ?? '', !empty($p_data['data_turno']) ? date('d/m/Y', strtotime($p_data['data_turno'])) : '']));
            $ora_formatted = orario_turno($p_data) ?: 'da definire';
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base_dir = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
            $link_ricevuta_url = $proto . $domain . $base_dir . "/stampa_ricevuta.php?code=" . urlencode($p_data['codice_prenotazione']);
            $btn_ricevuta_html = "<p style='margin-top:15px;'><a href='$link_ricevuta_url' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica Ricevuta PDF</a></p>";
            
            $obj_tpl = "Prenotazione Approvata: " . $p_data['evento_titolo'];
            $body_tpl = "<p>La tua richiesta per l'evento <strong>{$p_data['evento_titolo']}</strong> è stata <strong>APPROVATA</strong>!</p>$btn_ricevuta_html";
            inviaNotificaEmail($p_data['email'], $obj_tpl, $body_tpl, $conn, colore_area_turno($conn, $p_data['turno_id']));
        }
        flash_set("✅ Prenotazione approvata!");
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['rifiuta_pren'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['rifiuta_pren'];
        if (!pren_autorizzata($conn, $pr_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso_isc();
        $p_data = get_prenotazione_con_turno_evento($conn, $pr_id);
        if ($p_data) {
            $conn->query("UPDATE prenotazioni SET stato = 'rifiutata' WHERE id = $pr_id");
            $oggetto = "Aggiornamento Prenotazione: " . $p_data['evento_titolo'];
            $corpo = "<p>Siamo spiacenti di informarti che la tua richiesta per l'evento <strong>{$p_data['evento_titolo']}</strong> non è stata accolta.</p>";
            inviaNotificaEmail($p_data['email'], $oggetto, $corpo, $conn, colore_area_turno($conn, $p_data['turno_id']));
            // Il posto tenuto da una richiesta in approvazione si libera: offrilo alla lista d'attesa
            if (in_array($p_data['stato'], ['da_approvare', 'confermata', 'richiesta_conferma'], true)) {
                promuovi_lista_attesa($conn, (int)$p_data['turno_id']);
            }
        }
        flash_set("❌ Prenotazione rifiutata.", 'danger');
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['annulla_pren'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['annulla_pren'];
        if (!pren_autorizzata($conn, $pr_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso_isc();
        $p_data = get_prenotazione_con_turno_evento($conn, $pr_id);
        if ($p_data) {
            $upd_ok = $conn->query("UPDATE prenotazioni SET stato = 'annullata' WHERE id = $pr_id");
            if ($upd_ok && $conn->affected_rows > 0) {
                if (!empty($p_data['email'])) { inviaNotificaEmail($p_data['email'], "Prenotazione Annullata: " . $p_data['evento_titolo'], "La tua prenotazione è stata annullata dall'amministrazione.", $conn, colore_area_turno($conn, $p_data['turno_id'])); }
                if (in_array($p_data['stato'], ['confermata', 'richiesta_conferma', 'da_approvare'], true)) { promuovi_lista_attesa($conn, $p_data['turno_id']); }
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
        if (!pren_autorizzata($conn, $pr_del_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso_isc();
        $p_data = get_prenotazione_con_turno_evento($conn, $pr_del_id);
        if ($p_data) {
            $conn->query("DELETE FROM prenotazioni WHERE id = $pr_del_id");
            if (!empty($p_data['email'])) { inviaNotificaEmail($p_data['email'], "Cancellazione Prenotazione", "La tua prenotazione per <strong>{$p_data['evento_titolo']}</strong> è stata cancellata.", $conn, colore_area_turno($conn, $p_data['turno_id'])); }
            if (in_array($p_data['stato'], ['confermata', 'richiesta_conferma', 'da_approvare'], true)) { promuovi_lista_attesa($conn, $p_data['turno_id']); }
        }
        flash_set("Prenotazione eliminata!");
        admin_redirect("iscritti.php?p_id=$filtro_p&f_turno=$filtro_turno&f_stato=$filtro_stato$url_suffix");
    }

    if (isset($_POST['invia_messaggio_singolo'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['prenotazione_id'];
        if (!pren_autorizzata($conn, $pr_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso_isc();
        $p_msg = get_prenotazione_con_turno_evento($conn, $pr_id);
        $email_dest = (string)($p_msg['email'] ?? ''); // dal DB: il campo del form non è affidabile
        $ev_titolo = (string)($p_msg['evento_titolo'] ?? '');
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
        if (!turno_isc_autorizzato($conn, $turno_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso_isc();
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
                decadi_attese_vincolate($conn, (int)$stmt_man->insert_id);
                flash_set("✅ Prenotazione manuale inserita! Codice: <strong>$codice_p</strong>");
                if (!empty($email)) {
                    $body_conf = "<p>Gentile <strong>" . htmlspecialchars($nome . ' ' . $cognome) . "</strong>,</p>"
                        . "<p>La tua prenotazione per l'evento <strong>" . htmlspecialchars($t_info['evento_titolo']) . "</strong> è stata inserita dalla segreteria.</p>"
                        . "<p><strong>Codice prenotazione:</strong> <span style='font-family:monospace;font-size:1.2em;color:#B80000;'>$codice_p</span></p>"
                        . "<p>Conserva questo codice: ti servirà per il check-in il giorno dell'evento.</p>"
                        . "<p>Cordiali saluti,<br>Segreteria DiBEST</p>";
                    inviaNotificaEmail($email, "Conferma Prenotazione: " . $t_info['evento_titolo'], $body_conf, $conn, colore_area_turno($conn, $turno_id));
                }
            }
        }
        admin_redirect("iscritti.php?p_id=$filtro_p$url_suffix");
    }

    if (isset($_POST['edit_prenotazione'])) {
        csrf_verify($_POST['csrf_token'] ?? '');
        $pr_id = (int)$_POST['prenotazione_id'];
        $nuovo_turno_id = (int)$_POST['nuovo_turno_id'];
        if (!pren_autorizzata($conn, $pr_id, $filtro_p, $sql_filtro_eventi_rbac) || !turno_isc_autorizzato($conn, $nuovo_turno_id, $filtro_p, $sql_filtro_eventi_rbac)) nega_accesso_isc();
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

    $sql_export = "SELECT pr.codice_prenotazione, pr.presente, IFNULL(pr.stato, 'confermata') as stato, COALESCE(pr.num_posti, 1) as num_posti, pr.nome, pr.cognome, COALESCE(NULLIF(pr.matricola, ''), u.matricola_studente, u.matricola_dipendente, u.matricola, '') as matricola_effettiva, pr.email, e.titolo as evento, t.nome_turno, t.data_turno, t.orario_inizio, pr.dati_custom_json, pr.data_prenotazione
                FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id LEFT JOIN utenti u ON pr.utente_id = u.id
                WHERE e.pagina_id = $p_export $cond_turno_exp $cond_stato_exp ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.nome_turno ASC, pr.data_prenotazione DESC";
    $res_export = $conn->query($sql_export);

    ob_end_clean(); 
    if (isset($_POST['export_xls'])) {
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=iscritti_pagina_{$p_export}.xls");
        header("Pragma: no-cache"); header("Expires: 0");
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"></head><body><table border="1">';
        echo '<tr><th>Codice</th><th>Stato</th><th>Presenza</th><th>Posti</th><th>Nome</th><th>Cognome</th><th>Matricola</th><th>Email</th><th>Evento</th><th>Turno</th><th>Data</th><th>Ora</th>';
        foreach ($custom_cols as $key => $label) echo '<th>' . htmlspecialchars($label) . '</th>';
        echo '<th>Data Registrazione</th></tr>';
        while($row = $res_export->fetch_assoc()) { 
            $json = json_decode($row['dati_custom_json'] ?? '', true) ?: [];
            echo '<tr><td>'.htmlspecialchars($row['codice_prenotazione']).'</td><td>'.htmlspecialchars($row['stato']).'</td><td>'.($row['presente'] == 1 ? 'SI' : 'NO').'</td><td>'.htmlspecialchars($row['num_posti']).'</td><td>'.htmlspecialchars($row['nome']).'</td><td>'.htmlspecialchars($row['cognome']).'</td><td>'.htmlspecialchars($row['matricola_effettiva']).'</td><td>'.htmlspecialchars($row['email']).'</td><td>'.htmlspecialchars($row['evento']).'</td><td>'.htmlspecialchars($row['nome_turno'] ?? '').'</td><td>'.htmlspecialchars($row['data_turno'] ?? '').'</td><td>'.htmlspecialchars($row['orario_inizio'] ?? '').'</td>';
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
        $headers = ['Codice', 'Stato', 'Presenza', 'Posti', 'Nome', 'Cognome', 'Matricola', 'Email', 'Evento', 'Turno', 'Data', 'Ora'];
        foreach ($custom_cols as $key => $label) $headers[] = $label;
        $headers[] = 'Data Registrazione';
        fputcsv($output, $headers);
        while($row = $res_export->fetch_assoc()) { 
            $json = json_decode($row['dati_custom_json'] ?? '', true) ?: [];
            $line = [$row['codice_prenotazione'], $row['stato'], ($row['presente'] == 1 ? 'SI' : 'NO'), $row['num_posti'], $row['nome'], $row['cognome'], $row['matricola_effettiva'], $row['email'], $row['evento'], $row['nome_turno'] ?? '', $row['data_turno'] ?? '', $row['orario_inizio'] ?? ''];
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
           t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, t.evento_id, e.titolo as evento_titolo, e.luogo as evento_luogo, e.abilita_presenze
           FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id LEFT JOIN utenti u ON pr.utente_id = u.id
           $where_pr ORDER BY pr.data_prenotazione DESC LIMIT $per_page OFFSET $offset";
$res_pr = $conn->query($sql_pr);
if($res_pr) while($r = $res_pr->fetch_assoc()) $prenotazioni[] = $r;

$pr_ids = array_column($prenotazioni, 'id');
$messaggi_per_pr = get_messaggi_per_prenotazioni($conn, $pr_ids);
?>

<?php
$col_area_i = htmlspecialchars($page_cfg['colore_primario'] ?? '#0056b3');
?>
<style>
.isc-wrap { border-radius:12px; border:1px solid #e2e8f0; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.05); overflow:hidden; }
.isc-filters { padding:14px 16px; border-bottom:1px solid #f1f5f9; background:#fafafa; }
.isc-table thead th { background:#1e293b; color:#cbd5e1; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; border:none; padding:10px 14px; }
.isc-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .12s; }
.isc-table tbody tr:last-child { border-bottom:none; }
.isc-table tbody tr:hover { background:#f8fafc; }
.isc-table td { padding:10px 14px; vertical-align:middle; border:none; }
.avatar-circle { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; color:#fff; flex-shrink:0; }
.stato-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; white-space:nowrap; }
.presenza-btn { border:none; border-radius:20px; padding:3px 10px; font-size:.72rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:4px; transition:all .15s; }
.act-btn { width:30px; height:30px; border-radius:7px; display:inline-flex; align-items:center; justify-content:center; font-size:.78rem; border:1px solid #e2e8f0; background:#f8fafc; color:#475569; text-decoration:none; cursor:pointer; transition:all .12s; }
.act-btn:hover { background:#f1f5f9; color:#0f172a; }
.act-btn.green { border-color:#bbf7d0; background:#f0fdf4; color:#16a34a; }
.act-btn.green:hover { background:#dcfce7; }
.act-btn.blue { border-color:#bfdbfe; background:#eff6ff; color:#1d4ed8; }
.act-btn.blue:hover { background:#dbeafe; }
.act-btn.red { border-color:#fecaca; background:#fff1f2; color:#dc2626; }
.act-btn.red:hover { background:#fee2e2; }
.act-btn.orange { border-color:#fed7aa; background:#fff7ed; color:#c2410c; }
.act-btn.orange:hover { background:#ffedd5; }
.filter-chip { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:20px; border:1px solid #e2e8f0; background:#fff; font-size:.78rem; font-weight:600; color:#475569; cursor:pointer; transition:all .12s; }
.filter-chip:hover { border-color:#94a3b8; color:#0f172a; }
.filter-chip.active { background:<?php echo $col_area_i; ?>18; border-color:<?php echo $col_area_i; ?>; color:<?php echo $col_area_i; ?>; }
</style>

<!-- FRONT-END DELLA PAGINA -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold text-dark mb-0">
            <?php if ($is_archivio): ?>
                <i class="fa fa-archive me-2 text-secondary"></i>Archivio Iscritti
            <?php else: ?>
                <i class="fa fa-users me-2" style="color:<?php echo $col_area_i; ?>"></i>Iscritti & Check-in
            <?php endif; ?>
        </h4>
        <div class="text-muted mt-1" style="font-size:.8rem;"><?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?> &mdash; <?php echo number_format($total_count); ?> iscritti trovati</div>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <?php if ($is_archivio): ?>
            <a href="archivio.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-secondary btn-sm fw-bold" style="border-radius:8px;"><i class="fa fa-arrow-left me-1"></i>Archivio</a>
            <span class="badge p-2" style="background:#f1f5f9;color:#64748b;border-radius:8px;"><i class="fa fa-lock me-1"></i>Sola Lettura</span>
        <?php else: ?>
            <a href="scanner.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-sm fw-bold text-white" style="background:<?php echo $col_area_i; ?>;border-radius:8px;border:none;"><i class="fa fa-qrcode me-1"></i>Scanner</a>
            <a href="../cron_attestati.php" class="btn btn-sm btn-outline-success fw-bold" style="border-radius:8px;" data-confirm="Vuoi scansionare tutti gli eventi terminati e inviare le email agli studenti presenti?"><i class="fa fa-graduation-cap me-1"></i>Attestati</a>
            <button type="button" class="btn btn-sm fw-bold" style="border-radius:8px;background:#f1f5f9;border:1px solid #e2e8f0;color:#334155;" data-bs-toggle="modal" data-bs-target="#modMailMassiva"><i class="fa fa-paper-plane me-1"></i>Mail Massiva</button>
            <button type="button" class="btn btn-sm fw-bold text-white" style="background:<?php echo $col_area_i; ?>;border-radius:8px;border:none;" data-bs-toggle="modal" data-bs-target="#modPrenotazioneManuale"><i class="fa fa-user-plus me-1"></i>+ Manuale</button>
        <?php endif; ?>
        <a href="stampa_lista_iscritti.php?p_id=<?php echo $filtro_p; ?>&f_turno=<?php echo $filtro_turno; ?>&f_stato=<?php echo urlencode($filtro_stato); ?>&f_cerca=<?php echo urlencode($filtro_cerca); ?>&f_data_da=<?php echo urlencode($filtro_data_da); ?>&f_data_fine=<?php echo urlencode($filtro_data_fine); ?><?php echo $is_archivio ? '&archivio=1' : ''; ?>" target="_blank" class="btn btn-sm btn-outline-secondary fw-bold" style="border-radius:8px;"><i class="fa fa-print me-1"></i>Stampa</a>
        <form method="POST" class="d-flex gap-1">
            <?php csrf_field(); ?>
            <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
            <?php if ($is_archivio): ?><input type="hidden" name="archivio" value="1"><?php endif; ?>
            <input type="hidden" name="f_turno_export" value="<?php echo $filtro_turno; ?>">
            <input type="hidden" name="f_stato_export" value="<?php echo htmlspecialchars($filtro_stato); ?>">
            <input type="hidden" name="f_data_da_export" value="<?php echo htmlspecialchars($filtro_data_da); ?>">
            <input type="hidden" name="f_data_fine_export" value="<?php echo htmlspecialchars($filtro_data_fine); ?>">
            <button type="submit" name="export_xls" class="btn btn-sm btn-outline-success fw-bold" style="border-radius:8px;" title="Esporta Excel"><i class="fa fa-file-excel"></i></button>
            <button type="submit" name="export_csv" class="btn btn-sm btn-outline-secondary fw-bold" style="border-radius:8px;" title="Esporta CSV"><i class="fa fa-file-csv"></i></button>
        </form>
    </div>
</div>

<div class="isc-wrap">
    <!-- FILTRI -->
    <div class="isc-filters">
        <form method="GET" id="formFiltroTurno" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
            <?php if ($is_archivio): ?><input type="hidden" name="archivio" value="1"><?php endif; ?>

            <select name="f_turno" class="form-select form-select-sm" style="max-width:220px;border-radius:8px;" onchange="document.getElementById('formFiltroTurno').submit();">
                <option value="0">Tutti gli eventi</option>
                <?php foreach ($tutti_gli_eventi as $ev_m): ?>
                    <optgroup label="<?php echo mb_strimwidth(htmlspecialchars($ev_m['titolo']), 0, 40, '...'); ?>">
                        <?php foreach ($ev_m['turni'] as $t_m): ?>
                            <option value="<?php echo $t_m['id']; ?>" <?php echo $filtro_turno == $t_m['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(etichetta_turno($t_m)); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>

            <select name="f_stato" class="form-select form-select-sm" style="max-width:160px;border-radius:8px;" onchange="document.getElementById('formFiltroTurno').submit();">
                <option value="">Tutti gli stati</option>
                <option value="confermata"   <?php echo $filtro_stato==='confermata'   ? 'selected':'' ?>>Confermata</option>
                <option value="in_attesa"    <?php echo $filtro_stato==='in_attesa'    ? 'selected':'' ?>>In Attesa</option>
                <option value="da_approvare" <?php echo $filtro_stato==='da_approvare' ? 'selected':'' ?>>Da Approvare</option>
                <option value="annullata"    <?php echo $filtro_stato==='annullata'    ? 'selected':'' ?>>Annullata</option>
                <option value="rifiutata"    <?php echo $filtro_stato==='rifiutata'    ? 'selected':'' ?>>Rifiutata</option>
            </select>

            <input type="date" name="f_data_da" class="form-control form-control-sm" style="max-width:135px;border-radius:8px;" value="<?php echo htmlspecialchars($filtro_data_da); ?>" title="Data dal">
            <span class="text-muted">—</span>
            <input type="date" name="f_data_fine" class="form-control form-control-sm" style="max-width:135px;border-radius:8px;" value="<?php echo htmlspecialchars($filtro_data_fine); ?>" title="Data al">

            <div class="input-group input-group-sm" style="max-width:220px;">
                <input type="text" name="f_cerca" class="form-control" style="border-radius:8px 0 0 8px;" placeholder="Nome, email, codice..." value="<?php echo htmlspecialchars($filtro_cerca); ?>">
                <button type="submit" class="btn btn-outline-secondary" style="border-radius:0 8px 8px 0;"><i class="fa fa-search"></i></button>
            </div>

            <button type="submit" class="btn btn-sm fw-bold text-white" style="background:<?php echo $col_area_i; ?>;border-radius:8px;border:none;"><i class="fa fa-filter me-1"></i>Filtra</button>
            <?php if (!empty($filtro_cerca) || !empty($filtro_stato) || $filtro_turno > 0 || !empty($filtro_data_da) || !empty($filtro_data_fine)): ?>
                <a href="iscritti.php?p_id=<?php echo $filtro_p; ?><?php echo $is_archivio ? '&archivio=1' : ''; ?>" class="btn btn-sm btn-outline-danger fw-bold" style="border-radius:8px;" title="Azzera filtri"><i class="fa fa-times me-1"></i>Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (!$is_archivio): ?>
    <!-- AZIONI DI MASSA: compare quando si seleziona almeno un iscritto -->
    <form method="POST" id="bulkForm" class="d-none align-items-center flex-wrap gap-2 px-3 py-2 border-bottom" style="background:#fffbeb;position:sticky;top:0;z-index:5;">
        <?php csrf_field(); ?>
        <span class="fw-bold" style="font-size:.85rem;"><i class="fa fa-check-square me-1" aria-hidden="true"></i><span id="bulkCount">0</span> selezionati</span>
        <label for="bulkAzione" class="visually-hidden">Azione da applicare</label>
        <select name="bulk_azione" id="bulkAzione" class="form-select form-select-sm" style="max-width:260px;border-radius:8px;" required>
            <option value="">Scegli un'azione…</option>
            <option value="presente">Segna presenti</option>
            <option value="assente">Segna assenti</option>
            <option value="approva">Approva (richieste da approvare)</option>
            <option value="promuovi">Promuovi dalla lista d'attesa</option>
            <option value="annulla">Annulla prenotazioni</option>
        </select>
        <button type="submit" class="btn btn-sm fw-bold text-white" style="background:<?php echo $col_area_i; ?>;border-radius:8px;border:none;">Applica</button>
        <button type="button" id="bulkDeseleziona" class="btn btn-sm btn-outline-secondary fw-bold" style="border-radius:8px;">Deseleziona</button>
        <span class="text-muted small ms-auto">Le azioni si applicano solo agli iscritti con uno stato compatibile.</span>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('bulkForm'), tutti = document.getElementById('selTutti');
        var caselle = function () { return Array.prototype.slice.call(document.querySelectorAll('#tabellaIscritti .sel-pren')); };
        function aggiorna() {
            var n = caselle().filter(function (c) { return c.checked; }).length;
            document.getElementById('bulkCount').textContent = n;
            form.classList.toggle('d-none', n === 0);
            form.classList.toggle('d-flex', n > 0);
            var visibili = caselle().filter(function (c) { return c.offsetParent !== null; });
            tutti.checked = visibili.length > 0 && visibili.every(function (c) { return c.checked; });
        }
        document.getElementById('tabellaIscritti').addEventListener('change', function (e) {
            if (e.target.id === 'selTutti') {
                // "Seleziona tutti" agisce solo sulle righe visibili (rispetta la ricerca della tabella)
                caselle().forEach(function (c) { if (c.offsetParent !== null) c.checked = e.target.checked; });
            }
            if (e.target.id === 'selTutti' || e.target.classList.contains('sel-pren')) aggiorna();
        });
        document.getElementById('bulkDeseleziona').addEventListener('click', function () {
            caselle().forEach(function (c) { c.checked = false; }); aggiorna();
        });
        form.addEventListener('submit', function (e) {
            var scelte = caselle().filter(function (c) { return c.checked; });
            var azione = document.getElementById('bulkAzione');
            if (!confirm('Applicare "' + azione.options[azione.selectedIndex].text + '" a ' + scelte.length + ' iscritti?')) { e.preventDefault(); return; }
            form.querySelectorAll('input[name="bulk_ids[]"]').forEach(function (i) { i.remove(); });
            scelte.forEach(function (c) {
                var h = document.createElement('input'); h.type = 'hidden'; h.name = 'bulk_ids[]'; h.value = c.value; form.appendChild(h);
            });
        });
    });
    </script>
    <?php endif; ?>

    <!-- TABELLA -->
    <div class="table-responsive">
        <table id="tabellaIscritti" class="table isc-table align-middle mb-0">
            <thead>
                <tr>
                    <?php if (!$is_archivio): ?><th style="width:34px;"><input type="checkbox" class="form-check-input" id="selTutti" aria-label="Seleziona tutti gli iscritti visibili"></th><?php endif; ?>
                    <th>Partecipante</th>
                    <th>Evento / Turno</th>
                    <th>Stato</th>
                    <th>Presenza</th>
                    <th style="font-size:.65rem;color:#64748b;">Registrato</th>
                    <th class="text-end">Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php if(!empty($prenotazioni)): ?>
                <?php foreach($prenotazioni as $pr):
                    $json_c = json_decode($pr['dati_custom_json'] ?? '', true) ?: [];
                    $st_val = $pr['stato'] ?? 'confermata';
                    $ev_chk_attivo = (int)($pr['abilita_presenze'] ?? 1);
                    $is_presente = (int)($pr['presente'] ?? 0) === 1;
                    $initials = strtoupper(substr($pr['nome'] ?? 'U', 0, 1) . substr($pr['cognome'] ?? '', 0, 1));

                    // Colori avatar e stato
                    if (in_array($st_val, ['confermata','confermato','confirmed'])) {
                        $av_bg='#16a34a'; $st_bg='#dcfce7'; $st_tc='#166534'; $st_icon='fa-check'; $st_lbl='Confermata';
                    } elseif ($st_val === 'in_attesa' || $st_val === 'pending') {
                        $av_bg='#d97706'; $st_bg='#fef3c7'; $st_tc='#92400e'; $st_icon='fa-clock'; $st_lbl='In attesa';
                    } elseif ($st_val === 'da_approvare') {
                        $av_bg='#0891b2'; $st_bg='#e0f2fe'; $st_tc='#0c4a6e'; $st_icon='fa-hourglass-half'; $st_lbl='Da approvare';
                    } elseif ($st_val === 'annullata' || $st_val === 'annullato') {
                        $av_bg='#64748b'; $st_bg='#f1f5f9'; $st_tc='#334155'; $st_icon='fa-ban'; $st_lbl='Annullata';
                    } elseif ($st_val === 'rifiutata') {
                        $av_bg='#dc2626'; $st_bg='#fee2e2'; $st_tc='#991b1b'; $st_icon='fa-times'; $st_lbl='Rifiutata';
                    } else {
                        $av_bg='#475569'; $st_bg='#f1f5f9'; $st_tc='#334155'; $st_icon='fa-question'; $st_lbl=htmlspecialchars($st_val);
                    }
                    $row_opacity = in_array($st_val, ['annullata','rifiutata']) ? 'opacity:0.6;' : '';
                ?>
                <tr style="<?php echo $row_opacity; ?>">
                    <?php if (!$is_archivio): ?><td><input type="checkbox" class="form-check-input sel-pren" value="<?php echo (int)$pr['id']; ?>" aria-label="Seleziona <?php echo htmlspecialchars($pr['nome'] . ' ' . $pr['cognome']); ?>"></td><?php endif; ?>
                    <!-- Partecipante -->
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar-circle" style="background:<?php echo $av_bg; ?>;"><?php echo $initials; ?></div>
                            <div>
                                <div class="fw-semibold text-dark" style="font-size:.85rem;"><?php echo htmlspecialchars($pr['nome'] . ' ' . $pr['cognome']); ?></div>
                                <div class="text-muted" style="font-size:.72rem;"><?php echo htmlspecialchars($pr['email']); ?></div>
                                <div class="d-flex gap-1 mt-1 flex-wrap">
                                    <code style="font-size:.65rem;background:#f1f5f9;padding:1px 5px;border-radius:4px;color:#334155;"><?php echo $pr['codice_prenotazione']; ?></code>
                                    <?php if (!empty($pr['matricola_effettiva'])): ?>
                                        <span style="font-size:.65rem;background:#ede9fe;color:#5b21b6;padding:1px 5px;border-radius:4px;"><?php echo htmlspecialchars($pr['matricola_effettiva']); ?></span>
                                    <?php endif; ?>
                                    <?php if (($pr['num_posti'] ?? 1) > 1): ?>
                                        <span style="font-size:.65rem;background:#fef3c7;color:#92400e;padding:1px 5px;border-radius:4px;"><?php echo $pr['num_posti']; ?> posti</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>

                    <!-- Evento / Turno -->
                    <td>
                        <div class="fw-semibold text-dark" style="font-size:.82rem;max-width:200px;" title="<?php echo htmlspecialchars($pr['evento_titolo']); ?>"><?php echo htmlspecialchars(mb_strimwidth($pr['evento_titolo'], 0, 35, '…')); ?></div>
                        <div class="text-muted mt-1" style="font-size:.72rem;"><i class="fa fa-calendar me-1"></i><?php echo htmlspecialchars(etichetta_turno($pr)); ?></div>
                    </td>

                    <!-- Stato -->
                    <td>
                        <span class="stato-badge" style="background:<?php echo $st_bg; ?>;color:<?php echo $st_tc; ?>;"><i class="fa <?php echo $st_icon; ?>"></i><?php echo $st_lbl; ?></span>
                    </td>

                    <!-- Presenza -->
                    <td>
                        <?php if ($is_archivio): ?>
                            <?php if ($is_presente): ?>
                                <span class="stato-badge" style="background:#dcfce7;color:#166534;"><i class="fa fa-check"></i>Presente</span>
                            <?php else: ?>
                                <span class="stato-badge" style="background:#f1f5f9;color:#64748b;"><i class="fa fa-minus"></i>Assente</span>
                            <?php endif; ?>
                        <?php elseif ($ev_chk_attivo === 1): ?>
                            <form method="POST" class="d-inline">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="toggle_presenza" value="<?php echo $pr['id']; ?>">
                                <input type="hidden" name="val" value="<?php echo $is_presente ? '0' : '1'; ?>">
                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                <?php if ($is_presente): ?>
                                    <button type="submit" class="presenza-btn" style="background:#dcfce7;color:#166534;" title="Clicca per rimuovere la presenza"><i class="fa fa-check"></i>Presente</button>
                                <?php else: ?>
                                    <button type="submit" class="presenza-btn" style="background:#f1f5f9;color:#64748b;" title="Clicca per segnare presente"><i class="fa fa-minus"></i>Assente</button>
                                <?php endif; ?>
                            </form>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:.72rem;">N/A</span>
                        <?php endif; ?>
                    </td>

                    <!-- Data registrazione -->
                    <td style="font-size:.72rem;color:#94a3b8;white-space:nowrap;"><?php echo date('d/m/y H:i', strtotime($pr['data_prenotazione'])); ?></td>

                    <!-- Azioni -->
                    <td>
                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                            <a href="../stampa_ricevuta.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="act-btn" title="Ricevuta PDF"><i class="fa fa-file-pdf"></i></a>
                            <?php if ($ev_chk_attivo === 1 && $is_presente && $st_val === 'confermata'): ?>
                                <a href="../stampa_attestato.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="act-btn green" title="Attestato PDF"><i class="fa fa-graduation-cap"></i></a>
                            <?php endif; ?>
                            <?php if (!$is_archivio): ?>
                                <?php if ($st_val === 'da_approvare'): ?>
                                    <form method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="approva_pren" value="<?php echo $pr['id']; ?>">
                                        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                        <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                        <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                        <button type="submit" class="act-btn green" data-confirm="Approvare questa prenotazione?" title="Approva"><i class="fa fa-check"></i></button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="rifiuta_pren" value="<?php echo $pr['id']; ?>">
                                        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                        <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                        <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                        <button type="submit" class="act-btn orange" data-confirm="Rifiutare questa prenotazione?" title="Rifiuta"><i class="fa fa-times"></i></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (!empty($pr['email'])): ?>
                                    <button type="button" class="act-btn blue" data-bs-toggle="modal" data-bs-target="#modChat<?php echo $pr['id']; ?>" title="Chat / Messaggi"><i class="fa fa-comments"></i></button>
                                <?php endif; ?>
                                <button type="button" class="act-btn" data-bs-toggle="modal" data-bs-target="#modEditPren<?php echo $pr['id']; ?>" title="Dettagli / Modifica"><i class="fa fa-edit"></i></button>
                                <form method="POST" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="annulla_pren" value="<?php echo $pr['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                    <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                    <button type="submit" class="act-btn orange" data-confirm="Annullare questa prenotazione?" title="Annulla"><i class="fa fa-ban"></i></button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="del_pren" value="<?php echo $pr['id']; ?>">
                                    <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                    <input type="hidden" name="f_turno" value="<?php echo $filtro_turno; ?>">
                                    <input type="hidden" name="f_stato" value="<?php echo htmlspecialchars($filtro_stato); ?>">
                                    <button type="submit" class="act-btn red" data-confirm="Cancellare definitivamente la prenotazione?" title="Elimina"><i class="fa fa-trash"></i></button>
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
                                                                    echo "<option value='{$t_m['id']}' $sel>📅 " . htmlspecialchars(etichetta_turno($t_m)) . "</option>";
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
    <?php if ($total_pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center py-2 px-3 flex-wrap gap-2 border-top bg-white">
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
                                        <?php echo htmlspecialchars($ev_m['titolo'] . ' — ' . etichetta_turno($t_m)); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- CERCA UTENTE REGISTRATO -->
                    <div class="mb-3 p-3 rounded" style="background:#f0f7ff;border:1px dashed #93c5fd;">
                        <label class="form-label small fw-bold text-primary"><i class="fa fa-search me-1"></i>Cerca utente registrato (opzionale)</label>
                        <div class="position-relative">
                            <input type="text" id="cercaUtenteInput" class="form-control form-control-sm" placeholder="Scrivi nome, cognome, email o matricola..." autocomplete="off">
                            <div id="cercaUtenteDropdown" class="position-absolute w-100 bg-white border rounded shadow-sm d-none" style="z-index:9999;max-height:200px;overflow-y:auto;top:100%;left:0;"></div>
                        </div>
                        <div id="utenteSceltoInfo" class="d-none mt-2 d-flex align-items-center gap-2 p-2 rounded" style="background:#dbeafe;">
                            <i class="fa fa-user-check text-primary"></i>
                            <span id="utenteSceltoNome" class="fw-semibold text-primary small"></span>
                            <button type="button" class="btn-close ms-auto" id="btnSvuotaUtente" style="font-size:.65rem;" title="Deseleziona utente"></button>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4"><label class="form-label small fw-bold">Nome</label><input type="text" id="manNome" name="nome" class="form-control form-control-sm" required placeholder="Es. Mario"></div>
                        <div class="col-md-4"><label class="form-label small fw-bold">Cognome</label><input type="text" id="manCognome" name="cognome" class="form-control form-control-sm" required placeholder="Es. Rossi"></div>
                        <div class="col-md-4"><label class="form-label small fw-bold">Numero Posti</label><input type="number" name="num_posti" class="form-control form-control-sm" value="1" min="1" required></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Email</label><input type="email" id="manEmail" name="email" class="form-control form-control-sm" required placeholder="mario.rossi@unical.it"></div>
                        <div class="col-md-6"><label class="form-label small fw-bold">Matricola (Opzionale)</label><input type="text" id="manMatricola" name="matricola" class="form-control form-control-sm" placeholder="Es. 210000"></div>
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
        order: [[<?php echo $is_archivio ? 4 : 5; ?>, "desc"]],
        columnDefs: [ { orderable: false, targets: <?php echo $is_archivio ? "5" : "[0, 6]"; ?> } ]
    });

    // --- AUTOCOMPLETE UTENTE REGISTRATO nel modale prenotazione manuale ---
    var cercaTimer = null;
    var cercaInput = document.getElementById('cercaUtenteInput');
    var dropdown   = document.getElementById('cercaUtenteDropdown');
    var infoBox    = document.getElementById('utenteSceltoInfo');
    var infoNome   = document.getElementById('utenteSceltoNome');

    if (!cercaInput) return;

    cercaInput.addEventListener('input', function() {
        clearTimeout(cercaTimer);
        var q = this.value.trim();
        if (q.length < 2) { dropdown.classList.add('d-none'); dropdown.innerHTML = ''; return; }
        cercaTimer = setTimeout(function() {
            fetch('iscritti.php?ajax_cerca_utenti=1&q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    dropdown.innerHTML = '';
                    if (!data.length) {
                        dropdown.innerHTML = '<div class="px-3 py-2 text-muted small">Nessun utente trovato</div>';
                        dropdown.classList.remove('d-none');
                        return;
                    }
                    data.forEach(function(u) {
                        var item = document.createElement('div');
                        item.className = 'px-3 py-2 border-bottom';
                        item.style.cursor = 'pointer';
                        item.style.fontSize = '.82rem';
                        item.innerHTML = '<strong>' + u.cognome + ' ' + u.nome + '</strong>'
                            + '<span class="text-muted ms-2">' + u.email + '</span>'
                            + (u.matricola ? '<span class="ms-2 badge" style="background:#ede9fe;color:#5b21b6;">' + u.matricola + '</span>' : '');
                        item.addEventListener('mouseenter', function() { this.style.background = '#f0f7ff'; });
                        item.addEventListener('mouseleave', function() { this.style.background = ''; });
                        item.addEventListener('click', function() {
                            document.getElementById('manNome').value      = u.nome;
                            document.getElementById('manCognome').value   = u.cognome;
                            document.getElementById('manEmail').value     = u.email;
                            document.getElementById('manMatricola').value = u.matricola || '';
                            infoNome.textContent = u.cognome + ' ' + u.nome + ' — ' + u.email;
                            infoBox.classList.remove('d-none');
                            dropdown.classList.add('d-none');
                            cercaInput.value = '';
                        });
                        dropdown.appendChild(item);
                    });
                    dropdown.classList.remove('d-none');
                });
        }, 280);
    });

    document.addEventListener('click', function(e) {
        if (!cercaInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.add('d-none');
        }
    });

    var btnSvuota = document.getElementById('btnSvuotaUtente');
    if (btnSvuota) {
        btnSvuota.addEventListener('click', function() {
            document.getElementById('manNome').value      = '';
            document.getElementById('manCognome').value   = '';
            document.getElementById('manEmail').value     = '';
            document.getElementById('manMatricola').value = '';
            infoBox.classList.add('d-none');
        });
    }

    // Reset modale alla chiusura
    document.getElementById('modPrenotazioneManuale').addEventListener('hidden.bs.modal', function() {
        dropdown.classList.add('d-none');
        infoBox.classList.add('d-none');
        cercaInput.value = '';
    });
});
</script>

<?php require_once 'admin_footer.php'; ?>
