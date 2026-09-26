<?php
// scanner.php - Check-in integrato: scansione QR continua, contatore presenti in tempo reale, check-in manuale
require_once 'admin_header.php';

if (!$can_manage_iscritti) {
    echo "<div class='alert alert-danger fw-bold shadow-sm m-4'><i class='fa fa-ban me-2'></i> Accesso negato. Non hai i permessi per il check-in in quest'area.</div>";
    require_once 'admin_footer.php';
    exit;
}

// Turni con check-in attivo dell'area corrente, visibili al gestore (RBAC)
function turni_scanner($conn, int $p_id, string $rbac): array {
    $res = $conn->query(
        "SELECT t.id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo
         FROM turni t JOIN eventi e ON t.evento_id = e.id
         WHERE e.pagina_id = $p_id AND e.archiviato = 0 AND IFNULL(e.abilita_presenze, 1) = 1 $rbac
         ORDER BY (t.data_turno IS NULL), t.data_turno ASC, t.orario_inizio ASC, e.titolo ASC, t.id ASC"
    );
    $out = [];
    if ($res) while ($r = $res->fetch_assoc()) $out[(int)$r['id']] = $r;
    return $out;
}

// Stato del turno: contatori e lista delle prenotazioni confermate
function stato_turno_scanner($conn, int $t_id): array {
    $lista = [];
    $pres = 0; $tot = 0; $posti_pres = 0; $posti_tot = 0;
    $res = $conn->query(
        "SELECT id, nome, cognome, codice_prenotazione, num_posti, presente, data_presenza
         FROM prenotazioni WHERE turno_id = $t_id AND IFNULL(stato, 'confermata') = 'confermata'
         ORDER BY cognome ASC, nome ASC, id ASC"
    );
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $n = max(1, (int)$r['num_posti']);
            $tot++; $posti_tot += $n;
            if ((int)$r['presente'] === 1) { $pres++; $posti_pres += $n; }
            $lista[] = [
                'id' => (int)$r['id'], 'nome' => trim($r['cognome'] . ' ' . $r['nome']), 'codice' => $r['codice_prenotazione'],
                'posti' => $n, 'presente' => (int)$r['presente'] === 1,
                'ora' => !empty($r['data_presenza']) ? date('H:i', strtotime($r['data_presenza'])) : '',
            ];
        }
    }
    return ['presenti' => $pres, 'totale' => $tot, 'posti_presenti' => $posti_pres, 'posti_totali' => $posti_tot, 'lista' => $lista];
}

$turni = turni_scanner($conn, $filtro_p, $sql_filtro_eventi_rbac);

// ==============================================================================
// AJAX: risposte JSON (scarta l'HTML già prodotto dall'intestazione admin)
// ==============================================================================
if (isset($_REQUEST['ajax'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $rispondi = function (array $dati) { echo json_encode($dati, JSON_UNESCAPED_UNICODE); exit; };

    $t_id = (int)($_REQUEST['turno'] ?? 0);
    if (!isset($turni[$t_id])) $rispondi(['esito' => 'errore', 'titolo' => 'Turno non valido', 'dettaglio' => 'Seleziona un turno di quest\'area.']);

    $azione = (string)$_REQUEST['ajax'];
    if ($azione === 'stato') $rispondi(['esito' => 'ok'] + stato_turno_scanner($conn, $t_id));

    // Da qui in poi solo azioni che modificano dati: POST + token CSRF
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        $rispondi(['esito' => 'errore', 'titolo' => 'Sessione scaduta', 'dettaglio' => 'Ricarica la pagina e riprova.']);
    }

    // Cerca la prenotazione per codice (scansione) o per id (lista manuale)
    if ($azione === 'checkin') {
        $codice = strtoupper(trim((string)($_POST['codice'] ?? '')));
        if (!preg_match('/^[A-Z0-9-]{4,40}$/', $codice)) $rispondi(['esito' => 'errore', 'titolo' => 'QR non riconosciuto', 'dettaglio' => 'Il codice letto non è un biglietto del portale.']);
        $stmt = $conn->prepare("SELECT pr.*, t.id AS turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo, e.pagina_id
                                FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                                WHERE UPPER(pr.codice_prenotazione) = ? LIMIT 1");
        $stmt->bind_param("s", $codice);
    } elseif ($azione === 'presenza') {
        $pr_id = (int)($_POST['pr_id'] ?? 0);
        $stmt = $conn->prepare("SELECT pr.*, t.id AS turno_id, t.nome_turno, t.data_turno, t.orario_inizio, t.orario_fine, e.titolo AS evento_titolo, e.pagina_id
                                FROM prenotazioni pr JOIN turni t ON pr.turno_id = t.id JOIN eventi e ON t.evento_id = e.id
                                WHERE pr.id = ? LIMIT 1");
        $stmt->bind_param("i", $pr_id);
    } else {
        $rispondi(['esito' => 'errore', 'titolo' => 'Azione non valida', 'dettaglio' => '']);
    }
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();

    $contatori = function () use ($conn, $t_id) { $s = stato_turno_scanner($conn, $t_id); unset($s['lista']); return $s; };
    $nome = $p ? trim($p['nome'] . ' ' . $p['cognome']) : '';

    if (!$p) $rispondi(['esito' => 'errore', 'titolo' => 'Biglietto non trovato', 'dettaglio' => 'Nessuna prenotazione con questo codice.'] + $contatori());
    // Stessa area e stesso turno selezionato: niente check-in su eventi di altri
    if ((int)$p['pagina_id'] !== $filtro_p || !isset($turni[(int)$p['turno_id']])) {
        $rispondi(['esito' => 'errore', 'titolo' => 'Biglietto di un altro evento', 'dettaglio' => $p['evento_titolo'] . ' — non gestito da questo scanner.'] + $contatori());
    }
    $forza = !empty($_POST['forza']);
    if ((int)$p['turno_id'] !== $t_id && !$forza) {
        $rispondi(['esito' => 'turno', 'titolo' => 'Turno diverso', 'nome' => $nome, 'codice' => $p['codice_prenotazione'],
                   'dettaglio' => 'Il biglietto è per: ' . $p['evento_titolo'] . ' · ' . etichetta_turno($p)] + $contatori());
    }
    if (($p['stato'] ?? 'confermata') !== 'confermata') {
        $stati = ['in_attesa' => "in lista d'attesa", 'da_approvare' => 'da approvare', 'richiesta_conferma' => 'posto non ancora confermato', 'annullata' => 'annullata', 'rifiutata' => 'rifiutata', 'scaduta' => 'scaduta'];
        $rispondi(['esito' => 'errore', 'titolo' => 'Ingresso negato', 'nome' => $nome, 'dettaglio' => 'Prenotazione ' . ($stati[$p['stato']] ?? $p['stato']) . '.'] + $contatori());
    }

    $pr_id = (int)$p['id'];
    if ($azione === 'presenza' && empty($_POST['val'])) {
        // Annulla la presenza (correzione di un errore)
        $conn->query("UPDATE prenotazioni SET presente = 0, data_presenza = NULL WHERE id = $pr_id");
        if (function_exists('registra_log_audit')) registra_log_audit($conn, "Check-in annullato", ["Prenotazione" => $pr_id]);
        $rispondi(['esito' => 'ok', 'titolo' => 'Presenza annullata', 'nome' => $nome, 'dettaglio' => ''] + $contatori());
    }
    if ((int)$p['presente'] === 1) {
        $ora = !empty($p['data_presenza']) ? ' alle ' . date('H:i', strtotime($p['data_presenza'])) : '';
        $rispondi(['esito' => 'gia', 'titolo' => 'Già registrato', 'nome' => $nome, 'dettaglio' => 'Ingresso già registrato' . $ora . '.'] + $contatori());
    }
    $conn->query("UPDATE prenotazioni SET presente = 1, data_presenza = NOW() WHERE id = $pr_id AND presente = 0");
    invia_email_attestato_se_concluso($conn, $pr_id);
    $posti = max(1, (int)$p['num_posti']);
    $rispondi(['esito' => 'ok', 'titolo' => 'Ingresso consentito', 'nome' => $nome,
               'dettaglio' => ($posti > 1 ? "Prenotazione per $posti persone. " : '') . ($forza ? 'Registrato su un turno diverso da quello del biglietto.' : '')] + $contatori());
}

// ==============================================================================
// PAGINA
// ==============================================================================
$col_scan = colore_valido($page_cfg['colore_primario'] ?? '', '#0056B3');
$txt_scan = colore_testo_su($col_scan);

// Turno predefinito: quello richiesto, altrimenti quello di oggi più vicino, altrimenti il primo futuro
$turno_sel = (int)($_GET['turno'] ?? 0);
if (!isset($turni[$turno_sel])) {
    $turno_sel = 0; $oggi = date('Y-m-d');
    foreach ($turni as $id => $t) { if (($t['data_turno'] ?? '') === $oggi) { $turno_sel = $id; break; } }
    if (!$turno_sel) foreach ($turni as $id => $t) { if (!turno_concluso($t)) { $turno_sel = $id; break; } }
    if (!$turno_sel && $turni) $turno_sel = array_key_first($turni);
}
?>

<style>
    .scan-video { width: 100%; max-width: 480px; aspect-ratio: 1; margin: 0 auto; border-radius: 12px; overflow: hidden; background: #0f172a; }
    .scan-esito { border-radius: 12px; padding: 18px; text-align: center; transition: background .2s; }
    .scan-esito.ok     { background: #dcfce7; color: #14532d; }
    .scan-esito.gia    { background: #fef3c7; color: #78350f; }
    .scan-esito.turno  { background: #ffedd5; color: #7c2d12; }
    .scan-esito.errore { background: #fee2e2; color: #7f1d1d; }
    .scan-esito.attesa { background: #f1f5f9; color: #334155; }
    .scan-contatore { font-size: 2.6rem; font-weight: 800; line-height: 1; }
    .scan-lista { max-height: 520px; overflow-y: auto; }
    .scan-riga.presente { background: #f0fdf4; }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="fw-bold text-dark mb-0 fs-4"><i class="fa fa-qrcode me-2" style="color:<?php echo $col_scan; ?>" aria-hidden="true"></i>Scanner Check-in</h1>
        <div class="text-muted mt-1" style="font-size:.8rem;"><?php echo htmlspecialchars($page_cfg['titolo'] ?? ''); ?></div>
    </div>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
        <label for="selTurno" class="small fw-bold text-nowrap">Turno</label>
        <select name="turno" id="selTurno" class="form-select form-select-sm" style="min-width:280px;border-radius:8px;" onchange="this.form.submit()">
            <?php if (!$turni): ?><option value="">Nessun turno con check-in attivo</option><?php endif; ?>
            <?php foreach ($turni as $id => $t): ?>
                <option value="<?php echo $id; ?>" <?php echo $id === $turno_sel ? 'selected' : ''; ?>><?php echo htmlspecialchars(mb_strimwidth($t['evento_titolo'], 0, 40, '…') . ' — ' . etichetta_turno($t)); ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<?php if (!$turni): ?>
    <div class="alert alert-info">Non ci sono turni con il check-in attivo in quest'area.</div>
<?php else: ?>
<div class="row g-4">
    <!-- SCANSIONE -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm p-3" style="border-radius:12px;">
            <div class="d-flex justify-content-around text-center mb-3" aria-live="polite">
                <div>
                    <div class="scan-contatore" style="color:<?php echo $col_scan; ?>;"><span id="cntPresenti">–</span><span class="text-muted fs-4">/<span id="cntTotale">–</span></span></div>
                    <div class="small fw-bold text-muted text-uppercase">Presenti</div>
                </div>
                <div>
                    <div class="scan-contatore text-secondary" id="cntPercento">–</div>
                    <div class="small fw-bold text-muted text-uppercase">Affluenza</div>
                </div>
            </div>
            <div class="progress mb-3" style="height:10px;" role="progressbar" aria-label="Affluenza" id="barAffluenza" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <div class="progress-bar bg-success" style="width:0%;"></div>
            </div>

            <div id="scanVideo" class="scan-video mb-3"></div>
            <div class="d-flex gap-2 justify-content-center mb-3">
                <button type="button" id="btnCamera" class="btn fw-bold" style="background:<?php echo $col_scan; ?>;color:<?php echo $txt_scan; ?>;"><i class="fa fa-camera me-1" aria-hidden="true"></i>Avvia fotocamera</button>
            </div>

            <div id="scanEsito" class="scan-esito attesa mb-3" role="status" aria-live="assertive">
                <div class="fw-bold fs-5" id="esitoTitolo">Pronto</div>
                <div class="fw-semibold" id="esitoNome">Inquadra il QR del biglietto oppure digita il codice.</div>
                <div class="small mt-1" id="esitoDettaglio"></div>
                <button type="button" id="btnForza" class="btn btn-sm btn-dark fw-bold mt-2 d-none">Registra comunque su questo turno</button>
            </div>

            <form id="formCodice" class="input-group" autocomplete="off">
                <label for="inputCodice" class="visually-hidden">Codice prenotazione</label>
                <input type="text" id="inputCodice" class="form-control text-uppercase font-monospace" placeholder="Codice (es. WW-1A2B3C4D) o lettore USB">
                <button type="submit" class="btn btn-outline-dark fw-bold">Registra</button>
            </form>
        </div>
    </div>

    <!-- LISTA / CHECK-IN MANUALE -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm" style="border-radius:12px;">
            <div class="p-3 border-bottom d-flex gap-2 align-items-center">
                <label for="cercaLista" class="visually-hidden">Cerca iscritto</label>
                <input type="search" id="cercaLista" class="form-control form-control-sm" placeholder="Cerca per nome o codice…">
                <select id="filtroLista" class="form-select form-select-sm" style="max-width:150px;" aria-label="Filtra per presenza">
                    <option value="tutti">Tutti</option><option value="assenti">Da registrare</option><option value="presenti">Presenti</option>
                </select>
            </div>
            <div class="scan-lista" id="listaIscritti"><div class="p-4 text-center text-muted">Caricamento…</div></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    var TURNO = <?php echo (int)$turno_sel; ?>;
    var URL_API = 'scanner.php?p_id=<?php echo (int)$filtro_p; ?>&turno=' + TURNO;
    var CSRF = <?php echo json_encode(csrf_token()); ?>;
    var lista = [], ultimoCodice = '', ultimoIstante = 0, scanner = null, inPausa = false, codiceDaForzare = '';

    function $(id) { return document.getElementById(id); }

    // Il QR del biglietto contiene un URL .../checkin.php?code=XX: si estrae SOLO il codice, non si apre mai l'URL
    function estraiCodice(testo) {
        testo = (testo || '').trim();
        try { var u = new URL(testo); var c = u.searchParams.get('code'); if (c) return c.trim().toUpperCase(); } catch (e) {}
        return /^[A-Za-z0-9-]{4,40}$/.test(testo) ? testo.toUpperCase() : '';
    }

    function suono(ok) {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)(), o = ctx.createOscillator(), g = ctx.createGain();
            o.frequency.value = ok ? 880 : 220; o.connect(g); g.connect(ctx.destination); g.gain.value = 0.15;
            o.start(); o.stop(ctx.currentTime + (ok ? 0.12 : 0.35));
        } catch (e) {}
        if (navigator.vibrate) navigator.vibrate(ok ? 80 : [120, 60, 120]);
    }

    function mostraEsito(r) {
        var box = $('scanEsito');
        box.className = 'scan-esito mb-3 ' + (r.esito || 'errore');
        $('esitoTitolo').textContent = r.titolo || '';
        $('esitoNome').textContent = r.nome || '';
        $('esitoDettaglio').textContent = r.dettaglio || '';
        $('btnForza').classList.toggle('d-none', r.esito !== 'turno');
    }

    function aggiornaContatori(r) {
        if (typeof r.presenti === 'undefined') return;
        $('cntPresenti').textContent = r.presenti;
        $('cntTotale').textContent = r.totale;
        var pct = r.totale > 0 ? Math.round(r.presenti / r.totale * 100) : 0;
        $('cntPercento').textContent = pct + '%';
        $('barAffluenza').setAttribute('aria-valuenow', pct);
        $('barAffluenza').firstElementChild.style.width = pct + '%';
    }

    function disegnaLista() {
        var q = $('cercaLista').value.trim().toLowerCase(), f = $('filtroLista').value, box = $('listaIscritti');
        var righe = lista.filter(function (p) {
            if (f === 'presenti' && !p.presente) return false;
            if (f === 'assenti' && p.presente) return false;
            return !q || p.nome.toLowerCase().indexOf(q) !== -1 || p.codice.toLowerCase().indexOf(q) !== -1;
        });
        box.textContent = '';
        if (!righe.length) { var v = document.createElement('div'); v.className = 'p-4 text-center text-muted'; v.textContent = lista.length ? 'Nessun risultato.' : 'Nessun iscritto confermato per questo turno.'; box.appendChild(v); return; }
        righe.forEach(function (p) {
            var r = document.createElement('div');
            r.className = 'scan-riga d-flex justify-content-between align-items-center gap-2 px-3 py-2 border-bottom' + (p.presente ? ' presente' : '');
            var info = document.createElement('div');
            var n = document.createElement('div'); n.className = 'fw-semibold'; n.textContent = p.nome + (p.posti > 1 ? ' (' + p.posti + ' posti)' : '');
            var c = document.createElement('div'); c.className = 'small text-muted font-monospace'; c.textContent = p.codice + (p.presente && p.ora ? ' · entrato alle ' + p.ora : '');
            info.appendChild(n); info.appendChild(c);
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm fw-bold ' + (p.presente ? 'btn-outline-secondary' : 'btn-success');
            b.textContent = p.presente ? 'Annulla' : 'Presente';
            b.setAttribute('aria-label', (p.presente ? 'Annulla presenza di ' : 'Segna presente ') + p.nome);
            b.addEventListener('click', function () {
                if (p.presente && !confirm('Annullare la presenza di ' + p.nome + '?')) return;
                invia('presenza', { pr_id: p.id, val: p.presente ? '' : '1' });
            });
            r.appendChild(info); r.appendChild(b);
            box.appendChild(r);
        });
    }

    function aggiornaStato() {
        fetch(URL_API + '&ajax=stato', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) { if (r.esito === 'ok') { lista = r.lista; aggiornaContatori(r); disegnaLista(); } })
            .catch(function () {});
    }

    function invia(azione, dati) {
        var fd = new FormData();
        fd.append('ajax', azione); fd.append('csrf_token', CSRF);
        Object.keys(dati).forEach(function (k) { fd.append(k, dati[k]); });
        return fetch(URL_API, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                mostraEsito(r); aggiornaContatori(r); suono(r.esito === 'ok');
                if (r.esito === 'turno') codiceDaForzare = dati.codice || '';
                aggiornaStato();
                return r;
            })
            .catch(function () { mostraEsito({ esito: 'errore', titolo: 'Errore di rete', dettaglio: 'Controlla la connessione e riprova.' }); suono(false); });
    }

    function registraCodice(codice, forza) {
        if (!codice) { mostraEsito({ esito: 'errore', titolo: 'QR non riconosciuto', dettaglio: 'Il codice letto non è un biglietto del portale.' }); suono(false); return Promise.resolve(); }
        var dati = { codice: codice };
        if (forza) dati.forza = '1';
        return invia('checkin', dati);
    }

    // Scansione continua: stesso codice ignorato per 3 secondi, pausa breve dopo ogni lettura
    function suScansione(testo) {
        if (inPausa) return;
        var codice = estraiCodice(testo), ora = Date.now();
        if (codice && codice === ultimoCodice && ora - ultimoIstante < 3000) return;
        ultimoCodice = codice; ultimoIstante = ora; inPausa = true;
        registraCodice(codice).finally(function () { setTimeout(function () { inPausa = false; }, 1500); });
    }

    $('btnCamera').addEventListener('click', function () {
        var btn = this;
        if (scanner) {
            scanner.stop().then(function () { scanner.clear(); scanner = null; btn.innerHTML = '<i class="fa fa-camera me-1" aria-hidden="true"></i>Avvia fotocamera'; });
            return;
        }
        if (!window.Html5Qrcode) { mostraEsito({ esito: 'errore', titolo: 'Fotocamera non disponibile', dettaglio: 'Libreria di scansione non caricata: usa il campo codice.' }); return; }
        scanner = new Html5Qrcode('scanVideo');
        scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 240, height: 240 } }, suScansione, function () {})
            .then(function () { btn.innerHTML = '<i class="fa fa-stop me-1" aria-hidden="true"></i>Ferma fotocamera'; })
            .catch(function (e) { scanner = null; mostraEsito({ esito: 'errore', titolo: 'Fotocamera non disponibile', dettaglio: 'Consenti l\'accesso alla fotocamera (serve HTTPS) oppure usa il campo codice.' }); });
    });

    $('formCodice').addEventListener('submit', function (e) {
        e.preventDefault();
        var inp = $('inputCodice');
        registraCodice(estraiCodice(inp.value)).finally(function () { inp.value = ''; inp.focus(); });
    });
    $('btnForza').addEventListener('click', function () { registraCodice(codiceDaForzare, true); });
    $('cercaLista').addEventListener('input', disegnaLista);
    $('filtroLista').addEventListener('change', disegnaLista);

    // Contatore in tempo reale (anche i check-in fatti da altri dispositivi), fermo se la scheda è nascosta
    aggiornaStato();
    setInterval(function () { if (!document.hidden) aggiornaStato(); }, 5000);
    $('inputCodice').focus();
})();
</script>
<?php endif; ?>

<?php require_once 'admin_footer.php'; ?>
