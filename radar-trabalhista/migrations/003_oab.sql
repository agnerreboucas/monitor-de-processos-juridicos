-- Monitoramento por OAB: publicações do diário em nome dos advogados do escritório.
-- Fica separado de `processes` de propósito: um processo descoberto pela OAB não gasta vaga do plano
-- até alguém pedir para monitorá-lo.

ALTER TABLE plans ADD COLUMN max_oabs INT UNSIGNED NOT NULL DEFAULT 2 AFTER max_users;
UPDATE plans SET max_oabs = 2  WHERE code = 'essencial';
UPDATE plans SET max_oabs = 5  WHERE code = 'profissional';
UPDATE plans SET max_oabs = 15 WHERE code = 'equipe';

CREATE TABLE oab_registrations (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id        BIGINT UNSIGNED NOT NULL,
  oab_number       VARCHAR(12) NOT NULL,
  oab_uf           CHAR(2) NOT NULL,                 -- inscrição principal
  lawyer_name      VARCHAR(160) NOT NULL,
  supplementary_ufs VARCHAR(100) NULL,               -- inscrições suplementares já existentes: "SP,RJ"
  status           ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  baseline_at      DATETIME NULL,
  last_attempt_at  DATETIME NULL,
  last_success_at  DATETIME NULL,
  last_error       VARCHAR(500) NULL,
  fail_count       INT UNSIGNED NOT NULL DEFAULT 0,
  next_check_at    DATETIME NULL,
  locked_until     DATETIME NULL,                    -- reserva do worker (evita dois consultarem a mesma OAB)
  created_by       BIGINT UNSIGNED NULL,
  created_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oab_ref (id, tenant_id),
  UNIQUE KEY uq_oab_person (tenant_id, oab_number, oab_uf),
  KEY ix_oab_due (status, next_check_at),
  CONSTRAINT fk_oab_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;

CREATE TABLE oab_publications (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  oab_id          BIGINT UNSIGNED NOT NULL,
  external_id     VARCHAR(191) NOT NULL,
  cnj             CHAR(25) NOT NULL,
  cnj_digits      CHAR(20) NOT NULL,
  tribunal        VARCHAR(16) NOT NULL DEFAULT '',
  segment         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  uf              CHAR(2) NULL,                      -- NULL = o tribunal cobre mais de uma UF (indeterminada)
  published_at    DATE NULL,
  pub_year        SMALLINT UNSIGNED NULL,
  kind            VARCHAR(80) NOT NULL DEFAULT '',
  doc_type        VARCHAR(120) NOT NULL DEFAULT '',
  orgao           VARCHAR(255) NOT NULL DEFAULT '',
  class_name      VARCHAR(255) NOT NULL DEFAULT '',
  description     MEDIUMTEXT NOT NULL,
  description_raw MEDIUMTEXT NULL,
  source_url      VARCHAR(500) NULL,
  captured_at     DATETIME NOT NULL,
  is_baseline     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pub_dedupe (tenant_id, oab_id, external_id),
  KEY ix_pub_list (tenant_id, published_at),
  KEY ix_pub_stats (tenant_id, oab_id, pub_year, uf),
  KEY ix_pub_cnj (tenant_id, cnj_digits),
  CONSTRAINT fk_pub_oab FOREIGN KEY (oab_id, tenant_id) REFERENCES oab_registrations(id, tenant_id)
) ENGINE=InnoDB;

CREATE TABLE oab_alerts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  oab_id        BIGINT UNSIGNED NOT NULL,
  uf            CHAR(2) NOT NULL,
  alert_year    SMALLINT UNSIGNED NOT NULL,
  process_count INT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  seen_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alert (oab_id, uf, alert_year),
  KEY ix_alert_tenant (tenant_id, seen_at),
  CONSTRAINT fk_alert_oab FOREIGN KEY (oab_id, tenant_id) REFERENCES oab_registrations(id, tenant_id)
) ENGINE=InnoDB;
