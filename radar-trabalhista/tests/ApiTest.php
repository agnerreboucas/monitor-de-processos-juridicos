<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Api\ApiAdminService;
use Radar\Api\ApiKeys;
use Radar\Api\PublicApi;
use Radar\Exception\Forbidden;
use Radar\Exception\ValidationError;
use Radar\Http\Request;
use Radar\Monitoring\DatajudAdapter;
use Radar\Monitoring\DjenAdapter;
use Radar\Monitoring\HttpResponse;
use Radar\Service\LookupService;
use Radar\Tests\Support\FakeHttp;

final class ApiTest extends DbTestCase
{
    private int $adminId;
    private ApiAdminService $admin;
    private FakeHttp $djenHttp;
    private FakeHttp $datajudHttp;
    private PublicApi $api;

    protected function setUp(): void
    {
        parent::setUp();
        $o = $this->office('Plataforma', 'dono@plataforma.com');
        $this->adminId = $o['user_id'];
        $this->pdo->exec('UPDATE users SET is_platform_admin = 1 WHERE id = ' . $this->adminId);
        $this->admin = new ApiAdminService($this->pdo, $this->clock);
        $this->djenHttp = new FakeHttp();
        $this->datajudHttp = new FakeHttp();
        $djen = new DjenAdapter($this->djenHttp);
        $this->api = new PublicApi($this->pdo, new LookupService($this->pdo, $djen, new DatajudAdapter($this->datajudHttp, 'k')), $djen, $this->clock);
    }

    private function planId(string $code = 'starter'): int
    {
        return (int) $this->scalar('SELECT id FROM api_plans WHERE code = ?', [$code]);
    }

    /** @return array{0:int,1:string} id do cliente e token */
    private function clientWithKey(string $email = 'cli@x.com', string $plan = 'starter'): array
    {
        $c = $this->admin->createClient($this->adminId, ['name' => 'Cliente X', 'email' => $email, 'plan_id' => $this->planId($plan)]);
        $k = $this->admin->createKey($this->adminId, (int) $c['client']['id'], 'Produção', null);
        return [(int) $c['client']['id'], $k['token']];
    }

    private function get(string $path, ?string $token, array $query = [], string $header = 'authorization'): \Radar\Http\Response
    {
        $headers = $token === null ? [] : [$header => $header === 'authorization' ? 'Bearer ' . $token : $token];
        return $this->api->handle(new Request('GET', $path, $query, [], $headers, '203.0.113.9', true));
    }

    private function json(\Radar\Http\Response $r): array
    {
        return json_decode($r->body, true) ?? [];
    }

    private function datajudOk(): HttpResponse
    {
        return new HttpResponse(200, json_encode(['hits' => ['hits' => [['_source' => ['tribunal' => 'TRT8', 'grau' => 'G1', 'classe' => ['nome' => 'Ação Trabalhista'], 'assuntos' => [['nome' => 'Horas Extras']],
            'orgaoJulgador' => ['nome' => '5ª Vara'], 'dataAjuizamento' => '2026-01-20T00:00:00.000Z', 'nivelSigilo' => 0,
            'movimentos' => [['codigo' => 26, 'nome' => 'Distribuição', 'dataHora' => '2026-01-20T13:05:10.000Z']]]]]]]));
    }

    private function djenOk(): HttpResponse
    {
        return new HttpResponse(200, json_encode(['count' => 1, 'items' => [['id' => 5, 'data_disponibilizacao' => '2026-10-09', 'tipoComunicacao' => 'Intimação', 'tipoDocumento' => 'Despacho',
            'nomeOrgao' => 'Vara', 'nomeClasse' => 'X', 'siglaTribunal' => 'TRT8', 'texto' => '<p>Intimação publicada.</p>', 'link' => 'https://pje.exemplo/5', 'hash' => 'h',
            'numero_processo' => self::CNJ_TRT8_DIGITS, 'destinatarios' => [['nome' => 'ANA', 'polo' => 'A']], 'destinatarioadvogados' => [['advogado' => ['nome' => 'DRA', 'numero_oab' => '1', 'uf_oab' => 'PA']]]]]]));
    }

    private const CNJ_TRT8_DIGITS = '00014578520265080106';

    // ── painel do dono ───────────────────────────────────────────────────────

    public function testOnlyThePlatformAdminCanUseTheApiPanel(): void
    {
        $other = $this->office('Outro', 'o@o.com');
        $this->expectException(Forbidden::class);
        $this->admin->clients($other['user_id']);
    }

    public function testClientAndKeyLifecycleNeverStoresTheToken(): void
    {
        [$clientId, $token] = $this->clientWithKey();
        $this->assertTrue(ApiKeys::looksValid($token));
        $row = $this->pdo->query('SELECT * FROM api_keys')->fetch();
        $this->assertSame(ApiKeys::hash($token), $row['key_hash']);
        $this->assertSame(substr($token, 0, 13), $row['prefix']);
        $this->assertStringNotContainsString(substr($token, 13), json_encode($row));       // o token inteiro não está em lugar nenhum da linha
        $this->assertStringNotContainsString($token, (string) $this->scalar("SELECT GROUP_CONCAT(COALESCE(meta, '')) FROM audit_logs"));
        $view = $this->admin->client($this->adminId, $clientId);
        $this->assertSame(1, count($view['keys']));
        $this->assertArrayNotHasKey('key_hash', $view['keys'][0]);
        $this->assertCount(30, $view['days']);

        $this->admin->revokeKey($this->adminId, (int) $row['id']);
        $this->assertSame(403, $this->get('/v1/uso', $token)->status);
    }

    public function testClientValidationAndLimits(): void
    {
        foreach ([['name' => '', 'email' => 'a@a.com'], ['name' => 'A', 'email' => 'invalido'], ['name' => 'A', 'email' => 'a@a.com', 'document' => '123']] as $bad) {
            try {
                $this->admin->createClient($this->adminId, $bad + ['plan_id' => $this->planId()]);
                $this->fail('deveria recusar');
            } catch (ValidationError) {
                $this->addToAssertionCount(1);
            }
        }
        $c = $this->admin->createClient($this->adminId, ['name' => 'A', 'email' => 'a@a.com', 'document' => '123.456.789-09', 'plan_id' => $this->planId()]);
        $this->assertSame('12345678909', $c['client']['document']);
        $this->expectException(ValidationError::class);
        $this->admin->createClient($this->adminId, ['name' => 'B', 'email' => 'A@a.com', 'plan_id' => $this->planId()]);     // e-mail repetido
    }

    public function testTenKeysMaxAndSuspendedClientsCannotGetKeys(): void
    {
        [$clientId] = $this->clientWithKey();
        for ($i = 0; $i < 9; $i++) {
            $this->admin->createKey($this->adminId, $clientId, 'k' . $i, null);
        }
        try {
            $this->admin->createKey($this->adminId, $clientId, 'extra', null);
            $this->fail('11ª chave');
        } catch (ValidationError $e) {
            $this->assertStringContainsString('10 chaves', $e->getMessage());
        }
        $this->admin->updateClient($this->adminId, $clientId, ['status' => 'suspended']);
        $this->expectException(ValidationError::class);
        $this->admin->createKey($this->adminId, $clientId, 'nova', null);
    }

    public function testPlanValidation(): void
    {
        $plan = $this->admin->savePlan($this->adminId, null, ['code' => 'ouro', 'name' => 'Ouro', 'price_cents' => 49900, 'monthly_quota' => 50000, 'rate_per_minute' => 300]);
        $this->assertSame('Ouro', $plan['name']);
        $edited = $this->admin->savePlan($this->adminId, (int) $plan['id'], ['name' => 'Ouro 2', 'price_cents' => 59900, 'monthly_quota' => 60000, 'rate_per_minute' => 300, 'active' => false]);
        $this->assertSame([59900, 0], [(int) $edited['price_cents'], (int) $edited['active']]);
        $this->expectException(ValidationError::class);
        $this->admin->savePlan($this->adminId, null, ['code' => 'ruim', 'name' => 'Ruim', 'price_cents' => -1, 'monthly_quota' => 0, 'rate_per_minute' => 0]);
    }

    public function testInactivePlanCannotBeAssignedToNewClients(): void
    {
        $plan = $this->admin->savePlan($this->adminId, null, ['code' => 'velho', 'name' => 'Velho', 'price_cents' => 100, 'monthly_quota' => 10, 'rate_per_minute' => 1, 'active' => false]);
        $this->expectException(ValidationError::class);
        $this->admin->createClient($this->adminId, ['name' => 'A', 'email' => 'a@a.com', 'plan_id' => (int) $plan['id']]);
    }

    public function testUsageCsvAndOverviewForTheMonth(): void
    {
        $c = $this->admin->createClient($this->adminId, ['name' => '=cmd|calc', 'email' => 'f@f.com', 'plan_id' => $this->planId('pro')]);
        $k = $this->admin->createKey($this->adminId, (int) $c['client']['id'], 'p', null);
        $this->djenHttp->push($this->djenOk());
        $this->datajudHttp->push($this->datajudOk());
        $this->assertSame(200, $this->get('/v1/processos/' . self::CNJ_TRT8, $k['token'])->status);
        $csv = $this->admin->usageCsv($this->adminId, null);
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringContainsString('299,00', $csv);
        $ov = $this->admin->overview($this->adminId);
        $this->assertSame(1, $ov['clients']['active']);
        $this->assertSame(1, $ov['billable_month']);
        $this->assertSame(29900, $ov['mrr_cents']);
        $this->assertSame('=cmd|calc', $ov['top_clients'][0]['name']);
    }

    // ── API pública ──────────────────────────────────────────────────────────

    public function testRejectsMissingMalformedAndUnknownKeys(): void
    {
        $this->assertSame('chave_ausente', $this->json($this->get('/v1/uso', null))['erro']['codigo']);
        $this->assertSame('chave_invalida', $this->json($this->get('/v1/uso', 'qualquer-coisa'))['erro']['codigo']);
        $this->assertSame(401, $this->get('/v1/uso', ApiKeys::PREFIX . str_repeat('a', 40))->status);
    }

    public function testSuspendedClientAndExpiredKeyAreBlocked(): void
    {
        [$clientId, $token] = $this->clientWithKey();
        $this->assertSame(200, $this->get('/v1/uso', $token)->status);
        $this->admin->updateClient($this->adminId, $clientId, ['status' => 'suspended']);
        $r = $this->get('/v1/uso', $token);
        $this->assertSame(403, $r->status);
        $this->assertSame('cliente_suspenso', $this->json($r)['erro']['codigo']);
        $this->admin->updateClient($this->adminId, $clientId, ['status' => 'active']);
        $this->pdo->exec("UPDATE api_keys SET expires_at = '2000-01-01 00:00:00'");
        $this->assertSame('chave_expirada', $this->json($this->get('/v1/uso', $token))['erro']['codigo']);
    }

    public function testProcessEndpointReturnsConsolidatedDataMetersAndCaches(): void
    {
        [$clientId, $token] = $this->clientWithKey();
        $this->djenHttp->push($this->djenOk());
        $this->datajudHttp->push($this->datajudOk());
        $r = $this->get('/v1/processos/' . self::CNJ_TRT8, $token);
        $body = $this->json($r);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame('0001457-85.2026.5.08.0106', $body['numero']);
        $this->assertSame('Ação Trabalhista', $body['capa']['classe']);
        $this->assertEqualsCanonicalizing(['djen', 'datajud'], array_column($body['andamento'], 'fonte'));
        $this->assertSame('ANA', $body['partes'][0]['nome']);
        $this->assertSame('DRA', $body['advogados'][0]['nome']);
        $this->assertSame(['ok', 'ok'], array_column($body['fontes'], 'status'));
        $this->assertFalse($body['cache']);
        $this->assertSame('1999', $r->headers['X-Quota-Remaining']);

        $again = $this->get('/v1/processos/' . self::CNJ_TRT8, $token);          // 2ª vez: do cache, sem falar com as fontes e ainda conta na cota
        $this->assertTrue($this->json($again)['cache']);
        $this->assertCount(1, $this->djenHttp->requests);
        $this->assertCount(1, $this->datajudHttp->posts);
        $row = $this->pdo->query('SELECT SUM(requests) r, SUM(billable) b, SUM(cache_hits) c FROM api_usage_daily WHERE client_id = ' . $clientId)->fetch();
        $this->assertSame([2, 2, 1], [(int) $row['r'], (int) $row['b'], (int) $row['c']]);
        $this->assertNotNull($this->scalar('SELECT last_used_at FROM api_keys'));
    }

    public function testApiKeyHeaderWorksAndNoCorsIsOffered(): void
    {
        [, $token] = $this->clientWithKey();
        $r = $this->get('/v1/uso', $token, [], 'x-api-key');
        $this->assertSame(200, $r->status);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $r->headers);
    }

    public function testBadNumberIs422AndDoesNotSpendQuota(): void
    {
        [, $token] = $this->clientWithKey();
        $r = $this->get('/v1/processos/1234567-00.2026.5.08.0106', $token);
        $this->assertSame(422, $r->status);
        $this->assertSame(0, (int) $this->scalar('SELECT COALESCE(SUM(billable), 0) FROM api_usage_daily'));
    }

    public function testWhenNoSourceAnswersItIs502AndNotBilledNorCached(): void
    {
        [, $token] = $this->clientWithKey();
        $this->djenHttp->push(new HttpResponse(403, ''));
        $this->datajudHttp->push(new HttpResponse(503, ''));
        $r = $this->get('/v1/processos/' . self::CNJ_TRT8, $token);
        $this->assertSame(502, $r->status);
        $this->assertSame('fonte_indisponivel', $this->json($r)['erro']['codigo']);
        $this->assertSame(0, (int) $this->scalar('SELECT COALESCE(SUM(billable), 0) FROM api_usage_daily'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM api_cache'));
    }

    public function testOneSourceFailingStillAnswersButIsNotCached(): void
    {
        [, $token] = $this->clientWithKey();
        $this->djenHttp->push(new HttpResponse(403, ''));
        $this->datajudHttp->push($this->datajudOk());
        $r = $this->get('/v1/processos/' . self::CNJ_TRT8, $token);
        $body = $this->json($r);
        $this->assertSame(200, $r->status);
        $status = array_column($body['fontes'], 'status', 'codigo');
        $this->assertEquals(['djen' => 'erro', 'datajud' => 'ok'], $status);                // o quadro vem marcado como incompleto
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM api_cache'));
    }

    public function testPerMinuteLimitReturns429WithRetryAfter(): void
    {
        $this->pdo->exec("UPDATE api_plans SET rate_per_minute = 2 WHERE code = 'starter'");
        [, $token] = $this->clientWithKey();
        $this->assertSame(200, $this->get('/v1/uso', $token)->status);
        $this->assertSame(200, $this->get('/v1/uso', $token)->status);
        $r = $this->get('/v1/uso', $token);
        $this->assertSame(429, $r->status);
        $this->assertSame('limite_por_minuto', $this->json($r)['erro']['codigo']);
        $this->assertArrayHasKey('Retry-After', $r->headers);
        $this->clock->advance('PT1M');
        $this->assertSame(200, $this->get('/v1/uso', $token)->status);                    // minuto novo
    }

    public function testMonthlyQuotaStopsBillableCallsAndResetsNextMonth(): void
    {
        $this->pdo->exec("UPDATE api_plans SET monthly_quota = 2 WHERE code = 'starter'");
        [, $token] = $this->clientWithKey();
        foreach ([1, 2] as $_) {
            $this->djenHttp->push($this->djenOk());
            $this->datajudHttp->push($this->datajudOk());
        }
        $this->assertSame(200, $this->get('/v1/processos/' . self::CNJ_TRT8, $token)->status);
        $this->assertSame(200, $this->get('/v1/processos/' . self::CNJ_TRT8, $token)->status);
        $r = $this->get('/v1/processos/' . self::CNJ_TRT8, $token);
        $this->assertSame(429, $r->status);
        $this->assertSame('cota_mensal_esgotada', $this->json($r)['erro']['codigo']);
        $this->assertSame('0', $r->headers['X-Quota-Remaining']);
        $uso = $this->json($this->get('/v1/uso', $token));                                // ver o próprio consumo nunca é barrado
        $this->assertSame([2, 2, 0], [$uso['cota_mensal'], $uso['usadas_no_mes'], $uso['restantes']]);
        $this->clock->advance('P40D');
        $this->djenHttp->push($this->djenOk());
        $this->datajudHttp->push($this->datajudOk());
        $this->assertSame(200, $this->get('/v1/processos/' . self::CNJ_TRT8, $token)->status);   // mês novo, cota nova
    }

    public function testOabEndpointValidatesPeriodAndReturnsPublications(): void
    {
        [, $token] = $this->clientWithKey();
        $this->assertSame(422, $this->get('/v1/oab/XX/128733/publicacoes', $token)->status);
        $this->assertSame(422, $this->get('/v1/oab/SP/128733/publicacoes', $token, ['inicio' => $this->clock->now()->modify('-60 days')->format('Y-m-d'), 'fim' => $this->clock->now()->format('Y-m-d')])->status);   // mais de 31 dias
        $this->assertSame(422, $this->get('/v1/oab/SP/128733/publicacoes', $token, ['inicio' => 'ontem'])->status);
        $this->djenHttp->push($this->djenOk());
        $r = $this->get('/v1/oab/SP/128733A/publicacoes', $token, ['inicio' => $this->clock->now()->modify('-10 days')->format('Y-m-d'), 'fim' => $this->clock->now()->format('Y-m-d')]);
        $body = $this->json($r);
        $this->assertSame(200, $r->status, $r->body);
        $this->assertSame(1, $body['total']);
        $this->assertSame('0001457-85.2026.5.08.0106', $body['publicacoes'][0]['numero']);
        $this->assertSame('Intimação publicada.', $body['publicacoes'][0]['texto']);
        $this->assertSame('128733', $this->djenHttp->requests[0]['query']['numeroOab']);
    }

    public function testOnlyGetIsAllowedAndHealthNeedsNoKey(): void
    {
        $r = $this->api->handle(new Request('POST', '/v1/processos/' . self::CNJ_TRT8, [], [], [], '1.1.1.1', true));
        $this->assertSame(405, $r->status);
        $this->assertSame(200, $this->get('/v1/saude', null)->status);
        $spec = $this->json($this->get('/v1/openapi.json', null));
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertSame('https://radar.exemplo.com.br/v1', \Radar\Api\OpenApi::spec('https://radar.exemplo.com.br/')['servers'][0]['url']);      // URL completa quando APP_URL existe
        $this->assertArrayHasKey('/processos/{numero}', $spec['paths']);
    }

    public function testPurgeRemovesExpiredCacheAndOldRateRows(): void
    {
        $this->pdo->exec("INSERT INTO api_cache (cache_key, payload, expires_at) VALUES ('velho', '{}', '2000-01-01 00:00:00')");
        $r = (new \Radar\Service\PrivacyService($this->pdo, $this->clock))->purgeExpired();
        $this->assertSame(1, $r['api_cache']);
    }

    public function testPerSearchPlanChargesOnlySuccessfulSearchesAndHonoursTheSpendCap(): void
    {
        $c = $this->admin->createClient($this->adminId, ['name' => 'Pay Go', 'email' => 'pg@x.com', 'plan_id' => $this->planId('por-busca')]);
        $cid = (int) $c['client']['id'];
        $token = $this->admin->createKey($this->adminId, $cid, 'k', null)['token'];

        $this->djenHttp->push($this->djenOk());
        $this->datajudHttp->push($this->datajudOk());
        $this->assertSame(200, $this->get('/v1/processos/' . self::CNJ_TRT8, $token)->status);              // cobrada
        $this->djenHttp->push(new HttpResponse(403, ''));
        $this->datajudHttp->push(new HttpResponse(503, ''));
        $this->assertSame(502, $this->get('/v1/processos/0022929-16.2016.8.13.0297', $token)->status);       // falhou: não cobrada
        $this->assertSame(422, $this->get('/v1/processos/1234567-00.2026.5.08.0106', $token)->status);            // inválida: não cobrada

        $uso = $this->json($this->get('/v1/uso', $token));
        $this->assertSame(['por_busca', 0.3, 0.3, 1], [$uso['modelo'], $uso['preco_por_busca'], $uso['gasto_no_mes'], $uso['usadas_no_mes']]);
        $this->assertStringContainsString('falham não contam', $uso['cobranca']);

        $this->assertStringContainsString('0,30', $this->admin->usageCsv($this->adminId, null));
        $this->assertSame(30, $this->admin->client($this->adminId, $cid)['amount_cents']);
        $this->assertSame(30, $this->admin->clients($this->adminId)['items'][0]['amount_cents']);

        $this->admin->updateClient($this->adminId, $cid, ['spend_cap_cents' => 30]);                         // teto de R$ 0,30 = 1 busca
        $r = $this->get('/v1/processos/' . self::CNJ_TRT8, $token);
        $this->assertSame(429, $r->status);
        $this->assertSame('teto_de_gasto_atingido', $this->json($r)['erro']['codigo']);
        $this->assertSame(200, $this->get('/v1/uso', $token)->status);
    }
}
