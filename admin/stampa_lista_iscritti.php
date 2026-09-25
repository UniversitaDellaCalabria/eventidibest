<?php
// admin/stampa_lista_iscritti.php - Vista stampabile / PDF lista iscritti
require_once 'admin_header.php';

if (!$can_manage_iscritti) {
    echo "<p>Accesso negato.</p>"; exit;
}

$is_archivio    = (isset($_GET['archivio']) && $_GET['archivio'] == 1) ? 1 : 0;
$filtro_turno   = isset($_GET['f_turno']) ? (int)$_GET['f_turno'] : 0;
$_stati_ok      = ['confermata', 'in_attesa', 'da_approvare', 'annullata', 'rifiutata', 'scaduta'];
$filtro_stato   = (isset($_GET['f_stato']) && in_array($_GET['f_stato'], $_stati_ok, true)) ? $_GET['f_stato'] : '';
$filtro_cerca     = trim($_GET['f_cerca'] ?? '');
$filtro_data_da   = (isset($_GET['f_data_da'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['f_data_da']))   ? $_GET['f_data_da']   : '';
$filtro_data_fine = (isset($_GET['f_data_fine']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['f_data_fine'])) ? $_GET['f_data_fine'] : '';

$cond_turno  = $filtro_turno > 0 ? " AND t.id = $filtro_turno" : "";
$cond_stato  = !empty($filtro_stato) ? " AND IFNULL(pr.stato, 'confermata') = '$filtro_stato'" : "";
$cond_cerca  = '';
if (!empty($filtro_cerca)) {
    $cerca_esc = $conn->real_escape_string($filtro_cerca);
    $cond_cerca = " AND (pr.nome LIKE '%$cerca_esc%' OR pr.cognome LIKE '%$cerca_esc%' OR pr.email LIKE '%$cerca_esc%' OR pr.codice_prenotazione LIKE '%$cerca_esc%')";
}
$cond_data = '';
if (!empty($filtro_data_da) && !empty($filtro_data_fine)) {
    $cond_data = " AND t.data_turno BETWEEN '$filtro_data_da' AND '$filtro_data_fine'";
} elseif (!empty($filtro_data_da)) {
    $cond_data = " AND t.data_turno >= '$filtro_data_da'";
} elseif (!empty($filtro_data_fine)) {
    $cond_data = " AND t.data_turno <= '$filtro_data_fine'";
}
$where = "WHERE e.pagina_id = $filtro_p AND e.archiviato = $is_archivio $cond_turno $cond_stato $cond_cerca $cond_data $sql_filtro_eventi_rbac";

$sql = "SELECT pr.codice_prenotazione, IFNULL(pr.stato, 'confermata') as stato, pr.presente,
               pr.nome, pr.cognome, pr.email,
               COALESCE(NULLIF(pr.matricola,''), u.matricola_studente, u.matricola_dipendente) as matricola,
               pr.num_posti, t.nome_turno, t.data_turno, t.orario_inizio, e.titolo as evento_titolo,
               pr.data_prenotazione
        FROM prenotazioni pr
        JOIN turni t ON pr.turno_id = t.id
        JOIN eventi e ON t.evento_id = e.id
        LEFT JOIN utenti u ON pr.utente_id = u.id
        $where
        ORDER BY e.titolo ASC, (t.data_turno IS NULL), t.data_turno ASC, t.nome_turno ASC, pr.cognome ASC
        LIMIT 2000";

$res = $conn->query($sql);
$righe = [];
if ($res) { while ($r = $res->fetch_assoc()) $righe[] = $r; }

$titolo_pagina = htmlspecialchars($page_cfg['titolo'] ?? 'Area');
$data_stampa   = date('d/m/Y H:i');
$etichette_stato = [
    'confermata'         => 'Confermata',
    'in_attesa'          => 'In Attesa',
    'da_approvare'       => 'Da Approvare',
    'annullata'          => 'Annullata',
    'rifiutata'          => 'Rifiutata',
    'scaduta'            => 'Scaduta',
    'richiesta_conferma' => 'Conf. Richiesta',
];

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Lista Iscritti — <?php echo $titolo_pagina; ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 20px; background: #fff; }
        h2 { font-size: 16px; margin: 0 0 4px; }
        .meta { font-size: 10px; color: #666; margin-bottom: 14px; }
        .filters { font-size: 10px; color: #444; background: #f4f4f4; padding: 5px 8px; border-radius: 4px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1e293b; color: #fff; padding: 5px 6px; text-align: left; font-size: 10px; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
        tr:nth-child(even) td { background: #f9f9f9; }
        .badge-confermata  { color: #166534; font-weight: bold; }
        .badge-in_attesa   { color: #854d0e; }
        .badge-annullata,
        .badge-rifiutata   { color: #991b1b; }
        .badge-scaduta     { color: #6b7280; }
        .presente-si       { color: #166534; font-weight: bold; }
        .presente-no       { color: #9ca3af; }
        .totale            { margin-top: 12px; font-size: 11px; font-weight: bold; }
        .no-print          { margin-bottom: 12px; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 10px; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding:6px 16px; background:#1e293b; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px; font-weight:bold;">
            🖨️ Stampa / Salva PDF
        </button>
        <a href="iscritti.php?p_id=<?php echo $filtro_p; ?>&f_turno=<?php echo $filtro_turno; ?>&f_stato=<?php echo urlencode($filtro_stato); ?>&f_cerca=<?php echo urlencode($filtro_cerca); ?><?php echo $is_archivio ? '&archivio=1' : ''; ?>" style="margin-left:10px; font-size:11px;">← Torna alla lista</a>
    </div>

    <h2>Lista Iscritti — <?php echo $titolo_pagina; ?></h2>
    <div class="meta">Generato il <?php echo $data_stampa; ?> · Totale: <?php echo count($righe); ?> iscritti</div>

    <?php
    $label_filtri = [];
    if ($filtro_turno > 0) $label_filtri[] = "Turno ID: $filtro_turno";
    if (!empty($filtro_stato)) $label_filtri[] = "Stato: " . ($etichette_stato[$filtro_stato] ?? $filtro_stato);
    if (!empty($filtro_cerca)) $label_filtri[] = "Ricerca: \"" . htmlspecialchars($filtro_cerca) . "\"";
    if (!empty($filtro_data_da) && !empty($filtro_data_fine)) $label_filtri[] = "Date: " . date('d/m/Y', strtotime($filtro_data_da)) . " – " . date('d/m/Y', strtotime($filtro_data_fine));
    elseif (!empty($filtro_data_da))   $label_filtri[] = "Dal: " . date('d/m/Y', strtotime($filtro_data_da));
    elseif (!empty($filtro_data_fine)) $label_filtri[] = "Al: " . date('d/m/Y', strtotime($filtro_data_fine));
    if (!empty($label_filtri)): ?>
        <div class="filters">Filtri attivi: <?php echo implode(' &nbsp;|&nbsp; ', $label_filtri); ?></div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Codice</th>
                <th>Cognome</th>
                <th>Nome</th>
                <th>Email</th>
                <th>Matricola</th>
                <th>Evento</th>
                <th>Turno</th>
                <th>Ora</th>
                <th>Posti</th>
                <th>Stato</th>
                <th>Presenza</th>
                <th>Reg.</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($righe)): ?>
                <tr><td colspan="12" style="text-align:center; padding:20px; color:#999;">Nessun iscritto trovato.</td></tr>
            <?php else: ?>
                <?php foreach ($righe as $r):
                    $stato_val = $r['stato'] ?? 'confermata';
                    $stato_cls = 'badge-' . str_replace(['_', ' '], ['_', '_'], $stato_val);
                ?>
                <tr>
                    <td><code style="font-size:9px;"><?php echo htmlspecialchars($r['codice_prenotazione']); ?></code></td>
                    <td><?php echo htmlspecialchars($r['cognome']); ?></td>
                    <td><?php echo htmlspecialchars($r['nome']); ?></td>
                    <td style="font-size:9px;"><?php echo htmlspecialchars($r['email']); ?></td>
                    <td><?php echo htmlspecialchars($r['matricola'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($r['evento_titolo']); ?></td>
                    <td><?php echo htmlspecialchars(implode(' · ', array_filter([$r['nome_turno'] ?? '', !empty($r['data_turno']) ? date('d/m/Y', strtotime($r['data_turno'])) : '']))); ?></td>
                    <td><?php echo !empty($r['orario_inizio']) ? substr($r['orario_inizio'], 0, 5) : ''; ?></td>
                    <td style="text-align:center;"><?php echo (int)$r['num_posti']; ?></td>
                    <td class="<?php echo $stato_cls; ?>"><?php echo htmlspecialchars($etichette_stato[$stato_val] ?? $stato_val); ?></td>
                    <td class="<?php echo $r['presente'] ? 'presente-si' : 'presente-no'; ?>">
                        <?php echo $r['presente'] ? '✓ Sì' : '–'; ?>
                    </td>
                    <td style="font-size:9px;"><?php echo date('d/m/y', strtotime($r['data_prenotazione'])); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="totale">Totale righe: <?php echo count($righe); ?></div>
</body>
</html>
