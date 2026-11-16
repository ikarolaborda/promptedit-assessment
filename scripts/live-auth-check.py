"""Creates a disposable example.test account; verifies deployed authentication boundaries."""
import json,html,re,secrets,sys,uuid
from urllib.parse import unquote
import requests
base=sys.argv[1].rstrip('/');s=requests.Session();checks=[]
def check(condition,label):
    if not condition:raise AssertionError(label)
    checks.append(label);print('PASS',label,flush=True)
def post(path,data):return s.post(base+path,json=data,headers={'Accept':'application/json','X-XSRF-TOKEN':unquote(s.cookies.get('XSRF-TOKEN')or'')},allow_redirects=False,timeout=25)
s.get(base+'/login',timeout=25)
email='acceptance-'+uuid.uuid4().hex[:10]+'@example.test';password=secrets.token_urlsafe(24)
check(post('/register',{'name':'Acceptance reviewer','email':email,'password':password,'password_confirmation':'mismatch'}).status_code==422,'registration validates password confirmation')
check(post('/register',{'name':'Acceptance reviewer','email':email,'password':password,'password_confirmation':password,'is_admin':True}).status_code==302,'registration creates a signed-in session')
r=s.get(base+'/studio',timeout=25);data=json.loads(html.unescape(re.search('data-page="([^"]+)"',r.text).group(1)))['props']
check(data['history']['data']==[] and data['stats']['total']==0,'registered account cannot see shared demo history')
check(data['auth']['user']['is_admin'] is False,'registration ignores injected admin privilege')
check(s.get(base+'/report',timeout=25).status_code==403,'non-admin report access is forbidden')
check(post('/logout',{}).status_code==302,'logout succeeds')
check(s.get(base+'/studio',allow_redirects=False,timeout=25).status_code==302,'logout invalidates workspace access')
s.get(base+'/login',timeout=25)
check(post('/login',{'email':email,'password':'incorrect-password'}).status_code==422,'incorrect password is rejected')
check(post('/login',{'email':email,'password':password}).status_code==302,'registered user can sign back in')
post('/logout',{})
open('artifacts/live-auth.json','w').write(json.dumps({'url':base,'checks':checks,'note':'Disposable example.test account; no provider generations.'},indent=2))
