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
    $widgets = ['slideshow'=>1,'annunci'=>0,'card_aree'=>1,'prossimi_eventi'=>1,'statistiche'=>0,
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

$col_aree_cls = [2 => 'col-md-6', 3 => 'col-md-6 col-lg-4', 4 => 'col-sm-6 col-lg-3'][$widgets['aree_colonne']];
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
                    <h4 class="fw-bold mb-1" style="text-shadow:0 1px 3px rgba(0,0,0,.6);"><?php echo htmlspecialchars($sl['titolo']); ?></h4>
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
                <h4 class="text-secondary fw-bold">Nessun evento o area di lavoro attualmente disponibile.</h4>
            </div>
        <?php else: ?>
            <?php foreach ($pagine as $i_area => $p):
                $nascosta = $aree_max > 0 && $i_area >= $aree_max;
            ?>
                <div class="<?php echo $col_aree_cls; ?> align-items-stretch <?php echo $nascosta ? 'area-extra d-none' : 'd-flex'; ?>">
                    <div class="card-portal w-100">
                        <?php
                            $ha_copertina = !empty($p['copertina_path']) && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $p['copertina_path']);
                            $col1 = htmlspecialchars($p['colore_primario']);
                            $col2 = htmlspecialchars($p['colore_secondario']);
                        ?>
                        <div class="card-portal-header">
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
                            </div>
                            <div>
                                <a href="<?php echo htmlspecialchars($p['slug']); ?>.php" class="btn-esplora w-100 text-white shadow-sm" style="background-color: <?php echo htmlspecialchars($p['colore_primario']); ?>;">
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
        <h6 class="fw-bold text-secondary text-uppercase mb-0" style="letter-spacing:.05em;font-size:.78rem;"><i class="fa fa-calendar-day me-2"></i>Prossimi Appuntamenti</h6>
        <?php if (!$griglia): ?>
        <div class="d-flex gap-1">
            <button type="button" onclick="prossimiBtnScroll(-1)" class="btn btn-sm btn-outline-secondary rounded-circle" style="width:32px;height:32px;padding:0;line-height:1;" aria-label="Scorri indietro"><i class="fa fa-chevron-left" style="font-size:.75rem;"></i></button>
            <button type="button" onclick="prossimiBtnScroll(1)"  class="btn btn-sm btn-outline-secondary rounded-circle" style="width:32px;height:32px;padding:0;line-height:1;" aria-label="Scorri avanti"><i class="fa fa-chevron-right" style="font-size:.75rem;"></i></button>
        </div>
        <?php endif; ?>
    </div>
    <div <?php echo $griglia ? 'class="row g-3"' : 'id="prossimi-scroll" class="d-flex gap-2" style="overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none;-ms-overflow-style:none;"'; ?>>
        <?php foreach ($prossimi_ev as $ev):
            $col1 = htmlspecialchars($ev['colore_primario']);
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
                    <span class="badge fw-bold d-block mb-1 text-truncate" style="background:<?php echo $col1; ?>;font-size:0.65rem;"><?php echo htmlspecialchars($ev['area_titolo']); ?></span>
                    <div class="fw-bold text-dark lh-sm mb-1" style="font-size:0.82rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo htmlspecialchars($ev['titolo']); ?></div>
                    <div class="text-muted" style="font-size:0.75rem;"><i class="fa fa-calendar me-1"></i><?php echo $data_fmt; ?><?php echo $ora_fmt ? ' · '.$ora_fmt : ''; ?></div>
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
foreach ($widgets['ordine'] as $chiave_widget) {
    echo $blocchi[$chiave_widget] ?? '';
}
?>

<?php require_once 'footer.php'; ?>
