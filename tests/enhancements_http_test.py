"""New reading/media/content/recovery routes. Only run on an isolated SQLite server.

BOOKTRACK_SQLITE must point inside tests/runtime, and BOOKTRACK_URL must be a
loopback PHP server using that same database. Never run against the live library.
"""
import io
import json
import os
import pathlib
import re
import subprocess
import tarfile
import uuid
from urllib.parse import urlparse
import requests

ROOT = pathlib.Path(__file__).resolve().parent.parent
import sys
sys.path.insert(0,str(ROOT/'tests'))
from form_versions import form_version
DB = pathlib.Path(os.environ.get('BOOKTRACK_SQLITE', '')).resolve()
BASE = os.environ.get('BOOKTRACK_URL', '').rstrip('/')
if not DB.is_relative_to(ROOT / 'tests' / 'runtime') or DB.suffix != '.sqlite' or urlparse(BASE).hostname not in ('127.0.0.1', 'localhost'):
    raise SystemExit('Refusing to test outside an isolated loopback SQLite fixture.')
MARKER = 'HTTP reading test ' + uuid.uuid4().hex
WORK = ROOT / 'tests' / 'runtime' / ('http-' + uuid.uuid4().hex)
WORK.mkdir(parents=True)
ok = fail = 0
owned_media = []
owned_archives = set()

def php(code, *args):
    boot = "require $argv[1].'/includes/bootstrap.php';"
    return subprocess.check_output(['php', '-r', boot + code, str(ROOT), *args], text=True)

def sql(query, values=()):
    return json.loads(php('echo json_encode(q_all($argv[2],json_decode($argv[3],true)));', query, json.dumps(values)))

def check(condition, message):
    global ok, fail
    if condition: ok += 1
    else: fail += 1
    print(('PASS ' if condition else 'FAIL ') + message)

def token(session, path='/login.php'):
    text = session.get(BASE + path, timeout=30).text
    found = re.search(r'name="(?:_token|csrf-token)" (?:value|content)="([^"]+)"', text)
    if not found: raise RuntimeError('CSRF token missing at ' + path)
    return found.group(1)

def post(session, path, data, files=None, page=None):
    data=dict(data)
    if 'version' not in data:data['version']=form_version(session,BASE,path,data)
    return session.post(BASE + path, data={**data, '_token': token(session, page or path)}, files=files, timeout=120)

def login(name):
    session = requests.Session()
    result = post(session, '/login.php', {'username': name, 'password': 'admin123' if name == 'admin' else 'member123'})
    if 'login.php' in result.url: raise RuntimeError('Fixture login failed: ' + name)
    return session

def api(session, action, **data):
    state=session.get(BASE+'/api/reading.php?book_id=7&medium='+data.get('medium','ebook')).json()
    return post(session, '/api/reading.php', {'book_id': 7, 'medium': 'ebook', 'revision':state['revision'],'progress_version':state['progress_version'], 'action': action, **data}, page='/book.php?id=7')

def backups_in(text):
    return set(re.findall(r'backup-\d{8}-\d{6}-[a-f0-9]{12}\.tar', text))

try:
    # This marker also proves any archives cleaned up below belong to this run.
    sql('UPDATE books SET cover=NULL,pdf=NULL')
    sql('DELETE FROM collections')
    for table in ('book_media', 'book_chapters', 'reading_progress', 'reading_bookmarks'):
        sql(f'DELETE FROM {table} WHERE book_id=7')
    sql('UPDATE books SET title=? WHERE id=7', [MARKER])
    pdf = ROOT / 'uploads' / 'pdfs' / ('test-' + uuid.uuid4().hex + '.pdf')
    wav = WORK / 'test.wav'
    owned_media.append(pdf)
    php("require APP_ROOT.'/tests/reading_fixtures.php';reading_test_pdf($argv[2]);reading_test_audio($argv[3]);", str(pdf), str(wav))
    sql('UPDATE books SET pdf=? WHERE id=7', [pdf.relative_to(ROOT).as_posix()])
    admin, alice, brian = login('admin'), login('alice'), login('brian')
    guest = requests.Session()
    check('login.php' in guest.get(BASE + '/reader.php?id=7').url, 'guest cannot open reader')
    check('login.php' in guest.get(BASE + '/audio.php?id=7').url, 'guest cannot stream audio')
    check('admin/content.php' not in alice.get(BASE + '/admin/content.php').url, 'members cannot curate content')
    check('admin/backups.php' not in alice.get(BASE + '/admin/backups.php').url, 'members cannot access backups')
    r = alice.post(BASE + '/api/reading.php', data={'book_id': 7, 'medium': 'ebook', 'action': 'progress', 'position': 2, 'total': 3}, headers={'Accept': 'application/json'})
    check(r.status_code == 419, 'progress requires CSRF')
    check(api(alice, 'progress', position=2, total=3).json()['progress']['position'] == 2, 'reader position saved over HTTP')
    check(brian.get(BASE + '/api/reading.php?book_id=7&medium=ebook').json()['progress'] is None, 'progress is private to account')
    check(api(alice, 'progress', position='NaN', total=3).status_code == 422, 'malformed progress rejected')
    check(api(alice, 'progress', position=2, total=0).status_code == 422, 'invalid page count rejected')
    r = api(alice, 'bookmark', position=2, label='<script>test</script>')
    bookmark = r.json()['bookmarks'][0]['id']
    check(len(r.json()['bookmarks']) == 1, 'bookmark saved')
    check(not brian.get(BASE + '/api/reading.php?book_id=7&medium=ebook').json()['bookmarks'], 'bookmarks are private to account')
    api(brian, 'remove_bookmark', bookmark_id=bookmark)
    check(len(alice.get(BASE + '/api/reading.php?book_id=7&medium=ebook').json()['bookmarks']) == 1, 'another member cannot remove a bookmark')
    check('data-reader' in alice.get(BASE + '/reader.php?id=7').text, 'reader shell served')
    r = alice.get(BASE + '/read.php?id=7')
    check(r.headers.get('Content-Type', '').startswith('application/pdf') and r.content.startswith(b'%PDF-'), 'original PDF endpoint remains compatible')
    pdf_etag = r.headers.get('ETag')
    r = alice.get(BASE + '/read.php?id=7', headers={'Range': 'bytes=0-15'})
    check(r.status_code == 206 and r.content.startswith(b'%PDF-') and len(r.content) == 16, 'PDF supports partial downloads')
    check(alice.head(BASE + '/read.php?id=7').status_code == 200, 'PDF metadata request succeeds')
    head = alice.head(BASE + '/read.php?id=7', headers={'Range': 'bytes=0-15'})
    check(head.status_code == 200 and int(head.headers['Content-Length']) > 16, 'HEAD ignores a byte-range request')
    check(alice.get(BASE + '/read.php?id=7', headers={'If-None-Match': pdf_etag}).status_code == 304, 'unchanged private media revalidates')
    r = alice.get(BASE + '/read.php?id=7', headers={'Range': 'bytes=0-15', 'If-Range': '"old-recording"'})
    check(r.status_code == 200 and len(r.content) > 16, 'stale range validator returns complete document')
    sibling = ROOT / 'uploads' / ('pdfs-sibling-' + uuid.uuid4().hex + '.pdf')
    sibling.write_bytes(pdf.read_bytes());owned_media.append(sibling)
    sql('UPDATE books SET pdf=? WHERE id=7', [sibling.relative_to(ROOT).as_posix()])
    check(alice.get(BASE + '/read.php?id=7').status_code == 404, 'PDF prefix lookalike cannot escape the private folder')
    sql('UPDATE books SET pdf=? WHERE id=7', [pdf.relative_to(ROOT).as_posix()]);sibling.unlink()
    sql('UPDATE books SET pdf=? WHERE id=7', ['uploads/pdfs/unavailable-test.pdf'])
    check(api(alice, 'progress', position=2, total=3).status_code == 422, 'missing PDF cannot receive new progress')
    check(api(alice, 'bookmark', position=1, label='Missing').status_code == 422, 'missing PDF cannot receive bookmarks')
    check('>Continue reading' not in alice.get(BASE + '/discover.php').text, 'home skips unavailable resume files')
    sql('UPDATE books SET pdf=? WHERE id=7', [pdf.relative_to(ROOT).as_posix()])
    r = post(admin, '/admin/media.php?id=7', {'narrator': 'HTTP narrator'}, {'audio_file': ('fake.mp3', b'not audio', 'audio/mpeg')})
    check('Audio and chapters saved' not in r.text and not sql('SELECT audio_file FROM book_media WHERE book_id=7'), 'fake recording upload rejected')
    with wav.open('rb') as recording:
        r = post(admin, '/admin/media.php?id=7', {'narrator': 'HTTP narrator', 'ebook_total': 3, 'audio_total': 12, 'ebook_title[]': ['Opening', 'Second page'], 'ebook_position[]': ['1', '2'], 'audio_title[]': ['Opening', 'Second part'], 'audio_position[]': ['0', '0:06']}, {'audio_file': ('test.wav', recording, 'audio/wav')})
    media = sql('SELECT audio_file FROM book_media WHERE book_id=7')
    if media and media[0]['audio_file']: owned_media.append(ROOT / media[0]['audio_file'])
    check('Audio and chapters saved' in r.text and bool(media), 'valid recording and chapters uploaded')
    check(len(sql('SELECT id FROM book_chapters WHERE book_id=7')) == 4, 'both chapter formats persisted')
    php("require APP_ROOT.'/tests/reading_fixtures.php';reading_test_pdf($argv[2],200);", str(pdf))
    maximum = {'ebook_total': 200, 'audio_total': 12, 'narrator': 'HTTP narrator', 'ebook_title[]': [f'Chapter {i}' for i in range(1, 201)], 'ebook_position[]': list(range(1, 201)), 'audio_title[]': ['Opening', 'Second part'], 'audio_position[]': ['0', '0:06']}
    r = post(admin, '/admin/media.php?id=7', maximum)
    check('Audio and chapters saved' in r.text and len(sql("SELECT id FROM book_chapters WHERE book_id=7 AND medium='ebook'")) == 200, 'maximum-size chapter list saves successfully')
    chapter_form = re.search(r'data-chapter-group="ebook"(.*?)</section>', r.text, re.S).group(1)
    check(chapter_form.count('name="ebook_title[]"') == 200 and re.search(r'data-add-chapter\s+disabled', chapter_form), 'full editor does not add an unsaveable 201st row')
    php("require APP_ROOT.'/tests/reading_fixtures.php';reading_test_pdf($argv[2]);", str(pdf))
    post(admin, '/admin/media.php?id=7', {'ebook_total': 3, 'audio_total': 12, 'ebook_title[]': ['Opening'], 'ebook_position[]': ['1'], 'audio_title[]': ['Opening'], 'audio_position[]': ['0']})
    r = post(admin, '/admin/media.php?id=7', {'ebook_title[0][]': 'Nested', 'ebook_position[]': 1})
    check('Invalid chapter data' in r.text and 'Array to string conversion' not in r.text, 'malformed chapter drafts show validation without PHP warnings')
    audio_file = media[0]['audio_file']
    sql('UPDATE book_media SET audio_file=? WHERE book_id=7', ['uploads/audio/unavailable-test.wav'])
    check(api(alice, 'progress', medium='audio', position=1, total=12).status_code == 422, 'missing recording cannot receive new progress')
    sql('UPDATE book_media SET audio_file=? WHERE book_id=7', [audio_file])
    r = alice.get(BASE + '/audio.php?id=7', headers={'Range': 'bytes=0-31'})
    check(r.status_code == 206 and len(r.content) == 32 and r.content.startswith(b'RIFF'), 'audio byte ranges support seeking')
    r = alice.get(BASE + '/audio.php?id=7', headers={'Range': 'bytes=-16'})
    check(r.status_code == 206 and len(r.content) == 16, 'audio suffix range supported')
    check(alice.get(BASE + '/audio.php?id=7', headers={'Range': 'bytes=999999999-'}).status_code == 416, 'invalid audio range refused')
    check('data-audiobook' in alice.get(BASE + '/listen.php?id=7').text, 'listening page served')
    check(api(alice, 'progress', medium='audio', position=6, total=12, rate=1.5).json()['progress']['rate'] == 1.5, 'listening position and speed saved')
    before = sql('SELECT title FROM book_chapters WHERE book_id=7 ORDER BY id')
    r = post(admin, '/admin/media.php?id=7', {'audio_title[]': ['Duplicate', 'Duplicate'], 'audio_position[]': ['1', '1']})
    check('Audio and chapters saved' not in r.text and sql('SELECT title FROM book_chapters WHERE book_id=7 ORDER BY id') == before, 'invalid chapter edit leaves previous chapters intact')
    post(admin, '/admin/content.php', {'action': 'features', 'featured_book': 7, 'featured_audio': 7})
    check(sql("SELECT setting_value FROM app_settings WHERE setting_key='featured_book'")[0]['setting_value'] == '7', 'featured book selection saved')
    r = post(admin, '/admin/content.php', {'action': 'collection', 'title': MARKER, 'subtitle': 'Curated shelf', 'sort_order': 0, 'is_active': 1, 'books[]': [7, 14]})
    collection = sql('SELECT id FROM collections WHERE title=?', [MARKER])[0]['id']
    check('Collection saved' in r.text and len(sql('SELECT book_id FROM collection_books WHERE collection_id=?', [collection])) == 2, 'collection membership saved')
    r = post(admin, '/admin/content.php', {'action': 'collection', 'collection_id': collection, 'title': MARKER + ' draft', 'subtitle': 'Keep this draft', 'sort_order': 0, 'books[]': [7, 14], 'book_positions[7]': 'invalid'})
    check('Keep this draft' in r.text and MARKER + ' draft' in r.text and 'whole numbers' in r.text, 'invalid collection edit preserves entered values')
    check(not re.search(r'name="is_active"[^>]*checked', r.text), 'unpublished draft stays unpublished after validation error')
    post(admin, '/admin/content.php', {'action': 'collection', 'collection_id': collection, 'title': MARKER, 'sort_order': 0, 'is_active': 1, 'books[]': [7, 14], 'book_positions[7]': 20, 'book_positions[14]': 10})
    check([row['book_id'] for row in sql('SELECT book_id FROM collection_books WHERE collection_id=? ORDER BY position', [collection])] == [14, 7], 'collection follows explicit book order')
    edit = admin.get(BASE + f'/admin/content.php?edit={collection}').text
    check(re.findall(r'type="checkbox" name="books\[\]" value="(\d+)"', edit)[:2] == ['14', '7'], 'collection edit retains saved order in the form')
    check(MARKER in alice.get(BASE + f'/collection.php?id={collection}').text, 'published collection visible')
    post(admin, '/admin/content.php', {'action': 'archive', 'collection_id': collection})
    check(alice.get(BASE + f'/collection.php?id={collection}', allow_redirects=False).status_code in (302, 303, 404), 'hidden collection no longer exposed')
    post(admin, '/admin/content.php', {'action': 'collection', 'collection_id': collection, 'title': MARKER, 'sort_order': 0, 'is_active': 1, 'books[]': [7, 14]})
    check(MARKER in alice.get(BASE + '/discover.php').text, 'curated shelf appears on dashboard')
    # Small self-contained archive for HTTP review/restore. No real uploads enter it.
    archive = json.loads(php("require APP_ROOT.'/includes/backups.php';$dir=$argv[2];mkdir($dir.'/uploads/pdfs',0700,true);mkdir($dir.'/uploads/audio',0700,true);copy(APP_ROOT.'/'.$argv[3],$dir.'/'. $argv[3]);$audio=media_for(7)['audio_file'];copy(APP_ROOT.'/'.$audio,$dir.'/'.$audio);$GLOBALS['__config']['backups']=['directory'=>$dir.'/backups','upload_root'=>$dir.'/uploads'];echo json_encode(backup_create());", str(WORK), pdf.relative_to(ROOT).as_posix()))
    before_backups = backups_in(admin.get(BASE + '/admin/backups.php').text)
    r = post(admin, '/admin/backups.php', {'action': 'review'}, {'backup_file': ('bad.tar', b'bad archive', 'application/x-tar')})
    check('Choose a valid BookTrack TAR backup' in r.text, 'invalid backup upload rejected')
    with open(archive, 'rb') as source:
        r = post(admin, '/admin/backups.php', {'action': 'review'}, {'backup_file': ('library.tar', source, 'application/x-tar')})
    check('Restore review' in r.text and 'Restore this backup' in r.text, 'backup reviewed before restoration')
    r = post(admin, '/admin/backups.php', {'action': 'cancel_review'})
    check('Restore review cancelled' in r.text and 'Restore this backup' not in r.text, 'backup review can be cancelled without changing records')
    with open(archive, 'rb') as source:
        r = post(admin, '/admin/backups.php', {'action': 'review'}, {'backup_file': ('library.tar', source, 'application/x-tar')})
    r = post(admin, '/admin/backups.php', {'action': 'restore', 'confirmation': 'wrong', 'password': 'admin123'})
    check('Type RESTORE to confirm' in r.text, 'restore requires explicit typed confirmation')
    r = post(admin, '/admin/backups.php', {'action': 'restore', 'confirmation': 'RESTORE', 'password': 'wrong'})
    check('password was not correct' in r.text, 'restore checks current librarian password')
    sql('UPDATE books SET title=? WHERE id=7', [MARKER + ' changed'])
    r = post(admin, '/admin/backups.php', {'action': 'restore', 'confirmation': 'RESTORE', 'password': 'admin123'})
    check('restored=1' in r.url and sql('SELECT title FROM books WHERE id=7')[0]['title'] == MARKER, 'confirmed restore replaces only isolated fixture records')
    check('login.php' in alice.get(BASE + '/reader.php?id=7').url, 'restore invalidates existing sessions')
    admin = login('admin')
    after_backups = backups_in(admin.get(BASE + '/admin/backups.php').text)
    owned_archives = after_backups - before_backups
    check(len(owned_archives) == 1, 'restore creates one safety backup')
    if owned_archives:
        name = next(iter(owned_archives))
        r = admin.get(BASE + '/admin/backups.php?download=' + name, stream=True)
        check(r.status_code == 200 and r.headers.get('Content-Type', '').startswith('application/x-tar'), 'librarian can download safety backup')
        r.close()
    check(admin.get(BASE + '/admin/backups.php?download=../../config.php').status_code == 404, 'backup download refuses traversal')
    sql('DELETE FROM loans WHERE book_id=7')
    post(admin, '/admin/books.php', {'action': 'delete', 'id': 7})
    check(not sql('SELECT id FROM books WHERE id=7') and not owned_media[-1].exists(), 'book deletion removes its uploaded recording')
finally:
    for path in owned_media:
        resolved = path.resolve()
        if resolved.is_relative_to(ROOT / 'uploads') and resolved.is_file(): resolved.unlink()
    for name in owned_archives:
        path = ROOT / 'storage' / 'backups' / name
        with tarfile.open(path) as archive:
            data = json.load(archive.extractfile('database.json'))
        if any(row['title'].startswith(MARKER) for row in data['books']['rows']): path.unlink()
print(f'{ok} passed, {fail} failed')
raise SystemExit(1 if fail else 0)
