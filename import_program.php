<?php
require_once 'config.php';
require_once __DIR__ . '/lib/program_import.php';

// Duplikasi minimal insert (program.php tidak modular untuk require)
function program_csr_columns_import(PDO $pdo) {
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM program_csr")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $cols = [];
    }
    return $cols;
}

function program_csr_insert_import(PDO $pdo, array $row) {
    $allowed = array_flip(program_csr_columns_import($pdo));
    if (!isset($allowed['nama_program'])) {
        throw new PDOException('Tabel program_csr tidak tersedia.');
    }
    $cols = [];
    $ph = [];
    $params = [];
    foreach ($row as $key => $val) {
        if ($key === 'id' || !isset($allowed[$key])) {
            continue;
        }
        if ($val === null || $val === '') {
            continue;
        }
        $cols[] = '`' . $key . '`';
        $ph[] = '?';
        $params[] = $val;
    }
    if (empty($cols)) {
        throw new PDOException('Data program kosong.');
    }
    $sql = 'INSERT INTO program_csr (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

// Template CSV
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_program.csv"');
    $headers = ['Nama Program', 'Kategori', 'Deskripsi', 'Lokasi', 'Kecamatan', 'Kota', 'Provinsi', 'Tanggal Mulai', 'Tanggal Selesai', 'Budget', 'Status', 'Latitude', 'Longitude'];
    echo implode(';', $headers) . "\n";
    echo 'Program Bantuan Pendidikan;pendidikan;Bantuan sekolah;Jl. Contoh;Lubuk Begalung;Padang;Sumatera Barat;01/01/2026;31/12/2026;50000000;planning;-0.9492;100.3543';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    app_redirect('program.php?err=' . urlencode('Metode tidak valid'));
}

if (empty($_FILES['file_import']['tmp_name']) || !is_uploaded_file($_FILES['file_import']['tmp_name'])) {
    app_redirect('program.php?err=' . urlencode('Pilih file CSV atau Excel (.xls) terlebih dahulu.'));
}

$name = $_FILES['file_import']['name'] ?? '';
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xls', 'xlsx'], true)) {
    app_redirect('program.php?err=' . urlencode('Format file harus .csv, .xls, atau .xlsx'));
}

try {
    $raw = program_import_read_rows($_FILES['file_import']['tmp_name'], $ext === 'csv' ? 'csv' : 'xls');
    $programs = program_import_rows_to_programs($raw);
    $ok = 0;
    foreach ($programs as $p) {
        program_csr_insert_import($pdo, $p);
        $ok++;
    }
    app_redirect('program.php?msg=' . urlencode("Berhasil mengimpor $ok program dari file."));
} catch (Exception $e) {
    app_redirect('program.php?err=' . urlencode('Impor gagal: ' . $e->getMessage()));
}
