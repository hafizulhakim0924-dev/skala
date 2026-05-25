<?php
/**
 * Helper peta program CSR — dipakai Dashboard & program.php
 */

function program_csr_geo_columns(PDO $pdo) {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $c = $pdo->query("SHOW COLUMNS FROM program_csr")->fetchAll(PDO::FETCH_COLUMN);
        $cache = [
            'lat' => in_array('latitude', $c, true),
            'lng' => in_array('longitude', $c, true),
        ];
    } catch (Exception $e) {
        $cache = ['lat' => false, 'lng' => false];
    }
    return $cache;
}

function program_map_table_columns(PDO $pdo) {
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

function program_map_resolve_coords($kota, $provinsi) {
    $k = mb_strtolower(trim((string)$kota));
    $p = mb_strtolower(trim((string)$provinsi));
    $map = [
        'padang' => [-0.9492, 100.3543],
        'bukittinggi' => [-0.3056, 100.3692],
        'solok' => [-0.8006, 100.6567],
        'pariaman' => [-0.6267, 100.1208],
        'payakumbuh' => [-0.2206, 100.6331],
        'jakarta' => [-6.2088, 106.8456],
        'bandung' => [-6.9175, 107.6191],
        'surabaya' => [-7.2575, 112.7521],
        'medan' => [3.5952, 98.6722],
        'semarang' => [-6.9667, 110.4167],
        'yogyakarta' => [-7.7956, 110.3695],
        'makassar' => [-5.1477, 119.4327],
        'palembang' => [-2.9761, 104.7754],
        'manado' => [1.4748, 124.8421],
        'denpasar' => [-8.6705, 115.2126],
        'lombok' => [-8.5833, 116.1167],
        'mataram' => [-8.5833, 116.1167],
        'aceh' => [5.5483, 95.3238],
        'pekanbaru' => [0.5071, 101.4478],
        'batam' => [1.0456, 104.0305],
        'pontianak' => [-0.0263, 109.3425],
        'banjarmasin' => [-3.3194, 114.5908],
        'balikpapan' => [-1.2675, 116.8289],
    ];
    foreach ($map as $name => $coord) {
        if ($k !== '' && (strpos($k, $name) !== false || $k === $name)) {
            return $coord;
        }
    }
    if (strpos($p, 'sumatera barat') !== false || $p === 'sumbar') {
        return [-0.7394, 100.8008];
    }
    if (strpos($p, 'jawa barat') !== false) {
        return [-6.8892, 107.6405];
    }
    if (strpos($p, 'jawa timur') !== false) {
        return [-7.5361, 112.2384];
    }
    if (strpos($p, 'sumatera utara') !== false) {
        return [3.5970, 98.6783];
    }
    $key = $k . '|' . $p;
    $h = crc32($key);
    $lat = -2.2 + (($h % 200) / 100 - 1) * 1.8;
    $lng = 114 + (($h % 300) / 100 - 0.5) * 12;
    return [$lat, $lng];
}

/** Kumpulkan pin untuk peta (point + agregasi kota) */
function program_map_fetch_pins(PDO $pdo) {
    $map_pins = [];
    try {
        $pdo->query("SELECT 1 FROM program_csr LIMIT 1");
    } catch (PDOException $e) {
        return [];
    }

    try {
        $geo = program_csr_geo_columns($pdo);
        $cols = array_flip(program_map_table_columns($pdo));
        $hasKota = isset($cols['kota']);
        $hasProv = isset($cols['provinsi']);
        $hasPenerima = isset($cols['jumlah_penerima_manfaat']);
        $hasDeskripsi = isset($cols['deskripsi']);

        if ($geo['lat'] && $geo['lng']) {
            $selKota = $hasKota ? "TRIM(COALESCE(p.kota, ''))" : "''";
            $selProv = $hasProv ? "TRIM(COALESCE(p.provinsi, ''))" : "''";
            $selPenerima = $hasPenerima ? "COALESCE(p.jumlah_penerima_manfaat, 0)" : "0";
            $selDesk = $hasDeskripsi ? "p.deskripsi" : "NULL";
            $stmtPt = $pdo->query("
                SELECT 
                    p.id AS program_id,
                    p.nama_program,
                    $selDesk AS deskripsi,
                    $selKota AS kota,
                    $selProv AS provinsi,
                    $selPenerima AS total_penerima,
                    p.latitude AS lat,
                    p.longitude AS lng
                FROM program_csr p
                WHERE p.latitude IS NOT NULL AND p.longitude IS NOT NULL
                  AND ABS(p.latitude) > 0.0000001 AND ABS(p.longitude) > 0.0000001
            ");
            foreach ($stmtPt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $map_pins[] = [
                    'pin_kind' => 'point',
                    'program_id' => (int)$row['program_id'],
                    'nama_program' => $row['nama_program'],
                    'deskripsi' => (string)($row['deskripsi'] ?? ''),
                    'kota' => $row['kota'] ?? '',
                    'provinsi' => $row['provinsi'] ?? '',
                    'jumlah_program' => 1,
                    'total_penerima' => (int)$row['total_penerima'],
                    'contoh_nama' => $row['nama_program'],
                    'lat' => round((float)$row['lat'], 6),
                    'lng' => round((float)$row['lng'], 6),
                ];
            }
        }

        if ($hasKota) {
            $selProvAgg = $hasProv ? "TRIM(COALESCE(provinsi, ''))" : "''";
            $sumPenerima = $hasPenerima ? "SUM(COALESCE(jumlah_penerima_manfaat, 0))" : "0";
            $groupProv = $hasProv ? "TRIM(COALESCE(provinsi, ''))" : "''";

            $sqlMap = "
                SELECT 
                    TRIM(COALESCE(kota, '')) AS kota,
                    $selProvAgg AS provinsi,
                    COUNT(*) AS jumlah_program,
                    $sumPenerima AS total_penerima,
                    SUBSTRING(
                        GROUP_CONCAT(DISTINCT nama_program ORDER BY nama_program SEPARATOR ' • '),
                        1,
                        400
                    ) AS contoh_nama
            ";
            if ($geo['lat'] && $geo['lng']) {
                $sqlMap .= ",
                    AVG(NULLIF(latitude, 0)) AS lat,
                    AVG(NULLIF(longitude, 0)) AS lng
                ";
            }
            $sqlMap .= "
                FROM program_csr
                WHERE kota IS NOT NULL AND TRIM(kota) != ''
            ";
            if ($geo['lat'] && $geo['lng']) {
                $sqlMap .= "
                    AND (
                        latitude IS NULL OR longitude IS NULL
                        OR ABS(latitude) < 0.0000001 OR ABS(longitude) < 0.0000001
                    )
                ";
            }
            $sqlMap .= "
                GROUP BY TRIM(kota), $groupProv
                ORDER BY jumlah_program DESC, kota ASC
            ";
            $agg = $pdo->query($sqlMap)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($agg as $row) {
                $lat = isset($row['lat']) ? (float)$row['lat'] : null;
                $lng = isset($row['lng']) ? (float)$row['lng'] : null;
                if ($lat === null || $lng === null || abs($lat) < 0.0001 || abs($lng) < 0.0001) {
                    list($lat, $lng) = program_map_resolve_coords($row['kota'] ?? '', $row['provinsi'] ?? '');
                }
                $map_pins[] = [
                    'pin_kind' => 'cluster',
                    'program_id' => null,
                    'nama_program' => null,
                    'deskripsi' => '',
                    'kota' => $row['kota'] ?? '',
                    'provinsi' => $row['provinsi'] ?? '',
                    'jumlah_program' => (int)$row['jumlah_program'],
                    'total_penerima' => (int)$row['total_penerima'],
                    'contoh_nama' => $row['contoh_nama'] ?? '',
                    'lat' => round($lat, 6),
                    'lng' => round($lng, 6),
                ];
            }
        }
    } catch (PDOException $e) {
        return [];
    }

    return $map_pins;
}
