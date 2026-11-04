-- Data acuan (idempoten). Kategori sesuai pilihan di aplikasi lama.
INSERT INTO categories (name, sort_order) VALUES
  ('Alat Ukur', 1), ('Alat Berat', 2), ('Drone', 3), ('Toolkit', 4), ('Solar Panel', 5), ('Lain-lain', 6)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

INSERT INTO settings (key_name, value) VALUES
  ('admin_notification_email', 'wahyuardhana00@gmail.com'),
  ('password_min_length', '8')
ON DUPLICATE KEY UPDATE key_name = key_name;
