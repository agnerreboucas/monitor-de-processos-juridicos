-- Assinatura mensal por escritório (R$ 299 / 599 / 1.499), 1 busca por dia por processo, avisos do teste e primeira cobrança de 15 dias.

ALTER TABLE subscriptions ADD COLUMN first_paid_at DATETIME NULL COMMENT 'primeiro pagamento confirmado; enquanto vazio, a 1ª cobrança é de 15 dias';

UPDATE plans SET name = 'Essencial',     price_cents = 29900,  max_processes = 50,  max_users = 3,  max_oabs = 3,  min_interval_minutes = 1440 WHERE code = 'essencial';
UPDATE plans SET name = 'Profissional',  price_cents = 59900,  max_processes = 100, max_users = 5,  max_oabs = 5,  min_interval_minutes = 1440 WHERE code = 'profissional';
UPDATE plans SET name = 'Escritório',    price_cents = 149900, max_processes = 250, max_users = 15, max_oabs = 15, min_interval_minutes = 1440 WHERE code = 'equipe';

-- Cada aviso automático (boas-vindas, marcos do teste, resumo do dia) sai uma única vez por escritório.
CREATE TABLE tenant_notices (
  tenant_id BIGINT UNSIGNED NOT NULL,
  kind      VARCHAR(40) NOT NULL,
  sent_at   DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, kind),
  CONSTRAINT fk_notice_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
) ENGINE=InnoDB;
