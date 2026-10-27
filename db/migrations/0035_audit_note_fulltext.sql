-- Admin → Audit log: the "Note or row number" search uses a FULLTEXT index on the
-- note column instead of LIKE '%…%' (the audit table grows with every assignment).
CREATE FULLTEXT INDEX IF NOT EXISTS ft_audit_note ON chroma_audit_log (note);
