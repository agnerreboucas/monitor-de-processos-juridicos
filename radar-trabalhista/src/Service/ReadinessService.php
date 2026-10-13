<?php
declare(strict_types=1);

namespace Radar\Service;

use PDO;
use Radar\Config;
use Radar\Exception\Forbidden;
use Radar\Support\Clock;

/**
 * "Pronto para vender?": confere o que o dono do SaaS precisa ter no ar antes de receber clientes de verdade.
 * Só lê configuração e números; não mostra segredo nenhum (só se está preenchido ou não).
 */
final class ReadinessService
{
    public function __construct(private PDO $pdo, private Config $config, private string $publicDir, private string $backupDir, private Clock $clock = new Clock())
    {
    }

    /** @return list<array{id:string,label:string,status:string,detail:string}> status: ok | warn | fail */
    public function checks(int $adminId): array
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND is_platform_admin = 1 AND status = 'active'");
        $stmt->execute([$adminId]);
        if ($stmt->fetchColumn() === false) {
            throw new Forbidden();
        }
        $c = [];
        $add = function (string $id, string $label, string $status, string $detail) use (&$c): void {
            $c[] = ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail];
        };
        $one = function (string $sql, array $p = []): mixed {
            $s = $this->pdo->prepare($sql);
            $s->execute($p);
            return $s->fetchColumn();
        };

        $url = $this->config->get('APP_URL');
        $add('url', 'Endereço do sistema (APP_URL)', str_starts_with($url, 'https://') ? 'ok' : ($url === '' ? 'fail' : 'warn'),
            $url === '' ? 'Preencha APP_URL no arquivo .env (os links dos e-mails dependem dele).' : (str_starts_with($url, 'https://') ? $url : 'Use https:// em produção. Hoje: ' . $url));

        $last = $one('SELECT started_at FROM worker_runs ORDER BY id DESC LIMIT 1');
        $fresh = $last !== false && (string) $last >= Clock::sql($this->clock->now()->modify('-20 minutes'));
        $add('worker', 'Robô de consultas (cron)', $fresh ? 'ok' : 'fail', $fresh ? 'Rodou nos últimos 20 minutos.'
            : 'Não rodou nos últimos 20 minutos. Agende bin/worker.php a cada 5 minutos (Tarefas Cron da hospedagem).');

        $okEvents = (int) $one("SELECT COUNT(*) FROM monitoring_events WHERE finished_at >= ? AND outcome IN ('new_movements','no_news')", [Clock::sql($this->clock->now()->modify('-24 hours'))]);
        $badEvents = (int) $one("SELECT COUNT(*) FROM monitoring_events WHERE finished_at >= ? AND outcome IN ('source_error','invalid_response')", [Clock::sql($this->clock->now()->modify('-24 hours'))]);
        $add('source', 'Consulta ao Diário (DJEN) funcionando', $okEvents > 0 ? ($badEvents > $okEvents ? 'warn' : 'ok') : ($badEvents > 0 ? 'fail' : 'warn'),
            $okEvents > 0 ? "Últimas 24 h: $okEvents consulta(s) ok, $badEvents com falha." : ($badEvents > 0 ? "Só falhas nas últimas 24 h ($badEvents). Se for HTTP 403, o servidor não está no Brasil."
                : 'Ainda sem nenhuma consulta. Cadastre um processo real e rode bin/testar-fonte.php no servidor: o DJEN só responde a IP do Brasil.'));

        $dj = $this->config->get('DATAJUD_API_KEY') !== '';
        $add('datajud', 'Andamento completo (Datajud)', $dj ? 'ok' : 'warn', $dj ? 'Chave configurada.' : 'Sem DATAJUD_API_KEY: o sistema funciona só com o Diário e a tela avisa que o quadro é incompleto.');

        $smtp = $this->config->get('SMTP_HOST') !== '' && $this->config->get('SMTP_FROM') !== '';
        $failedMail = (int) $one("SELECT COUNT(*) FROM notifications WHERE channel = 'email' AND status = 'failed'");
        $add('smtp', 'Envio de e-mail (SMTP)', !$smtp ? 'fail' : ($failedMail > 0 ? 'warn' : 'ok'),
            !$smtp ? 'Preencha SMTP_HOST, SMTP_USER, SMTP_PASS e SMTP_FROM. Sem e-mail, ninguém recebe aviso nem redefine a senha.' : ($failedMail > 0 ? "$failedMail aviso(s) por e-mail com falha: confira as credenciais." : 'Configurado.'));

        $newest = 0;
        foreach (glob(rtrim($this->backupDir, '/') . '/*.sql.gz') ?: [] as $f) {
            $newest = max($newest, (int) filemtime($f));
        }
        $age = $newest > 0 ? $this->clock->now()->getTimestamp() - $newest : null;
        $add('backup', 'Cópia de segurança do banco', $age !== null && $age < 172800 ? 'ok' : 'fail', $age === null ? 'Nenhuma cópia encontrada. Agende bin/backup.php todo dia e teste a restauração.'
            : ($age < 172800 ? 'Última cópia há ' . max(1, intdiv($age, 3600)) . ' h.' : 'Última cópia há mais de 2 dias. Confira o agendamento.'));

        $pending = [];
        foreach (['termos.html' => 'Termos de Uso', 'privacidade.html' => 'Política de Privacidade'] as $file => $name) {
            $text = (string) @file_get_contents(rtrim($this->publicDir, '/') . '/app/' . $file);
            if ($text === '' || str_contains($text, '[PREENCHER]') || stripos($text, 'rascunho') !== false) {
                $pending[] = $name;
            }
        }
        $add('legal', 'Termos de Uso e Privacidade revisados', $pending === [] ? 'ok' : 'fail',
            $pending === [] ? 'Sem pendências marcadas.' : implode(' e ', $pending) . ': ainda é rascunho com campos [PREENCHER]. Peça a revisão de um advogado e preencha antes de vender.');

        $pay = $this->config->get('PAYMENT_INFO') !== '';
        $add('payment', 'Instruções de pagamento (PAYMENT_INFO)', $pay ? 'ok' : 'fail', $pay ? 'Aparece na tela Plano dos clientes.' : 'Preencha PAYMENT_INFO (ex.: chave Pix e e-mail/WhatsApp). Sem isso, o cliente não sabe como pagar.');
        $sup = $this->config->get('SUPPORT_CONTACT') !== '';
        $add('support', 'Contato de suporte (SUPPORT_CONTACT)', $sup ? 'ok' : 'warn', $sup ? 'Aparece na tela Plano e em Minha conta.' : 'Preencha SUPPORT_CONTACT (e-mail ou WhatsApp) para o cliente pedir ajuda.');

        $plans = (int) $one('SELECT COUNT(*) FROM plans WHERE active = 1');
        $add('plans', 'Planos ativos', $plans > 0 ? 'ok' : 'fail', $plans > 0 ? "$plans plano(s). Confira preços e limites na Administração." : 'Nenhum plano ativo: ninguém consegue se cadastrar.');
        return $c;
    }
}
