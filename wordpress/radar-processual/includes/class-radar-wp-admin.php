<?php
if (!defined('ABSPATH')) {
    exit;
}

use Radar\Monitoring\CurlHttpClient;

/** Tela do administrador do site: Radar > Configurações e saúde do sistema. */
final class Radar_WP_Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', static function (): void {
            add_menu_page('Radar Processual', 'Radar', 'manage_options', 'radar-processual', [self::class, 'page'], 'dashicons-chart-area', 58);
        });
        add_action('admin_init', static function (): void {
            register_setting('radar_wp', Radar_WP_Plugin::OPTION, ['sanitize_callback' => [self::class, 'sanitize']]);
        });
        add_action('admin_post_radar_wp_run', [self::class, 'actionRun']);
        add_action('admin_post_radar_wp_test', [self::class, 'actionTest']);
        add_action('admin_post_radar_wp_owner', [self::class, 'actionOwner']);
        add_filter('plugin_action_links_' . plugin_basename(RADAR_WP_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=radar-processual')) . '">Configurar</a>');
            return $links;
        });
    }

    /** @param mixed $in @return array<string,mixed> */
    public static function sanitize($in): array
    {
        $in = is_array($in) ? $in : [];
        $old = Radar_WP_Plugin::settings();
        $djen = esc_url_raw((string) ($in['djen_url'] ?? ''), ['https']);
        $key = trim((string) ($in['datajud_key'] ?? ''));
        return [
            'datajud_key' => $key !== '' ? sanitize_text_field($key) : (!empty($in['clear_key']) ? '' : (string) $old['datajud_key']),     // campo vazio mantém a chave guardada
            'djen_url' => $djen !== '' ? $djen : 'https://comunicaapi.pje.jus.br/api/v1/comunicacao',
            'payment_info' => sanitize_textarea_field((string) ($in['payment_info'] ?? '')),
            'support_contact' => sanitize_text_field((string) ($in['support_contact'] ?? '')),
            'http_proxy' => esc_url_raw((string) ($in['http_proxy'] ?? ''), ['http', 'https']),
            'trusted_proxies' => sanitize_text_field((string) ($in['trusted_proxies'] ?? '')),
            'delete_on_uninstall' => !empty($in['delete_on_uninstall']) ? 1 : 0,
        ];
    }

    private static function nonce(string $action): string
    {
        return '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce($action)) . '">';
    }

    private static function back(string $msg, string $type = 'success'): void
    {
        set_transient('radar_wp_notice_' . get_current_user_id(), [$type, $msg], 60);
        wp_safe_redirect(admin_url('admin.php?page=radar-processual'));
        exit;
    }

    private static function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.', 403);
        }
        check_admin_referer($action);
    }

    public static function actionRun(): void
    {
        self::guard('radar_wp_run');
        $r = Radar_WP_Plugin::tick();
        $r === null
            ? self::back('A rodada não executou (outra já estava em andamento ou deu erro). Veja o resultado abaixo.', 'warning')
            : self::back(sprintf('Rodada concluída: %d processo(s) consultado(s), %d com sucesso, %d falha(s), %d movimentação(ões) nova(s), %d e-mail(s).', $r['checked'], $r['ok'], $r['failed'], $r['new_movements'], $r['emails'] + $r['lifecycle']));
    }

    public static function actionTest(): void
    {
        self::guard('radar_wp_test');
        $s = Radar_WP_Plugin::settings();
        $res = (new CurlHttpClient($s['http_proxy'] ?: null))->get((string) $s['djen_url'],
            ['numeroProcesso' => '00014578520265080106', 'itensPorPagina' => 1, 'pagina' => 1], ['Accept' => 'application/json', 'User-Agent' => 'RadarProcessual/1.0'], 20);
        if ($res->status === 200) {
            self::back('O Diário (DJEN) respondeu normalmente. A hospedagem serve.');
        }
        self::back($res->status === 403 || $res->status === 0
            ? 'O Diário recusou ou não respondeu a este servidor (HTTP ' . $res->status . '). O Diário só atende servidores no Brasil.'
            : 'O Diário respondeu HTTP ' . $res->status . '. Tente de novo mais tarde.', 'error');
    }

    public static function actionOwner(): void
    {
        self::guard('radar_wp_owner');
        $email = sanitize_email((string) ($_POST['owner_email'] ?? ''));
        if ($email === '') {
            self::back('Informe o e-mail da conta do Radar.', 'error');
        }
        Radar_WP_Plugin::makeOwner($email)
            ? self::back('Pronto: ' . $email . ' agora é administradora do Radar (aparece o menu Administração, Usuários e uso e API e clientes).')
            : self::back('Não achei uma conta ativa do Radar com esse e-mail. Crie a conta em ' . Radar_WP_Plugin::basePath() . '/entrar#/cadastro e tente de novo.', 'error');
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = Radar_WP_Plugin::settings();
        $notice = get_transient('radar_wp_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('radar_wp_notice_' . get_current_user_id());
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr(in_array($notice[0], ['error', 'warning'], true) ? $notice[0] : 'success'), esc_html((string) $notice[1]));
        }
        $entrar = home_url(Radar_WP_Plugin::SLUG . '/entrar');
        $last = get_option('radar_wp_last_tick');
        $next = wp_next_scheduled('radar_wp_tick');
        $tablesOk = true;
        $owners = 0;
        try {
            Radar_WP_Plugin::pdo()->query('SELECT 1 FROM plans LIMIT 1');
            $owners = (int) Radar_WP_Plugin::pdo()->query('SELECT COUNT(*) FROM users WHERE is_platform_admin = 1')->fetchColumn();
        } catch (\Throwable) {
            $tablesOk = false;
        }
        $row = static fn (string $label, bool $ok, string $detail) => printf('<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html($label), $ok ? '<span style="color:#1a7f37">●</span> ok' : '<span style="color:#b42318">●</span> atenção', esc_html($detail));
        echo '<div class="wrap"><h1>Radar Processual</h1>';
        echo '<p>Painel do sistema: <a href="' . esc_url($entrar) . '" target="_blank" rel="noopener">' . esc_html($entrar) . '</a> · API: <code>' . esc_html(home_url('radar/v1')) . '</code></p>';
        echo '<h2>Saúde do sistema</h2><table class="widefat striped" style="max-width:900px"><tbody>';
        $row('Tabelas do Radar no banco', $tablesOk, $tablesOk ? 'Criadas (prefixo ' . $GLOBALS['wpdb']->prefix . 'radar_).' : 'Não encontrei as tabelas. Desative e ative o plugin de novo.');
        $row('Dono do sistema', $owners > 0, $owners > 0 ? $owners . ' conta(s) administradora(s).' : 'Nenhuma ainda. Crie sua conta no painel e informe o e-mail abaixo.');
        $row('Robô de consultas (WP-Cron)', is_array($last) && !empty($last['ok']) && $last['at'] > time() - 1200,
            is_array($last) ? 'Última rodada: ' . wp_date('d/m/Y H:i', (int) $last['at']) . (empty($last['ok']) ? ' (com erro: ' . ($last['error'] ?? '') . ')' : '') : 'Ainda não rodou. O WP-Cron roda quando o site recebe visitas.');
        $row('Próxima rodada agendada', $next !== false, $next !== false ? wp_date('d/m/Y H:i', $next) : 'Sem agendamento. Desative e ative o plugin.');
        $row('Cron do servidor', defined('DISABLE_WP_CRON') && DISABLE_WP_CRON, defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'WP-Cron desligado: o cron do servidor deve chamar wp-cron.php a cada minuto.' : 'Recomendado: no painel da hospedagem, um cron a cada minuto chamando wp-cron.php (veja o guia).');
        $row('Chave do Datajud', $s['datajud_key'] !== '', $s['datajud_key'] !== '' ? 'Preenchida.' : 'Vazia: o sistema mostra só as publicações do Diário, sem o andamento completo.');
        $row('HTTPS', is_ssl() || str_starts_with(home_url(), 'https://'), str_starts_with(home_url(), 'https://') ? 'O site usa HTTPS.' : 'Ative o HTTPS: os cookies e a API dependem dele.');
        echo '</tbody></table>';
        echo '<div style="margin-top:12px"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
        echo '<input type="hidden" name="action" value="radar_wp_run">'; echo self::nonce('radar_wp_run'); submit_button('Rodar o robô agora', 'secondary', '', false); echo '</form> ';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline"><input type="hidden" name="action" value="radar_wp_test">'; echo self::nonce('radar_wp_test'); submit_button('Testar o Diário (DJEN)', 'secondary', '', false); echo '</form></div>';
        echo '<h2>Dono do sistema</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="radar_wp_owner">'; echo self::nonce('radar_wp_owner');
        echo '<p>Depois de criar sua conta no painel, informe aqui o mesmo e-mail para virar administrador do Radar (clientes, API, usuários e uso).</p><input type="email" name="owner_email" class="regular-text" placeholder="seu@email.com"> '; submit_button('Tornar administrador', 'primary', '', false); echo '</form>';
        echo '<h2>Configurações</h2><form method="post" action="options.php">'; settings_fields('radar_wp');
        $o = Radar_WP_Plugin::OPTION;
        echo '<table class="form-table"><tbody>';
        printf('<tr><th>Chave do Datajud</th><td><input type="password" name="%s[datajud_key]" class="regular-text" autocomplete="new-password" placeholder="%s"> <label><input type="checkbox" name="%s[clear_key]" value="1"> apagar a chave guardada</label><p class="description">Chave pública do CNJ (APIKey). Deixe vazio para manter a atual. Nunca é exibida de volta.</p></td></tr>', esc_attr($o), esc_attr($s['datajud_key'] !== '' ? '(guardada)' : ''), esc_attr($o));
        printf('<tr><th>Endereço do Diário (DJEN)</th><td><input type="url" name="%s[djen_url]" class="large-text" value="%s"></td></tr>', esc_attr($o), esc_attr($s['djen_url']));
        printf('<tr><th>Como o cliente paga</th><td><textarea name="%s[payment_info]" rows="4" class="large-text" placeholder="Pix: chave..., favorecido...">%s</textarea><p class="description">Aparece na tela Plano do cliente.</p></td></tr>', esc_attr($o), esc_textarea($s['payment_info']));
        printf('<tr><th>Contato de suporte</th><td><input type="text" name="%s[support_contact]" class="regular-text" value="%s" placeholder="suporte@seudominio.com.br"></td></tr>', esc_attr($o), esc_attr($s['support_contact']));
        printf('<tr><th>Proxy de saída (opcional)</th><td><input type="url" name="%s[http_proxy]" class="regular-text" value="%s" placeholder="http://usuario:senha@host:porta"><p class="description">Só se a hospedagem não for no Brasil e você tiver um proxy autorizado.</p></td></tr>', esc_attr($o), esc_attr($s['http_proxy']));
        printf('<tr><th>Proxies confiáveis</th><td><input type="text" name="%s[trusted_proxies]" class="regular-text" value="%s" placeholder="IPs separados por vírgula"><p class="description">Só preencha se o site fica atrás de um proxy reverso (para ler o IP real do visitante).</p></td></tr>', esc_attr($o), esc_attr($s['trusted_proxies']));
        printf('<tr><th>Ao excluir o plugin</th><td><label><input type="checkbox" name="%s[delete_on_uninstall]" value="1" %s> apagar também todas as tabelas e dados do Radar</label></td></tr>', esc_attr($o), checked(!empty($s['delete_on_uninstall']), true, false));
        echo '</tbody></table>'; submit_button('Salvar configurações'); echo '</form></div>';
    }
}
