-- API comercial: planos, clientes, chaves (token), uso diário, limite por minuto e cache das consultas.

CREATE TABLE api_plans (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code            VARCHAR(40) NOT NULL,
  name            VARCHAR(80) NOT NULL,
  price_cents     INT UNSIGNED NOT NULL DEFAULT 0,
  monthly_quota   INT UNSIGNED NOT NULL,           -- consultas bem-sucedidas por mês
  rate_per_minute INT UNSIGNED NOT NULL,           -- consultas por minuto, por chave
  active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_plan_code (code)
) ENGINE=InnoDB;

-- Valores de exemplo: o dono do SaaS ajusta no painel.
INSERT INTO api_plans (code, name, price_cents, monthly_quota, rate_per_minute, active, created_at) VALUES
  ('starter',  'Starter',   9900,   2000,  30, 1, UTC_TIMESTAMP()),
  ('pro',      'Pro',      29900,  15000, 120, 1, UTC_TIMESTAMP()),
  ('business', 'Business', 89900, 100000, 600, 1, UTC_TIMESTAMP());

CREATE TABLE api_clients (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(160) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  document       VARCHAR(20) NULL,                 -- CPF ou CNPJ, só dígitos
  company        VARCHAR(160) NULL,
  plan_id        INT UNSIGNED NOT NULL,
  status         ENUM('active','suspended','cancelled') NOT NULL DEFAULT 'active',
  quota_override INT UNSIGNED NULL,                -- combinado especial: substitui a cota do plano
  notes          TEXT NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_api_client_status (status),
  UNIQUE KEY uq_api_client_email (email),
  CONSTRAINT fk_api_client_plan FOREIGN KEY (plan_id) REFERENCES api_plans (id)
) ENGINE=InnoDB;

CREATE TABLE api_keys (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id    BIGINT UNSIGNED NOT NULL,
  label        VARCHAR(80) NOT NULL,
  prefix       VARCHAR(16) NOT NULL,               -- só para reconhecer a chave na tela; o token inteiro nunca é guardado
  key_hash     CHAR(64) NOT NULL,                  -- SHA-256 do token
  last_used_at DATETIME NULL,
  last_used_ip VARCHAR(45) NULL,
  expires_at   DATETIME NULL,
  revoked_at   DATETIME NULL,
  created_at   DATETIME NOT NULL,
  created_by   BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_key_hash (key_hash),
  KEY ix_api_key_client (client_id),
  CONSTRAINT fk_api_key_client FOREIGN KEY (client_id) REFERENCES api_clients (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE api_usage_daily (
  key_id     BIGINT UNSIGNED NOT NULL,
  client_id  BIGINT UNSIGNED NOT NULL,
  day        DATE NOT NULL,
  endpoint   VARCHAR(40) NOT NULL,
  requests   INT UNSIGNED NOT NULL DEFAULT 0,      -- todas as chamadas autenticadas
  billable   INT UNSIGNED NOT NULL DEFAULT 0,      -- as que deram certo (contam na cota)
  errors     INT UNSIGNED NOT NULL DEFAULT 0,
  cache_hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (key_id, day, endpoint),
  KEY ix_api_usage_client (client_id, day),
  CONSTRAINT fk_api_usage_key FOREIGN KEY (key_id) REFERENCES api_keys (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE api_rate (
  key_id BIGINT UNSIGNED NOT NULL,
  bucket INT UNSIGNED NOT NULL,                    -- minuto (epoch / 60)
  hits   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (key_id, bucket)
) ENGINE=InnoDB;

CREATE TABLE api_cache (
  cache_key  VARCHAR(190) NOT NULL,
  payload    MEDIUMTEXT NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (cache_key),
  KEY ix_api_cache_exp (expires_at)
) ENGINE=InnoDB;
