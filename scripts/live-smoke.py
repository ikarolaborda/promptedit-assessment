"""Exercise the deployed public HTTP flow using a real Pixelforge key on the server.
Creates three labelled, simulated generations in the shared demo workspace.
Does not replace browser interaction or visual verification.
"""
import argparse,json,time,uuid,re,html
from decimal import Decimal
from urllib.parse import unquote
import requests
p=argparse.ArgumentParser();p.add_argument('url');p.add_argument('--output',default='artifacts/live-smoke.json');args=p.parse_args()
base=args.url.rstrip('/');s=requests.Session();s.headers['User-Agent']='FrameStudioAcceptance/1.0'
checks=[]
version=None
def check(value,label):
    if not value: raise AssertionError(label)
    checks.append(label);print('PASS',label,flush=True)
def post(path,data):
    token=unquote(s.cookies.get('XSRF-TOKEN') or '')
    return s.post(base+path,json=data,headers={'Accept':'application/json','X-XSRF-TOKEN':token},allow_redirects=False,timeout=30)
def page(path='/studio'):
    global version
    headers={'Accept':'text/html'}
    if version is not None: headers.update({'X-Inertia':'true','X-Inertia-Version':version})
    r=s.get(base+path,headers=headers,timeout=30);r.raise_for_status()
    if r.headers.get('X-Inertia')=='true': data=r.json()
    else: data=json.loads(html.unescape(re.search(r'data-page="([^"]+)"',r.text).group(1)))
    version=data.get('version');return data['props']
r=s.get(base+'/up',timeout=30);check(r.status_code==200,'Azure HTTPS health check')
r=s.get(base+'/login',timeout=30);check(r.status_code==200 and 'Frame Studio' in r.text,'sign-in page and server-rendered shell')
check(s.get(base+'/studio',allow_redirects=False,timeout=30).status_code==302,'anonymous workspace is protected')
check(s.post(base+'/demo',timeout=30,allow_redirects=False).status_code==419,'real HTTP request rejects missing CSRF token')
demo_response=post('/demo',{})
check(demo_response.status_code==302,'demo authentication and session rotation')
check(demo_response.headers.get('Location','').startswith('https://'),'authentication redirect preserves HTTPS')
before=page();old_ids={x['id'] for x in before['history']['data']};before_count=before['stats']['total'];before_spent=Decimal(before['stats']['spent'])
request_id=str(uuid.uuid4());balance=Decimal(before['stats']['balance'])
check(post('/credits',{'request_id':request_id}).status_code==302,'simulated credit purchase')
check(post('/credits',{'request_id':request_id}).status_code==302,'credit purchase replay')
check(Decimal(page()['stats']['balance'])==balance+5,'purchase credited exactly once')
cases=[('success','A coastal studio with terracotta walls [succeed] [duplicate]','1536x1024','medium',['https://example.com/reference.jpg']),('failure','A sample failure path [fail]','1024x1024','low',[]),('declined','A sample declined prompt [blocked]','1024x1536','high',[])]
inputs=[]
for label,prompt,size,quality,refs in cases:
    data={'prompt':prompt,'size':size,'quality':quality,'reference_images':refs,'client_request_id':str(uuid.uuid4())}
    quote=post('/quote',{k:v for k,v in data.items() if k!='client_request_id'});check(quote.status_code==200,label+' live pricing')
    q=quote.json();check(Decimal(q['minimum'])<=Decimal(q['estimate'])<=Decimal(q['maximum']),label+' estimate within quoted range')
    response=post('/generations',data);check(response.status_code==302,label+' submission accepted by app')
    inputs.append(data)
check(post('/generations',inputs[0]).status_code==302,'generation replay accepted')
check(page()['stats']['total']==before_count+3,'generation replay did not create another record')
limit=time.monotonic()+160;records=[]
while time.monotonic()<limit:
    props=page();records=[x for x in props['history']['data'] if x['id'] not in old_ids]
    if len(records)>=3 and all(x['status'] in ('succeeded','failed') for x in records):break
    time.sleep(4)
by_prompt={r['prompt']:r for r in records}
check(by_prompt[cases[0][1]]['status']=='succeeded','real provider generation completed')
for c in cases[1:]:
    check(by_prompt[c[1]]['status']=='failed' and Decimal(by_prompt[c[1]]['actual'])==0,c[0]+' is terminal and free')
success=by_prompt[cases[0][1]];check(Decimal(success['actual'])>0 and success['usage'] is not None,'actual token cost charged after completion')
image=s.get(success['image_url'],timeout=30);check(image.status_code==200 and image.headers.get('Content-Type','').startswith('image/'),'generated image URL returns an image')
final=page();check(Decimal(final['stats']['reserved'])==0,'all terminal generations released credit reservations')
check(Decimal(final['stats']['spent'])-before_spent==Decimal(success['actual']),'only the successful generation increased spend')
report=page('/report');check(report['providerError'] is None and all(x['matches'] is True for x in report['daily']),'daily cost and generation count reconcile exactly with provider usage')
for day in report['daily']:check(Decimal(day['charged'])-Decimal(day['provider'])==Decimal(day['profit']),'exact daily profit '+day['date'])
result={'url':base,'checked_at_utc':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'checks':checks,'generation_ids':[x['id']for x in records],'final_stats':final['stats'],'webhook_events':report['eventCount'],'daily':report['daily'],'note':'HTTP acceptance test. Browser visual checks tracked separately.'}
open(args.output,'w').write(json.dumps(result,indent=2));print('Evidence written to',args.output)
