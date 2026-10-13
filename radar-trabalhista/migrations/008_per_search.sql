-- Cobrança por busca: o cliente da API paga um valor por consulta bem-sucedida; as que falham não contam.

ALTER TABLE api_plans
  ADD COLUMN billing_model ENUM('monthly','per_search') NOT NULL DEFAULT 'monthly',
  ADD COLUMN price_per_search_cents INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE api_clients
  ADD COLUMN spend_cap_cents INT UNSIGNED NULL COMMENT 'teto de gasto por mês (só plano por busca); vazio = sem teto';

INSERT INTO api_plans (code, name, price_cents, monthly_quota, rate_per_minute, active, created_at, billing_model, price_per_search_cents)
VALUES ('por-busca', 'Pague por busca', 0, 1000000, 60, 1, UTC_TIMESTAMP(), 'per_search', 30);
