-- Radar Trabalhista — esquema inicial (MySQL 8 / MariaDB 10.6+, InnoDB, utf8mb4)
--
-- Isolamento entre escritórios: toda tabela de dados do cliente tem tenant_id, e as
-- chaves estrangeiras entre elas são COMPOSTAS (id, tenant_id). Assim o próprio banco
-- recusa, por exemplo, uma movimentação do escritório 2 ligada a um processo do 1.
-- Horários sempre em UTC.

CREATE TABLE tenants (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(160) NOT NULL,
  slug        VARCHAR(80)  NOT NULL,
  phone       VARCHAR(30)  NULL,
  status      ENUM('active','suspended','cancelled') NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB;

CREATE TABLE users (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name               VARCHAR(160) NOT NULL,
  email              VARCHAR(254) NOT NULL,
  password_hash      VARCHAR(255) NOT NULL,
  is_platform_admin  TINYINT(1) NOT NULL DEFAULT 0,
  status             ENUM('active','disabled') NOT NULL DEFAULT 'active',
  failed_logins      INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until       DATETIME NULL,
  last_login_at      DATETIME NULL,
  created_at         DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE tenant_members (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  role        ENUM('office_admin','lawyer','assistant') NOT NULL,
  status      ENUM('active','invited','disabled') NOT NULL DEFAULT 'active',
  invited_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_member (tenant_id, user_id),
  UNIQUE KEY uq_member_ref (id, tenant_id),
  KEY ix_member_user (user_id),
  CONSTRAINT fk_member_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_member_user   FOREIGN KEY (user_id)   REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,          -- só o hash do token fica guardado
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reset_token (token_hash),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE plans (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(40)  NOT NULL,
  name                  VARCHAR(80)  NOT NULL,
  price_cents           INT UNSIGNED NOT NULL DEFAULT 0,
  max_processes         INT UNSIGNED NOT NULL,
  max_users             INT UNSIGNED NOT NULL,
  min_interval_minutes  INT UNSIGNED NOT NULL,   -- frequência mínima entre consultas do mesmo processo
  active                TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_code (code)
) ENGINE=InnoDB;

CREATE TABLE subscriptions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id          BIGINT UNSIGNED NOT NULL,
  plan_id            BIGINT UNSIGNED NOT NULL,
  status             ENUM('trialing','active','past_due','suspended','cancelled') NOT NULL DEFAULT 'trialing',
  current_period_end DATETIME NULL,
  created_at         DATETIME NOT NULL,
  updated_at         DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sub_tenant (tenant_id),
  CONSTRAINT fk_sub_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_sub_plan   FOREIGN KEY (plan_id)   REFERENCES plans(id)
) ENGINE=InnoDB;

CREATE TABLE integration_sources (
  id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code     VARCHAR(30) NOT NULL,
  name     VARCHAR(80) NOT NULL,
  enabled  TINYINT(1) NOT NULL DEFAULT 1,
  config   TEXT NULL,                     -- só configuração pública; segredos ficam no .env
  PRIMARY KEY (id),
  UNIQUE KEY uq_source_code (code)
) ENGINE=InnoDB;

CREATE TABLE processes (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id           BIGINT UNSIGNED NOT NULL,
  cnj                 CHAR(25) NOT NULL,          -- 0000000-00.0000.0.00.0000
  cnj_digits          CHAR(20) NOT NULL,
  tribunal            VARCHAR(16) NOT NULL DEFAULT '',
  segment             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  client_label        VARCHAR(160) NULL,
  responsible_user_id BIGINT UNSIGNED NULL,
  status              ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  notes               TEXT NULL,
  baseline_at         DATETIME NULL,              -- 1ª consulta com sucesso: o histórico entra sem alertar
  last_attempt_at     DATETIME NULL,
  last_success_at     DATETIME NULL,
  last_error          VARCHAR(500) NULL,
  next_check_at       DATETIME NULL,
  created_by          BIGINT UNSIGNED NULL,
  created_at          DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_process_ref (id, tenant_id),
  UNIQUE KEY uq_process_cnj (tenant_id, cnj_digits),
  KEY ix_process_due (status, next_check_at),
  KEY ix_process_tenant (tenant_id, status),
  CONSTRAINT fk_process_tenant      FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  CONSTRAINT fk_process_responsible FOREIGN KEY (responsible_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE process_movements (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       BIGINT UNSIGNED NOT NULL,
  process_id      BIGINT UNSIGNED NOT NULL,
  source_id       BIGINT UNSIGNED NOT NULL,
  external_id     VARCHAR(191) NOT NULL,          -- identificador na origem (ou hash documentado)
  occurred_at     DATE NULL,                      -- data da movimentação, quando a fonte informa
  captured_at     DATETIME NOT NULL,              -- quando NÓS a capturamos
  kind            VARCHAR(80) NOT NULL DEFAULT '',
  description     MEDIUMTEXT NOT NULL,            -- texto limpo, para leitura
  description_raw MEDIUMTEXT NULL,                -- original recebido, sem alteração
  source_url      VARCHAR(500) NULL,
  metadata        TEXT NULL,
  is_baseline     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  -- a deduplicação é do banco: dois workers ao mesmo tempo não conseguem duplicar
  UNIQUE KEY uq_movement_dedupe (tenant_id, process_id, source_id, external_id),
  UNIQUE KEY uq_movement_ref (id, tenant_id),
  KEY ix_movement_process (process_id, occurred_at),
  CONSTRAINT fk_movement_process FOREIGN KEY (process_id, tenant_id) REFERENCES processes(id, tenant_id),
  CONSTRAINT fk_movement_source  FOREIGN KEY (source_id) REFERENCES integration_sources(id)
) ENGINE=InnoDB;

CREATE TABLE monitoring_jobs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  process_id  BIGINT UNSIGNED NOT NULL,
  status      ENUM('queued','running','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
  attempts    INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  run_after   DATETIME NOT NULL,
  locked_at   DATETIME NULL,
  locked_by   VARCHAR(80) NULL,
  started_at  DATETIME NULL,
  finished_at DATETIME NULL,
  last_error  VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_job_pick (status, run_after),
  KEY ix_job_process (process_id, status),
  CONSTRAINT fk_job_process FOREIGN KEY (process_id, tenant_id) REFERENCES processes(id, tenant_id)
) ENGINE=InnoDB;

CREATE TABLE monitoring_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  process_id  BIGINT UNSIGNED NOT NULL,
  job_id      BIGINT UNSIGNED NULL,
  source_id   BIGINT UNSIGNED NULL,
  outcome     ENUM('new_movements','no_news','source_error','invalid_response') NOT NULL,
  new_count   INT UNSIGNED NOT NULL DEFAULT 0,
  started_at  DATETIME NOT NULL,
  finished_at DATETIME NOT NULL,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  message     VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY ix_event_process (process_id, finished_at),
  CONSTRAINT fk_event_process FOREIGN KEY (process_id, tenant_id) REFERENCES processes(id, tenant_id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  process_id  BIGINT UNSIGNED NOT NULL,
  movement_id BIGINT UNSIGNED NOT NULL,
  channel     ENUM('panel','email') NOT NULL,
  status      ENUM('pending','sent','failed','read') NOT NULL DEFAULT 'pending',
  attempts    INT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(500) NULL,
  sent_at     DATETIME NULL,
  read_at     DATETIME NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  -- a mesma movimentação nunca gera dois alertas iguais para a mesma pessoa e canal
  UNIQUE KEY uq_notification (movement_id, user_id, channel),
  KEY ix_notification_user (tenant_id, user_id, status),
  CONSTRAINT fk_notification_process  FOREIGN KEY (process_id, tenant_id)  REFERENCES processes(id, tenant_id),
  CONSTRAINT fk_notification_movement FOREIGN KEY (movement_id, tenant_id) REFERENCES process_movements(id, tenant_id),
  CONSTRAINT fk_notification_user     FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   BIGINT UNSIGNED NULL,
  user_id     BIGINT UNSIGNED NULL,
  action      VARCHAR(64) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id   BIGINT UNSIGNED NULL,
  ip          VARCHAR(45) NULL,
  meta        TEXT NULL,               -- nunca senha, token ou segredo
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_audit_tenant (tenant_id, created_at),
  KEY ix_audit_action (action, created_at)
) ENGINE=InnoDB;

-- Dados iniciais: fontes e planos experimentais do PRD (limites ainda a validar).
INSERT INTO integration_sources (code, name, enabled) VALUES
  ('djen', 'Diário de Justiça Eletrônico Nacional (DJEN/Comunica)', 1),
  ('datajud', 'Datajud (CNJ)', 1);

INSERT INTO plans (code, name, price_cents, max_processes, max_users, min_interval_minutes) VALUES
  ('essencial',    'Essencial',     9900,  50,  2, 360),
  ('profissional', 'Profissional', 19900, 200,  5, 180),
  ('equipe',       'Equipe',       39900, 600, 15,  60);
