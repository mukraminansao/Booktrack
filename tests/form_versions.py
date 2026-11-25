"""Read the editor's real version token, just as submitting the current UI does."""
import html,json,re
def form_version(session,base,path,data):
    action=data.get('action')
    identity=data.get('id') or data.get('collection_id')
    if path.startswith('/admin/media.php'):
        text=session.get(base+path).text
    elif path.startswith('/admin/content.php') and action in ('features','collection','archive'):
        suffix=('?edit='+str(identity)) if action=='collection' and identity else ''
        text=session.get(base+'/admin/content.php'+suffix).text
        for form in re.findall(r'<form\b[^>]*>(.*?)</form>',text,re.S):
            if not re.search(r'name="action" value="'+action+'"',form):continue
            if action=='archive' and not re.search(r'name="collection_id" value="'+str(identity)+'"',form):continue
            found=re.search(r'name="version" value="([^"]+)"',form)
            if found:return html.unescape(found.group(1))
        return ''
    elif action=='save' and identity and path in ('/admin/books.php','/admin/members.php'):
        text=session.get(base+path+('?edit='+str(identity) if 'books.php' in path else '')).text
        for raw in re.findall(r'data-fill="([^"]+)"',text):
            item=json.loads(html.unescape(raw))
            if str(item.get('id'))==str(identity):return item.get('version','')
    else:return ''
    found=re.search(r'name="version" value="([^"]+)"',text)
    return html.unescape(found.group(1)) if found else ''
