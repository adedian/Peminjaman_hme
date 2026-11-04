CREATE TABLE categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(60) NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Master barang. master_status (Tersedia/Dipinjam) hanya diubah service di dalam transaksi (BR-09/21/25/29).
CREATE TABLE items (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code             VARCHAR(50)  NOT NULL,
  name             VARCHAR(150) NOT NULL,
  category_id      INT UNSIGNED NOT NULL,
  photo_path       VARCHAR(255) NULL,
  photo_legacy_url VARCHAR(500) NULL,
  finance_ref      VARCHAR(100) NULL,
  is_pqa           TINYINT(1) NOT NULL DEFAULT 0,
  master_status    ENUM('Tersedia','Dipinjam') NOT NULL DEFAULT 'Tersedia',
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_items_code (code),
  KEY idx_items_category (category_id),
  KEY idx_items_list (is_active, master_status),
  CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
