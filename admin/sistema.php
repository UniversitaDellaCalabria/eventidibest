<?php
// sistema.php - Configurazione Sistema Email, SMTP e Promemoria
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

// ==============================================================================
// BLOCCO ELABORAZIONE AZIONI BACKEND (GET / POST)
// ==============================================================================

// 1. ESECUZIONE MANUALE PROMEMORIA (CRON)
if (isset($_POST['run_reminders_manual'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Esecuzione Manuale Promemoria Email", ["Finestra_Ore" => 72]);
    admin_redirect("cron_reminders.php?manual=1");
}

// 1b. EMAIL DI PROVA: verifica la configurazione SMTP mostrando l'errore esatto del server
if (isset($_POST['invia_email_test'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $dest_test = trim($_POST['email_test'] ?? '');
    $ok_test = inviaNotificaEmail($dest_test, "Email di prova - Eventi DiBEST", "<p>Questa è un'email di prova inviata dal pannello <strong>Sistema Email</strong> il " . date('d/m/Y H:i') . ".</p><p>Se la ricevi, la configurazione SMTP funziona.</p>", $conn);
    if ($ok_test) flash_set("Email di prova accettata dal server per <strong>" . htmlspecialchars($dest_test) . "</strong>. Se non arriva entro qualche minuto controlla lo spam.");
    else flash_set("Invio fallito: " . htmlspecialchars($GLOBALS['ultimo_errore_email'] ?: 'errore sconosciuto'), 'danger');
    admin_redirect("sistema.php?p_id=$filtro_p#log-email");
}

// 2. SALVATAGGIO CONFIGURAZIONI SMTP E TEMPLATE
if (isset($_POST['save_system_settings'])) {
    csrf_verify($_POST['csrf_token'] ?? '');
    $host   = $conn->real_escape_string($_POST['smtp_host'] ?? '');
    $port   = (int)($_POST['smtp_port'] ?? 587);
    $user   = $conn->real_escape_string($_POST['smtp_username'] ?? '');
    $pass   = $conn->real_escape_string($_POST['smtp_password'] ?? '');
    $sec    = $conn->real_escape_string($_POST['smtp_secure'] ?? 'tls');
    $from_e = $conn->real_escape_string($_POST['smtp_from_email'] ?? '');
    $from_n = $conn->real_escape_string($_POST['smtp_from_name'] ?? '');

    $em_conf_obj = $conn->real_escape_string($_POST['email_conferma_oggetto'] ?? '');
    $em_conf_cor = $conn->real_escape_string($_POST['email_conferma_corpo'] ?? '');
    $em_canc_u_obj = $conn->real_escape_string($_POST['email_canc_utente_oggetto'] ?? '');
    $em_canc_u_cor = $conn->real_escape_string($_POST['email_canc_utente_corpo'] ?? '');
    $em_canc_a_obj = $conn->real_escape_string($_POST['email_canc_admin_oggetto'] ?? '');
    $em_canc_a_cor = $conn->real_escape_string($_POST['email_canc_admin_corpo'] ?? '');
    $em_rem_obj = $conn->real_escape_string($_POST['email_reminder_oggetto'] ?? '');
    $em_rem_cor = $conn->real_escape_string($_POST['email_reminder_corpo'] ?? '');
    
    // Nuovi Template
    $em_att_obj = $conn->real_escape_string($_POST['email_attestato_oggetto'] ?? '');
    $em_att_cor = $conn->real_escape_string($_POST['email_attestato_corpo'] ?? '');
    $em_son_obj = $conn->real_escape_string($_POST['email_sondaggio_oggetto'] ?? '');
    $em_son_cor = $conn->real_escape_string($_POST['email_sondaggio_corpo'] ?? '');

    $conn->query("UPDATE impostazioni_sistema SET 
        smtp_host='$host', smtp_port=$port, smtp_username='$user', smtp_password='$pass', smtp_secure='$sec', smtp_from_email='$from_e', smtp_from_name='$from_n', 
        email_conferma_oggetto='$em_conf_obj', email_conferma_corpo='$em_conf_cor', 
        email_canc_utente_oggetto='$em_canc_u_obj', email_canc_utente_corpo='$em_canc_u_cor', 
        email_canc_admin_oggetto='$em_canc_a_obj', email_canc_admin_corpo='$em_canc_a_cor', 
        email_reminder_oggetto='$em_rem_obj', email_reminder_corpo='$em_rem_cor',
        email_attestato_oggetto='$em_att_obj', email_attestato_corpo='$em_att_cor',
        email_sondaggio_oggetto='$em_son_obj', email_sondaggio_corpo='$em_son_cor'
        WHERE id = 1");
    
    if (function_exists('registra_log_audit')) registra_log_audit($conn, "Aggiornamento Impostazioni Sistema/SMTP e Template", ["Mittente" => $from_e]);
    
    flash_set("Impostazioni SMTP e Template Email salvati con successo!");
    admin_redirect("sistema.php?p_id=$filtro_p");
}
?>

<!-- FRONT-END DELLA PAGINA -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-dark m-0"><i class="fa fa-envelope text-primary me-2"></i> Sistema Email & Promemoria</h4>
    
    <form method="POST" action="" class="m-0 text-end">
        <?php csrf_field(); ?>
        <button type="submit" name="run_reminders_manual" class="btn btn-warning fw-bold shadow-sm text-dark" data-confirm="Vuoi inviare ora i promemoria a tutti gli utenti con eventi programmati nelle prossime 72 ore? L\'operazione potrebbe richiedere alcuni secondi.">
            <i class="fa fa-paper-plane me-1"></i> Invia Promemoria Ora (Eventi prossime 72h)
        </button>
    </form>
</div>

<div class="card shadow-sm border-0 p-4">
    <form method="POST">
        <?php csrf_field(); ?>
        <h5 class="fw-bold text-primary border-bottom pb-2 mb-4"><i class="fa fa-cogs me-1"></i> Configurazione Server SMTP e Template Notifiche</h5>
        
        <div class="row g-3 mb-4 bg-light p-3 border rounded">
            <div class="col-md-4">
                <label class="form-label small fw-bold">Host SMTP</label>
                <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_host'] ?? 'smtpservizi.unical.it'); ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold">Porta SMTP</label>
                <input type="number" name="smtp_port" class="form-control" value="<?php echo (int)($sys['smtp_port'] ?? 587); ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Cifratura</label>
                <select name="smtp_secure" class="form-select">
                    <option value="tls" <?php echo ($sys['smtp_secure'] ?? '') == 'tls' ? 'selected' : ''; ?>>TLS</option>
                    <option value="ssl" <?php echo ($sys['smtp_secure'] ?? '') == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Username SMTP</label>
                <input type="text" name="smtp_username" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_username'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Password SMTP</label>
                <input type="password" name="smtp_password" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_password'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Email Mittente (Da)</label>
                <input type="email" name="smtp_from_email" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_from_email'] ?? 'noreply.eventi@unical.it'); ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Nome Mittente (Visualizzato)</label>
                <input type="text" name="smtp_from_name" class="form-control" value="<?php echo htmlspecialchars($sys['smtp_from_name'] ?? 'Eventi DiBEST - Unical'); ?>" required>
            </div>
        </div>

        <div class="alert alert-info border-info mb-4 shadow-sm">
            <h6 class="fw-bold m-0 mb-2"><i class="fa fa-code me-1"></i> Segnaposto Dinamici Utilizzabili nei Template Email:</h6>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{NOME}</span>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{COGNOME}</span>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{MATRICOLA}</span>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{TITOLO_EVENTO}</span>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{DATA_TURNO}</span>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{ORARIO_TURNO}</span>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{LUOGO}</span>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{CODICE_PRENOTAZIONE}</span>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{LINK_RICEVUTA}</span>
            <span class="badge bg-white text-dark border font-monospace me-1 mb-1">{LINK_AREA_PERSONALE}</span>
        </div>

        <div class="row g-4">
            <!-- 1. Email Conferma -->
            <div class="col-md-6">
                <div class="border p-4 rounded bg-white h-100 shadow-sm border-success" style="border-top-width: 4px !important;">
                    <h6 class="fw-bold text-success border-bottom pb-2 mb-3">📩 1. Conferma Prenotazione Utente</h6>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Oggetto Email</label>
                        <input type="text" name="email_conferma_oggetto" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($sys['email_conferma_oggetto'] ?? 'Conferma Prenotazione Eventi'); ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Corpo Email (HTML)</label>
                        <textarea name="email_conferma_corpo" class="form-control editor-html" rows="4"><?php echo htmlspecialchars($sys['email_conferma_corpo'] ?? "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>La tua prenotazione per l'evento <strong>{TITOLO_EVENTO}</strong> è stata confermata con successo!</p><p><strong>Dettagli:</strong><br>📅 Data: {DATA_TURNO}<br>🕒 Orario: {ORARIO_TURNO}<br>📍 Luogo: {LUOGO}<br>🎟️ Codice Prenotazione: <strong>{CODICE_PRENOTAZIONE}</strong></p><p>Ti aspettiamo!</p>"); ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- 2. Email Annullamento Admin -->
            <div class="col-md-6">
                <div class="border p-4 rounded bg-white h-100 shadow-sm border-danger" style="border-top-width: 4px !important;">
                    <h6 class="fw-bold text-danger border-bottom pb-2 mb-3">🚫 2. Annullamento da Amministrazione</h6>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Oggetto Email</label>
                        <input type="text" name="email_canc_admin_oggetto" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($sys['email_canc_admin_oggetto'] ?? 'Annullamento Prenotazione Evento'); ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Corpo Email (HTML)</label>
                        <textarea name="email_canc_admin_corpo" class="form-control editor-html" rows="4"><?php echo htmlspecialchars($sys['email_canc_admin_corpo'] ?? "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Ti informiamo che la tua prenotazione per l'evento <strong>{TITOLO_EVENTO}</strong> del {DATA_TURNO} è stata annullata dall'amministrazione.</p>"); ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- 3. Email Annullamento Utente -->
            <div class="col-md-6">
                <div class="border p-4 rounded bg-white h-100 shadow-sm border-warning" style="border-top-width: 4px !important;">
                    <h6 class="fw-bold text-warning border-bottom pb-2 mb-3 text-dark">🗑️ 3. Conferma Cancellazione (da Utente)</h6>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Oggetto Email</label>
                        <input type="text" name="email_canc_utente_oggetto" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($sys['email_canc_utente_oggetto'] ?? 'Cancellazione Prenotazione Evento'); ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Corpo Email (HTML)</label>
                        <textarea name="email_canc_utente_corpo" class="form-control editor-html" rows="4"><?php echo htmlspecialchars($sys['email_canc_utente_corpo'] ?? "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>La tua prenotazione per l'evento <strong>{TITOLO_EVENTO}</strong> è stata cancellata correttamente come richiesto.</p>"); ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- 4. Email Promemoria -->
            <div class="col-md-6">
                <div class="border p-4 rounded bg-white h-100 shadow-sm border-primary" style="border-top-width: 4px !important;">
                    <h6 class="fw-bold text-primary border-bottom pb-2 mb-3">⏰ 4. Promemoria / Reminder Automatico</h6>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Oggetto Email</label>
                        <input type="text" name="email_reminder_oggetto" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($sys['email_reminder_oggetto'] ?? 'Promemoria Evento Imminente'); ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Corpo Email (HTML)</label>
                        <textarea name="email_reminder_corpo" class="form-control editor-html" rows="4"><?php echo htmlspecialchars($sys['email_reminder_corpo'] ?? "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Ti ricordiamo che l'evento <strong>{TITOLO_EVENTO}</strong> si terrà a breve il {DATA_TURNO} alle {ORARIO_TURNO}.</p><p style='color:red;'>Se non potrai partecipare, annulla la prenotazione dall'Area Personale per cedere il posto ad altri.</p>"); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- 5. Email Attestato Disponibile (NUOVO) -->
            <div class="col-md-6">
                <div class="border p-4 rounded bg-white h-100 shadow-sm border-info" style="border-top-width: 4px !important;">
                    <h6 class="fw-bold text-info border-bottom pb-2 mb-3">🎓 5. Attestato Disponibile (Post-Evento)</h6>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Oggetto Email</label>
                        <input type="text" name="email_attestato_oggetto" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($sys['email_attestato_oggetto'] ?? 'Il tuo Attestato di Partecipazione è pronto'); ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Corpo Email (HTML)</label>
                        <textarea name="email_attestato_corpo" class="form-control editor-html" rows="4"><?php echo htmlspecialchars($sys['email_attestato_corpo'] ?? "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Grazie per aver partecipato all'evento <strong>{TITOLO_EVENTO}</strong>.</p><p>Ti informiamo che il tuo Attestato di Partecipazione è stato generato ed è ora disponibile per il download.</p><p>Puoi scaricarlo accedendo alla tua {LINK_AREA_PERSONALE}.</p>"); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- 6. Email Sondaggio (NUOVO) -->
            <div class="col-md-6">
                <div class="border p-4 rounded bg-white h-100 shadow-sm border-secondary" style="border-top-width: 4px !important;">
                    <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3">📊 6. Richiesta Feedback / Sondaggio (Post-Evento)</h6>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Oggetto Email</label>
                        <input type="text" name="email_sondaggio_oggetto" class="form-control form-control-sm fw-bold" value="<?php echo htmlspecialchars($sys['email_sondaggio_oggetto'] ?? 'Aiutaci a migliorare: lascia il tuo feedback'); ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Corpo Email (HTML)</label>
                        <textarea name="email_sondaggio_corpo" class="form-control editor-html" rows="4"><?php echo htmlspecialchars($sys['email_sondaggio_corpo'] ?? "<p>Gentile <strong>{NOME} {COGNOME}</strong>,</p><p>Speriamo che l'evento <strong>{TITOLO_EVENTO}</strong> sia stato di tuo gradimento.</p><p>Per aiutarci a migliorare la qualità delle nostre iniziative, ti chiediamo di dedicare 2 minuti per compilare il nostro questionario di valutazione anonimo.</p><p>Clicca sul link seguente per accedere al sondaggio: <br><a href='#'>[INSERISCI QUI IL LINK AL TUO MODULO GOOGLE O MS FORMS]</a></p>"); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-end mt-4 pt-3 border-top">
            <button type="submit" name="save_system_settings" onclick="tinymce.triggerSave();" class="btn btn-primary fw-bold px-4 py-3 shadow-sm">
                <i class="fa fa-save me-1"></i> Salva Impostazioni SMTP e Template
            </button>
        </div>
    </form>
</div>

<?php
// Registro invii (tabella creata al primo invio da registra_log_email())
$log_email = [];
$stat_email = ['ok' => 0, 'ko' => 0];
$res_log = @$conn->query("SELECT * FROM log_email ORDER BY id DESC LIMIT 100");
if ($res_log) { while ($l = $res_log->fetch_assoc()) { $log_email[] = $l; } }
$res_stat = @$conn->query("SELECT SUM(esito = 1) AS ok, SUM(esito = 0) AS ko FROM log_email WHERE created_at >= NOW() - INTERVAL 7 DAY");
if ($res_stat && $s = $res_stat->fetch_assoc()) { $stat_email = ['ok' => (int)$s['ok'], 'ko' => (int)$s['ko']]; }
$email_admin = '';
$res_me = $conn->query("SELECT email FROM utenti WHERE id = " . (int)$u_id_curr . " LIMIT 1");
if ($res_me && $me = $res_me->fetch_assoc()) { $email_admin = $me['email'] ?? ''; }
?>
<div class="card shadow-sm border-0 p-4 mt-4" id="log-email">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 border-bottom pb-3 mb-3">
        <h5 class="fw-bold text-primary m-0"><i class="fa fa-list-alt me-1"></i> Verifica invio email</h5>
        <form method="POST" class="d-flex gap-2 m-0">
            <?php csrf_field(); ?>
            <input type="email" name="email_test" class="form-control form-control-sm" style="min-width:240px" placeholder="destinatario@unical.it" value="<?php echo htmlspecialchars($email_admin); ?>" required>
            <button type="submit" name="invia_email_test" class="btn btn-outline-primary btn-sm fw-bold text-nowrap"><i class="fa fa-paper-plane me-1"></i> Invia email di prova</button>
        </form>
    </div>
    <p class="small text-muted mb-3">
        Ultimi 7 giorni: <span class="badge bg-success"><?php echo $stat_email['ok']; ?> accettate</span>
        <span class="badge bg-danger"><?php echo $stat_email['ko']; ?> fallite</span>.
        "Accettata" significa che il server SMTP ha preso in carico il messaggio; se non arriva, il problema è a valle (spam, filtri della casella).
    </p>
    <?php if (empty($log_email)): ?>
        <div class="text-muted small">Nessun invio registrato finora.</div>
    <?php else: ?>
        <div class="table-responsive" style="max-height:420px; overflow-y:auto;">
            <table class="table table-sm table-hover align-middle small m-0">
                <thead class="table-light" style="position:sticky; top:0;"><tr><th>Data</th><th>Destinatario</th><th>Oggetto</th><th>Esito</th></tr></thead>
                <tbody>
                <?php foreach ($log_email as $l): ?>
                    <tr>
                        <td class="text-nowrap"><?php echo date('d/m/Y H:i', strtotime($l['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($l['destinatario']); ?></td>
                        <td><?php echo htmlspecialchars($l['oggetto']); ?></td>
                        <td>
                            <?php if ((int)$l['esito'] === 1): ?>
                                <span class="badge bg-success">Inviata</span><?php if ($l['canale'] === 'mail()'): ?> <span class="badge bg-warning text-dark" title="<?php echo htmlspecialchars($l['errore'] ?? ''); ?>">via mail()</span><?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-danger">Fallita</span> <span class="text-danger"><?php echo htmlspecialchars($l['errore'] ?? ''); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'admin_footer.php'; ?>
