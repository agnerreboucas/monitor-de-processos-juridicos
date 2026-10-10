-- Estrutura de SaaS: aceite dos termos, preferência de aviso, ciclo da assinatura e batimento do worker.

ALTER TABLE users
  ADD COLUMN terms_accepted_at DATETIME NULL,
  ADD COLUMN terms_version VARCHAR(20) NULL,
  ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE subscriptions
  ADD COLUMN admin_note VARCHAR(500) NULL,
  ADD COLUMN suspended_reason VARCHAR(160) NULL;

-- Uma linha por rodada do cron (bin/worker.php). Serve para saber se o robô parou.
CREATE TABLE worker_runs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at   DATETIME NOT NULL,
  finished_at  DATETIME NULL,
  summary      TEXT NULL,
  PRIMARY KEY (id),
  KEY ix_worker_runs_started (started_at)
) ENGINE=InnoDB;
