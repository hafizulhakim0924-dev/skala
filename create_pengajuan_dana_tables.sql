-- Tabel pengajuan dana & approval bertingkat
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pengajuan_dana_tim` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pengajuan_id` int(11) NOT NULL,
  `nama_program` varchar(255) NOT NULL,
  `nominal` decimal(15,2) NOT NULL DEFAULT 0,
  `keterangan` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pengajuan_id` (`pengajuan_id`),
  CONSTRAINT `fk_pengajuan_tim` FOREIGN KEY (`pengajuan_id`) REFERENCES `pengajuan_dana` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pengajuan_dana_approval` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pengajuan_id` int(11) NOT NULL,
  `level` tinyint(4) NOT NULL COMMENT '1=Tim Program, 2=Keuangan, 3=Direktur',
  `approver_name` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `catatan` text,
  `tanggal_approval` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pengajuan_level` (`pengajuan_id`,`level`),
  CONSTRAINT `fk_pengajuan_approval` FOREIGN KEY (`pengajuan_id`) REFERENCES `pengajuan_dana` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
