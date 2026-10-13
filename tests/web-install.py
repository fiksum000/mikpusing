import http.cookiejar, pathlib, subprocess, urllib.request, urllib.parse
root=pathlib.Path(__file__).resolve().parents[1]
# This test reads the disposable installation's password without logging it.
credentials=subprocess.check_output(["php","-r", "require 'include/config.php'; require 'lib/decrypt_helper.php'; echo explode('<|<',$data['mikhmon'][1])[1].PHP_EOL.decrypt(explode('>|>',$data['mikhmon'][2])[1]);"],cwd=root,text=True).splitlines()
jar=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
def page(route,data=None):
    request=urllib.request.Request('http://127.0.0.1/admin.php?id='+route,data=urllib.parse.urlencode(data).encode() if data else None)
    return client.open(request).read().decode()
page('login',{'login':'1','user':credentials[0],'pass':credentials[1]})
outside=page('sessions')
nav=outside.split('id="sidenav"',1)[1].split('</aside>',1)[0]
assert 'js/mikhmon.js' not in outside and 'Quick Print' not in outside
assert 'id=radius-nas' in nav and 'id=billing' not in nav and '> Open' in outside
for route in ('radius-dashboard','radius-accounting','radius-users','bulk-import','billing','tripay-settings','fonnte','wa-templates','fonnte-log','pppoe-packages','billing-integrations'):
    html=page(route)
    assert 'id="main"' in html and 'id=radius-dashboard' in html and 'id=tripay-settings' in html, route
    assert 'Data belum tersedia' not in html, route
config=root/'include/config.php'
before=config.read_bytes()
response=page('sessions',dict(save='1',useradm='intruder',passadm='invalid-update-password',qrbt='disable',csrf='wrong'))
assert config.read_bytes()==before and 'Token keamanan' in response
print('PASS login, outer/inner navigation, operational pages and admin CSRF')
