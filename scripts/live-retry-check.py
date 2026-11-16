"""Exercise slow completion and bounded overload retries on the deployed demo."""
import html,json,re,sys,time,uuid
from decimal import Decimal
from urllib.parse import unquote
import requests
b=sys.argv[1].rstrip('/');s=requests.Session();checks=[]
def check(x,label):
    if not x:raise AssertionError(label)
    checks.append(label);print('PASS',label,flush=True)
def post(path,data):return s.post(b+path,json=data,headers={'Accept':'application/json','X-XSRF-TOKEN':unquote(s.cookies.get('XSRF-TOKEN')or'')},allow_redirects=False,timeout=30)
s.get(b+'/login',timeout=30);check(post('/demo',{}).status_code==302,'demo session')
r=s.get(b+'/studio',timeout=30);d=json.loads(html.unescape(re.search('data-page="([^"]+)"',r.text).group(1)));version=d['version']
def page(path='/studio'):
    r=s.get(b+path,headers={'X-Inertia':'true','X-Inertia-Version':version},timeout=30);r.raise_for_status();return r.json()['props']
slow='Extended verification: a mountain lake [slow] [succeed]';busy='Extended verification: provider busy [overloaded]'
for prompt in [slow,busy]:
    r=post('/generations',{'prompt':prompt,'size':'1024x1536','quality':'high','reference_images':[],'client_request_id':str(uuid.uuid4())});check(r.status_code==302,'submitted '+('slow' if prompt==slow else 'overloaded'))
saw_inflight=False;saw_retry=False;limit=time.monotonic()+240;records={}
while time.monotonic()<limit:
    data=page();records={x['prompt']:x for x in data['history']['data'] if x['prompt'] in [slow,busy]}
    if slow in records and records[slow]['status'] in ['queued','processing']:saw_inflight=True
    if busy in records and records[busy]['status']=='queued' and records[busy]['error_message']:saw_retry=True
    if len(records)==2 and all(x['status'] in ['succeeded','failed']for x in records.values()):break
    time.sleep(5)
check(saw_inflight,'slow generation remains visibly in flight before completion')
check(saw_retry,'provider overload produces visible automatic retry state')
check(records[slow]['status']=='succeeded' and Decimal(records[slow]['actual'])>0,'slow generation completes and is charged')
check(records[busy]['status']=='failed' and Decimal(records[busy]['actual'])==0,'bounded overload retries end free and terminal')
check('six attempts' in records[busy]['error_message'],'overload explains bounded failure to user')
check(Decimal(page()['stats']['reserved'])==0,'retry cases leave no stuck credit reservation')
report=page('/report');check(report['providerError']is None and all(x['matches']is True for x in report['daily']),'extended cases reconcile with provider usage')
open('artifacts/live-retries.json','w').write(json.dumps({'url':b,'checks':checks,'generation_ids':[x['id']for x in records.values()]},indent=2))
