CREATE TABLE IF NOT EXISTS parents (
  id VARCHAR(32) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS children (
  id VARCHAR(32) PRIMARY KEY,
  parent_id VARCHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(80) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  schedule_json LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_children_parent (parent_id),
  CONSTRAINT fk_children_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tokens (
  token VARCHAR(128) PRIMARY KEY,
  user_id VARCHAR(32) NOT NULL,
  child_id VARCHAR(32) NULL,
  role ENUM('parent','child','tracker') NOT NULL DEFAULT 'parent',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  last_used_at DATETIME NULL,
  revoked_at DATETIME NULL,
  INDEX idx_tokens_user (user_id),
  INDEX idx_tokens_child (child_id),
  CONSTRAINT fk_tokens_parent FOREIGN KEY (user_id) REFERENCES parents(id) ON DELETE CASCADE,
  CONSTRAINT fk_tokens_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rules (
  id VARCHAR(32) PRIMARY KEY,
  parent_id VARCHAR(32) NOT NULL,
  child_id VARCHAR(32) NOT NULL DEFAULT 'ALL',
  type ENUM('allow','block') NOT NULL,
  match_type ENUM('domain','keyword') NOT NULL DEFAULT 'domain',
  pattern VARCHAR(255) NOT NULL,
  category VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_rules_parent (parent_id),
  INDEX idx_rules_child (child_id),
  INDEX idx_rules_type (type),
  CONSTRAINT fk_rules_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  log_id VARCHAR(32) PRIMARY KEY,
  child_id VARCHAR(32) NOT NULL,
  parent_id VARCHAR(32) NOT NULL,
  url TEXT NOT NULL,
  web_title VARCHAR(255) NOT NULL DEFAULT '',
  web_description TEXT NULL,
  detail_url TEXT NULL,
  grant_access TINYINT(1) NULL,
  classified_url_json LONGTEXT NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'extension',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_logs_parent_child (parent_id, child_id),
  INDEX idx_logs_created (created_at),
  CONSTRAINT fk_logs_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
  CONSTRAINT fk_logs_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_requests (
  id VARCHAR(32) PRIMARY KEY,
  parent_id VARCHAR(32) NOT NULL,
  child_id VARCHAR(32) NOT NULL,
  url TEXT NOT NULL,
  host VARCHAR(255) NOT NULL,
  reason TEXT NULL,
  status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_requests_parent (parent_id),
  INDEX idx_requests_child (child_id),
  INDEX idx_requests_status (status),
  CONSTRAINT fk_requests_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
  CONSTRAINT fk_requests_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS tracker_status (
  child_id VARCHAR(32) PRIMARY KEY,
  parent_id VARCHAR(32) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  block_dangerous TINYINT(1) NOT NULL DEFAULT 0,
  last_seen_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_tracker_parent (parent_id),
  CONSTRAINT fk_tracker_parent FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE CASCADE,
  CONSTRAINT fk_tracker_child FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
