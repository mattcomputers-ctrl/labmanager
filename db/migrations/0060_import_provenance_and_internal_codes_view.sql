-- Imported codes were logged as "entered by hand" (code.manual_*) because the
-- importer reuses CodeAssigner::manualInternal/manualSell. The history of an
-- imported code now says "Imported from the spreadsheets"; this corrects the
-- rows written before that change (only rows whose code has source = 'import').
UPDATE chroma_audit_log a
JOIN chroma_internal_codes i ON i.id = a.row_id
SET a.action = 'code.import_internal', a.note = REPLACE(a.note, 'Entered by hand: ', 'Imported from the spreadsheets: ')
WHERE a.table_name = 'chroma_internal_codes' AND a.action = 'code.manual_internal' AND i.source = 'import';

UPDATE chroma_audit_log a
JOIN chroma_sell_numbers s ON s.id = a.row_id
SET a.action = 'code.import_sell', a.note = REPLACE(a.note, 'Entered by hand: ', 'Imported from the spreadsheets: ')
WHERE a.table_name = 'chroma_sell_numbers' AND a.action = 'code.manual_sell' AND s.source = 'import';

-- v_chroma_code_lookup is driven by sell numbers, so an E number that has no
-- sell number yet (R&D trials, a formula waiting for its sell number) is not
-- in it. This view lists every E number, with its sell numbers as one text
-- column, so the ERP sees the same codes the app's lookup and the E log show.
CREATE OR REPLACE VIEW v_chroma_internal_codes AS
SELECT
  ic.id AS internal_code_id,
  CASE WHEN ic.version = '' THEN CONCAT('E', ic.number) ELSE CONCAT('E', ic.number, '-', ic.version) END AS internal_code,
  ic.number AS internal_number,
  ic.version AS internal_version,
  ic.description,
  c.name AS customer,
  ic.date_assigned,
  ic.resale,
  ic.vendor_name,
  ic.vendor_code,
  ic.source,
  ic.voided AS internal_code_voided,
  (SELECT GROUP_CONCAT(CONCAT(p.code, LPAD(sn.number, p.min_digits, '0'), COALESCE(sx.code, '')) ORDER BY p.sort_order, p.code, sn.number, sn.suffix_id SEPARATOR ', ')
     FROM chroma_sell_numbers sn JOIN chroma_code_prefixes p ON p.id = sn.prefix_id LEFT JOIN chroma_code_suffixes sx ON sx.id = sn.suffix_id
     WHERE sn.internal_code_id = ic.id AND sn.voided = 0) AS sell_numbers,
  (SELECT COUNT(*) FROM chroma_sell_numbers sn WHERE sn.internal_code_id = ic.id) AS sell_number_count
FROM chroma_internal_codes ic
LEFT JOIN chroma_customers c ON c.id = ic.original_customer_id;
