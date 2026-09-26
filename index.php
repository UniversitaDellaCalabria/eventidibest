<?php
$page_title = "EventiDiBEST - Portale Eventi e Laboratori Dipartimentali";
require_once 'header.php';

$pagine = array_values(array_filter(get_pagine_eventi_visibili($conn), fn($p) => (int)($p['mostra_in_home'] ?? 1) === 1));
$pagine_home_ids = array_map('intval', array_column($pagine, 'id'));

// ── Configurazione widget home (attivazione, ordine, colonne, numero card) ────
if (function_exists('get_widgets_home')) {
    $widgets = get_widgets_home($cfg_portale_header ?? null);
} else {
    // functions.php sul server non aggiornato: meglio la home standard che una pagina bianca
    error_log('[index.php] get_widgets_home() mancante: caricare la versione aggiornata di functions.php');
    $widgets = ['slideshow'=>1,'mia_prenotazione'=>0,'annunci'=>0,'card_aree'=>1,'ultimi_posti'=>0,'prossimi_eventi'=>1,'statistiche'=>0,
                'ordine'=>['slideshow','annunci','card_aree','prossimi_eventi','statistiche'],
                'aree_colonne'=>2,'aree_max'=>0,'eventi_num'=>8,'eventi_layout'=>'scroll'];
}

$mesi_brevi = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];

// Slide attive per il carousel
$slides_home = [];
if ($widgets['slideshow']) {
    $res_slides = @$conn->query("SELECT * FROM slide_home WHERE attiva = 1 ORDER BY ordine ASC, id ASC");
    if ($res_slides) while ($sl = $res_slides->fetch_assoc()) $slides_home[] = $sl;
}

// Prossimi eventi (cross-area, ordinati per prossimo turno).
// I turni senza data non scadono mai: l'evento compare in coda come "data da definire".
// Sentinelle per l'ordinamento: data NULL -> 9999-12-31, orario NULL -> 99:99:99.
$prossimi_ev = [];
if ($widgets['prossimi_eventi'] && !empty($pagine_home_ids)) {
    $ids_home = implode(',', $pagine_home_ids);
    $lim_ev   = (int)$widgets['eventi_num'];
    $res_pr = @$conn->query(
        "SELECT e.id, e.titolo, e.locandina_path, e.pagina_id,
                p.titolo as area_titolo, p.colore_primario, p.slug,
                MIN(CONCAT(COALESCE(t.data_turno, '9999-12-31'), ' ', COALESCE(t.orario_inizio, '99:99:99'))) AS prossimo
         FROM eventi e
         JOIN pagine_eventi p ON e.pagina_id = p.id
         JOIN turni t ON t.evento_id = e.id
         WHERE e.archiviato = 0 AND p.visibile = 1 AND e.pagina_id IN ($ids_home)
           AND (t.data_turno IS NULL OR t.data_turno >= CURDATE())
         GROUP BY e.id, e.titolo, e.locandina_path, e.pagina_id, p.titolo, p.colore_primario, p.slug
         ORDER BY prossimo ASC
         LIMIT $lim_ev"
    );
    if ($res_pr) {
        while ($r = $res_pr->fetch_assoc()) {
            $d = substr($r['prossimo'], 0, 10);
            $o = substr($r['prossimo'], 11, 5);
            $r['prossima_data']   = $d === '9999-12-31' ? null : $d;
            $r['prossimo_orario'] = $o === '99:99' ? '' : $o;
            $prossimi_ev[] = $r;
        }
    }
}

// Statistiche automatiche
$stat = ['eventi'=>0,'aree'=>0,'iscritti'=>0];
if ($widgets['statistiche']) {
    $r1 = @$conn->query("SELECT COUNT(DISTINCT e.id) as n FROM eventi e JOIN pagine_eventi p ON e.pagina_id = p.id JOIN turni t ON t.evento_id=e.id
                         WHERE e.archiviato=0 AND p.visibile=1 AND (t.data_turno IS NULL OR t.data_turno>=CURDATE())");
    if ($r1) $stat['eventi'] = (int)$r1->fetch_assoc()['n'];
    $r2 = @$conn->query("SELECT COUNT(*) as n FROM pagine_eventi WHERE visibile=1");
    if ($r2) $stat['aree'] = (int)$r2->fetch_assoc()['n'];
    // Solo iscrizioni effettive (niente annullate, rifiutate, scadute o liste d'attesa)
    $r3 = @$conn->query("SELECT COUNT(*) as n FROM prenotazioni WHERE IFNULL(stato, 'confermata') = 'confermata'");
    if ($r3) $stat['iscritti'] = (int)$r3->fetch_assoc()['n'];
}

// La mia prossima prenotazione (solo utenti loggati)
$mie_pren = [];
if ($widgets['mia_prenotazione'] && !empty($u_logged_header)) {
    $mie_pren = get_prenotazioni_attive_utente($conn, (int)$_SESSION['utente_id'], 10);
}

// Ultimi posti / iscrizioni in chiusura
$ultimi_posti = [];
if ($widgets['ultimi_posti']) {
    $ultimi_posti = get_turni_ultimi_posti($conn, $pagine_home_ids, 4);
}

$col_aree_cls = [2 => 'col-md-6', 3 => 'col-md-6 col-lg-4', 4 => 'col-sm-6 col-lg-3'][$widgets['aree_colonne']];

// Posti dei turni ancora prenotabili, per le barre sulle card
$posti_ev   = $widgets['prossimi_eventi'] ? get_riepilogo_posti($conn, 'evento', array_column($prossimi_ev, 'id')) : [];
$posti_aree = $widgets['card_aree'] ? get_riepilogo_posti($conn, 'pagina', $pagine_home_ids) : [];

// Barra posti (stessi colori della pagina gruppi). $compatta: solo barra + posti liberi.
$barra_posti = function (?array $r, bool $compatta = false): string {
    if (!$r || $r['capienza'] <= 0) return '';
    $pct = min(100, (int)round($r['occupati'] / $r['capienza'] * 100));
    $cls = $pct >= 100 ? 'bg-danger' : ($pct >= 75 ? 'bg-warning' : 'bg-success');
    $lib = $r['liberi'] > 0 ? $r['liberi'] . ($r['liberi'] === 1 ? ' posto libero' : ' posti liberi') : 'Completo';
    $h  = '<div class="mt-2"><div class="progress" style="height:6px;" role="progressbar" aria-label="Posti occupati: ' . $pct . '%" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100">'
        . '<div class="progress-bar ' . $cls . '" style="width:' . $pct . '%;"></div></div>'
        . '<div class="d-flex justify-content-between mt-1" style="font-size:.72rem;">';
    if (!$compatta) $h .= '<span class="text-muted">' . $r['occupati'] . '/' . $r['capienza'] . ' iscritti</span>';
    $h .= '<span class="fw-bold ' . ($r['liberi'] > 0 ? 'text-success' : 'text-danger') . '">' . $lib . '</span></div></div>';
    return $h;
};
?>

<style>
    .card-portal { background: #ffffff; border-radius: 14px; border: 1px solid #e2e8f0; transition: transform 0.25s ease, box-shadow 0.25s ease; height: 100%; overflow: hidden; display: flex; flex-direction: column; }
    .card-portal:hover { transform: translateY(-4px); box-shadow: 0 10px 24px rgba(0,0,0,0.11); }
    .card-portal-header { padding: 20px 20px; color: white; min-height: 120px; display: flex; flex-direction: column; justify-content: flex-end; position: relative; }
    .card-portal-header-bg { position: absolute; inset: 0; background-size: cover; background-position: center; }
    .card-portal-header-overlay { position: absolute; inset: 0; }
    .card-portal-header-content { position: relative; z-index: 1; }
    .card-portal-body { padding: 18px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; }
    .btn-esplora { font-weight: 700; text-transform: uppercase; font-size: 0.8rem; padding: 10px 20px; border-radius: 7px; border: none; text-decoration: none; display: inline-block; text-align: center; }
    .aree-col-4 .card-portal-header { min-height: 100px; padding: 16px; }
    .aree-col-4 .card-portal-header h2 { font-size: 1.05rem !important; }
    .aree-col-4 .card-portal-body { padding: 14px; }
    .aree-col-4 .btn-esplora { font-size: .72rem; padding: 9px 12px; }
    #prossimi-scroll::-webkit-scrollbar { display: none; }
    /* Bootstrap Italia aggiunge 48px sotto ogni .card con ::after: in home non serve */
    #main-content .card::after { display: none !important; }
</style>

<?php
// Ogni widget viene preparato in un blocco e poi stampato nell'ordine scelto in admin
$blocchi = [];

// ── SLIDESHOW HOME ────────────────────────────────────────────────────────────
if ($widgets['slideshow'] && !empty($slides_home)):
ob_start(); ?>
<div id="carouselHome" class="carousel slide" data-bs-ride="carousel" data-bs-interval="5000" style="max-height:480px;overflow:hidden;">
    <div class="carousel-indicators">
        <?php foreach ($slides_home as $i => $sl): ?>
            <button type="button" data-bs-target="#carouselHome" data-bs-slide-to="<?php echo $i; ?>" <?php echo $i === 0 ? 'class="active" aria-current="true"' : ''; ?> aria-label="Slide <?php echo $i+1; ?>"></button>
        <?php endforeach; ?>
    </div>
    <div class="carousel-inner">
        <?php foreach ($slides_home as $i => $sl):
            $has_text = !empty($sl['titolo']) || !empty($sl['sottotitolo']);
        ?>
        <div class="carousel-item <?php echo $i === 0 ? 'active' : ''; ?>">
            <?php if (!empty($sl['link'])): ?>
                <a href="<?php echo htmlspecialchars($sl['link']); ?>">
            <?php endif; ?>
            <img src="<?php echo htmlspecialchars($sl['immagine_path']); ?>" class="d-block w-100" alt="<?php echo htmlspecialchars($sl['titolo'] ?? ''); ?>" style="max-height:480px;object-fit:cover;object-position:center;">
            <?php if (!empty($sl['link'])): ?></a><?php endif; ?>

            <?php if ($has_text): ?>
            <div class="carousel-caption d-none d-md-block" style="background:rgba(0,0,0,0.45);border-radius:10px;padding:16px 24px;bottom:30px;max-width:600px;margin:0 auto;left:0;right:0;">
                <?php if (!empty($sl['titolo'])): ?>
                    <h2 class="fw-bold fs-4 mb-1" style="text-shadow:0 1px 3px rgba(0,0,0,.6);"><?php echo htmlspecialchars($sl['titolo']); ?></h2>
                <?php endif; ?>
                <?php if (!empty($sl['sottotitolo'])): ?>
                    <p class="mb-0 small" style="opacity:.9;"><?php echo htmlspecialchars($sl['sottotitolo']); ?></p>
                <?php endif; ?>
                <?php if (!empty($sl['link'])): ?>
                    <a href="<?php echo htmlspecialchars($sl['link']); ?>" class="btn btn-sm btn-light fw-bold mt-2">Scopri <i class="fa fa-arrow-right ms-1"></i></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($slides_home) > 1): ?>
    <button class="carousel-control-prev" type="button" data-bs-target="#carouselHome" data-bs-slide="prev">
        <span class="carousel-control-prev-icon"></span><span class="visually-hidden">Precedente</span>
    </button>
    <button class="carousel-control-next" type="button" data-bs-target="#carouselHome" data-bs-slide="next">
        <span class="carousel-control-next-icon"></span><span class="visually-hidden">Successiva</span>
    </button>
    <?php endif; ?>
</div>
<?php $blocchi['slideshow'] = ob_get_clean();
endif;

// ── LA MIA PROSSIMA PRENOTAZIONE (utenti loggati) ─────────────────────────────
if ($widgets['mia_prenotazione'] && !empty($mie_pren)):
$mp          = $mie_pren[0];
$altre_pren  = count($mie_pren) - 1;
$col_mp      = colore_valido($mp['colore_primario'] ?? '');
$txt_mp      = colore_testo_su($col_mp);
$ha_data_mp  = !empty($mp['data_turno']);
$inizio_iso  = $ha_data_mp ? date('c', strtotime($mp['data_turno'] . ' ' . ($mp['orario_inizio'] ?: '00:00:00'))) : '';
$fine_iso    = $ha_data_mp ? date('c', strtotime($mp['data_turno'] . ' ' . ($mp['orario_fine'] ?: '23:59:59'))) : '';
$stati_mp = [
    'confermata'         => ['Confermata', 'success', 'fa-check-circle'],
    'richiesta_conferma' => ['Posto disponibile', 'warning', 'fa-bell'],
    'da_approvare'       => ['In valutazione', 'info', 'fa-hourglass-half'],
    'in_attesa'          => ["In lista d'attesa", 'secondary', 'fa-clock'],
];
[$st_lbl, $st_col, $st_ico] = $stati_mp[$mp['stato']] ?? $stati_mp['confermata'];
if ($mp['stato'] === 'in_attesa') {
    $pa_mp = get_posizioni_lista_attesa($conn, [(int)$mp['id']])[(int)$mp['id']] ?? null;
    if ($pa_mp) $st_lbl = $pa_mp['posizione'] === 1 ? "In lista: sei il prossimo" : "In lista: sei " . $pa_mp['posizione'] . "°";
}
$url_ricevuta = 'stampa_ricevuta.php?code=' . urlencode($mp['codice_prenotazione']);
$url_qr_mp    = 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&margin=10&data=' . urlencode(url_base_sito() . '/checkin.php?code=' . urlencode($mp['codice_prenotazione']));
ob_start(); ?>
<div class="container mt-4" style="max-width:1200px;">
    <div class="card border-0 shadow-sm overflow-hidden" style="border-left:6px solid <?php echo $col_mp; ?> !important;border-radius:12px;">
        <div class="card-body p-3 p-md-4 d-flex flex-column flex-lg-row gap-3 align-items-lg-center">
            <div class="flex-grow-1" style="min-width:0;">
                <div class="text-uppercase fw-bold text-secondary mb-1" style="letter-spacing:.05em;font-size:.72rem;"><i class="fa fa-ticket me-1"></i>La tua prossima prenotazione</div>
                <h2 class="fw-bold fs-5 mb-2 text-truncate" style="color:<?php echo $col_mp; ?>;"><?php echo htmlspecialchars($mp['evento_titolo']); ?></h2>
                <div class="small text-secondary fw-semibold d-flex flex-wrap" style="gap:.25rem 1.1rem;">
                    <span><i class="fa fa-layer-group me-1"></i><?php echo htmlspecialchars($mp['area_titolo']); ?></span>
                    <span><i class="fa fa-calendar-day me-1"></i><?php echo htmlspecialchars(etichetta_turno($mp)); ?></span>
                    <?php if (!empty($mp['luogo'])): ?><span><i class="fa fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($mp['luogo']); ?></span><?php endif; ?>
                    <?php if ((int)$mp['num_posti'] > 1): ?><span><i class="fa fa-users me-1"></i><?php echo (int)$mp['num_posti']; ?> posti</span><?php endif; ?>
                </div>
            </div>

            <div class="flex-shrink-0 text-lg-center" style="min-width:150px;">
                <div id="mpCountdown" class="fw-bold fs-5 text-dark" data-inizio="<?php echo $inizio_iso; ?>" data-fine="<?php echo $fine_iso; ?>">
                    <?php echo $ha_data_mp ? htmlspecialchars(date('d/m/Y', strtotime($mp['data_turno']))) : 'Data da definire'; ?>
                </div>
                <span class="badge bg-<?php echo $st_col; ?><?php echo $st_col === 'warning' || $st_col === 'info' ? ' text-dark' : ''; ?> mt-1"><i class="fa <?php echo $st_ico; ?> me-1"></i><?php echo $st_lbl; ?></span>
            </div>

            <div class="d-flex flex-wrap gap-2 flex-shrink-0">
                <?php if ($mp['stato'] === 'richiesta_conferma'): ?>
                    <a href="area_personale.php?conferma_posto=<?php echo (int)$mp['id']; ?>" class="btn btn-success fw-bold"><i class="fa fa-check me-1"></i>Conferma il posto</a>
                <?php elseif ($mp['stato'] === 'confermata'): ?>
                    <button type="button" class="btn fw-bold" style="background:<?php echo $col_mp; ?>;color:<?php echo $txt_mp; ?>;" data-bs-toggle="modal" data-bs-target="#modBigliettoHome"><i class="fa fa-qrcode me-1" aria-hidden="true"></i>Ricevuta</button>
                    <a href="<?php echo $url_ricevuta; ?>" target="_blank" class="btn fw-bold" style="border:2px solid #17334F;color:#17334F;"><i class="fa fa-file-pdf me-1" aria-hidden="true"></i>Scarica PDF</a>
                <?php else: ?>
                    <a href="area_personale.php" class="btn fw-bold" style="border:2px solid #17334F;color:#17334F;"><i class="fa fa-eye me-1" aria-hidden="true"></i>Dettagli</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($mp['stato'] === 'richiesta_conferma' && !empty($mp['scadenza_conferma'])): ?>
            <div class="px-3 px-md-4 py-2 small fw-bold bg-warning bg-opacity-25 border-top">
                <i class="fa fa-hourglass-half me-1"></i>Si è liberato un posto per te: conferma entro il <?php echo date('d/m/Y \a\l\l\e H:i', strtotime($mp['scadenza_conferma'])); ?>.
            </div>
        <?php endif; ?>
        <?php if ($altre_pren > 0): ?>
            <div class="px-3 px-md-4 py-2 small bg-light border-top">
                <a href="area_personale.php" class="text-decoration-none fw-semibold">Hai <?php echo $altre_pren === 1 ? "un'altra prenotazione attiva" : "altre $altre_pren prenotazioni attive"; ?> <i class="fa fa-arrow-right ms-1"></i></a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($mp['stato'] === 'confermata'): ?>
<div class="modal fade" id="modBigliettoHome" tabindex="-1" aria-labelledby="modBigliettoHomeLbl" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content text-center">
            <div class="modal-header border-0" style="background:<?php echo $col_mp; ?>;color:<?php echo $txt_mp; ?>;">
                <h5 class="modal-title fw-bold" id="modBigliettoHomeLbl"><i class="fa fa-ticket me-2" aria-hidden="true"></i>La tua ricevuta</h5>
                <button type="button" class="btn-close<?php echo $txt_mp === '#FFFFFF' ? ' btn-close-white' : ''; ?>" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body d-flex flex-column align-items-center justify-content-center p-4">
                <div class="fw-bold fs-5 mb-1"><?php echo htmlspecialchars($mp['evento_titolo']); ?></div>
                <div class="text-secondary small fw-semibold mb-3"><?php echo htmlspecialchars(etichetta_turno($mp)); ?></div>
                <img src="<?php echo htmlspecialchars($url_qr_mp); ?>" alt="QR code del biglietto <?php echo htmlspecialchars($mp['codice_prenotazione']); ?>" class="img-fluid border rounded p-2 bg-white" style="width:320px;max-width:100%;" loading="lazy">
                <div class="font-monospace fw-bold fs-4 mt-3" style="letter-spacing:.08em;"><?php echo htmlspecialchars($mp['codice_prenotazione']); ?></div>
                <div class="small text-muted mt-2"><i class="fa fa-sun me-1"></i>Aumenta la luminosità dello schermo e mostra il QR all'ingresso.</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var el = document.getElementById('mpCountdown');
    if (!el || !el.dataset.inizio) return;
    var inizio = new Date(el.dataset.inizio), fine = new Date(el.dataset.fine);
    function aggiorna() {
        var ora = new Date(), diff = inizio - ora;
        if (ora >= inizio && ora <= fine) { el.textContent = 'In corso'; el.classList.add('text-success'); return; }
        if (diff <= 0) return;
        var min = Math.floor(diff / 60000), ore = Math.floor(min / 60), giorni = Math.floor(ore / 24);
        if (giorni >= 2)      el.textContent = 'Tra ' + giorni + ' giorni';
        else if (giorni === 1) el.textContent = 'Domani';
        else if (ore >= 1)    el.textContent = 'Tra ' + ore + ' h ' + (min % 60) + ' min';
        else                  el.textContent = 'Tra ' + Math.max(1, min) + ' min';
        if (ore < 24) el.classList.add('text-danger');
    }
    aggiorna();
    setInterval(aggiorna, 30000);
})();
</script>
<?php $blocchi['mia_prenotazione'] = ob_get_clean();
endif;

// ── ULTIMI POSTI / ISCRIZIONI IN CHIUSURA ─────────────────────────────────────
if ($widgets['ultimi_posti'] && !empty($ultimi_posti)):
ob_start(); ?>
<div class="container my-4" style="max-width:1200px;">
    <h2 class="fw-bold text-secondary text-uppercase mb-3" style="letter-spacing:.05em;font-size:.78rem;"><i class="fa fa-fire text-danger me-2" aria-hidden="true"></i>Ultimi posti disponibili</h2>
    <div class="row g-3">
        <?php foreach ($ultimi_posti as $up):
            $col_up  = colore_valido($up['colore_primario'] ?? '');
            $max_up  = max(1, (int)$up['max_posti']);
            $pct_up  = min(100, round((int)$up['occupati'] / $max_up * 100));
            $liberi  = (int)$up['liberi'];
            $ha_foto = !empty($up['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $up['locandina_path']);

            // Avviso chiusura iscrizioni (solo se entro 48 ore)
            $chiusura_txt = '';
            if (!empty($up['data_chiusura'])) {
                $ts_ch  = strtotime($up['data_chiusura']);
                $sec_ch = $ts_ch - time();
                if ($sec_ch > 0 && $sec_ch <= 48 * 3600) {
                    if ($sec_ch < 3600)                                 $chiusura_txt = 'Chiude tra ' . max(1, (int)floor($sec_ch / 60)) . ' min';
                    elseif (date('Y-m-d', $ts_ch) === date('Y-m-d'))    $chiusura_txt = 'Chiude oggi alle ' . date('H:i', $ts_ch);
                    elseif (date('Y-m-d', $ts_ch) === date('Y-m-d', strtotime('+1 day'))) $chiusura_txt = 'Chiude domani alle ' . date('H:i', $ts_ch);
                    else                                                $chiusura_txt = 'Chiude tra ' . (int)floor($sec_ch / 3600) . ' ore';
                }
            }
        ?>
        <div class="col-sm-6 col-lg-3">
            <a href="<?php echo htmlspecialchars($up['slug']); ?>.php" class="text-decoration-none">
                <div class="card h-100 border-0 shadow-sm" style="border-top:3px solid <?php echo $col_up; ?> !important;border-radius:10px;overflow:hidden;">
                    <?php if ($ha_foto): ?>
                        <div style="height:90px;background:url('<?php echo htmlspecialchars($up['locandina_path']); ?>') center/cover;"></div>
                    <?php endif; ?>
                    <div class="p-3 d-flex flex-column h-100">
                        <span class="badge fw-bold align-self-start mb-2 text-truncate mw-100" style="background:<?php echo $col_up; ?>;color:<?php echo colore_testo_su($col_up); ?>;font-size:.65rem;"><?php echo htmlspecialchars($up['area_titolo']); ?></span>
                        <div class="fw-bold text-dark lh-sm mb-1" style="font-size:.9rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo htmlspecialchars($up['titolo']); ?></div>
                        <div class="text-muted mb-2" style="font-size:.75rem;"><i class="fa fa-calendar me-1"></i><?php echo htmlspecialchars(etichetta_turno($up)); ?></div>
                        <div class="mt-auto">
                            <div class="progress mb-1" style="height:6px;" role="progressbar" aria-label="Posti occupati" aria-valuenow="<?php echo $pct_up; ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar <?php echo $pct_up >= 90 ? 'bg-danger' : 'bg-warning'; ?>" style="width:<?php echo $pct_up; ?>%;"></div>
                            </div>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1" style="font-size:.75rem;">
                                <?php if ($liberi <= max(1, (int)ceil($max_up * 0.10))): ?>
                                    <span class="fw-bold text-danger"><?php echo $liberi === 1 ? 'Ultimo posto!' : "Ultimi $liberi posti"; ?></span>
                                <?php else: ?>
                                    <span class="fw-bold text-success"><?php echo $liberi; ?> posti liberi</span>
                                <?php endif; ?>
                                <?php if ($chiusura_txt !== ''): ?><span class="badge bg-dark"><i class="fa fa-clock me-1"></i><?php echo $chiusura_txt; ?></span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php $blocchi['ultimi_posti'] = ob_get_clean();
endif;

// ── BACHECA ANNUNCI ───────────────────────────────────────────────────────────
if ($widgets['annunci'] && !empty($cfg_portale_header['annuncio_home'])):
$ann_col = htmlspecialchars($cfg_portale_header['annuncio_colore'] ?? 'info');
ob_start(); ?>
<div class="container mt-4" style="max-width:1200px;">
    <div class="alert alert-<?php echo $ann_col; ?> border-<?php echo $ann_col; ?> shadow-sm d-flex align-items-start gap-3" role="alert">
        <i class="fa fa-bullhorn fs-4 mt-1 flex-shrink-0"></i>
        <div><?php echo $cfg_portale_header['annuncio_home']; ?></div>
    </div>
</div>
<?php $blocchi['annunci'] = ob_get_clean();
endif;

// ── CARD AREE ─────────────────────────────────────────────────────────────────
if ($widgets['card_aree']):
$aree_max   = (int)$widgets['aree_max'];
$aree_extra = $aree_max > 0 ? max(0, count($pagine) - $aree_max) : 0;
ob_start(); ?>
<div class="container my-5 aree-col-<?php echo (int)$widgets['aree_colonne']; ?>" style="max-width: 1200px;">
    <div class="row g-4 justify-content-center">
        <?php if (empty($pagine)): ?>
            <div class="col-12 text-center p-5">
                <i class="fa fa-calendar-times text-muted display-1 mb-3"></i>
                <p class="text-secondary fw-bold fs-4">Nessun evento o area di lavoro attualmente disponibile.</p>
            </div>
        <?php else: ?>
            <?php foreach ($pagine as $i_area => $p):
                $nascosta = $aree_max > 0 && $i_area >= $aree_max;
            ?>
                <div class="<?php echo $col_aree_cls; ?> align-items-stretch <?php echo $nascosta ? 'area-extra d-none' : 'd-flex'; ?>">
                    <div class="card-portal w-100">
                        <?php
                            $ha_copertina = !empty($p['copertina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $p['copertina_path']);
                            $col1 = colore_valido($p['colore_primario'] ?? '');
                            $col2 = colore_valido($p['colore_secondario'] ?? '', $col1);
                        ?>
                        <div class="card-portal-header" style="color:<?php echo colore_testo_su($col1); ?>;">
                            <?php if ($ha_copertina): ?>
                                <div class="card-portal-header-bg" style="background-image: url('<?php echo htmlspecialchars($p['copertina_path']); ?>');"></div>
                                <div class="card-portal-header-overlay" style="background: linear-gradient(160deg, <?php echo $col1; ?>d0 0%, <?php echo $col2; ?>bb 100%);"></div>
                            <?php else: ?>
                                <div class="card-portal-header-overlay" style="background: linear-gradient(135deg, <?php echo $col1; ?> 0%, <?php echo $col2; ?> 100%); position:absolute; inset:0;"></div>
                            <?php endif; ?>
                            <div class="card-portal-header-content">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-white text-dark fw-bold text-uppercase" style="backdrop-filter:blur(4px);"><?php echo htmlspecialchars($p['sidebar_intervallo_date'] ?: 'Aperto'); ?></span>
                                    <i class="fa fa-calendar-check fs-4 text-white-50"></i>
                                </div>
                                <h2 class="fw-bold mt-2 mb-0" style="text-shadow: 0 1px 4px rgba(0,0,0,0.4);font-size:1.25rem;"><?php echo htmlspecialchars($p['titolo'] . ' ' . ($p['sottotitolo'] ?? '')); ?></h2>
                            </div>
                        </div>
                        <div class="card-portal-body">
                            <div class="mb-4">
                                <p class="text-secondary m-0" style="display: -webkit-box; -webkit-line-clamp: <?php echo $widgets['aree_colonne'] >= 4 ? 3 : 4; ?>; -webkit-box-orient: vertical; overflow: hidden;">
                                    <?php echo strip_tags($p['hero_descrizione'] ?? ''); ?>
                                </p>
                                <?php echo $barra_posti($posti_aree[(int)$p['id']] ?? null); ?>
                            </div>
                            <div>
                                <a href="<?php echo htmlspecialchars($p['slug']); ?>.php" class="btn-esplora w-100 shadow-sm" style="background-color: <?php echo $col1; ?>;color: <?php echo colore_testo_su($col1); ?>;">
                                    <?php echo $widgets['aree_colonne'] >= 4 ? 'Esplora e Prenota' : 'Esplora Programma e Prenota'; ?> <i class="fa fa-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php if ($aree_extra > 0): ?>
        <div class="text-center mt-4">
            <button type="button" id="btnMostraAree" class="btn btn-outline-secondary fw-bold px-4" aria-expanded="false">
                <i class="fa fa-th-large me-1"></i> Mostra tutte le aree (+<?php echo $aree_extra; ?>)
            </button>
        </div>
        <script>
        document.getElementById('btnMostraAree').addEventListener('click', function () {
            document.querySelectorAll('.area-extra').forEach(function (el) { el.classList.replace('d-none', 'd-flex'); });
            this.parentNode.remove();
        });
        </script>
    <?php endif; ?>
</div>
<?php $blocchi['card_aree'] = ob_get_clean();
endif;

// ── PROSSIMI EVENTI ───────────────────────────────────────────────────────────
if ($widgets['prossimi_eventi'] && !empty($prossimi_ev)):
$griglia = $widgets['eventi_layout'] === 'griglia';
ob_start(); ?>
<div class="container mb-5" style="max-width:1200px;">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="fw-bold text-secondary text-uppercase mb-0" style="letter-spacing:.05em;font-size:.78rem;"><i class="fa fa-calendar-day me-2" aria-hidden="true"></i>Prossimi Appuntamenti</h2>
        <?php if (!$griglia): ?>
        <div class="d-flex gap-1">
            <button type="button" onclick="prossimiBtnScroll(-1)" class="btn btn-sm btn-outline-secondary rounded-circle" style="width:32px;height:32px;padding:0;line-height:1;" aria-label="Scorri indietro"><i class="fa fa-chevron-left" style="font-size:.75rem;"></i></button>
            <button type="button" onclick="prossimiBtnScroll(1)"  class="btn btn-sm btn-outline-secondary rounded-circle" style="width:32px;height:32px;padding:0;line-height:1;" aria-label="Scorri avanti"><i class="fa fa-chevron-right" style="font-size:.75rem;"></i></button>
        </div>
        <?php endif; ?>
    </div>
    <div <?php echo $griglia ? 'class="row g-3"' : 'id="prossimi-scroll" class="d-flex gap-2" style="overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;-ms-overflow-style:none;"'; ?>>
        <?php foreach ($prossimi_ev as $ev):
            $col1 = colore_valido($ev['colore_primario'] ?? '');
            if (!empty($ev['prossima_data'])) {
                $ts = strtotime($ev['prossima_data']);
                $data_fmt = date('j', $ts) . ' ' . $mesi_brevi[(int)date('n', $ts)] . ' ' . date('Y', $ts);
            } else {
                $data_fmt = 'Data da definire';
            }
            $ora_fmt  = $ev['prossimo_orario'];
            $ha_foto  = !empty($ev['locandina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $ev['locandina_path']);
        ?>
        <a href="<?php echo htmlspecialchars($ev['slug']); ?>.php" class="text-decoration-none <?php echo $griglia ? 'col-6 col-md-4 col-lg-3' : 'flex-shrink-0'; ?>" style="<?php echo $griglia ? '' : 'scroll-snap-align:start;width:210px;'; ?>">
            <div class="card h-100 border-0 shadow-sm" style="border-top:3px solid <?php echo $col1; ?> !important;border-radius:10px;overflow:hidden;">
                <?php if ($ha_foto): ?>
                <div style="height:<?php echo $griglia ? 110 : 85; ?>px;background:url('<?php echo htmlspecialchars($ev['locandina_path']); ?>') center/cover;"></div>
                <?php else: ?>
                <div style="height:<?php echo $griglia ? 110 : 85; ?>px;background:linear-gradient(135deg,<?php echo $col1; ?>18 0%,<?php echo $col1; ?>38 100%);display:flex;align-items:center;justify-content:center;">
                    <i class="fa fa-calendar-star" style="color:<?php echo $col1; ?>;opacity:.4;font-size:1.8rem;"></i>
                </div>
                <?php endif; ?>
                <div class="p-3">
                    <span class="badge fw-bold d-block mb-1 text-truncate" style="background:<?php echo $col1; ?>;color:<?php echo colore_testo_su($col1); ?>;font-size:0.65rem;"><?php echo htmlspecialchars($ev['area_titolo']); ?></span>
                    <div class="fw-bold text-dark lh-sm mb-1" style="font-size:0.82rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo htmlspecialchars($ev['titolo']); ?></div>
                    <div class="text-muted" style="font-size:0.75rem;"><i class="fa fa-calendar me-1"></i><?php echo $data_fmt; ?><?php echo $ora_fmt ? ' · '.$ora_fmt : ''; ?></div>
                    <?php echo $barra_posti($posti_ev[(int)$ev['id']] ?? null, true); ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php if (!$griglia): ?>
<script>
function prossimiBtnScroll(dir) {
    var el = document.getElementById('prossimi-scroll');
    el.scrollBy({left: dir * 226, behavior: 'smooth'});
}
</script>
<?php endif; ?>
<?php $blocchi['prossimi_eventi'] = ob_get_clean();
endif;

// ── STATISTICHE ───────────────────────────────────────────────────────────────
if ($widgets['statistiche']):
ob_start(); ?>
<div style="background:linear-gradient(135deg,#1e293b 0%,#334155 100%); padding:24px 0; margin-bottom:0;">
    <div class="container" style="max-width:1200px;">
        <div class="row g-3 text-center text-white">
            <div class="col-4">
                <div class="fw-bold" style="font-size:2rem;"><?php echo $stat['eventi']; ?></div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size:.7rem;letter-spacing:.05em;">eventi in programma</div>
            </div>
            <div class="col-4 border-start border-end border-secondary">
                <div class="fw-bold" style="font-size:2rem;"><?php echo $stat['aree']; ?></div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size:.7rem;letter-spacing:.05em;">aree di lavoro</div>
            </div>
            <div class="col-4">
                <div class="fw-bold" style="font-size:2rem;"><?php echo number_format($stat['iscritti'], 0, ',', '.'); ?></div>
                <div class="text-white-50 text-uppercase fw-bold" style="font-size:.7rem;letter-spacing:.05em;">iscrizioni confermate</div>
            </div>
        </div>
    </div>
</div>
<?php $blocchi['statistiche'] = ob_get_clean();
endif;

// ── STAMPA NELL'ORDINE CONFIGURATO ────────────────────────────────────────────
echo '<h1 class="visually-hidden">' . htmlspecialchars($titolo_portale ?? 'EventiDiBEST') . '</h1>';
foreach ($widgets['ordine'] as $chiave_widget) {
    echo $blocchi[$chiave_widget] ?? '';
}
?>

<?php require_once 'footer.php'; ?>
