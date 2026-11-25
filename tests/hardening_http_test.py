"""Only disposable SQLite/loopback fixtures; exercises actual authenticated forms."""
import base64, html, json, os, pathlib, re, sqlite3, subprocess, uuid
from urllib.parse import urlparse
import requests
ROOT=pathlib.Path(__file__).resolve().parent.parent
import sys
sys.path.insert(0,str(ROOT/'tests'))
from form_versions import form_version
DB=pathlib.Path(os.environ.get('BOOKTRACK_SQLITE','')).resolve()
BASE=os.environ.get('BOOKTRACK_URL','').rstrip('/')
if not DB.is_relative_to(ROOT/'tests'/'runtime') or DB.suffix!='.sqlite' or urlparse(BASE).hostname not in ('localhost','127.0.0.1'):
    raise SystemExit('Refusing to run outside a disposable loopback SQLite fixture.')
passed=failed=0
owned=set()
work=ROOT/'tests'/'runtime'/('http-hardening-'+uuid.uuid4().hex);work.mkdir()
def check(name,ok):
    global passed,failed
    if ok: passed+=1
    else: failed+=1
    print(('PASS ' if ok else 'FAIL ')+name,flush=True)
def sql(query,args=()):
    with sqlite3.connect(DB) as connection:
        connection.row_factory=sqlite3.Row
        return [dict(row) for row in connection.execute(query,args).fetchall()]
def token(session,path='/login.php'):
    found=re.search(r'name="(?:_token|csrf-token)" (?:value|content)="([^"]+)"',session.get(BASE+path,timeout=30).text)
    if not found: raise RuntimeError('No CSRF token')
    return found.group(1)
def post(session,path,data,files=None):
    data=dict(data)
    if 'version' not in data:data['version']=form_version(session,BASE,path,data)
    return session.post(BASE+path,data={**data,'_token':token(session,path)},files=files,timeout=40)
def login(name,password=None):
    session=requests.Session();r=post(session,'/login.php',{'username':name,'password':password or ('admin123' if name=='admin' else 'member123')})
    if 'login.php' in r.url: raise RuntimeError('Fixture login failed')
    return session
def media_files():
    return {path for folder in ['covers','pdfs','audio'] for path in (ROOT/'uploads'/folder).glob('*') if path.is_file()}
def api(session,**data):
    return session.post(BASE+'/api/reading.php',data={'_token':token(session,'/book.php?id=7'),'book_id':7,'medium':'ebook',**data},headers={'Accept':'application/json'},timeout=30)
try:
    pdf=ROOT/'uploads'/'pdfs'/('test-'+uuid.uuid4().hex+'.pdf');wav=work/'test.wav';owned.add(pdf)
    subprocess.check_call(['php','-r',"require $argv[1].'/tests/reading_fixtures.php';reading_test_pdf($argv[2]);reading_test_audio($argv[3]);",str(ROOT),str(pdf),str(wav)])
    sql('UPDATE books SET pdf=? WHERE id=7',[pdf.relative_to(ROOT).as_posix()])
    admin=login('admin');alice=login('alice');other=login('alice')
    initial={'ebook_total':3,'audio_total':12,'audio_title[]':['Opening'],'audio_position[]':['0'],'ebook_title[]':['Opening'],'ebook_position[]':['1']}
    with wav.open('rb') as recording:r=post(admin,'/admin/media.php?id=7',initial,{'audio_file':('test.wav',recording,'audio/wav')})
    old_audio=ROOT/sql('SELECT audio_file FROM book_media WHERE book_id=7')[0]['audio_file'];owned.add(old_audio)
    check('initial media save leaves a readable recording',old_audio.is_file() and 'Audio and chapters saved' in r.text)
    sql("CREATE TRIGGER hardening_http_log BEFORE INSERT ON activity_log BEGIN SELECT RAISE(ABORT,'test activity failure'); END")
    before=media_files()
    with wav.open('rb') as recording:r=post(admin,'/admin/media.php?id=7',initial,{'audio_file':('replacement.wav',recording,'audio/wav')})
    check('failed audio audit rolls back and preserves the previous recording',old_audio.is_file() and (ROOT/sql('SELECT audio_file FROM book_media WHERE book_id=7')[0]['audio_file'])==old_audio and 'could not be saved' in r.text)
    check('failed audio save removes only its uncommitted upload',media_files()==before)
    book=sql('SELECT * FROM books WHERE id=7')[0]
    png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z5KsAAAAASUVORK5CYII=')
    r=post(admin,'/admin/books.php',{'action':'save','id':7,'title':'Should roll back','author':book['author'],'copies':max(5,book['copies'])}, {'cover_file':('test.png',png,'image/png'),'pdf_file':('test.pdf',pdf.read_bytes(),'application/pdf')})
    check('failed book save restores its previous database values',sql('SELECT title,pdf FROM books WHERE id=7')[0]=={'title':book['title'],'pdf':book['pdf']} and 'could not be saved' in r.text)
    check('failed book save leaves no orphan cover or PDF',media_files()==before)
    sql('DROP TRIGGER hardening_http_log')
    outside=ROOT/'uploads'/('test-'+uuid.uuid4().hex+'.pdf');outside.write_bytes(b'keep me');owned.add(outside)
    relative='uploads/pdfs/../'+outside.name
    subprocess.check_call(['php','-r',"require $argv[1].'/includes/bootstrap.php';delete_upload($argv[2]);",str(ROOT),relative])
    check('upload cleanup refuses traversal outside the exact folder',outside.exists())
    state=alice.get(BASE+'/api/reading.php?book_id=7&medium=ebook').json()
    r=api(alice,action='progress',position=2,total=3)
    check('unversioned outdated readers must refresh before saving',r.status_code==409)
    payload={'action':'progress','position':2,'total':3,'revision':state['revision'],'progress_version':state['progress_version']}
    first=api(alice,**payload);second=api(other,**{**payload,'position':3})
    check('stale device progress receives a conflict and cannot overwrite',first.status_code==200 and second.status_code==409 and sql("SELECT position FROM reading_progress WHERE user_id=2 AND book_id=7 AND medium='ebook'")[0]['position']==2)
    version=form_version(admin,BASE,'/admin/books.php',{'action':'save','id':7});sql('UPDATE books SET description=? WHERE id=7',['Updated elsewhere'])
    r=post(admin,'/admin/books.php',{'action':'save','id':7,'version':version,'title':'Outdated title','author':book['author'],'copies':5})
    check('a stale librarian form cannot overwrite a newer book edit','changed in another editor' in r.text and sql('SELECT title FROM books WHERE id=7')[0]['title']==book['title'])
    stale_revision=alice.get(BASE+'/api/reading.php?book_id=7&medium=audio').json()['revision']
    with wav.open('rb') as recording:r=post(admin,'/admin/media.php?id=7',initial,{'audio_file':('replacement.wav',recording,'audio/wav')})
    new_audio=ROOT/sql('SELECT audio_file FROM book_media WHERE book_id=7')[0]['audio_file'];owned.add(new_audio)
    check('committed replacement keeps new media and removes superseded upload',new_audio.is_file() and not old_audio.exists())
    r=api(alice,action='progress',medium='audio',position=2,total=12,revision=stale_revision)
    check('old audio tabs cannot save against a replaced recording',r.status_code==409)
    r=post(admin,'/admin/media.php?id=7',{**initial,'ebook_position[]':['4']})
    check('chapter beyond verified PDF length is rejected','beyond the end' in r.text)
    r=post(admin,'/admin/media.php?id=7',{**initial,'audio_position[]':['12']})
    check('chapter at end of recording is rejected','beyond the end' in r.text)
    r=post(admin,'/admin/media.php?id=7',{**initial,'remove_audio':'1'})
    check('removing audio clears its markers and recording without manual row deletion','Audio and chapters saved' in r.text and sql('SELECT audio_file FROM book_media WHERE book_id=7')[0]['audio_file'] is None and not sql("SELECT id FROM book_chapters WHERE book_id=7 AND medium='audio'"))
    r=admin.post(BASE+'/admin/books.php',data={'oversized':'x'*(13*1024*1024)},headers={'Accept':'application/json'},timeout=30)
    check('oversized form returns 413 instead of a CSRF error',r.status_code==413 and 'limit' in r.json()['error'].lower())
    with sqlite3.connect(DB,timeout=1) as connection:
        connection.execute('BEGIN EXCLUSIVE')
        r=alice.get(BASE+'/api/reading.php?book_id=7&medium=ebook',headers={'Accept':'application/json'},timeout=15)
        connection.rollback()
    check('database failure returns a retryable 503 without sending users to setup',r.status_code==503 and r.headers.get('Retry-After')=='10' and 'setup.php' not in r.headers.get('Location','') and not r.json()['ok'])
    r=post(alice,'/profile.php',{'action':'password','current_password':'member123','new_password':'temporary-http-123','confirm_password':'temporary-http-123'})
    check('password change retains the changing session','profile.php' in r.url)
    check('password change revokes the other signed-in session','login.php' in other.get(BASE+'/loans.php').url)
    post(alice,'/profile.php',{'action':'password','current_password':'temporary-http-123','new_password':'member123','confirm_password':'member123'})
    member=sql('SELECT name,username,email,phone,is_active FROM users WHERE id=2')[0]
    post(admin,'/admin/members.php',{'action':'save','id':2,**member,'password':'admin-reset-test-password'})
    check('librarian password reset revokes the member session','login.php' in alice.get(BASE+'/loans.php').url)
    post(admin,'/admin/members.php',{'action':'save','id':2,**member,'password':'member123'})
finally:
    try:sql('DROP TRIGGER IF EXISTS hardening_http_log')
    except sqlite3.Error:pass
    for path in owned:
        if path.resolve().is_relative_to(ROOT/'uploads') and path.is_file():path.unlink()
    sql('UPDATE books SET pdf=NULL WHERE id=7');sql('DELETE FROM book_media WHERE book_id=7')
print(f'{passed} passed, {failed} failed')
raise SystemExit(1 if failed else 0)
