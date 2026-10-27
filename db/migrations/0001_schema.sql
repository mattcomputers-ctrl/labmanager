-- Chroma initial schema. Every statement is idempotent (IF NOT EXISTS) so
-- bin/migrate can be re-run safely. All tables carry the chroma_ prefix so
-- they can share a database with Precision Ink's other systems later.
-- Timestamps are UTC; dates (date_submitted etc.) are plain calendar dates.

CREATE TABLE IF NOT EXISTS chroma_migrations (
  name VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  display_name VARCHAR(150) NOT NULL DEFAULT '',
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  ui_mode VARCHAR(10) NOT NULL DEFAULT 'easy',
  initials VARCHAR(5) NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_groups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_groups_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_group_permissions (
  group_id INT UNSIGNED NOT NULL,
  permission VARCHAR(80) NOT NULL,
  PRIMARY KEY (group_id, permission),
  CONSTRAINT fk_gp_group FOREIGN KEY (group_id) REFERENCES chroma_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_user_groups (
  user_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, group_id),
  CONSTRAINT fk_ug_user FOREIGN KEY (user_id) REFERENCES chroma_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ug_group FOREIGN KEY (group_id) REFERENCES chroma_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_sessions (
  id VARCHAR(250) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  data MEDIUMTEXT NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY ix_sessions_user (user_id),
  KEY ix_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL,
  KEY ix_login_attempts_time (attempted_at),
  KEY ix_login_attempts_user (username, attempted_at),
  KEY ix_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_settings (
  `key` VARCHAR(100) NOT NULL PRIMARY KEY,
  `value` TEXT NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(150) NOT NULL DEFAULT '',
  action VARCHAR(80) NOT NULL,
  table_name VARCHAR(80) NOT NULL DEFAULT '',
  row_id BIGINT UNSIGNED NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY ix_audit_row (table_name, row_id),
  KEY ix_audit_time (created_at),
  KEY ix_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_sequences (
  name VARCHAR(50) NOT NULL PRIMARY KEY,
  next_value BIGINT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_customers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  name_normalized VARCHAR(200) NOT NULL,
  cms_customer_id VARCHAR(50) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_customers_cms (cms_customer_id),
  KEY ix_customers_norm (name_normalized),
  KEY ix_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_code_prefixes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL,
  label VARCHAR(100) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  next_number INT UNSIGNED NOT NULL DEFAULT 1,
  min_digits TINYINT UNSIGNED NOT NULL DEFAULT 4,
  retired TINYINT(1) NOT NULL DEFAULT 0,
  retired_at DATETIME NULL,
  retired_by INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 100,
  tag VARCHAR(20) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_prefix_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_code_suffixes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL,
  label VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_suffix_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_code_prefix_suffixes (
  prefix_id INT UNSIGNED NOT NULL,
  suffix_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (prefix_id, suffix_id),
  CONSTRAINT fk_cps_prefix FOREIGN KEY (prefix_id) REFERENCES chroma_code_prefixes(id) ON DELETE CASCADE,
  CONSTRAINT fk_cps_suffix FOREIGN KEY (suffix_id) REFERENCES chroma_code_suffixes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_internal_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  number INT UNSIGNED NOT NULL,
  version VARCHAR(20) NOT NULL DEFAULT '',
  description VARCHAR(500) NOT NULL DEFAULT '',
  resale TINYINT(1) NOT NULL DEFAULT 0,
  vendor_name VARCHAR(200) NULL,
  vendor_code VARCHAR(100) NULL,
  legacy_handschy_number VARCHAR(100) NULL,
  legacy_internal_code VARCHAR(100) NULL,
  date_assigned DATE NULL,
  assigned_by INT UNSIGNED NULL,
  original_customer_id INT UNSIGNED NULL,
  voided TINYINT(1) NOT NULL DEFAULT 0,
  void_reason VARCHAR(500) NULL,
  voided_at DATETIME NULL,
  voided_by INT UNSIGNED NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'assigned',
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_internal_number_version (number, version),
  KEY ix_internal_number (number),
  KEY ix_internal_customer (original_customer_id),
  KEY ix_internal_date (date_assigned),
  KEY ix_internal_legacy (legacy_internal_code),
  FULLTEXT KEY ft_internal_desc (description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_sell_numbers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  prefix_id INT UNSIGNED NOT NULL,
  number INT UNSIGNED NOT NULL,
  suffix_id INT UNSIGNED NULL,
  sell_number_normalized VARCHAR(40) NOT NULL,
  legacy_text VARCHAR(60) NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  internal_code_id INT UNSIGNED NULL,
  legacy_internal_code VARCHAR(100) NULL,
  original_customer_id INT UNSIGNED NULL,
  date_assigned DATE NULL,
  assigned_by INT UNSIGNED NULL,
  reason VARCHAR(40) NULL,
  reason_text VARCHAR(500) NULL,
  voided TINYINT(1) NOT NULL DEFAULT 0,
  void_reason VARCHAR(500) NULL,
  voided_at DATETIME NULL,
  voided_by INT UNSIGNED NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'assigned',
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_sell_normalized (sell_number_normalized),
  UNIQUE KEY uq_sell_triplet (prefix_id, number, suffix_id),
  KEY ix_sell_prefix_number (prefix_id, number),
  KEY ix_sell_internal (internal_code_id),
  KEY ix_sell_customer (original_customer_id),
  KEY ix_sell_date (date_assigned),
  FULLTEXT KEY ft_sell_desc (description),
  CONSTRAINT fk_sell_prefix FOREIGN KEY (prefix_id) REFERENCES chroma_code_prefixes(id),
  CONSTRAINT fk_sell_suffix FOREIGN KEY (suffix_id) REFERENCES chroma_code_suffixes(id),
  CONSTRAINT fk_sell_internal FOREIGN KEY (internal_code_id) REFERENCES chroma_internal_codes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_ink_series (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_ink_series_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_coatings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_coatings_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_substrates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  notes VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_substrates_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_project_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(100) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  item_label VARCHAR(40) NOT NULL DEFAULT 'Items',
  item_label_singular VARCHAR(40) NOT NULL DEFAULT 'Item',
  has_customer TINYINT(1) NOT NULL DEFAULT 1,
  customer_required TINYINT(1) NOT NULL DEFAULT 1,
  has_stages TINYINT(1) NOT NULL DEFAULT 0,
  has_team TINYINT(1) NOT NULL DEFAULT 0,
  can_be_confidential TINYINT(1) NOT NULL DEFAULT 0,
  fields_json JSON NULL,
  default_group_id INT UNSIGNED NULL,
  color VARCHAR(20) NOT NULL DEFAULT 'blue',
  active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 100,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_project_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_project_stages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_type_id INT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_terminal TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_stage (project_type_id, code),
  CONSTRAINT fk_stage_type FOREIGN KEY (project_type_id) REFERENCES chroma_project_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_item_statuses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_type_id INT UNSIGNED NULL,
  code VARCHAR(40) NOT NULL,
  label VARCHAR(100) NOT NULL,
  sort_order INT NOT NULL DEFAULT 100,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  is_success TINYINT(1) NOT NULL DEFAULT 0,
  color VARCHAR(20) NOT NULL DEFAULT 'gray',
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_item_status (project_type_id, code),
  CONSTRAINT fk_item_status_type FOREIGN KEY (project_type_id) REFERENCES chroma_project_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_projects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_number INT UNSIGNED NOT NULL,
  project_type_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  problem_statement TEXT NULL,
  goal TEXT NULL,
  root_cause TEXT NULL,
  resolution TEXT NULL,
  outcome TEXT NULL,
  date_submitted DATE NULL,
  date_completed DATE NULL,
  target_date DATE NULL,
  assigned_user_id INT UNSIGNED NULL,
  stage_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  confidential TINYINT(1) NOT NULL DEFAULT 0,
  default_ink_series_id INT UNSIGNED NULL,
  default_substrate_id INT UNSIGNED NULL,
  default_substrate_text VARCHAR(200) NULL,
  default_coating_id INT UNSIGNED NULL,
  default_prefix_id INT UNSIGNED NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  completed_by INT UNSIGNED NULL,
  cancelled_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_project_number (project_number),
  KEY ix_projects_status (status, date_submitted),
  KEY ix_projects_customer (customer_id),
  KEY ix_projects_type (project_type_id),
  KEY ix_projects_tech (assigned_user_id),
  KEY ix_projects_completed (date_completed),
  FULLTEXT KEY ft_projects (title, description),
  CONSTRAINT fk_project_type FOREIGN KEY (project_type_id) REFERENCES chroma_project_types(id),
  CONSTRAINT fk_project_customer FOREIGN KEY (customer_id) REFERENCES chroma_customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_project_team (
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (project_id, user_id),
  CONSTRAINT fk_team_project FOREIGN KEY (project_id) REFERENCES chroma_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_user FOREIGN KEY (user_id) REFERENCES chroma_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The general "project items" table: a color, a trial, a sample, or an
-- existing product under investigation. The lab UI calls these "Colors"
-- on customer projects; the spec calls the table project_colors.
CREATE TABLE IF NOT EXISTS chroma_project_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  sequence INT UNSIGNED NOT NULL DEFAULT 1,
  kind VARCHAR(20) NOT NULL DEFAULT 'color',
  description VARCHAR(500) NOT NULL DEFAULT '',
  pantone VARCHAR(60) NULL,
  hex_color VARCHAR(7) NULL,
  ink_series_id INT UNSIGNED NULL,
  substrate_id INT UNSIGNED NULL,
  substrate_text VARCHAR(200) NULL,
  proofs_generated TINYINT(1) NOT NULL DEFAULT 0,
  proof_count INT UNSIGNED NOT NULL DEFAULT 0,
  coating_id INT UNSIGNED NULL,
  is_digital_match TINYINT(1) NOT NULL DEFAULT 0,
  internal_code_id INT UNSIGNED NULL,
  sell_number_id INT UNSIGNED NULL,
  status_id INT UNSIGNED NULL,
  assigned_user_id INT UNSIGNED NULL,
  date_started DATE NULL,
  date_completed DATE NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_items_project (project_id, sequence),
  KEY ix_items_internal (internal_code_id),
  KEY ix_items_sell (sell_number_id),
  KEY ix_items_status (status_id),
  CONSTRAINT fk_item_project FOREIGN KEY (project_id) REFERENCES chroma_projects(id),
  CONSTRAINT fk_item_internal FOREIGN KEY (internal_code_id) REFERENCES chroma_internal_codes(id),
  CONSTRAINT fk_item_sell FOREIGN KEY (sell_number_id) REFERENCES chroma_sell_numbers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_project_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  kind VARCHAR(30) NOT NULL DEFAULT 'note',
  body TEXT NOT NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL,
  KEY ix_activity_project (project_id, created_at),
  CONSTRAINT fk_activity_project FOREIGN KEY (project_id) REFERENCES chroma_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_project_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  caption VARCHAR(255) NOT NULL DEFAULT '',
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NULL,
  deleted_by INT UNSIGNED NULL,
  KEY ix_attachments_project (project_id),
  KEY ix_attachments_item (item_id),
  CONSTRAINT fk_attachment_project FOREIGN KEY (project_id) REFERENCES chroma_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_project_tasks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  owner_user_id INT UNSIGNED NULL,
  due_date DATE NULL,
  done TINYINT(1) NOT NULL DEFAULT 0,
  done_at DATETIME NULL,
  done_by INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_tasks_project (project_id, done),
  KEY ix_tasks_owner (owner_user_id, done, due_date),
  CONSTRAINT fk_task_project FOREIGN KEY (project_id) REFERENCES chroma_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_imports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  started_by INT UNSIGNED NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  dry_run TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'running',
  files_json JSON NULL,
  summary_json JSON NULL,
  log_text MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_import_issues (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  import_id INT UNSIGNED NULL,
  source_file VARCHAR(100) NOT NULL DEFAULT '',
  source_row INT UNSIGNED NULL,
  issue_type VARCHAR(40) NOT NULL,
  message VARCHAR(500) NOT NULL,
  raw_json JSON NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  resolved_by INT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  resolution VARCHAR(40) NULL,
  resolution_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  KEY ix_import_issues_status (status, issue_type),
  KEY ix_import_issues_import (import_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_api_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  token_prefix CHAR(8) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  UNIQUE KEY uq_api_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chroma_cms_sync_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  started_by INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'running',
  created_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_count INT UNSIGNED NOT NULL DEFAULT 0,
  message VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
