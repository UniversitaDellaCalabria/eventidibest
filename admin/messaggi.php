<?php
// admin/messaggi.php - Inbox Centralizzata Stile Ticket (Filtrata per Area)
require_once 'admin_header.php';

// Controllo Permessi
if (!$can_manage_iscritti) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato.</div>";
    require_once 'admin_footer.php';
    exit;
}

function admin_redirect($url) {
    echo "<script>window.location.replace('$url');</script>";
    exit;
}

// 1. INVIO RISPOSTA DALLA INBOX E MARCATURA COME LETTO
if (isset($_POST['invia_risposta_inbox'])) {
    $pr_id = (int)$_POST['prenotazione_id'];
    $email_dest = trim($_POST['email_destinatario']);
    $ev_titolo = trim($_POST['evento_titolo']);
    $messaggio_raw = trim($_POST['corpo_messaggio']);
    $admin_id = $_SESSION['utente_id'] ?? 0;

    // Sanitizzazione: mantieni solo tag HTML sicuri, rimuovi script e attributi pericolosi
    $tag_consentiti = '<b><strong><i><em><u><br><p><ul><ol><li><span>';
    $messaggio_html = strip_tags($messaggio_raw, $tag_consentiti);

    if ($pr_id > 0 && !empty($messaggio_html)) {
        // Segna come letti tutti i messaggi precedenti dell'utente in questa chat
        $conn->query("UPDATE messaggi_prenotazioni SET letto = 1 WHERE prenotazione_id = $pr_id AND mittente_tipo = 'utente'");

        // Salva la risposta dell'Admin
        $stmt_msg = $conn->prepare("INSERT INTO messaggi_prenotazioni (prenotazione_id, mittente_tipo, mittente_id, messaggio, letto) VALUES (?, 'admin', ?, ?, 1)");
        $stmt_msg->bind_param("iis", $pr_id, $admin_id, $messaggio_html);
        $stmt_msg->execute();

        // Notifica Email
        // Notifica Email
        if (!empty($email_dest)) {
            $url_area = "https://dibest2.unical.it/eventi/area_personale.php";
            $oggetto = "Nuova risposta - Evento: " . $ev_titolo;
            $body_mail = "<p>L'amministrazione ha risposto al tuo messaggio per l'evento <strong>$ev_titolo</strong>:</p>
                          <div style='background:#f8fafc; padding:15px; border-left:4px solid #B80000; margin-bottom:20px; font-style:italic;'>
                            $messaggio_html
                          </div>
                          <p><a href='$url_area' style='background-color:#B80000; color:#ffffff; padding:12px 25px; text-decoration:none; border-radius:6px; display:inline-block; font-weight:bold; font-family:sans-serif;'>Vai all'Area Personale</a></p>";
            inviaNotificaEmail($email_dest, $oggetto, $body_mail, $conn);
        }
        flash_set("✅ Risposta inviata con successo.");
    }
    // Mantiene l'area di lavoro corrente dopo il redirect
    admin_redirect("messaggi.php?p_id=$filtro_p");
}

// 2. AZIONE RAPIDA: SEGNA COME LETTO SENZA RISPONDERE
if (isset($_GET['segna_letto'])) {
    $pr_id = (int)$_GET['segna_letto'];
    $conn->query("UPDATE messaggi_prenotazioni SET letto = 1 WHERE prenotazione_id = $pr_id AND mittente_tipo = 'utente'");
    admin_redirect("messaggi.php?p_id=$filtro_p");
}

// 3. ESTRAZIONE DI TUTTE LE CONVERSAZIONI (Filtrate per Area e Permessi)
$pr_filter_sql = (!$is_full_admin && !$can_manage_iscritti) ? " AND FIND_IN_SET($u_id_curr, e.gestori_utenti_ids) > 0 " : "";

// Aggiunto e.pagina_id = $filtro_p per limitare i messaggi all'area selezionata
$sql_inbox = "SELECT 
                p.id as prenotazione_id, p.codice_prenotazione, p.nome, p.cognome, p.email,
                e.titolo as evento_titolo, e.pagina_id,
                MAX(m.data_invio) as ultimo_messaggio_data,
                COUNT(m.id) as totale_messaggi,
                SUM(CASE WHEN m.letto = 0 AND m.mittente_tipo = 'utente' THEN 1 ELSE 0 END) as messaggi_da_leggere
              FROM messaggi_prenotazioni m
              JOIN prenotazioni p ON m.prenotazione_id = p.id
              JOIN turni t ON p.turno_id = t.id
              JOIN eventi e ON t.evento_id = e.id
              WHERE e.pagina_id = $filtro_p $pr_filter_sql
              GROUP BY p.id
              ORDER BY messaggi_da_leggere DESC, ultimo_messaggio_data DESC";

$conversazioni = [];
$res_inbox = $conn->query($sql_inbox);
if ($res_inbox) {
    while ($row = $res_inbox->fetch_assoc()) {
        $conversazioni[] = $row;
    }
}
?>

<h4 class="fw-bold text-dark mb-4"><i class="fa fa-envelope-open-text text-danger me-2"></i> Tutti i Messaggi (Inbox)</h4>

<?php echo flash_html(); ?>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100 m-0">
                <thead class="table-dark">
                    <tr>
                        <th class="py-3 px-4">Evento / Richiesta</th>
                        <th>Utente</th>
                        <th>Ultimo Aggiornamento</th>
                        <th>Stato Messaggi</th>
                        <th class="text-end px-4">Azione</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($conversazioni)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fa fa-check-double fs-2 d-block mb-2 opacity-50"></i> Nessuna conversazione presente in questa Area.</td></tr>
                    <?php else: ?>
                        <?php foreach($conversazioni as $conv): 
                            $is_unread = $conv['messaggi_da_leggere'] > 0;
                            $row_class = $is_unread ? 'table-warning font-weight-bold' : '';
                        ?>
                            <tr class="<?php echo $row_class; ?>">
                                <td class="py-3 px-4">
                                    <span class="d-block fw-bold text-danger mb-1"><?php echo htmlspecialchars($conv['evento_titolo']); ?></span>
                                    <span class="badge bg-dark fw-normal font-monospace">Ticket: <?php echo $conv['codice_prenotazione']; ?></span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($conv['nome'] . ' ' . $conv['cognome']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($conv['email']); ?></small>
                                </td>
                                <td>
                                    <span class="d-block"><i class="fa fa-clock text-secondary me-1"></i> <?php echo date('d/m/Y H:i', strtotime($conv['ultimo_messaggio_data'])); ?></span>
                                </td>
                                <td>
                                    <?php if ($is_unread): ?>
                                        <span class="badge bg-danger rounded-pill shadow-sm py-1 px-2">
                                            <i class="fa fa-envelope me-1"></i> <?php echo $conv['messaggi_da_leggere']; ?> da leggere
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border py-1 px-2">
                                            <i class="fa fa-envelope-open me-1"></i> Letti (Tot. <?php echo $conv['totale_messaggi']; ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end px-4">
                                    <?php if ($is_unread): ?>
                                        <a href="?segna_letto=<?php echo $conv['prenotazione_id']; ?>&p_id=<?php echo $filtro_p; ?>" class="btn btn-sm btn-outline-secondary me-1" title="Segna come già letto senza aprire"><i class="fa fa-check-double"></i></a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-dark btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modChatInbox<?php echo $conv['prenotazione_id']; ?>">
                                        <i class="fa fa-book-reader me-1"></i> Leggi / Rispondi
                                    </button>
                                </td>
                            </tr>

                            <!-- MODALE CHAT INBOX -->
                            <div class="modal fade" id="modChatInbox<?php echo $conv['prenotazione_id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content shadow-lg border-0">
                                        <div class="modal-header py-3 bg-dark text-white">
                                            <h6 class="modal-title fw-bold"><i class="fa fa-comments me-2"></i> Chat con: <?php echo htmlspecialchars($conv['nome'] . ' ' . $conv['cognome']); ?></h6>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-0 bg-light">
                                            <div class="p-4" style="max-height: 450px; overflow-y: auto; background: #f8fafc;">
                                                <?php 
                                                // Estraiamo i messaggi di QUESTA conversazione
                                                $pr_id_chat = $conv['prenotazione_id'];
                                                $res_chat = $conn->query("SELECT * FROM messaggi_prenotazioni WHERE prenotazione_id = $pr_id_chat ORDER BY data_invio ASC");
                                                while ($msg = $res_chat->fetch_assoc()):
                                                ?>
                                                    <?php if($msg['mittente_tipo'] === 'admin'): ?>
                                                        <div class="d-flex justify-content-end mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted text-end mb-1" style="font-size: 0.7rem;">Tu (Admin) - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <div class="p-2 rounded-3 text-white shadow-sm" style="background-color: #B80000; border-bottom-right-radius: 0 !important;">
                                                                    <?php echo strip_tags($msg['messaggio'], '<b><strong><i><em><u><br><p><ul><ol><li><span>'); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="d-flex justify-content-start mb-3">
                                                            <div style="max-width: 80%;">
                                                                <div class="small text-muted mb-1" style="font-size: 0.7rem;"><?php echo htmlspecialchars($conv['nome']); ?> - <?php echo date('d/m/Y H:i', strtotime($msg['data_invio'])); ?></div>
                                                                <div class="p-2 rounded-3 text-dark shadow-sm bg-white border" style="border-bottom-left-radius: 0 !important;">
                                                                    <?php echo nl2br(htmlspecialchars($msg['messaggio'])); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endwhile; ?>
                                            </div>
                                            
                                            <form method="POST" class="border-top p-3 bg-white">
                                                <input type="hidden" name="prenotazione_id" value="<?php echo $conv['prenotazione_id']; ?>">
                                                <input type="hidden" name="email_destinatario" value="<?php echo htmlspecialchars($conv['email']); ?>">
                                                <input type="hidden" name="evento_titolo" value="<?php echo htmlspecialchars($conv['evento_titolo']); ?>">
                                                <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
                                                
                                                <label class="form-label small fw-bold text-dark">Invia Risposta all'Utente</label>
                                                <textarea name="corpo_messaggio" class="form-control editor-html" rows="3" placeholder="Scrivi una risposta qui..." required></textarea>
                                                
                                                <div class="d-flex justify-content-between align-items-center mt-3">
                                                    <small class="text-muted"><i class="fa fa-info-circle me-1"></i> Rispondendo, il ticket verrà automaticamente segnato come letto.</small>
                                                    <button type="submit" name="invia_risposta_inbox" onclick="tinymce.triggerSave();" class="btn btn-primary fw-bold px-4 shadow-sm" style="background-color: #B80000; border: none;">
                                                        <i class="fa fa-paper-plane me-1"></i> Invia Risposta
                                                    </button>
                                                </div>
                                            </form>

                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'admin_footer.php'; ?>
