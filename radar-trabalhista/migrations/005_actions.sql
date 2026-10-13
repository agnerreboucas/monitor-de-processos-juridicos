-- Triagem de ações: de que lado o escritório atua em cada processo e o que já foi tratado em cada publicação.

ALTER TABLE processes
  ADD COLUMN client_side CHAR(1) NULL COMMENT 'A = autor/reclamante, P = réu/reclamado';

CREATE TABLE movement_actions (
  tenant_id   BIGINT UNSIGNED NOT NULL,
  movement_id BIGINT UNSIGNED NOT NULL,
  status      ENUM('open','done','ignored') NOT NULL DEFAULT 'open',
  user_id     BIGINT UNSIGNED NULL,
  note        VARCHAR(255) NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (movement_id, tenant_id),
  CONSTRAINT fk_action_movement FOREIGN KEY (movement_id, tenant_id) REFERENCES process_movements (id, tenant_id) ON DELETE CASCADE
) ENGINE=InnoDB;
