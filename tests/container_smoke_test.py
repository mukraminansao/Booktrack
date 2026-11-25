"""HTTP acceptance for an owned, disposable Render-compatible demo container."""
import http.cookiejar,json,os,re,urllib.error,urllib.parse,urllib.request
BASE=os.environ.get('BOOKTRACK_CONTAINER_URL','http://127.0.0.1:8087').rstrip('/')
if urllib.parse.urlparse(BASE).hostname not in ('localhost','127.0.0.1'):raise SystemExit('Container tests require a disposable loopback target.')
passed=failed=0
def check(name,ok):
 global passed,failed
 if ok:passed+=1
 else:failed+=1
 print(('PASS ' if ok else 'FAIL ')+name,flush=True)
def get(path,method='GET',headers=None):
 try:return urllib.request.urlopen(urllib.request.Request(BASE+path,method=method,headers=headers or {}),timeout=30)
 except urllib.error.HTTPError as response:return response
health=get('/health.php');check('health reports a ready database',health.status==200 and json.load(health)=={'status':'ok'});check('health creates no session cookie',health.headers.get('Set-Cookie') is None);check('health is not cached',health.headers.get('Cache-Control')=='no-store')
head=get('/health.php','HEAD');check('health HEAD returns metadata without a body',head.status==200 and head.read()==b'');check('health rejects writes',get('/health.php','POST').status==405)
for path in ['/config.local.php','/database/booktrack.sql','/storage/','/includes/bootstrap.php','/tools/readiness.php','/tests/make_sqlite.php']:
 response=get(path);check('private path blocked: '+path,response.status in (403,404))
page=get('/login.php',headers={'X-Forwarded-Proto':'https'});check('trusted proxy HTTPS sets Secure session cookies','secure' in page.headers.get('Set-Cookie','').lower())
jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar));page=client.open(BASE+'/login.php',timeout=30).read().decode();token=re.search(r'name="_token" value="([^"]+)"',page).group(1)
check('public demo buttons remain available','Sign in as member' in page and 'Sign in as librarian' in page)
body=client.open(BASE+'/login.php',urllib.parse.urlencode({'_token':token,'username':'alice','password':'member123'}).encode(),timeout=30).read().decode()
check('member reaches warm dashboard','reading-home' in body and 'Happy reading' in body);check('dashboard retains reading and listening sections','Popular Now' in body and 'Audio Book' in body and 'Chapters' in body)
check('dashboard renders without PHP warnings','Fatal error' not in body and 'Warning:' not in body)
for path in ['/assets/css/reading-home.css','/assets/js/reading-state.js','/assets/vendor/pdfjs/build/pdf.mjs']:
 response=get(path);check('release asset loads: '+path,response.status==200 and len(response.read())>100)
print(f'{passed} passed, {failed} failed');raise SystemExit(1 if failed else 0)
