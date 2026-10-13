-- Venda sem gateway de pagamento: o escritório pede a contratação, o dono do SaaS é avisado e libera depois de receber.

CREATE TABLE plan_requests (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NULL,
  plan_code  VARCHAR(40) NOT NULL,
  message    VARCHAR(500) NULL,
  status     ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  handled_at DATETIME NULL,
  handled_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_plan_request_status (status, created_at),
  KEY ix_plan_request_tenant (tenant_id, status),
  CONSTRAINT fk_plan_request_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB;
