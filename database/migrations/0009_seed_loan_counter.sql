-- Penghitung nomor urut transaksi (BR-06). Baris ini dikunci singkat tiap pengajuan baru sehingga nomor tidak pernah ganda.
-- Tahap 6 (migrasi data) menyetel nilainya ke MAX(seq_no) data lama.
INSERT INTO settings (key_name, value) VALUES ('loan_seq', '0') ON DUPLICATE KEY UPDATE key_name = key_name;
