<?php
// admin/audit_log.php - Visualizzazione del Log di Sistema (Solo Super Admin)
require_once 'admin_header.php';

// Sicurezza assoluta: Solo l'amministratore principale può vedere i log
if (!$is_full_admin) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Solo i Super Admin possono visualizzare il registro di sistema.</div>";
    require_once 'admin_footer.php';
    exit;
}

// Estrazione del registro (ultime 1000 azioni per non appesantire)
$sql_log = "SELECT l.*, u.nome, u.cognome, u.codice_fiscale 
            FROM log_attivita l 
            LEFT JOIN utenti u ON l.utente_id = u.id 
            ORDER BY l.data_ora DESC LIMIT 1000";
$res_log = $conn->query($sql_log);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold text-dark m-0"><i class="fa fa-user-secret text-danger me-2"></i> Registro Audit (System Log)</h4>
    <span class="badge bg-secondary">Ultime 1000 operazioni</span>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle" id="tabellaAudit">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 15%;">Data e Ora</th>
                        <th style="width: 25%;">Utente (Admin/Gestore)</th>
                        <th style="width: 30%;">Azione Eseguita</th>
                        <th style="width: 20%;">Dettagli / Dati (JSON)</th>
                        <th style="width: 10%;">Indirizzo IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($res_log && $res_log->num_rows > 0): ?>
                        <?php while ($r = $res_log->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="d-none"><?php echo $r['data_ora']; ?></span>
                                    <i class="fa fa-clock text-muted me-1"></i> <?php echo date('d/m/Y H:i:s', strtotime($r['data_ora'])); ?>
                                </td>
                                <td class="fw-bold text-primary">
                                    <?php echo htmlspecialchars($r['nome'] . ' ' . $r['cognome']); ?><br>
                                    <small class="text-muted font-monospace"><?php echo htmlspecialchars($r['codice_fiscale']); ?></small>
                                </td>
                                <td class="fw-bold">
                                    <?php echo htmlspecialchars($r['azione']); ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['dettagli_json'])): ?>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="alert(<?php echo htmlspecialchars(json_encode($r['dettagli_json'])); ?>)">
                                            <i class="fa fa-code"></i> Vedi Dati
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-monospace small text-muted">
                                    <?php echo htmlspecialchars($r['indirizzo_ip']); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">Nessuna attività registrata finora.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document.ready(function() {
    $('#tabellaAudit').DataTable({
        "language": { "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/it-IT.json" },
        "order": [[ 0, "desc" ]],
        "pageLength": 25
    });
});
</script>

<?php require_once 'admin_footer.php'; ?>
