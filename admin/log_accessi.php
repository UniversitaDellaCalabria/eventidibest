<?php
// admin/log_accessi.php - Log accessi SSO
ob_start();
require_once 'admin_header.php';

if (!$is_full_admin) {
    echo "<div class='alert alert-danger fw-bold m-4'><i class='fa fa-ban me-2'></i> Accesso negato: solo Super Admin.</div>";
    require_once 'admin_footer.php'; exit;
}


$per_page = 50;
$page     = max(1, (int)($_GET['page'] ?? 1));

$f_cerca  = trim($_GET['f_cerca'] ?? '');
$f_da     = (isset($_GET['f_da'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['f_da']))   ? $_GET['f_da']   : '';
$f_fine   = (isset($_GET['f_fine']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['f_fine'])) ? $_GET['f_fine'] : '';

$cond = "WHERE 1";
if (!empty($f_cerca)) {
    $esc = $conn->real_escape_string($f_cerca);
    $cond .= " AND (email LIKE '%$esc%' OR nome LIKE '%$esc%' OR cognome LIKE '%$esc%' OR ip LIKE '%$esc%')";
}
if (!empty($f_da))   $cond .= " AND DATE(created_at) >= '$f_da'";
if (!empty($f_fine)) $cond .= " AND DATE(created_at) <= '$f_fine'";

$total = 0;
$r_tot = $conn->query("SELECT COUNT(*) as c FROM log_accessi $cond");
if ($r_tot) $total = (int)$r_tot->fetch_assoc()['c'];
$total_pages = max(1, (int)ceil($total / $per_page));
$page   = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$righe = [];
$res = $conn->query("SELECT * FROM log_accessi $cond ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
if ($res) while ($r = $res->fetch_assoc()) $righe[] = $r;
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h4 class="fw-bold text-dark m-0"><i class="fa fa-sign-in-alt text-success me-2"></i> Log Accessi SSO</h4>
    <span class="badge bg-secondary fs-6"><?php echo $total; ?> accessi totali</span>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-2">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
            <input type="hidden" name="p_id" value="<?php echo $filtro_p; ?>">
            <div class="input-group input-group-sm" style="max-width:260px;">
                <span class="input-group-text"><i class="fa fa-search"></i></span>
                <input type="text" name="f_cerca" class="form-control" placeholder="Email, nome, IP..." value="<?php echo htmlspecialchars($f_cerca); ?>">
            </div>
            <input type="date" name="f_da"   class="form-control form-control-sm" style="max-width:150px;" value="<?php echo htmlspecialchars($f_da); ?>"   title="Dal">
            <input type="date" name="f_fine" class="form-control form-control-sm" style="max-width:150px;" value="<?php echo htmlspecialchars($f_fine); ?>" title="Al">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-filter me-1"></i> Filtra</button>
            <?php if (!empty($f_cerca) || !empty($f_da) || !empty($f_fine)): ?>
                <a href="log_accessi.php?p_id=<?php echo $filtro_p; ?>" class="btn btn-outline-danger btn-sm"><i class="fa fa-times me-1"></i> Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle m-0 small">
                <thead class="table-dark">
                    <tr>
                        <th class="px-3">Data / Ora</th>
                        <th>Utente</th>
                        <th>Email</th>
                        <th>Tipo</th>
                        <th>IP</th>
                        <th>Browser / OS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($righe)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">Nessun accesso registrato.</td></tr>
                    <?php else: ?>
                        <?php foreach ($righe as $r): ?>
                        <tr>
                            <td class="px-3 text-nowrap">
                                <span class="fw-bold"><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></span><br>
                                <small class="text-muted"><?php echo date('H:i:s', strtotime($r['created_at'])); ?></small>
                            </td>
                            <td>
                                <?php echo htmlspecialchars(trim($r['nome'] . ' ' . $r['cognome'])); ?>
                                <?php if ($r['utente_id']): ?>
                                    <br><small class="text-muted">ID <?php echo (int)$r['utente_id']; ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($r['email'] ?? ''); ?></td>
                            <td>
                                <span class="badge <?php echo $r['tipo'] === 'sso' ? 'bg-primary' : 'bg-secondary'; ?>">
                                    <?php echo htmlspecialchars(strtoupper($r['tipo'] ?? 'SSO')); ?>
                                </span>
                            </td>
                            <td><code><?php echo htmlspecialchars($r['ip'] ?? ''); ?></code></td>
                            <td>
                                <small class="text-muted" style="max-width:300px; display:block; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['user_agent'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($r['user_agent'] ?? ''); ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <small class="text-muted">Pagina <?php echo $page; ?> di <?php echo $total_pages; ?> (<?php echo $total; ?> record)</small>
        <nav>
            <ul class="pagination pagination-sm m-0">
                <?php for ($i = max(1, $page-2); $i <= min($total_pages, $page+2); $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?p_id=<?php echo $filtro_p; ?>&page=<?php echo $i; ?>&f_cerca=<?php echo urlencode($f_cerca); ?>&f_da=<?php echo urlencode($f_da); ?>&f_fine=<?php echo urlencode($f_fine); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'admin_footer.php'; ?>
