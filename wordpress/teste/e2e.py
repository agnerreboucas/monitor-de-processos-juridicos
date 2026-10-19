import json, urllib.request, http.cookiejar, sys
import os
B=os.environ.get('SITE','http://127.0.0.1:9201')
jar=http.cookiejar.CookieJar(); op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
csrf=['']
def call(m,path,body=None,key=None,extra=None):
    h={'Origin':B}
    data=None
    if body is not None or m!='GET':
        h['Content-Type']='application/json'; h['X-CSRF-Token']=csrf[0]; data=json.dumps(body or {}).encode()
    if key: h['Authorization']='Bearer '+key
    h.update(extra or {})
    req=urllib.request.Request(B+path,data=data,headers=h,method=m)
    try:
        r=op.open(req,timeout=40); code=r.status; txt=r.read().decode()
    except urllib.error.HTTPError as e:
        code=e.code; txt=e.read().decode()
    try: j=json.loads(txt)
    except Exception: j=txt[:200]
    return code,j
def ok(label,cond,extra=''):
    print(('OK   ' if cond else 'FALHA'),label,extra); 
    if not cond: ok.fail+=1
ok.fail=0
c,j=call('GET','/radar/api/session'); csrf[0]=j['data']['csrf']; ok('sessão anônima',c==200 and j['data']['authenticated'] is False)
c,j=call('POST','/radar/api/register',{'office_name':'Silva Advocacia','name':'Dra. Helena Silva','email':'helena@silva.teste','password':'senha-segura-123','accept_terms':True,'plan':'profissional'})
ok('cadastro com plano da página de venda',c==201,str(j)[:100])
c,j=call('GET','/radar/api/session'); csrf[0]=j['data']['csrf']; ok('logada após cadastro',j['data']['authenticated'] is True)
c,j=call('GET','/radar/api/plan'); p=j['data']; ok('plano profissional, teste de 14 dias',p['plan_code']=='profissional' and p['days_left'] in (14,15) and p['first_charge_cents']==29950,f"{p['plan_code']} {p['days_left']} {p['first_charge_cents']}")
c,j=call('POST','/radar/api/processes',{'cnj':'0001457-85.2026.5.08.0106','client_label':'Cliente A'}); ok('cadastro de processo',c in (200,201),str(j)[:90])
c,j=call('GET','/radar/api/summary'); ok('resumo do dia',c==200 and j['data']['monitored']==1,str(j['data'])[:100])
c,j=call('PATCH','/radar/api/billing/settings',{'search_time':'05:30'}); ok('horário 05:30',c==200 and j['data']['search_time']=='05:30')
c,j=call('PATCH','/radar/api/billing/settings',{'search_mode':'six_hours'}); ok('modo de 6 em 6 horas',c==200 and j['data']['search_mode']=='six_hours')
c,j=call('POST','/radar/api/processes/check-all'); ok('consultar tudo agora',c==200 and j['data']['processes']>=0,str(j))
# Origin de outro site é barrado
c,j=call('POST','/radar/api/processes/check-all',{},extra={'Origin':'https://malicioso.exemplo'}); ok('origem estranha barrada',c==403,str(c))

# --- dona do sistema: painel da API e chamadas com chave ---
import subprocess
subprocess.run(['php', os.environ['WPDIR'] + '/../cli.php', 'owner', 'helena@silva.teste'], check=True, stdout=subprocess.DEVNULL)
c,j=call('GET','/radar/api/session'); csrf[0]=j['data']['csrf']
c,j=call('POST','/radar/api/login',{'email':'helena@silva.teste','password':'senha-segura-123'}); ok('login',c==200,str(j)[:80])
c,j=call('GET','/radar/api/session'); csrf[0]=j['data']['csrf']; ok('é dona do sistema',j['data'].get('platform_admin') is True)
c,j=call('GET','/radar/api/admin/api/plans'); pid=j['data'][0]['id']; ok('planos de API',c==200 and len(j['data'])>=1)
c,j=call('POST','/radar/api/admin/api/clients',{'name':'Meu GPT','email':'gpt@exemplo.teste','plan_id':pid}); d=j.get('data') or {}; cid=(d.get('client') or d).get('id',0); ok('cliente de API criado',c in (200,201) and cid>0,str(j)[:100])
c,j=call('POST',f'/radar/api/admin/api/clients/{cid}/keys',{'label':'GPT'}); d=j.get('data') or {}
key=d.get('token') or d.get('key') or d.get('plain') or d.get('secret') or ''
ok('chave criada (aparece uma vez)',key.startswith('rdr_live_'))
# API pública: sem cookie, com a chave
op2=urllib.request.build_opener()
def v1(path,k=None):
    req=urllib.request.Request(B+path,headers=({'Authorization':'Bearer '+k} if k else {}))
    try:
        r=op2.open(req,timeout=40); return r.status,json.loads(r.read().decode()),r.headers
    except urllib.error.HTTPError as e:
        return e.code,json.loads(e.read().decode() or '{}'),e.headers
c,j,h=v1('/radar/v1/saude'); ok('/v1/saude sem chave',c==200)
c,j,h=v1('/radar/v1/uso'); ok('/v1/uso sem chave dá 401',c==401)
c,j,h=v1('/radar/v1/uso',key); ok('/v1/uso com chave',c==200,str(j)[:100]); ok('sem cookie na API',h.get('Set-Cookie') is None)
c,j,h=v1('/radar/v1/openapi.json'); ok('openapi com URL completa',j['servers'][0]['url']=='http://127.0.0.1:9201/radar/v1',j['servers'][0]['url'])
c,j,h=v1('/radar/v1/processos/0001457-85.2026.5.08.0106',key); ok('consulta de processo responde (fonte fora do ar aqui)',c in (200,502),f"{c} {str(j)[:120]}")
# --- páginas do tema (criadas a partir do JSON do Elementor; sem o Elementor instalado, aparecem como texto simples) ---
import re as _re
def pagina(path):
    try: r=op.open(urllib.request.Request(B+path),timeout=40); return r.status,r.read().decode()
    except urllib.error.HTTPError as e: return e.code,''
c,h=pagina('/'); ok('página inicial do tema (Início)',c==200 and 'Seus processos vigiados' in h and 'wa.me/5511958649509' in h and 'Pague por busca' in h)
c,h=pagina('/demonstracao/'); ok('demonstração do tema, com as 3 tabelas e datas de hoje',c==200 and h.count('<table')==3 and 'Prado &amp; Associados' in h or 'Prado & Associados' in h)
print('FALHAS:',ok.fail)

sys.exit(1 if ok.fail else 0)
