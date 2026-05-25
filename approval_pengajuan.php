<?php
require_once 'config.php';

/** Pastikan tabel pengajuan dana ada */
function pengajuan_dana_ensure_tables(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pengajuan_dana` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `nomor_pengajuan` varchar(50) DEFAULT NULL,
          `judul` varchar(255) NOT NULL,
          `pemohon` varchar(255) DEFAULT NULL,
          `total_nominal` decimal(15,2) DEFAULT 0,
          `keterangan` text,
          `status` enum('draft','submitted','approved_tim','approved_keuangan','approved_direktur','rejected') DEFAULT 'draft',
          `tanggal_pengajuan` date DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pengajuan_dana_tim` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `pengajuan_id` int(11) NOT NULL,
          `nama_program` varchar(255) NOT NULL,
          `nominal` decimal(15,2) NOT NULL DEFAULT 0,
          `keterangan` varchar(500) DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `pengajuan_id` (`pengajuan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `pengajuan_dana_approval` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `pengajuan_id` int(11) NOT NULL,
          `level` tinyint(4) NOT NULL,
          `approver_name` varchar(255) DEFAULT NULL,
          `status` enum('pending','approved','rejected') DEFAULT 'pending',
          `catatan` text,
          `tanggal_approval` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `pengajuan_level` (`pengajuan_id`,`level`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function pengajuan_dana_level_labels() {
    return [
        1 => ['label' => 'Tim Program', 'status_after' => 'approved_tim'],
        2 => ['label' => 'Keuangan', 'status_after' => 'approved_keuangan'],
        3 => ['label' => 'Direktur', 'status_after' => 'approved_direktur'],
    ];
}

function pengajuan_dana_init_approvals(PDO $pdo, $pengajuanId) {
    $labels = pengajuan_dana_level_labels();
    $stmt = $pdo->prepare("INSERT IGNORE INTO pengajuan_dana_approval (pengajuan_id, level, status) VALUES (?,?, 'pending')");
    foreach (array_keys($labels) as $lvl) {
        $stmt->execute([$pengajuanId, $lvl]);
    }
}

function pengajuan_dana_status_badge($status) {
    $map = [
        'draft' => 'badge-planning',
        'submitted' => 'badge-ongoing',
        'approved_tim' => 'badge-ongoing',
        'approved_keuangan' => 'badge-ongoing',
        'approved_direktur' => 'badge-completed',
        'rejected' => 'badge-cancelled',
    ];
    return $map[$status] ?? 'badge-planning';
}

function pengajuan_dana_status_label($status) {
    $map = [
        'draft' => 'Draft',
        'submitted' => 'Diajukan',
        'approved_tim' => 'Disetujui Tim Program',
        'approved_keuangan' => 'Disetujui Keuangan',
        'approved_direktur' => 'Disetujui Direktur',
        'rejected' => 'Ditolak',
    ];
    return $map[$status] ?? $status;
}

$setup_error = null;
try {
    pengajuan_dana_ensure_tables($pdo);
} catch (Throwable $e) {
    error_log('pengajuan_dana_ensure_tables: ' . $e->getMessage());
    $setup_error = 'Tabel pengajuan dana gagal dibuat: ' . $e->getMessage();
}

$view_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$error_msg = isset($_GET['err']) ? (string)$_GET['err'] : $setup_error;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_pengajuan') {
        try {
            $judul = trim($_POST['judul'] ?? '');
            $pemohon = trim($_POST['pemohon'] ?? '');
            $keterangan = trim($_POST['keterangan'] ?? '');
            $tanggal = $_POST['tanggal_pengajuan'] ?? date('Y-m-d');
            $nama_programs = $_POST['nama_program'] ?? [];
            $nominals = $_POST['nominal'] ?? [];
            $ket_tim = $_POST['keterangan_tim'] ?? [];

            if ($judul === '') {
                throw new Exception('Judul pengajuan wajib diisi.');
            }

            $timRows = [];
            $total = 0;
            foreach ($nama_programs as $i => $nama) {
                $nama = trim((string)$nama);
                $nom = isset($nominals[$i]) ? (float)str_replace(['.', ','], ['', '.'], preg_replace('/[^\d,.-]/', '', $nominals[$i])) : 0;
                if ($nama === '' && $nom <= 0) {
                    continue;
                }
                if ($nama === '') {
                    throw new Exception('Nama program pada baris tim wajib diisi jika ada nominal.');
                }
                $timRows[] = [
                    'nama_program' => $nama,
                    'nominal' => $nom,
                    'keterangan' => trim((string)($ket_tim[$i] ?? '')),
                ];
                $total += $nom;
            }
            if (empty($timRows)) {
                throw new Exception('Minimal satu baris tim program (nama program & nominal).');
            }

            $nomor = 'PD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO pengajuan_dana (nomor_pengajuan, judul, pemohon, total_nominal, keterangan, status, tanggal_pengajuan) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$nomor, $judul, $pemohon, $total, $keterangan ?: null, 'submitted', $tanggal]);
            $pid = (int)$pdo->lastInsertId();

            $stmtTim = $pdo->prepare("INSERT INTO pengajuan_dana_tim (pengajuan_id, nama_program, nominal, keterangan) VALUES (?,?,?,?)");
            foreach ($timRows as $tr) {
                $stmtTim->execute([$pid, $tr['nama_program'], $tr['nominal'], $tr['keterangan'] ?: null]);
            }
            pengajuan_dana_init_approvals($pdo, $pid);
            $pdo->commit();

            app_redirect('approval_pengajuan.php?view=' . $pid . '&msg=' . urlencode('Pengajuan dana berhasil dibuat.'));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('add_pengajuan: ' . $e->getMessage());
            app_redirect('approval_pengajuan.php?err=' . urlencode($e->getMessage()));
        }
    }

    if ($action === 'approve_level') {
        try {
            $pid = (int)($_POST['pengajuan_id'] ?? 0);
            $level = (int)($_POST['level'] ?? 0);
            $decision = $_POST['decision'] ?? 'approved';
            $approver = trim($_POST['approver_name'] ?? '');
            $catatan = trim($_POST['catatan'] ?? '');

            $labels = pengajuan_dana_level_labels();
            if (!isset($labels[$level])) {
                throw new Exception('Level approval tidak valid.');
            }

            $row = $pdo->prepare("SELECT * FROM pengajuan_dana WHERE id=?");
            $row->execute([$pid]);
            $pengajuan = $row->fetch();
            if (!$pengajuan) {
                throw new Exception('Pengajuan tidak ditemukan.');
            }

            $needStatus = [1 => 'submitted', 2 => 'approved_tim', 3 => 'approved_keuangan'];
            if ($pengajuan['status'] !== $needStatus[$level]) {
                throw new Exception('Urutan approval belum sesuai. Status saat ini: ' . pengajuan_dana_status_label($pengajuan['status']));
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE pengajuan_dana_approval SET status=?, approver_name=?, catatan=?, tanggal_approval=NOW() WHERE pengajuan_id=? AND level=?");
            $stmt->execute([$decision === 'approved' ? 'approved' : 'rejected', $approver ?: null, $catatan ?: null, $pid, $level]);

            if ($decision === 'rejected') {
                $pdo->prepare("UPDATE pengajuan_dana SET status='rejected' WHERE id=?")->execute([$pid]);
            } else {
                $newStatus = $labels[$level]['status_after'];
                $pdo->prepare("UPDATE pengajuan_dana SET status=? WHERE id=?")->execute([$newStatus, $pid]);
            }
            $pdo->commit();

            app_redirect('approval_pengajuan.php?view=' . $pid . '&msg=' . urlencode('Approval level ' . $labels[$level]['label'] . ' berhasil disimpan.'));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('approve_level: ' . $e->getMessage());
            $back = (int)($_POST['pengajuan_id'] ?? 0);
            $url = $back > 0
                ? 'approval_pengajuan.php?view=' . $back . '&err=' . urlencode($e->getMessage())
                : 'approval_pengajuan.php?err=' . urlencode($e->getMessage());
            app_redirect($url);
        }
    }
}

// Daftar program CSR untuk autocomplete
$program_csr_list = [];
try {
    $program_csr_list = $pdo->query("SELECT nama_program FROM program_csr ORDER BY nama_program")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $program_csr_list = [];
}

$pengajuan_list = $pdo->query("
    SELECT p.*, 
        (SELECT COUNT(*) FROM pengajuan_dana_tim t WHERE t.pengajuan_id = p.id) AS jumlah_tim
    FROM pengajuan_dana p
    ORDER BY p.created_at DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$view_pengajuan = null;
$view_tim = [];
$view_approvals = [];
if ($view_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM pengajuan_dana WHERE id=?");
    $stmt->execute([$view_id]);
    $view_pengajuan = $stmt->fetch();
    if ($view_pengajuan) {
        $stmt = $pdo->prepare("SELECT * FROM pengajuan_dana_tim WHERE pengajuan_id=? ORDER BY id");
        $stmt->execute([$view_id]);
        $view_tim = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT * FROM pengajuan_dana_approval WHERE pengajuan_id=? ORDER BY level");
        $stmt->execute([$view_id]);
        $view_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($view_approvals)) {
            pengajuan_dana_init_approvals($pdo, $view_id);
            $stmt->execute([$view_id]);
            $view_approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$level_labels = pengajuan_dana_level_labels();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Approval Bertingkat - Pengajuan Dana</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= getCssLink() ?>
    <style>
        .approval-steps { display:flex; gap:8px; flex-wrap:wrap; margin:16px 0; }
        .approval-step {
            flex:1; min-width:140px; padding:12px; border-radius:8px;
            border:1px solid var(--border-color); background:#fff; text-align:center;
        }
        .approval-step.done { border-color:var(--success-color); background:#f0fff4; }
        .approval-step.rejected { border-color:var(--danger-color); background:#fff5f5; }
        .approval-step.pending { opacity:0.85; }
        .tim-row { display:grid; grid-template-columns:2fr 1fr 1.5fr auto; gap:8px; margin-bottom:8px; align-items:end; }
        @media (max-width:768px) { .tim-row { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<div class="navbar">
    <div class="navbar-brand">
        <?php
        $logoFile = __DIR__ . '/logo.php';
        if (is_readable($logoFile)) {
            include $logoFile;
        } else {
            echo '<h1 class="navbar-title-fallback">🏛️ Rangkiang Peduli Negeri</h1>';
        }
        ?>
    </div>
    <?= getNavMenu() ?>
</div>

<div class="container">
    <div class="card-header">
        <h1 style="margin:0">✅ Approval Bertingkat — Pengajuan Dana</h1>
        <?php if (!$view_pengajuan): ?>
        <button type="button" class="btn btn-success" onclick="document.getElementById('modalPengajuan').style.display='block'">+ Pengajuan Dana</button>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <?php if ($view_pengajuan): ?>
    <a href="approval_pengajuan.php" class="btn" style="margin-bottom:16px">← Kembali ke Daftar</a>

    <div class="card">
        <h3><?= htmlspecialchars($view_pengajuan['judul']) ?></h3>
        <div class="detail-grid" style="margin-top:12px">
            <div class="detail-item"><label>No. Pengajuan</label><span><?= htmlspecialchars($view_pengajuan['nomor_pengajuan'] ?? '-') ?></span></div>
            <div class="detail-item"><label>Pemohon</label><span><?= htmlspecialchars($view_pengajuan['pemohon'] ?? '-') ?></span></div>
            <div class="detail-item"><label>Tanggal</label><span><?= $view_pengajuan['tanggal_pengajuan'] ? date('d/m/Y', strtotime($view_pengajuan['tanggal_pengajuan'])) : '-' ?></span></div>
            <div class="detail-item"><label>Total</label><span class="text-success"><strong><?= formatRupiah($view_pengajuan['total_nominal']) ?></strong></span></div>
            <div class="detail-item"><label>Status</label>
                <span class="badge <?= pengajuan_dana_status_badge($view_pengajuan['status']) ?>"><?= pengajuan_dana_status_label($view_pengajuan['status']) ?></span>
            </div>
        </div>
        <?php if ($view_pengajuan['keterangan']): ?>
        <p style="margin-top:12px;color:var(--light-text)"><?= nl2br(htmlspecialchars($view_pengajuan['keterangan'])) ?></p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3>👥 Tim Program</h3>
        <table>
            <thead>
                <tr><th>No</th><th>Nama Program</th><th>Nominal</th><th>Keterangan</th></tr>
            </thead>
            <tbody>
                <?php foreach ($view_tim as $i => $t): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($t['nama_program']) ?></strong></td>
                    <td class="text-success"><?= formatRupiah($t['nominal']) ?></td>
                    <td><?= htmlspecialchars($t['keterangan'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="text-right"><strong>Total</strong></td>
                    <td colspan="2"><strong><?= formatRupiah($view_pengajuan['total_nominal']) ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="card">
        <h3>Alur Approval Bertingkat</h3>
        <div class="approval-steps">
            <?php
            $approvalByLevel = [];
            foreach ($view_approvals as $a) {
                $approvalByLevel[(int)$a['level']] = $a;
            }
            foreach ($level_labels as $lvl => $meta):
                $ap = $approvalByLevel[$lvl] ?? null;
                $cls = 'pending';
                if ($ap && $ap['status'] === 'approved') $cls = 'done';
                if ($ap && $ap['status'] === 'rejected') $cls = 'rejected';
            ?>
            <div class="approval-step <?= $cls ?>">
                <div style="font-size:20px"><?= $lvl ?></div>
                <strong><?= htmlspecialchars($meta['label']) ?></strong>
                <div style="font-size:12px;margin-top:4px">
                    <?= $ap ? ucfirst($ap['status']) : 'Pending' ?>
                    <?php if ($ap && $ap['approver_name']): ?><br><small><?= htmlspecialchars($ap['approver_name']) ?></small><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php
        $canApprove = !in_array($view_pengajuan['status'], ['rejected', 'approved_direktur'], true);
        $nextLevel = null;
        if ($view_pengajuan['status'] === 'submitted') $nextLevel = 1;
        elseif ($view_pengajuan['status'] === 'approved_tim') $nextLevel = 2;
        elseif ($view_pengajuan['status'] === 'approved_keuangan') $nextLevel = 3;
        ?>

        <?php if ($canApprove && $nextLevel): ?>
        <form method="POST" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-color)">
            <input type="hidden" name="action" value="approve_level">
            <input type="hidden" name="pengajuan_id" value="<?= (int)$view_pengajuan['id'] ?>">
            <input type="hidden" name="level" value="<?= $nextLevel ?>">
            <h4>Approval: <?= htmlspecialchars($level_labels[$nextLevel]['label']) ?></h4>
            <div class="form-row">
                <div class="form-group">
                    <label>Nama Approver</label>
                    <input type="text" name="approver_name" placeholder="Nama penyetuju">
                </div>
                <div class="form-group">
                    <label>Keputusan</label>
                    <select name="decision">
                        <option value="approved">Setujui</option>
                        <option value="rejected">Tolak</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Catatan</label>
                <textarea name="catatan" rows="2" placeholder="Catatan approval (opsional)"></textarea>
            </div>
            <button type="submit" class="btn btn-success">Simpan Approval</button>
        </form>
        <?php elseif ($view_pengajuan['status'] === 'approved_direktur'): ?>
        <p class="alert alert-success" style="margin-top:12px">Pengajuan telah disetujui penuh (3 level).</p>
        <?php elseif ($view_pengajuan['status'] === 'rejected'): ?>
        <p class="alert alert-error" style="margin-top:12px">Pengajuan ditolak.</p>
        <?php endif; ?>
    </div>

    <?php else: ?>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nomor</th>
                    <th>Judul</th>
                    <th>Pemohon</th>
                    <th>Tanggal</th>
                    <th>Tim Program</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pengajuan_list)): ?>
                <tr><td colspan="9" style="text-align:center;padding:32px;color:#999">Belum ada pengajuan dana.</td></tr>
                <?php else: ?>
                <?php foreach ($pengajuan_list as $idx => $p): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td><?= htmlspecialchars($p['nomor_pengajuan'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($p['judul']) ?></td>
                    <td><?= htmlspecialchars($p['pemohon'] ?? '-') ?></td>
                    <td><?= $p['tanggal_pengajuan'] ? date('d/m/Y', strtotime($p['tanggal_pengajuan'])) : '-' ?></td>
                    <td><?= (int)$p['jumlah_tim'] ?> program</td>
                    <td class="text-success"><strong><?= formatRupiah($p['total_nominal']) ?></strong></td>
                    <td><span class="badge <?= pengajuan_dana_status_badge($p['status']) ?>"><?= pengajuan_dana_status_label($p['status']) ?></span></td>
                    <td><a href="?view=<?= (int)$p['id'] ?>" class="btn" style="padding:5px 10px;font-size:12px">Detail</a></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Pengajuan -->
<div id="modalPengajuan" class="modal">
    <div class="modal-content" style="max-width:720px">
        <span class="close" onclick="document.getElementById('modalPengajuan').style.display='none'">&times;</span>
        <h2>Pengajuan Dana Baru</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_pengajuan">
            <div class="form-group">
                <label>Judul Pengajuan *</label>
                <input type="text" name="judul" required placeholder="Contoh: Pengajuan dana program Q1 2026">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Pemohon</label>
                    <input type="text" name="pemohon" placeholder="Nama pemohon / divisi">
                </div>
                <div class="form-group">
                    <label>Tanggal Pengajuan</label>
                    <input type="date" name="tanggal_pengajuan" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Keterangan</label>
                <textarea name="keterangan" rows="2" placeholder="Ringkasan kebutuhan dana"></textarea>
            </div>

            <h4 style="margin:16px 0 8px">Tim Program (nama program & nominal)</h4>
            <div id="timProgramRows">
                <div class="tim-row">
                    <div class="form-group" style="margin:0">
                        <label>Nama Program</label>
                        <input type="text" name="nama_program[]" list="programDatalist" required placeholder="Nama program">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label>Nominal (Rp)</label>
                        <input type="number" name="nominal[]" min="0" step="1" required placeholder="0">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label>Keterangan</label>
                        <input type="text" name="keterangan_tim[]" placeholder="Opsional">
                    </div>
                    <button type="button" class="btn btn-danger btn-xs" onclick="this.closest('.tim-row').remove()" title="Hapus baris">×</button>
                </div>
            </div>
            <button type="button" class="btn btn-sm" style="margin-top:8px" onclick="addTimRow()">+ Tambah Program</button>

            <datalist id="programDatalist">
                <?php foreach ($program_csr_list as $pn): ?>
                <option value="<?= htmlspecialchars($pn) ?>">
                <?php endforeach; ?>
            </datalist>

            <div class="text-right" style="margin-top:20px">
                <button type="button" class="btn" onclick="document.getElementById('modalPengajuan').style.display='none'">Batal</button>
                <button type="submit" class="btn btn-success">Ajukan</button>
            </div>
        </form>
    </div>
</div>

<script>
function addTimRow() {
    var wrap = document.getElementById('timProgramRows');
    var row = document.createElement('div');
    row.className = 'tim-row';
    row.innerHTML = '<div class="form-group" style="margin:0"><label>Nama Program</label><input type="text" name="nama_program[]" list="programDatalist" required placeholder="Nama program"></div>' +
        '<div class="form-group" style="margin:0"><label>Nominal (Rp)</label><input type="number" name="nominal[]" min="0" step="1" required placeholder="0"></div>' +
        '<div class="form-group" style="margin:0"><label>Keterangan</label><input type="text" name="keterangan_tim[]" placeholder="Opsional"></div>' +
        '<button type="button" class="btn btn-danger btn-xs" onclick="this.closest(\'.tim-row\').remove()">×</button>';
    wrap.appendChild(row);
}
window.addEventListener('click', function(e) {
    var m = document.getElementById('modalPengajuan');
    if (e.target === m) m.style.display = 'none';
});
</script>

<?= getGlobalUiEnhancer() ?>
</body>
</html>
