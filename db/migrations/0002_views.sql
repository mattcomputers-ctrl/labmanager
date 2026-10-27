-- Read-only views for the future ERP / reporting tools.

CREATE OR REPLACE VIEW v_chroma_code_lookup AS
SELECT
  sn.id AS sell_number_id,
  CONCAT(p.code, LPAD(sn.number, p.min_digits, '0'), COALESCE(sx.code, '')) AS sell_number,
  sn.sell_number_normalized,
  p.code AS prefix,
  sn.number AS sell_number_value,
  sx.code AS suffix,
  ic.id AS internal_code_id,
  CASE WHEN ic.id IS NULL THEN NULL
       WHEN ic.version = '' THEN CONCAT('E', ic.number)
       ELSE CONCAT('E', ic.number, '-', ic.version) END AS internal_code,
  ic.number AS internal_number,
  ic.version AS internal_version,
  COALESCE(NULLIF(sn.description, ''), ic.description) AS description,
  c.name AS customer,
  sn.date_assigned,
  ic.resale,
  ic.vendor_name,
  ic.vendor_code,
  sn.voided AS sell_number_voided,
  ic.voided AS internal_code_voided
FROM chroma_sell_numbers sn
JOIN chroma_code_prefixes p ON p.id = sn.prefix_id
LEFT JOIN chroma_code_suffixes sx ON sx.id = sn.suffix_id
LEFT JOIN chroma_internal_codes ic ON ic.id = sn.internal_code_id
LEFT JOIN chroma_customers c ON c.id = COALESCE(sn.original_customer_id, ic.original_customer_id);

CREATE OR REPLACE VIEW v_chroma_project_status AS
SELECT
  pr.id AS project_id,
  pr.project_number,
  pt.code AS project_type,
  pt.label AS project_type_label,
  c.name AS customer,
  pr.title,
  pr.status,
  pr.date_submitted,
  pr.date_completed,
  pr.target_date,
  u.display_name AS assigned_to,
  (SELECT COUNT(*) FROM chroma_project_items i WHERE i.project_id = pr.id) AS item_count,
  (SELECT COUNT(*) FROM chroma_project_items i JOIN chroma_item_statuses s ON s.id = i.status_id WHERE i.project_id = pr.id AND s.is_done = 1) AS items_done
FROM chroma_projects pr
JOIN chroma_project_types pt ON pt.id = pr.project_type_id
LEFT JOIN chroma_customers c ON c.id = pr.customer_id
LEFT JOIN chroma_users u ON u.id = pr.assigned_user_id
WHERE pr.confidential = 0;
