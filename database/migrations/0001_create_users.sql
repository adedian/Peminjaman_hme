-- Pengguna. Penonaktifan memakai is_active (keputusan Q3), tidak ada hapus permanen.
CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  name                 VARCHAR(100) NOT NULL,
  initials             VARCHAR(4)   NOT NULL,
  role                 ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at        DATETIME NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
