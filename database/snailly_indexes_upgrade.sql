-- Optional upgrade untuk database Snailly yang sudah terlanjur dibuat sebelum versi optimasi.
-- Jalankan di phpMyAdmin kalau dashboard/log terasa lambat.
-- Abaikan error "Duplicate key name" jika index sudah ada.

ALTER TABLE activity_logs ADD INDEX idx_logs_parent_child_created (parent_id, child_id, created_at);
ALTER TABLE activity_logs ADD INDEX idx_logs_parent_created (parent_id, created_at);
ALTER TABLE activity_logs ADD INDEX idx_logs_parent_grant_created (parent_id, grant_access, created_at);
ALTER TABLE access_requests ADD INDEX idx_requests_parent_status_created (parent_id, status, created_at);
ALTER TABLE rules ADD INDEX idx_rules_parent_child_type (parent_id, child_id, type);
ALTER TABLE rules ADD INDEX idx_rules_parent_match (parent_id, match_type);
