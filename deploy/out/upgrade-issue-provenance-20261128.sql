-- Install the four PM issue provenance labels before deploying the application.
-- Existing lookup values and permissions are preserved.
INSERT INTO lookup_items (category, code, name, sort, is_active) VALUES
('issue_provenance', 'internal', '內部發現／討論', 10, 1),
('issue_provenance', 'meeting', '會議轉入', 20, 1),
('issue_provenance', 'ticket', '客戶單轉入', 30, 1),
('issue_provenance', 'unknown_import', '匯入待確認', 40, 1)
ON DUPLICATE KEY UPDATE code = VALUES(code);
