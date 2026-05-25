<?php
/**
 * Parser impor program dari CSV / Excel (.xls HTML)
 */

function program_import_normalize_header($h) {
    $h = mb_strtolower(trim((string)$h));
    $h = preg_replace('/\s+/', '_', $h);
    $map = [
        'nama_program' => 'nama_program',
        'nama' => 'nama_program',
        'program' => 'nama_program',
        'kategori' => 'kategori',
        'deskripsi' => 'deskripsi',
        'lokasi' => 'lokasi',
        'kecamatan' => 'kecamatan',
        'kota' => 'kota',
        'provinsi' => 'provinsi',
        'tanggal_mulai' => 'tanggal_mulai',
        'tanggal_selesai' => 'tanggal_selesai',
        'budget' => 'budget',
        'status' => 'status',
        'latitude' => 'latitude',
        'longitude' => 'longitude',
        'jenis_bantuan' => 'jenis_bantuan',
        'jumlah_bantuan' => 'jumlah_bantuan',
        'satuan' => 'satuan',
        'pic' => 'pic',
    ];
    return $map[$h] ?? null;
}

function program_import_parse_number($val) {
    if ($val === null || $val === '') {
        return 0;
    }
    $s = preg_replace('/[^\d,.-]/', '', (string)$val);
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '.', $s);
    }
    return (float)$s;
}

function program_import_parse_date($val) {
    $val = trim((string)$val);
    if ($val === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        return $val;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    $ts = strtotime($val);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** Baca baris dari file upload */
function program_import_read_rows($filePath, $ext) {
    $rows = [];
    if ($ext === 'csv') {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception('Tidak bisa membaca file CSV.');
        }
        $first = fgets($handle);
        rewind($handle);
        $delim = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
        while (($data = fgetcsv($handle, 0, $delim)) !== false) {
            if (count(array_filter($data, function ($c) { return trim((string)$c) !== ''; })) === 0) {
                continue;
            }
            $rows[] = $data;
        }
        fclose($handle);
        return $rows;
    }

    $html = file_get_contents($filePath);
    if ($html === false) {
        throw new Exception('Tidak bisa membaca file.');
    }
    if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $trMatches)) {
        foreach ($trMatches[1] as $tr) {
            if (!preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $tr, $cellMatches)) {
                continue;
            }
            $cells = array_map(function ($c) {
                return trim(html_entity_decode(strip_tags($c), ENT_QUOTES, 'UTF-8'));
            }, $cellMatches[1]);
            if (count(array_filter($cells)) === 0) {
                continue;
            }
            $rows[] = $cells;
        }
    }
    if (empty($rows)) {
        throw new Exception('File Excel kosong atau format tidak dikenali. Gunakan template CSV.');
    }
    return $rows;
}

/** Konversi baris mentah → array asosiatif program */
function program_import_rows_to_programs(array $rawRows) {
    if (count($rawRows) < 2) {
        throw new Exception('File harus memiliki header dan minimal 1 baris data.');
    }
    $headerRow = array_shift($rawRows);
    $colMap = [];
    foreach ($headerRow as $i => $h) {
        $key = program_import_normalize_header($h);
        if ($key) {
            $colMap[$i] = $key;
        }
    }
    if (!in_array('nama_program', $colMap, true)) {
        throw new Exception('Kolom "Nama Program" wajib ada di baris pertama (header).');
    }

    $programs = [];
    $line = 1;
    foreach ($rawRows as $row) {
        $line++;
        $item = [];
        foreach ($colMap as $i => $key) {
            $item[$key] = isset($row[$i]) ? trim((string)$row[$i]) : '';
        }
        if (trim($item['nama_program'] ?? '') === '') {
            continue;
        }
        $programs[] = [
            'nama_program' => $item['nama_program'],
            'kategori' => ($item['kategori'] ?? '') !== '' ? $item['kategori'] : null,
            'deskripsi' => ($item['deskripsi'] ?? '') !== '' ? $item['deskripsi'] : null,
            'lokasi' => ($item['lokasi'] ?? '') !== '' ? $item['lokasi'] : null,
            'kecamatan' => ($item['kecamatan'] ?? '') !== '' ? $item['kecamatan'] : null,
            'kota' => ($item['kota'] ?? '') !== '' ? $item['kota'] : null,
            'provinsi' => ($item['provinsi'] ?? '') !== '' ? $item['provinsi'] : 'Sumatera Barat',
            'tanggal_mulai' => program_import_parse_date($item['tanggal_mulai'] ?? ''),
            'tanggal_selesai' => program_import_parse_date($item['tanggal_selesai'] ?? ''),
            'budget' => program_import_parse_number($item['budget'] ?? 0),
            'status' => in_array($item['status'] ?? '', ['planning', 'ongoing', 'completed', 'cancelled'], true)
                ? $item['status'] : 'planning',
            'latitude' => ($item['latitude'] ?? '') !== '' ? (float)$item['latitude'] : null,
            'longitude' => ($item['longitude'] ?? '') !== '' ? (float)$item['longitude'] : null,
            'jenis_bantuan' => ($item['jenis_bantuan'] ?? '') !== '' ? $item['jenis_bantuan'] : null,
            'jumlah_bantuan' => program_import_parse_number($item['jumlah_bantuan'] ?? 0),
            'satuan' => ($item['satuan'] ?? '') !== '' ? $item['satuan'] : null,
            'jumlah_penerima_manfaat' => 0,
            'jumlah_relawan_terlibat' => 0,
            'realisasi_budget' => 0,
            'progress' => 0,
        ];
    }
    if (empty($programs)) {
        throw new Exception('Tidak ada baris program valid ditemukan.');
    }
    return $programs;
}
