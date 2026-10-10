-- Tentativas controladas de envio: quando tentar de novo.
ALTER TABLE notifications ADD COLUMN next_attempt_at DATETIME NULL AFTER last_error;
CREATE INDEX ix_notification_dispatch ON notifications (channel, status, next_attempt_at);
