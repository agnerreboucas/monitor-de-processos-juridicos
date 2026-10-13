-- Buscas automáticas em horário escolhido pelo escritório e cobrança por busca (falhas não são cobradas).

ALTER TABLE plans
  ADD COLUMN billing_model ENUM('monthly','per_search') NOT NULL DEFAULT 'monthly',
  ADD COLUMN price_per_search_cents INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE tenants
  ADD COLUMN search_time TIME NULL COMMENT 'horário (Brasília) em que todas as buscas do escritório rodam uma vez por dia; vazio = pelo intervalo do plano',
  ADD COLUMN spend_cap_cents INT UNSIGNED NULL COMMENT 'teto de gasto por mês (plano por busca); vazio = sem teto';

INSERT INTO plans (code, name, price_cents, max_processes, max_users, max_oabs, min_interval_minutes, active, billing_model, price_per_search_cents)
VALUES ('por-busca', 'Pague por busca', 0, 100000, 50, 200, 60, 1, 'per_search', 30);

-- Uma linha por escritório, dia e tipo de busca. "ok" é o que se cobra; "failed" nunca é cobrado.
CREATE TABLE search_usage_daily (
  tenant_id BIGINT UNSIGNED NOT NULL,
  day       DATE NOT NULL,
  kind      ENUM('process','oab','lookup') NOT NULL,
  ok        INT UNSIGNED NOT NULL DEFAULT 0,
  failed    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, day, kind),
  CONSTRAINT fk_search_usage_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;
