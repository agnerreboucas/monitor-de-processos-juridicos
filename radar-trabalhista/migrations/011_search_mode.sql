-- Como o escritório quer ser atualizado: uma vez por dia (no horário escolhido) ou de 6 em 6 horas.
-- manual_check_at: última vez que alguém clicou em "Consultar tudo agora" (limite de uma vez a cada 15 minutos).
ALTER TABLE tenants
  ADD COLUMN search_mode ENUM('daily','six_hours') NOT NULL DEFAULT 'daily',
  ADD COLUMN manual_check_at DATETIME NULL;
