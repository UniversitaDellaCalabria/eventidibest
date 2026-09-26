<?php
// =========================================================================
// Fase 4: Autenticazione, SSO, dati utente — tutto centralizzato in middleware.php
// $u_id, $u_ruolo, $is_full_admin, $is_gestore, $user_info, $u_email_sql
// =========================================================================
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/middleware.php';

// =======================================================================
// AZIONE: INVIO MESSAGGIO ALLA SEGRETERIA (CHAT)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invia_messaggio_utente'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $pr_id = (int)$_POST['prenotazione_id'];
    $messaggio_html = nl2br(htmlspecialchars(trim($_POST['corpo_messaggio'])));

    // Verifica che la prenotazione appartenga all'utente
    $stmt_chk = $conn->prepare("SELECT id FROM prenotazioni WHERE id = ? AND (utente_id = ? OR LOWER(email) = ?)");
    $stmt_chk->bind_param("iis", $pr_id, $u_id, $u_email_sql);
    $stmt_chk->execute();
    $res_chk = $stmt_chk->get_result();

    if ($res_chk->num_rows > 0 && !empty($messaggio_html)) {
        // Segna gli eventuali messaggi dell'admin come letti
        $conn->query("UPDATE messaggi_prenotazioni SET letto = 1 WHERE prenotazione_id = $pr_id AND mittente_tipo = 'admin'");
        
        // Salva il nuovo messaggio
        $stmt_msg = $conn->prepare("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio, letto) VALUES (?, 'utente', ?, ?, 0)");
        $stmt_msg->bind_param("iis", $pr_id, $u_id, $messaggio_html);
        $stmt_msg->execute();

        // Notifica email ai gestori dell'evento
        $res_ev_msg = $conn->query("SELECT pr.nome, pr.cognome, e.id as evento_id, e.titolo as evento_titolo
                                    FROM prenotazioni pr
                                    JOIN turni t ON pr.turno_id = t.id
                                    JOIN eventi e ON t.evento_id = e.id
                                    WHERE pr.id = $pr_id LIMIT 1");
        if ($res_ev_msg && $ev_msg_data = $res_ev_msg->fetch_assoc()) {
            $subj_g = "Nuovo messaggio assistenza – " . $ev_msg_data['evento_titolo'];
            $body_g = "<p>Gentile Gestore,</p>"
                    . "<p><strong>" . htmlspecialchars($ev_msg_data['nome'] . ' ' . $ev_msg_data['cognome']) . "</strong> ha inviato un messaggio riguardante l'evento <strong>" . htmlspecialchars($ev_msg_data['evento_titolo']) . "</strong>.</p>"
                    . "<p>Accedi al pannello di amministrazione &gt; Messaggi per rispondere.</p>"
                    . "<p>Cordiali saluti,<br>Sistema EventiDiBEST</p>";
            // I messaggi di assistenza vanno a tutti i gestori, indipendentemente dall'interruttore notifiche prenotazioni
            foreach (get_email_gestori_evento($conn, (int)$ev_msg_data['evento_id'], false) as $em_gest) {
                inviaNotificaEmail($em_gest, $subj_g, $body_g, $conn);
            }
        }

        $_SESSION['msg_area_pers'] = "<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-paper-plane me-1'></i> Messaggio inviato con successo alla segreteria.</div>";
    }
    header("Location: area_personale.php");
    exit;
}

// =======================================================================
// AZIONE: AGGIORNAMENTO EMAIL (TAB PROFILO)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aggiorna_email'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $nuova_email = strtolower(trim($_POST['nuova_email'] ?? ''));
    if (empty($nuova_email) || !filter_var($nuova_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Inserisci un indirizzo email valido.</div>";
    } else {
        $risultato = aggiorna_email_utente($conn, $u_id, $nuova_email);
        if ($risultato === true) {
            $_SESSION['utente_email'] = $nuova_email;
            $_SESSION['msg_area_pers'] = "<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-check-circle me-1'></i> Email aggiornata correttamente.</div>";
        } else {
            $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> " . htmlspecialchars($risultato) . "</div>";
        }
    }
    header("Location: area_personale.php#profilo");
    exit;
}

// =======================================================================
// AZIONE: MODIFICA PRENOTAZIONE (SECURE - Prepared Statements + Cambio Turno)
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_prenotazione_utente'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $pr_id = (int)$_POST['prenotazione_id'];
    $nuovo_turno_id = isset($_POST['nuovo_turno_id']) ? (int)$_POST['nuovo_turno_id'] : 0;
    
    $nome = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $matricola = trim($_POST['matricola'] ?? '');

    $stmt_chk_prop = $conn->prepare("SELECT turno_id, num_posti FROM prenotazioni WHERE id = ? AND (utente_id = ? OR (email IS NOT NULL AND LOWER(email) = ?))");
    $stmt_chk_prop->bind_param("iis", $pr_id, $u_id, $u_email_sql);
    $stmt_chk_prop->execute();
    $res_prop = $stmt_chk_prop->get_result();
    
    if ($res_prop->num_rows === 0) {
        $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Operazione non autorizzata.</div>";
        header("Location: area_personale.php");
        exit;
    }
    
    $old_data = $res_prop->fetch_assoc();
    $turno_attuale_id = (int)$old_data['turno_id'];
    $posti_richiesti = (int)$old_data['num_posti'];
    
    $turno_da_salvare = $turno_attuale_id;
    $nuovo_stato = null;

    $custom_data = [];
    foreach ($_POST as $k => $v) {
        if (strpos($k, 'custom_') === 0) {
            $field_name = str_replace('custom_', '', $k);
            $custom_data[$field_name] = is_array($v) ? implode(', ', $v) : trim($v);
        }
    }
    $json_custom_bind = !empty($custom_data) ? json_encode($custom_data, JSON_UNESCAPED_UNICODE) : null;

    // =====================================================================
    // FASE 2: SEZIONE CRITICA - transazione + lock pessimistico anti-overbooking
    // sul turno di destinazione (se l'utente sta cambiando turno).
    // =====================================================================
    $conn->begin_transaction();
    $update_ok = false;
    try {
        if ($nuovo_turno_id > 0 && $nuovo_turno_id !== $turno_attuale_id) {
            $stmt_nuovo_t = $conn->prepare("SELECT max_posti, abilita_lista_attesa FROM turni WHERE id = ? LIMIT 1");
            $stmt_nuovo_t->bind_param("i", $nuovo_turno_id);
            $stmt_nuovo_t->execute();
            $res_nuovo_t = $stmt_nuovo_t->get_result();
            if ($res_nuovo_t && $info_t = $res_nuovo_t->fetch_assoc()) {
                // FOR UPDATE: blocca le prenotazioni del turno di destinazione finché questa
                // transazione non fa commit/rollback, per un conteggio affidabile anche in concorrenza.
                $stmt_occ = $conn->prepare("SELECT COALESCE(SUM(num_posti), 0) as tot FROM prenotazioni WHERE turno_id = ? AND stato = 'confermata' FOR UPDATE");
                $stmt_occ->bind_param("i", $nuovo_turno_id);
                $stmt_occ->execute();
                $occupati_nuovo = $stmt_occ->get_result()->fetch_assoc()['tot'];
                $posti_liberi = $info_t['max_posti'] - $occupati_nuovo;

                if ($posti_liberi >= $posti_richiesti) {
                    $turno_da_salvare = $nuovo_turno_id;
                    $nuovo_stato = 'confermata';
                } elseif ($info_t['abilita_lista_attesa'] == 1) {
                    $turno_da_salvare = $nuovo_turno_id;
                    $nuovo_stato = 'in_attesa';
                } else {
                    $conn->rollback();
                    $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Il turno selezionato è esaurito.</div>";
                    header("Location: area_personale.php");
                    exit;
                }
            }
        }

        if ($nuovo_stato !== null) {
            $stmt_upd = $conn->prepare("UPDATE prenotazioni SET turno_id = ?, stato = ?, nome = ?, cognome = ?, email = ?, matricola = ?, dati_custom_json = ? WHERE id = ?");
            $stmt_upd->bind_param("issssssi", $turno_da_salvare, $nuovo_stato, $nome, $cognome, $email, $matricola, $json_custom_bind, $pr_id);
        } else {
            $stmt_upd = $conn->prepare("UPDATE prenotazioni SET nome = ?, cognome = ?, email = ?, matricola = ?, dati_custom_json = ? WHERE id = ?");
            $stmt_upd->bind_param("sssssi", $nome, $cognome, $email, $matricola, $json_custom_bind, $pr_id);
        }

        $update_ok = $stmt_upd->execute();
        if (!$update_ok) {
            throw new Exception($conn->error ?: 'Errore sconosciuto in fase di aggiornamento prenotazione');
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log("[EditPrenotazione][pr_id=$pr_id] Transazione fallita: " . $e->getMessage());
        $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Errore durante l'aggiornamento. Riprova.</div>";
        header("Location: area_personale.php");
        exit;
    }
    // ================= FINE SEZIONE CRITICA =================

    if ($update_ok) {
        if ($nuovo_stato === 'confermata') { decadi_attese_vincolate($conn, $pr_id); }
        if ($nuovo_turno_id > 0 && $nuovo_turno_id !== $turno_attuale_id) {
            $res_promo = $conn->query("SELECT * FROM prenotazioni WHERE turno_id = $turno_attuale_id AND stato = 'in_attesa' ORDER BY data_prenotazione ASC, id ASC LIMIT 1");
            if ($res_promo && $u_promo = $res_promo->fetch_assoc()) {
                $id_promo = (int)$u_promo['id'];
                $conn->query("UPDATE prenotazioni SET stato = 'confermata' WHERE id = $id_promo");
                decadi_attese_vincolate($conn, $id_promo);

                $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $link_ricevuta_url = $proto . $domain . $base_dir . "/stampa_ricevuta.php?code=" . urlencode($u_promo['codice_prenotazione']);
                $btn_ricevuta_html = "<p style='margin-top:15px;'><a href='$link_ricevuta_url' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>";

                $obj_tpl_promo = "Posto Disponibile! Prenotazione CONFERMATA";
                $body_tpl_promo = "<p>Ottime notizie <strong>" . htmlspecialchars($u_promo['nome']) . "</strong>!</p><p>Si è appena liberato un posto e la tua prenotazione in lista d'attesa è passata a <strong>CONFERMATA UFFICIALMENTE</strong>.</p>$btn_ricevuta_html";
                inviaNotificaEmail($u_promo['email'], $obj_tpl_promo, $body_tpl_promo, $conn, colore_area_turno($conn, $turno_attuale_id));
            }
            
            $msg_extra = $nuovo_stato === 'in_attesa' ? " Sei stato inserito in Lista d'Attesa per il nuovo orario." : " Turno aggiornato con successo!";
            $_SESSION['msg_area_pers'] = "<div class='alert alert-success fw-bold text-center my-3 shadow-sm border-0 border-start border-5 border-success'><i class='fa fa-check-circle me-1'></i> Modifica salvata." . $msg_extra . "</div>";
        } else {
            $_SESSION['msg_area_pers'] = "<div class='alert alert-success fw-bold text-center my-3 shadow-sm border-0 border-start border-5 border-success'><i class='fa fa-check-circle me-1'></i> Prenotazione aggiornata con successo!</div>";
        }
    } else {
        $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Errore durante l'aggiornamento.</div>";
    }
    header("Location: area_personale.php");
    exit;
}

// =======================================================================
// AZIONE: CANCELLAZIONE PRENOTAZIONE
// =======================================================================
if (isset($_GET['cancella_prenotazione'])) {
    csrf_verify($_GET['csrf'] ?? '');
    $pr_id = (int)$_GET['cancella_prenotazione'];

    $stmt_chk = $conn->prepare("SELECT pr.*, t.id as turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo as evento_titolo, e.luogo, e.pagina_id, e.id as evento_id FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id WHERE pr.id = ? AND (pr.utente_id = ? OR LOWER(pr.email) = ?)");
    $stmt_chk->bind_param("iis", $pr_id, $u_id, $u_email_sql);
    $stmt_chk->execute();
    $res_chk = $stmt_chk->get_result();
    
    if ($res_chk && $res_chk->num_rows > 0) {
        $p_data = $res_chk->fetch_assoc();
        // Anche un posto offerto e non ancora confermato va ripassato alla coda
        $was_confermata = in_array($p_data['stato'], ['confermata', 'richiesta_conferma'], true);
        $tid_promo = (int)$p_data['turno_id'];

        // Fix: UPDATE stato='annullata' invece di DELETE, così la riga resta
        // nel DB per storico/audit e non ricompare nel listing (filtrato sotto).
        $conn->query("UPDATE prenotazioni SET stato = 'annullata' WHERE id = $pr_id");

        $data_formatted = implode(' · ', array_filter([$p_data['nome_turno'] ?? '', !empty($p_data['data_turno']) ? date('d/m/Y', strtotime($p_data['data_turno'])) : '']));
        $ora_formatted = orario_turno($p_data) ?: 'da definire';
        $r_find = ['{NOME}', '{COGNOME}', '{MATRICOLA}', '{TITOLO_EVENTO}', '{DATA_TURNO}', '{ORARIO_TURNO}', '{LUOGO}', '{CODICE_PRENOTAZIONE}', '{LINK_RICEVUTA}'];
        $r_repl = [$p_data['nome'], $p_data['cognome'], $p_data['matricola'], $p_data['evento_titolo'], $data_formatted, $ora_formatted, $p_data['luogo'], $p_data['codice_prenotazione'], ''];

        $sys_email = $conn->query("SELECT * FROM impostazioni_sistema WHERE id = 1")->fetch_assoc();

        $obj_tpl  = $sys_email['email_canc_utente_oggetto'] ?: 'Cancellazione Prenotazione Confermata';
        $body_tpl = $sys_email['email_canc_utente_corpo'] ?: "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>La tua prenotazione per l'evento <strong>{TITOLO_EVENTO}</strong> è stata cancellata con successo.</p>";
        inviaNotificaEmail($p_data['email'], str_replace($r_find, $r_repl, $obj_tpl), str_replace($r_find, $r_repl, $body_tpl), $conn, colore_area_turno($conn, $p_data['turno_id']));

        // Gestori con notifiche attive + indirizzi aggiuntivi dell'evento, con il riepilogo completo
        $destinatari_notifica = get_destinatari_notifiche_prenotazione($conn, (int)$p_data['evento_id']);
        $riepilogo = $destinatari_notifica ? html_riepilogo_prenotazione($conn, $pr_id) : null;
        if ($riepilogo) {
            $obj_gest = "Disdetta: " . $p_data['evento_titolo'];
            $intro_gest = "<p><strong>" . htmlspecialchars($p_data['nome'] . ' ' . $p_data['cognome']) . "</strong> ha appena <strong>annullato</strong> la prenotazione per l'evento <strong>" . htmlspecialchars($p_data['evento_titolo']) . "</strong>.</p>";
            $gestori_ev = get_email_gestori_evento($conn, (int)$p_data['evento_id']);
            foreach ($destinatari_notifica as $em_gest) {
                inviaNotificaEmail($em_gest, $obj_gest, corpo_notifica_per($em_gest, $intro_gest, $riepilogo, $gestori_ev), $conn, colore_area_turno($conn, $p_data['turno_id']));
            }
        }

        if ($was_confermata) {
            $res_promo = $conn->query("SELECT * FROM prenotazioni WHERE turno_id = $tid_promo AND stato = 'in_attesa' ORDER BY data_prenotazione ASC, id ASC LIMIT 1");
            if ($res_promo && $u_promo = $res_promo->fetch_assoc()) {
                $id_promo = (int)$u_promo['id'];
                $conn->query("UPDATE prenotazioni SET stato = 'confermata' WHERE id = $id_promo");
                decadi_attese_vincolate($conn, $id_promo);

                $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $link_ricevuta_url = $proto . $domain . $base_dir . "/stampa_ricevuta.php?code=" . urlencode($u_promo['codice_prenotazione']);
                $btn_ricevuta_html = "<p style='margin-top:15px;'><a href='$link_ricevuta_url' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>";

                $obj_tpl_promo = "Posto Disponibile! Prenotazione CONFERMATA: " . $p_data['evento_titolo'];
                $body_tpl_promo = "<p>Ottime notizie <strong>{NOME} {COGNOME}</strong>!</p><p>Si è appena liberato un posto e la tua prenotazione in lista d'attesa per l'evento <strong>{TITOLO_EVENTO}</strong> è passata a <strong>CONFERMATA UFFICIALMENTE</strong>.</p>{LINK_RICEVUTA}";
                
                $r_repl_promo = [$u_promo['nome'], $u_promo['cognome'], $u_promo['matricola'], $p_data['evento_titolo'], $data_formatted, $ora_formatted, $p_data['luogo'], $u_promo['codice_prenotazione'], $btn_ricevuta_html];
                inviaNotificaEmail($u_promo['email'], str_replace($r_find, $r_repl_promo, $obj_tpl_promo), str_replace($r_find, $r_repl_promo, $body_tpl_promo), $conn, colore_area_turno($conn, $tid_promo));
            }
        }

        $_SESSION['msg_area_pers'] = "<div class='alert alert-success fw-bold text-center my-4 shadow-sm border-0 border-start border-5 border-success'><i class='fa fa-check-circle me-2'></i> Prenotazione annullata correttamente.</div>";
    } else {
        $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-4 shadow-sm'><i class='fa fa-times-circle me-2'></i> Errore o autorizzazione negata per l'annullamento.</div>";
    }
    header("Location: area_personale.php");
    exit;
}

// =======================================================================
// AZIONE: CONFERMA / RINUNCIA POSTO LIBERATO DALLA LISTA D'ATTESA
// Il link nell'email (promuovi_lista_attesa) apre solo il riquadro di scelta;
// la conferma vera e propria avviene via POST con CSRF.
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['conferma_posto_ok']) || isset($_POST['rinuncia_posto']))) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $pr_id    = (int)($_POST['pr_id'] ?? 0);
    $conferma = isset($_POST['conferma_posto_ok']);

    $conn->begin_transaction();
    $stmt_cp = $conn->prepare("SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo, e.luogo
                               FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                               WHERE pr.id = ? AND (pr.utente_id = ? OR LOWER(pr.email) = ?) FOR UPDATE");
    $stmt_cp->bind_param("iis", $pr_id, $u_id, $u_email_sql);
    $stmt_cp->execute();
    $p_cp = $stmt_cp->get_result()->fetch_assoc();

    $scaduta = $p_cp && (($p_cp['stato'] === 'scaduta')
            || ($p_cp['stato'] === 'richiesta_conferma' && !empty($p_cp['scadenza_conferma']) && $p_cp['scadenza_conferma'] < date('Y-m-d H:i:s')));
    if (!$p_cp || $p_cp['stato'] !== 'richiesta_conferma' || $scaduta) {
        $conn->rollback();
        $motivo = $scaduta ? "Il tempo per confermare il posto è scaduto e il posto è stato riassegnato."
                           : "Questa offerta di posto non è più valida.";
        $_SESSION['msg_area_pers'] = "<div class='alert alert-warning fw-bold text-center my-3 shadow-sm'><i class='fa fa-clock me-1'></i> $motivo</div>";
        header("Location: area_personale.php");
        exit;
    }

    $nuovo_stato_cp = $conferma ? 'confermata' : 'annullata';
    $stmt_up_cp = $conn->prepare("UPDATE prenotazioni SET stato = ? WHERE id = ?");
    $stmt_up_cp->bind_param("si", $nuovo_stato_cp, $pr_id);
    if (!$stmt_up_cp->execute()) {
        $conn->rollback();
        $_SESSION['msg_area_pers'] = "<div class='alert alert-danger fw-bold text-center my-3 shadow-sm'><i class='fa fa-times-circle me-1'></i> Errore durante il salvataggio. Riprova.</div>";
        header("Location: area_personale.php");
        exit;
    }
    $conn->commit();

    if ($conferma) {
        decadi_attese_vincolate($conn, $pr_id);
        $link_ricevuta_cp = url_base_sito() . "/stampa_ricevuta.php?code=" . urlencode($p_cp['codice_prenotazione']);
        $body_cp = "<p>Gentile <strong>" . htmlspecialchars($p_cp['nome'] . ' ' . $p_cp['cognome']) . "</strong>,</p>"
                 . "<p>hai confermato il tuo posto per <strong>" . htmlspecialchars($p_cp['evento_titolo']) . "</strong> (" . htmlspecialchars(etichetta_turno($p_cp)) . "). La prenotazione è <strong>CONFERMATA</strong>.</p>"
                 . "<p style='margin-top:15px;'><a href='$link_ricevuta_cp' target='_blank' style='background:#B80000; color:#ffffff; padding:10px 18px; text-decoration:none; border-radius:6px; font-weight:bold;'>📄 Scarica / Stampa Ricevuta PDF</a></p>";
        inviaNotificaEmail($p_cp['email'], "Prenotazione CONFERMATA: " . $p_cp['evento_titolo'], $body_cp, $conn, colore_area_turno($conn, $p_cp['turno_id']));
        $_SESSION['msg_area_pers'] = "<div class='alert alert-success fw-bold text-center my-3 shadow-sm border-0 border-start border-5 border-success'><i class='fa fa-check-circle me-1'></i> Posto confermato! Ti abbiamo inviato la ricevuta via email.</div>";
    } else {
        // Il posto passa subito al prossimo in lista d'attesa
        promuovi_lista_attesa($conn, (int)$p_cp['turno_id']);
        $_SESSION['msg_area_pers'] = "<div class='alert alert-info fw-bold text-center my-3 shadow-sm'><i class='fa fa-info-circle me-1'></i> Hai rinunciato al posto. Grazie per averlo lasciato ad altri.</div>";
    }
    header("Location: area_personale.php");
    exit;
}

$box_conferma_posto = null;
if (isset($_GET['conferma_posto'])) {
    $pr_id_cp = (int)$_GET['conferma_posto'];
    $stmt_cp = $conn->prepare("SELECT pr.id, pr.stato, pr.scadenza_conferma, pr.num_posti, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo, e.luogo
                               FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                               WHERE pr.id = ? AND (pr.utente_id = ? OR LOWER(pr.email) = ?)");
    $stmt_cp->bind_param("iis", $pr_id_cp, $u_id, $u_email_sql);
    $stmt_cp->execute();
    $row_cp = $stmt_cp->get_result()->fetch_assoc();
    if ($row_cp && $row_cp['stato'] === 'richiesta_conferma') {
        $box_conferma_posto = $row_cp;
    } elseif ($row_cp && $row_cp['stato'] === 'confermata') {
        $_SESSION['msg_area_pers'] = "<div class='alert alert-success fw-bold text-center my-3 shadow-sm'><i class='fa fa-check-circle me-1'></i> Questo posto è già confermato.</div>";
    } elseif ($row_cp && $row_cp['stato'] === 'scaduta') {
        $_SESSION['msg_area_pers'] = "<div class='alert alert-warning fw-bold text-center my-3 shadow-sm'><i class='fa fa-clock me-1'></i> Il tempo per confermare il posto è scaduto e il posto è stato riassegnato.</div>";
    } else {
        $_SESSION['msg_area_pers'] = "<div class='alert alert-warning fw-bold text-center my-3 shadow-sm'><i class='fa fa-exclamation-triangle me-1'></i> Offerta di posto non valida o non più disponibile.</div>";
    }
}

// =======================================================================
// ESTRAZIONE DATI PRENOTAZIONI DELL'UTENTE E TURNI ALTERNATIVI
// =======================================================================
$prenotazioni_attive = [];
$prenotazioni_passate = [];
$now = date('Y-m-d H:i:s');

$sql_pr = "SELECT pr.*, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine,
           e.titolo as evento_titolo, e.luogo as evento_luogo, e.id as evento_id, e.locandina_path, e.abilita_presenze,
           pe.titolo as pagina_titolo, pe.colore_primario, pe.id as p_id
           FROM prenotazioni pr
           JOIN turni t ON pr.turno_id = t.id
           JOIN eventi e ON t.evento_id = e.id
           JOIN pagine_eventi pe ON e.pagina_id = pe.id
           WHERE (pr.utente_id = ? OR LOWER(pr.email) = ?)
           ORDER BY t.data_turno DESC, t.orario_inizio DESC";

$stmt_list = $conn->prepare($sql_pr);
$stmt_list->bind_param("is", $u_id, $u_email_sql);
$stmt_list->execute();
$res_list = $stmt_list->get_result();

// =======================================================================
// FASE 3: RISOLUZIONE N+1 QUERY PROBLEM
// La versione precedente faceva, PER OGNI prenotazione dell'utente, una query
// per i turni alternativi e poi, PER OGNI turno alternativo, un'altra query per
// contare gli occupati: con uno storico prenotazioni consistente si arrivava
// facilmente a centinaia di query per un singolo caricamento della pagina.
// Qui invece: 1) una prima passata leggera (nessuna query) smista le righe già
// lette in attive/passate e raccoglie gli evento_id di cui servono i turni
// alternativi - servono solo per le prenotazioni ATTIVE, l'unico posto in cui
// vengono mostrati (modale "Modifica Turno/Dati"); 2) UNA query carica tutti i
// turni futuri di tutti quegli eventi in blocco; 3) UNA query carica tutti i
// conteggi posti occupati di quei turni in blocco (GROUP BY). Risultato: al
// massimo 3 query totali invece di 1 + 2N, indipendentemente da quante
// prenotazioni ha l'utente.
// =======================================================================
$righe_prenotazioni = [];
$evento_ids_per_alternativi = [];
if ($res_list) {
    while ($row = $res_list->fetch_assoc()) {
        $row['turni_alternativi'] = [];
        $row['_is_attiva'] = !turno_concluso($row);
        if ($row['_is_attiva']) {
            $evento_ids_per_alternativi[(int)$row['evento_id']] = true;
        }
        $righe_prenotazioni[] = $row;
    }
}

$turni_per_evento = [];
$occupati_per_turno = [];
if (!empty($evento_ids_per_alternativi)) {
    $ev_ids_list = implode(',', array_map('intval', array_keys($evento_ids_per_alternativi)));

    // Query 2: tutti i turni futuri di tutti gli eventi coinvolti, in un colpo solo
    $res_alt_batch = $conn->query("SELECT id, evento_id, nome_turno, data_turno, orario_inizio, orario_fine, max_posti, data_apertura, data_chiusura
                                    FROM turni
                                    WHERE evento_id IN ($ev_ids_list) AND (data_turno IS NULL OR CONCAT(data_turno, ' ', COALESCE(orario_inizio, '23:59:59')) > '$now')
                                    ORDER BY (data_turno IS NULL), data_turno ASC, orario_inizio ASC, nome_turno ASC, id ASC");
    $turno_ids_coinvolti = [];
    if ($res_alt_batch) {
        while ($ta = $res_alt_batch->fetch_assoc()) {
            $turni_per_evento[(int)$ta['evento_id']][] = $ta;
            $turno_ids_coinvolti[] = (int)$ta['id'];
        }
    }

    // Query 3: conteggio posti occupati di tutti quei turni, in un colpo solo
    if (!empty($turno_ids_coinvolti)) {
        $turno_ids_list = implode(',', array_unique($turno_ids_coinvolti));
        $res_occ_batch = $conn->query("SELECT turno_id, COALESCE(SUM(num_posti), 0) as tot
                                        FROM prenotazioni
                                        WHERE turno_id IN ($turno_ids_list) AND stato = 'confermata'
                                        GROUP BY turno_id");
        if ($res_occ_batch) {
            while ($o = $res_occ_batch->fetch_assoc()) {
                $occupati_per_turno[(int)$o['turno_id']] = (int)$o['tot'];
            }
        }
    }
}

// Seconda passata: assembla turni_alternativi usando solo dati già in memoria
// (nessuna nuova query) e smista definitivamente attive/passate.
foreach ($righe_prenotazioni as $row) {
    if ($row['_is_attiva']) {
        $ev_id_curr = (int)$row['evento_id'];
        foreach (($turni_per_evento[$ev_id_curr] ?? []) as $ta) {
            $occupati = $occupati_per_turno[(int)$ta['id']] ?? 0;
            $ta['posti_liberi'] = $ta['max_posti'] - $occupati;

            $is_closed = false;
            if (!empty($ta['data_apertura']) && date('Y-m-d H:i:s') < $ta['data_apertura']) $is_closed = true;
            if (!empty($ta['data_chiusura']) && date('Y-m-d H:i:s') > $ta['data_chiusura']) $is_closed = true;
            $ta['is_closed'] = $is_closed;

            $row['turni_alternativi'][] = $ta;
        }
        unset($row['_is_attiva']);
        $prenotazioni_attive[] = $row;
    } else {
        unset($row['_is_attiva']);
        $prenotazioni_passate[] = $row;
    }
}

// Posizione in lista d'attesa ("Sei 3° in lista") per le prenotazioni in coda
$pos_attesa = get_posizioni_lista_attesa($conn, array_column(
    array_filter($prenotazioni_attive, fn($p) => ($p['stato'] ?? '') === 'in_attesa'), 'id'));

// RECUPERO TUTTI I MESSAGGI PER LE PRENOTAZIONI DELL'UTENTE
$all_pr_ids     = array_merge(array_column($prenotazioni_attive, 'id'), array_column($prenotazioni_passate, 'id'));
$messaggi_per_pr = get_messaggi_per_prenotazioni($conn, $all_pr_ids);

// Stats rapide per l'header
$all_pr_merged = array_merge($prenotazioni_attive, $prenotazioni_passate);
$stat_presenze  = 0;
$stat_attestati = 0;
foreach ($all_pr_merged as $pr_s) {
    if ((int)($pr_s['presente'] ?? 0) === 1) {
        $stat_presenze++;
        if (in_array($pr_s['stato'] ?? '', ['confermata','confermato','confirmed'])) $stat_attestati++;
    }
}

function countdown_to($data_turno, $orario_inizio) {
    if (empty($data_turno)) return null;
    $diff = strtotime($data_turno . ' ' . $orario_inizio) - time();
    if ($diff <= 0) return null;
    if ($diff < 3600)  return ['label' => 'Tra ' . max(1, round($diff / 60)) . ' min', 'cls' => 'danger'];
    if ($diff < 86400) return ['label' => 'Tra ' . round($diff / 3600) . ' ore', 'cls' => 'warning'];
    $d = (int)round($diff / 86400);
    if ($d === 1)     return ['label' => 'Domani', 'cls' => 'info'];
    if ($d <= 14)     return ['label' => 'Tra ' . $d . ' giorni', 'cls' => 'primary'];
    return null;
}

require_once 'header.php';
?>

<style>
    .custom-tabs .nav-link { color: #475569; transition: all 0.2s ease; }
    .custom-tabs .nav-link:hover { color: #0d6efd; background-color: #f8f9fa; }
    .custom-tabs .nav-link.active { background-color: #0d6efd !important; color: #ffffff !important; }
    .btn-glass {
        background-color: rgba(255,255,255,0.1);
        border: 2px solid rgba(255,255,255,0.6);
        color: #ffffff;
        backdrop-filter: blur(5px);
        transition: all 0.3s ease;
    }
    .btn-glass:hover { background-color: rgba(255,255,255,0.25); border-color: #ffffff; color: #ffffff; transform: translateY(-2px); }
    .stat-kpi { text-align: center; padding: 10px 16px; }
    .stat-kpi .val { font-size: 1.8rem; font-weight: 700; line-height: 1; }
    .stat-kpi .lbl { font-size: 0.7rem; opacity: 0.75; text-transform: uppercase; letter-spacing: .05em; margin-top: 3px; }
    .countdown-badge { font-size: 0.7rem; font-weight: 600; padding: 3px 10px; border-radius: 20px; letter-spacing: .02em; }
    .card-evento-attivo { border-radius: 12px; border: none; border-left: 5px solid #dee2e6; }
    .card-evento-passato { border-radius: 12px; border: none; border-left: 5px solid #dee2e6; opacity: .92; }
    .azioni-desktop { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
    @media (max-width: 575px) { .azioni-desktop { justify-content: flex-start; } }
    .presenza-grande { padding: 8px 14px; border-radius: 10px; font-weight: 600; font-size: .9rem; display: inline-flex; align-items: center; gap: 6px; }
</style>

<div class="container my-4" style="max-width: 900px;">
    
    <!-- INTESTAZIONE CON STATS + SCANNER -->
    <div class="card shadow-sm border-0 mb-4 overflow-hidden" style="border-radius: 14px;">
        <div class="p-4 text-center" style="background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: white;">
            <div class="d-inline-flex justify-content-center align-items-center bg-white text-dark rounded-circle mb-2 shadow" style="width: 52px; height: 52px;">
                <i class="fa fa-user-circle fs-3 text-primary"></i>
            </div>
            <h4 class="fw-bold m-0 mb-1">Area Personale</h4>
            <p class="opacity-75 m-0 small fw-bold text-uppercase"><?php echo htmlspecialchars($user_info['nome'] . ' ' . $user_info['cognome']); ?></p>
            <?php if (!empty($user_info['matricola_studente']) || !empty($user_info['matricola_dipendente'])): ?>
                <div class="mt-1"><span class="badge bg-light text-dark font-monospace shadow-sm">Matricola: <?php echo htmlspecialchars($user_info['matricola_studente'] ?: $user_info['matricola_dipendente']); ?></span></div>
            <?php endif; ?>

            <!-- MINI KPI -->
            <div class="row g-0 mt-3 pt-3 border-top border-secondary">
                <div class="col-4 stat-kpi border-end border-secondary">
                    <div class="val"><?php echo count($prenotazioni_attive); ?></div>
                    <div class="lbl">Prossimi</div>
                </div>
                <div class="col-4 stat-kpi border-end border-secondary">
                    <div class="val"><?php echo $stat_presenze; ?></div>
                    <div class="lbl">Presenze</div>
                </div>
                <div class="col-4 stat-kpi">
                    <div class="val"><?php echo $stat_attestati; ?></div>
                    <div class="lbl">Attestati</div>
                </div>
            </div>

            <!-- SCANNER -->
            <div class="mt-3 pt-3 border-top border-secondary">
                <a href="scanner_studente.php" class="btn btn-glass btn-lg fw-bold rounded-pill px-4 shadow-sm">
                    <i class="fa fa-camera me-2"></i> Registra Presenza (Scanner QR)
                </a>
                <p class="small mt-2 mb-0" style="color:rgba(255,255,255,0.6);">Inquadra il QR Code in aula per il check-in.</p>
            </div>
        </div>
    </div>

    <?php 
    if (isset($_SESSION['msg_area_pers'])) {
        echo $_SESSION['msg_area_pers'];
        unset($_SESSION['msg_area_pers']);
    }
    ?>

    <?php if ($box_conferma_posto): $bc = $box_conferma_posto; ?>
        <div class="card border-0 shadow my-4" style="border-left: 6px solid #198754 !important; border-radius: 10px;">
            <div class="card-body p-4">
                <h4 class="fw-bold text-success mb-2"><i class="fa fa-bell me-2"></i>Si è liberato un posto per te!</h4>
                <p class="mb-1 fs-5 fw-bold text-dark"><?php echo htmlspecialchars($bc['evento_titolo']); ?></p>
                <p class="mb-2 text-secondary fw-semibold">
                    <i class="fa fa-calendar-day me-1"></i><?php echo htmlspecialchars(etichetta_turno($bc)); ?>
                    <?php if (!empty($bc['luogo'])): ?> · <i class="fa fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($bc['luogo']); ?><?php endif; ?>
                    <?php if ((int)$bc['num_posti'] > 1): ?> · <?php echo (int)$bc['num_posti']; ?> posti<?php endif; ?>
                </p>
                <?php if (!empty($bc['scadenza_conferma'])): ?>
                    <div class="alert alert-warning py-2 small fw-bold mb-3"><i class="fa fa-hourglass-half me-1"></i> Conferma entro il <?php echo date('d/m/Y \a\l\l\e H:i', strtotime($bc['scadenza_conferma'])); ?>, altrimenti il posto passerà al prossimo in lista.</div>
                <?php endif; ?>
                <form method="POST" action="area_personale.php" class="d-flex flex-wrap gap-2">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="pr_id" value="<?php echo (int)$bc['id']; ?>">
                    <button type="submit" name="conferma_posto_ok" value="1" class="btn btn-success fw-bold px-4"><i class="fa fa-check me-1"></i>Conferma il mio posto</button>
                    <button type="submit" name="rinuncia_posto" value="1" class="btn btn-outline-secondary fw-bold" onclick="return confirm('Rinunci al posto? Verrà assegnato al prossimo in lista d\'attesa.');"><i class="fa fa-times me-1"></i>Rinuncio</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- MENU A TAB (RESTYLING COLORI) -->
    <ul class="nav nav-pills nav-fill gap-2 p-1 bg-light rounded-pill border mb-4 shadow-sm custom-tabs" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active rounded-pill fw-bold" id="pills-attive-tab" data-bs-toggle="pill" data-bs-target="#pills-attive" type="button" role="tab">
                <i class="fa fa-calendar-check me-1"></i> Prossimi Eventi (<?php echo count($prenotazioni_attive); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-bold" id="pills-passate-tab" data-bs-toggle="pill" data-bs-target="#pills-passate" type="button" role="tab">
                <i class="fa fa-history me-1"></i> Storico & Attestati (<?php echo count($prenotazioni_passate); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-bold" id="pills-sondaggi-tab" data-bs-toggle="pill" data-bs-target="#pills-sondaggi" type="button" role="tab">
                <i class="fa fa-poll me-1"></i> Sondaggi
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill fw-bold" id="pills-profilo-tab" data-bs-toggle="pill" data-bs-target="#pills-profilo" type="button" role="tab">
                <i class="fa fa-user-edit me-1"></i> Profilo
            </button>
        </li>
    </ul>

    <div class="tab-content" id="pills-tabContent">
        
        <!-- TAB 1: PRENOTAZIONI ATTIVE -->
        <div class="tab-pane fade show active" id="pills-attive" role="tabpanel" tabindex="0">
            <?php if (empty($prenotazioni_attive)): ?>
                <div class="alert alert-light text-center border p-5 shadow-sm rounded-4 text-muted">
                    <i class="fa fa-ticket-alt fs-1 d-block mb-3 opacity-50"></i>
                    <h5 class="fw-bold">Nessuna prenotazione futura</h5>
                    <p class="m-0">Non hai ancora prenotato eventi imminenti. Visita la Home per scoprire i prossimi appuntamenti.</p>
                    <a href="index.php" class="btn btn-primary fw-bold mt-3 px-4 shadow-sm rounded-pill">Sfoglia Eventi</a>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($prenotazioni_attive as $pr): ?>
                        <?php
                            $col_p   = colore_valido($pr['colore_primario'] ?? '', '#0056B3');
                            $st      = $pr['stato'];
                            $cd      = countdown_to($pr['data_turno'], $pr['orario_inizio']);
                            $unread  = 0;
                            if (isset($messaggi_per_pr[$pr['id']])) {
                                foreach ($messaggi_per_pr[$pr['id']] as $m) {
                                    if ($m['mittente_tipo'] === 'admin' && $m['letto'] == 0) $unread++;
                                }
                            }
                            $href_annulla = '?cancella_prenotazione=' . $pr['id'] . '&csrf=' . urlencode(csrf_token());
                        ?>
                        <div class="card card-evento-attivo shadow-sm mb-1" style="border-left-color:<?php echo $col_p; ?>;">
                            <div class="card-body p-3 p-md-4">
                                <!-- TOP ROW: titolo + countdown -->
                                <div class="d-flex align-items-start justify-content-between gap-2 mb-2 flex-wrap">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($pr['locandina_path'])): ?>
                                            <img src="<?php echo htmlspecialchars($pr['locandina_path']); ?>" alt="" class="rounded d-none d-sm-block flex-shrink-0" style="width:48px;height:48px;object-fit:cover;">
                                        <?php endif; ?>
                                        <div>
                                            <h5 class="fw-bold m-0 mb-1" style="color:<?php echo $col_p; ?>;"><?php echo htmlspecialchars($pr['evento_titolo']); ?></h5>
                                            <div class="small text-muted fw-bold text-uppercase"><i class="fa fa-layer-group me-1"></i><?php echo htmlspecialchars($pr['pagina_titolo']); ?></div>
                                        </div>
                                    </div>
                                    <?php if ($cd): ?>
                                        <span class="countdown-badge bg-<?php echo $cd['cls']; ?> <?php echo $cd['cls'] === 'warning' ? 'text-dark' : 'text-white'; ?> flex-shrink-0">
                                            <i class="fa fa-clock me-1"></i><?php echo $cd['label']; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <!-- META INFO -->
                                <div class="d-flex flex-wrap gap-3 small fw-semibold text-secondary bg-light p-2 rounded mb-3">
                                    <?php if (!empty($pr['nome_turno'])): ?><span><i class="fa fa-tag text-secondary me-1"></i><?php echo htmlspecialchars($pr['nome_turno']); ?></span><?php endif; ?>
                                    <?php if (!empty($pr['data_turno'])): ?><span><i class="fa fa-calendar-day text-danger me-1"></i><?php echo date('d/m/Y', strtotime($pr['data_turno'])); ?></span><?php endif; ?>
                                    <?php if (!empty($pr['orario_inizio'])): ?><span><i class="fa fa-clock text-primary me-1"></i><?php echo substr($pr['orario_inizio'], 0, 5); ?></span><?php endif; ?>
                                    <?php if (!empty($pr['evento_luogo'])): ?><span><i class="fa fa-map-marker-alt text-success me-1"></i><?php echo htmlspecialchars($pr['evento_luogo']); ?></span><?php endif; ?>
                                    <span><i class="fa fa-hashtag text-secondary me-1"></i>Ticket: <strong class="text-dark font-monospace"><?php echo $pr['codice_prenotazione']; ?></strong></span>
                                </div>

                                <!-- STATO + AZIONI -->
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                    <div>
                                        <?php
                                        if ($st === 'in_attesa') {
                                            echo '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-clock me-1" aria-hidden="true"></i>Lista d\'Attesa</span>';
                                            if (isset($pos_attesa[(int)$pr['id']])) {
                                                $pa = $pos_attesa[(int)$pr['id']];
                                                echo ' <span class="badge bg-light text-dark border px-3 py-2" title="Persone in lista d\'attesa per questo turno: ' . $pa['totale'] . '">'
                                                   . ($pa['posizione'] === 1 ? 'Sei il prossimo in lista!' : 'Sei ' . $pa['posizione'] . '° in lista')
                                                   . ' <span class="fw-normal text-muted">su ' . $pa['totale'] . '</span></span>';
                                            }
                                        }
                                        elseif ($st === 'richiesta_conferma') echo '<span class="badge bg-warning text-dark px-3 py-2"><i class="fa fa-bell me-1"></i>Posto disponibile</span> <a href="area_personale.php?conferma_posto=' . (int)$pr['id'] . '" class="btn btn-success btn-sm fw-bold ms-1"><i class="fa fa-check me-1"></i>Conferma ora</a>';
                                        elseif ($st === 'da_approvare') echo '<span class="badge bg-info text-dark px-3 py-2"><i class="fa fa-hourglass-half me-1"></i>In Valutazione</span>';
                                        elseif ($st === 'rifiutata')  echo '<span class="badge bg-secondary px-3 py-2"><i class="fa fa-times me-1"></i>Rifiutata</span>';
                                        elseif ($st === 'annullata')  echo '<span class="badge bg-danger px-3 py-2"><i class="fa fa-ban me-1"></i>Annullata</span>';
                                        else                          echo '<span class="badge bg-success px-3 py-2"><i class="fa fa-check-circle me-1"></i>Confermata</span>';
                                        ?>
                                    </div>
                                    <div class="azioni-desktop">
                                        <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modChatStudente<?php echo $pr['id']; ?>">
                                            <i class="fa fa-comments me-1"></i>Assistenza<?php if ($unread > 0): ?> <span class="badge bg-danger"><?php echo $unread; ?></span><?php endif; ?>
                                        </button>
                                        <?php if ($st !== 'rifiutata'): ?>
                                        <a href="stampa_ricevuta.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="btn btn-outline-dark btn-sm fw-bold">
                                            <i class="fa fa-file-pdf me-1"></i>Ricevuta&nbsp;/&nbsp;QR
                                        </a>
                                        <?php endif; ?>
                                        <?php if ((int)$pr['presente'] === 1 && $st === 'confermata'): ?>
                                        <a href="stampa_attestato.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="btn btn-success btn-sm fw-bold" style="background:#198754;border:none;">
                                            <i class="fa fa-graduation-cap me-1"></i>Attestato
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($st !== 'annullata' && $st !== 'rifiutata'): ?>
                                        <button type="button" class="btn btn-sm fw-bold text-white" style="background:<?php echo $col_p; ?>;border:none;" data-bs-toggle="modal" data-bs-target="#modEditUser<?php echo $pr['id']; ?>">
                                            <i class="fa fa-edit me-1"></i>Modifica
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm fw-bold"
                                            data-href="<?php echo htmlspecialchars($href_annulla); ?>"
                                            data-titolo="<?php echo htmlspecialchars($pr['evento_titolo']); ?>"
                                            onclick="apriFinestraAnnulla(this)">
                                            <i class="fa fa-times me-1"></i>Annulla
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- MODALE CHAT STUDENTE -->
                        <div class="modal fade" id="modChatStudente<?php echo $pr['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content shadow-lg border-0">
                                    <div class="modal-header py-3 bg-secondary text-white">
                                        <h6 class="modal-title fw-bold"><i class="fa fa-comments me-2"></i> Assistenza Segreteria - <?php echo htmlspecialchars($pr['evento_titolo']); ?></h6>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-0 bg-light text-start">
                                        <div class="p-4" style="max-height: 400px; overflow-y: auto; background: #f8fafc;">
                                            <?php 
                                            $chat_msgs = $messaggi_per_pr[$pr['id']] ?? [];
                                            if(empty($chat_msgs)): 
                                            ?>
                                                <div class="text-center text-muted my-4 small">
                                                    <i class="fa fa-comment-slash fs-3 mb-2 opacity-50 d-block"></i>
                                                    Hai bisogno di informazioni su questo evento? Invia un messaggio alla segreteria.
                                                </div>
                                            <?php else: ?>
                                                <?php foreach($chat_msgs as $msg): ?>
                                                    <?php if($msg['mittente_tipo'] === 'utente'): ?>
                                                        <div class="d-flex justify-content-end mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted text-end mb-1" style="font-size: 0.7rem;">Tu - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <div class="p-2 rounded-3 text-dark shadow-sm bg-white border" style="border-bottom-right-radius: 0 !important;">
                                                                    <?php echo nl2br(htmlspecialchars($msg['messaggio'])); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="d-flex justify-content-start mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted mb-1" style="font-size: 0.7rem;">Segreteria - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <?php $col_bolla = colore_valido($pr['colore_primario'] ?? '', '#B80000'); ?><div class="p-2 rounded-3 shadow-sm" style="background-color: <?php echo $col_bolla; ?>; color: <?php echo colore_testo_su($col_bolla); ?>; border-bottom-left-radius: 0 !important;">
                                                                    <?php echo strip_tags($msg['messaggio'], '<b><strong><i><em><u><br><p><ul><ol><li><span>'); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <form method="POST" class="border-top p-3 bg-white">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="prenotazione_id" value="<?php echo $pr['id']; ?>">
                                            <label class="form-label small fw-bold text-primary">Invia un messaggio</label>
                                            <textarea name="corpo_messaggio" class="form-control" rows="3" placeholder="Scrivi qui la tua richiesta..." required></textarea>
                                            <div class="text-end mt-3">
                                                <button type="submit" name="invia_messaggio_utente" class="btn btn-primary fw-bold px-4 shadow-sm"><i class="fa fa-paper-plane me-1"></i> Invia</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- MODALE MODIFICA UTENTE -->
                        <div class="modal fade" id="modEditUser<?php echo $pr['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content shadow-lg border-0" style="border-radius: 12px;">
                                    <form method="POST">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="prenotazione_id" value="<?php echo $pr['id']; ?>">
                                        <input type="hidden" name="edit_prenotazione_utente" value="1">
                                        <div class="modal-header py-3 text-white" style="background-color: <?php echo $col_p; ?>; border-radius: 12px 12px 0 0;">
                                            <h6 class="modal-title fw-bold m-0"><i class="fa fa-edit me-2"></i> Modifica Prenotazione: <?php echo htmlspecialchars($pr['evento_titolo']); ?></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body text-start p-4">
                                            
                                            <div class="mb-4 p-3 border rounded shadow-sm bg-light">
                                                <label class="form-label small fw-bold text-dark"><i class="fa fa-exchange-alt me-1"></i> Modifica Orario / Turno (Opzionale)</label>
                                                <select name="nuovo_turno_id" class="form-select border-primary fw-bold">
                                                    <?php
                                                    foreach ($pr['turni_alternativi'] as $ta) {
                                                        $sel = ($ta['id'] == $pr['turno_id']) ? 'selected' : '';
                                                        $lbl_ta = htmlspecialchars(etichetta_turno($ta));

                                                        if ($ta['id'] == $pr['turno_id']) {
                                                            echo "<option value='{$ta['id']}' selected>📅 $lbl_ta — [Il tuo turno attuale]</option>";
                                                        } elseif (!$ta['is_closed']) {
                                                            if ($ta['posti_liberi'] >= $pr['num_posti']) {
                                                                echo "<option value='{$ta['id']}'>📅 $lbl_ta — ✅ Disponibile ({$ta['posti_liberi']} posti)</option>";
                                                            } else {
                                                                echo "<option value='{$ta['id']}'>📅 $lbl_ta — ⏳ Esaurito (Finirai in Lista d'Attesa)</option>";
                                                            }
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </div>

                                            <div class="row g-3 mb-3">
                                                <div class="col-md-6"><label class="form-label small fw-bold">Nome</label><input type="text" name="nome" class="form-control" value="<?php echo htmlspecialchars($pr['nome']); ?>" required></div>
                                                <div class="col-md-6"><label class="form-label small fw-bold">Cognome</label><input type="text" name="cognome" class="form-control" value="<?php echo htmlspecialchars($pr['cognome']); ?>" required></div>
                                                <div class="col-md-6"><label class="form-label small fw-bold">Email</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($pr['email']); ?>" required></div>
                                                <div class="col-md-6"><label class="form-label small fw-bold">Matricola</label><input type="text" name="matricola" class="form-control" value="<?php echo htmlspecialchars($pr['matricola']); ?>"></div>
                                            </div>
                                            
                                            <?php 
                                                $json_c = json_decode($pr['dati_custom_json'] ?? '', true) ?: [];
                                                $res_cf = $conn->query("SELECT * FROM campi_form WHERE (pagina_id = {$pr['p_id']} AND (evento_id IS NULL OR evento_id = 0)) OR evento_id = {$pr['evento_id']} ORDER BY ordine ASC, id ASC");
                                                if ($res_cf && $res_cf->num_rows > 0):
                                            ?>
                                                <div class="border-top pt-3 mt-4">
                                                    <h6 class="fw-bold text-primary mb-3"><i class="fa fa-list-check me-1"></i> Le tue risposte aggiuntive:</h6>
                                                    <div class="row g-3">
                                                    <?php while ($cf = $res_cf->fetch_assoc()): ?>
                                                        <?php 
                                                            $input_name = htmlspecialchars($cf['nome_campo']);
                                                            $val_c = $json_c[$input_name] ?? '';
                                                        ?>
                                                        <div class="col-md-6">
                                                            <label class="form-label small fw-bold mb-1"><?php echo htmlspecialchars($cf['etichetta']); ?></label>
                                                            <?php if (strpos($val_c, 'uploads/allegati_prenotazioni/') !== false): ?>
                                                                <div class="p-2 border rounded bg-light text-muted small">
                                                                    <i class="fa fa-file-pdf text-danger me-1"></i> File allegato. Impossibile modificarlo da qui. Se occorre cambiarlo, contatta la segreteria.
                                                                </div>
                                                            <?php else: ?>
                                                                <input type="text" name="custom_<?php echo $input_name; ?>" class="form-control" value="<?php echo htmlspecialchars($val_c); ?>">
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endwhile; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer py-2 bg-light border-top-0" style="border-radius: 0 0 12px 12px;">
                                            <button type="button" class="btn btn-secondary fw-bold px-4" data-bs-dismiss="modal">Chiudi</button>
                                            <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm" style="background-color: <?php echo $col_p; ?>; border:none;" onclick="return confirm('Sei sicuro di voler salvare queste modifiche?');"><i class="fa fa-save me-1"></i> Salva Modifiche</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 2: STORICO E ATTESTATI -->
        <div class="tab-pane fade" id="pills-passate" role="tabpanel" tabindex="0">
            <?php if (empty($prenotazioni_passate)): ?>
                <div class="alert alert-light text-center border p-5 shadow-sm rounded-4 text-muted">
                    <i class="fa fa-history fs-1 d-block mb-3 opacity-50"></i>
                    <h5 class="fw-bold">Nessun evento passato</h5>
                    <p class="m-0">Il tuo storico delle attività è vuoto.</p>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($prenotazioni_passate as $pr): ?>
                        <?php
                            $st      = empty($pr['stato']) ? 'confermata' : $pr['stato'];
                            $presente = (int)($pr['presente'] ?? 0);
                            $col_p   = $presente === 1 ? '#198754' : '#6c757d';
                            $unread  = 0;
                            if (isset($messaggi_per_pr[$pr['id']])) {
                                foreach ($messaggi_per_pr[$pr['id']] as $m) {
                                    if ($m['mittente_tipo'] === 'admin' && $m['letto'] == 0) $unread++;
                                }
                            }
                        ?>
                        <div class="card card-evento-passato shadow-sm" style="border-left-color:<?php echo $col_p; ?>;">
                            <div class="card-body p-3 p-md-4">
                                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
                                    <div>
                                        <h5 class="fw-bold text-dark m-0 mb-1"><?php echo htmlspecialchars($pr['evento_titolo']); ?></h5>
                                        <div class="small text-muted fw-bold text-uppercase"><i class="fa fa-layer-group me-1"></i><?php echo htmlspecialchars($pr['pagina_titolo']); ?></div>
                                    </div>
                                    <?php if ($presente === 1): ?>
                                        <div class="presenza-grande bg-success bg-opacity-10 text-success">
                                            <i class="fa fa-user-check"></i> Presenza registrata
                                        </div>
                                    <?php else: ?>
                                        <div class="presenza-grande bg-secondary bg-opacity-10 text-secondary">
                                            <i class="fa fa-user-times"></i> Assente
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex flex-wrap gap-3 small fw-semibold text-secondary mb-3">
                                    <span><i class="fa fa-calendar-day me-1"></i><?php echo htmlspecialchars(etichetta_turno($pr)); ?></span>
                                    <span class="font-monospace"><i class="fa fa-hashtag me-1"></i><?php echo $pr['codice_prenotazione']; ?></span>
                                </div>

                                <div class="azioni-desktop">
                                    <button type="button" class="btn btn-outline-secondary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#modChatStudente<?php echo $pr['id']; ?>">
                                        <i class="fa fa-comments me-1"></i>Assistenza<?php if ($unread > 0): ?> <span class="badge bg-danger"><?php echo $unread; ?></span><?php endif; ?>
                                    </button>
                                    <?php if ($st !== 'rifiutata'): ?>
                                    <a href="stampa_ricevuta.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="btn btn-outline-dark btn-sm fw-bold">
                                        <i class="fa fa-file-pdf me-1"></i>Ricevuta
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($presente === 1 && in_array($st, ['confermata','confermato','confirmed'])): ?>
                                    <a href="stampa_attestato.php?code=<?php echo urlencode($pr['codice_prenotazione']); ?>" target="_blank" class="btn btn-success btn-sm fw-bold" style="background:#198754;border:none;">
                                        <i class="fa fa-graduation-cap me-1"></i>Scarica Attestato
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- MODALE CHAT STUDENTE -->
                        <div class="modal fade" id="modChatStudente<?php echo $pr['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content shadow-lg border-0">
                                    <div class="modal-header py-3 bg-secondary text-white">
                                        <h6 class="modal-title fw-bold"><i class="fa fa-comments me-2"></i> Assistenza Segreteria - <?php echo htmlspecialchars($pr['evento_titolo']); ?></h6>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-0 bg-light text-start">
                                        <div class="p-4" style="max-height: 400px; overflow-y: auto; background: #f8fafc;">
                                            <?php 
                                            $chat_msgs = $messaggi_per_pr[$pr['id']] ?? [];
                                            if(empty($chat_msgs)): 
                                            ?>
                                                <div class="text-center text-muted my-4 small">
                                                    <i class="fa fa-comment-slash fs-3 mb-2 opacity-50 d-block"></i>
                                                    Hai bisogno di informazioni su questo evento? Invia un messaggio alla segreteria.
                                                </div>
                                            <?php else: ?>
                                                <?php foreach($chat_msgs as $msg): ?>
                                                    <?php if($msg['mittente_tipo'] === 'utente'): ?>
                                                        <div class="d-flex justify-content-end mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted text-end mb-1" style="font-size: 0.7rem;">Tu - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <div class="p-2 rounded-3 text-dark shadow-sm bg-white border" style="border-bottom-right-radius: 0 !important;">
                                                                    <?php echo nl2br(htmlspecialchars($msg['messaggio'])); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="d-flex justify-content-start mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted mb-1" style="font-size: 0.7rem;">Segreteria - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <?php $col_bolla = colore_valido($pr['colore_primario'] ?? '', '#B80000'); ?><div class="p-2 rounded-3 shadow-sm" style="background-color: <?php echo $col_bolla; ?>; color: <?php echo colore_testo_su($col_bolla); ?>; border-bottom-left-radius: 0 !important;">
                                                                    <?php echo strip_tags($msg['messaggio'], '<b><strong><i><em><u><br><p><ul><ol><li><span>'); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <form method="POST" class="border-top p-3 bg-white">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="prenotazione_id" value="<?php echo $pr['id']; ?>">
                                            <label class="form-label small fw-bold text-primary">Invia un messaggio</label>
                                            <textarea name="corpo_messaggio" class="form-control" rows="3" placeholder="Scrivi qui la tua richiesta..." required></textarea>
                                            <div class="text-end mt-3">
                                                <button type="submit" name="invia_messaggio_utente" class="btn btn-primary fw-bold px-4 shadow-sm"><i class="fa fa-paper-plane me-1"></i> Invia</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>

                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 3: SONDAGGI (AGGIORNATO AL MOTORE INTERNO) -->
        <div class="tab-pane fade" id="pills-sondaggi" role="tabpanel" tabindex="0">
            <?php 

                $sondaggi_disponibili = 0;
                $eventi_con_sondaggio = [];
                
                foreach ($prenotazioni_passate as $pr) {
                    $ev_id = (int)$pr['evento_id'];
                    $pr_id = (int)$pr['id'];
                    
                    $stato = empty($pr['stato']) ? 'confermata' : $pr['stato']; 
                    $presente = (int)$pr['presente'];
                    $sond_completato = (int)($pr['sondaggio_completato'] ?? 0);

                    if ($stato === 'confermata' && $sond_completato === 0) {
                        $res_sond = $conn->query("SELECT id FROM sondaggi WHERE evento_id = $ev_id AND attivo = 1 LIMIT 1");
                        if ($res_sond && $res_sond->num_rows > 0) {
                            $abilita_presenze = (int)($pr['abilita_presenze'] ?? 1);
                            
                            if ($presente === 1 || $abilita_presenze === 0) {
                                $token_sond = $pr['token_sondaggio'] ?? '';
                                if (empty($token_sond)) {
                                    $token_sond = bin2hex(random_bytes(16));
                                    $conn->query("UPDATE prenotazioni SET token_sondaggio = '$token_sond' WHERE id = $pr_id");
                                }

                                if (!isset($eventi_con_sondaggio[$ev_id])) {
                                    $eventi_con_sondaggio[$ev_id] = [
                                        'titolo' => $pr['evento_titolo'],
                                        'link' => "sondaggio.php?token=" . $token_sond,
                                        'data' => $pr['data_turno']
                                    ];
                                    $sondaggi_disponibili++;
                                }
                            }
                        }
                    }
                }
            ?>

            <?php if ($sondaggi_disponibili === 0): ?>
                <div class="alert alert-light text-center border p-5 shadow-sm rounded-4 text-muted">
                    <i class="fa fa-poll-h fs-1 d-block mb-3 opacity-50"></i>
                    <h5 class="fw-bold">Nessun sondaggio attivo</h5>
                    <p class="m-0">Non ci sono questionari da compilare al momento per gli eventi a cui hai partecipato (oppure li hai già completati tutti).</p>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <div class="alert alert-info border-0 border-start border-5 border-info shadow-sm mb-2">
                        <i class="fa fa-info-circle me-2"></i> La tua opinione è importante! Compila i sondaggi di gradimento per gli eventi a cui hai partecipato.
                    </div>
                    <?php foreach ($eventi_con_sondaggio as $sondaggio): ?>
                        <div class="card shadow-sm border-0 overflow-hidden" style="border-radius: 12px; border-left: 6px solid #0dcaf0 !important;">
                            <div class="card-body p-4 d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                                <div>
                                    <h5 class="fw-bold text-dark m-0 mb-1"><?php echo htmlspecialchars($sondaggio['titolo']); ?></h5>
                                    <?php if (!empty($sondaggio['data'])): ?><span class="text-muted small fw-bold"><i class="fa fa-calendar-day me-1"></i> Evento del: <?php echo date('d/m/Y', strtotime($sondaggio['data'])); ?></span><?php endif; ?>
                                </div>
                                <div>
                                    <a href="<?php echo htmlspecialchars($sondaggio['link']); ?>" target="_blank" class="btn btn-info text-white fw-bold shadow-sm px-4 rounded-pill">
                                        <i class="fa fa-edit me-1"></i> Compila il Sondaggio
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- TAB 4: PROFILO -->
        <div class="tab-pane fade" id="pills-profilo" role="tabpanel" tabindex="0">
            <?php $ruoli_label = [1=>'Amministratore',2=>'Gestore',3=>'Studente',4=>'Dipendente',5=>'Esterno']; ?>

            <!-- Dati anagrafici -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="fa fa-id-card me-2 text-secondary"></i>Dati personali</div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Nome e cognome</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars(trim(($user_info['nome'] ?? '') . ' ' . ($user_info['cognome'] ?? ''))); ?></dd>

                        <dt class="col-sm-4 text-muted">Codice fiscale</dt>
                        <dd class="col-sm-8 font-monospace"><?php echo htmlspecialchars($user_info['codice_fiscale'] ?? '—'); ?></dd>

                        <dt class="col-sm-4 text-muted">Ruolo</dt>
                        <dd class="col-sm-8">
                            <?php
                            $label_ruolo = $ruoli_label[$u_ruolo] ?? 'Sconosciuto';
                            $badge_color = ['Amministratore'=>'danger','Gestore'=>'warning text-dark','Studente'=>'primary','Dipendente'=>'success','Esterno'=>'secondary'];
                            $bc = $badge_color[$label_ruolo] ?? 'secondary';
                            echo '<span class="badge bg-' . $bc . '">' . htmlspecialchars($label_ruolo) . '</span>';
                            if (!empty($user_info['ruoli_secondari'])) {
                                foreach (explode(',', $user_info['ruoli_secondari']) as $rs) {
                                    $rs = trim($rs);
                                    if ($rs !== '' && isset($ruoli_label[(int)$rs])) {
                                        $lrs = $ruoli_label[(int)$rs];
                                        $brs = $badge_color[$lrs] ?? 'secondary';
                                        echo ' <span class="badge bg-' . $brs . ' opacity-75">' . htmlspecialchars($lrs) . '</span>';
                                    }
                                }
                            }
                            ?>
                        </dd>

                        <?php if (!empty($user_info['matricola_studente'])): ?>
                        <dt class="col-sm-4 text-muted">Matricola studente</dt>
                        <dd class="col-sm-8 font-monospace"><?php echo htmlspecialchars($user_info['matricola_studente']); ?></dd>
                        <?php endif; ?>

                        <?php if (!empty($user_info['matricola_dipendente'])): ?>
                        <dt class="col-sm-4 text-muted">Matricola dipendente</dt>
                        <dd class="col-sm-8 font-monospace"><?php echo htmlspecialchars($user_info['matricola_dipendente']); ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Email -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header fw-bold"><i class="fa fa-envelope me-2 text-secondary"></i>Indirizzo email</div>
                <div class="card-body">
                    <p class="mb-3">Email attuale:
                        <?php if (!empty($user_info['email'])): ?>
                            <strong><?php echo htmlspecialchars($user_info['email']); ?></strong>
                        <?php else: ?>
                            <span class="text-muted fst-italic">non impostata</span>
                        <?php endif; ?>
                    </p>
                    <p class="text-muted small mb-3">
                        <i class="fa fa-info-circle me-1"></i>
                        Questa email viene usata per le notifiche di conferma, promemoria e comunicazioni relative alle tue prenotazioni.
                        L'email fornita dall'Università al momento del login viene salvata automaticamente; puoi sovrascriverla qui se preferisci usarne un'altra.
                    </p>
                    <form method="POST" novalidate>
                        <?php csrf_field(); ?>
                        <input type="hidden" name="aggiorna_email" value="1">
                        <div class="input-group">
                            <input type="email" name="nuova_email" class="form-control"
                                   placeholder="nuova@email.it"
                                   value="<?php echo htmlspecialchars($user_info['email'] ?? ''); ?>"
                                   required aria-label="Nuovo indirizzo email">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i> Salva</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- MODALE GLOBALE CONFERMA ANNULLAMENTO -->
<div class="modal fade" id="modConfermaAnnulla" tabindex="-1" aria-labelledby="modAnnullaLabel">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-danger shadow-lg" style="border-radius:14px;">
            <div class="modal-header bg-danger text-white py-2" style="border-radius:14px 14px 0 0;">
                <h6 class="modal-title fw-bold" id="modAnnullaLabel"><i class="fa fa-exclamation-triangle me-2"></i>Conferma annullamento</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-3">
                <p class="mb-1 fw-bold" id="modAnnullaEv"></p>
                <p class="text-muted small mb-0">Perderai il posto e verrà inviata notifica ai gestori. Operazione irreversibile.</p>
            </div>
            <div class="modal-footer py-2 justify-content-center gap-2">
                <button class="btn btn-secondary btn-sm fw-bold px-4" data-bs-dismiss="modal">No, torna</button>
                <a id="modAnnullaBtn" href="#" class="btn btn-danger btn-sm fw-bold px-4"><i class="fa fa-times me-1"></i>Sì, annulla</a>
            </div>
        </div>
    </div>
</div>

<script>
function apriFinestraAnnulla(btn) {
    document.getElementById('modAnnullaEv').textContent = btn.dataset.titolo;
    document.getElementById('modAnnullaBtn').href = btn.dataset.href;
    new bootstrap.Modal(document.getElementById('modConfermaAnnulla')).show();
}
// Auto-scroll alla tab storico se si ritorna dopo un'azione
(function(){
    var hash = window.location.hash;
    var tabMap = { '#storico': 'pills-passate-tab', '#sondaggi': 'pills-sondaggi-tab', '#profilo': 'pills-profilo-tab' };
    if (tabMap[hash]) {
        var t = document.getElementById(tabMap[hash]);
        if (t) { bootstrap.Tab.getOrCreateInstance(t).show(); }
    }
})();
</script>

<?php require_once 'footer.php'; ?>
