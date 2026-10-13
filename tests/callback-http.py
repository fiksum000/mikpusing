import hashlib, hmac, json, os, pathlib, subprocess, tempfile, time, urllib.request, urllib.error
root = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as directory:
    state = pathlib.Path(directory)
    invoice = dict(invoice_no='CI-1', amount=25000, tripay_reference='REF-1', status='pending', customer_phone='628000000000')
    path = state/'invoices.json'
    path.write_text(json.dumps([invoice]))
    env = dict(os.environ, MIKPUSING_CALLBACK_TEST='1', MIKPUSING_CALLBACK_STATE=directory, MIKHMON_BILLING_FILE=str(path))
    with (state/'server.log').open('w') as log:
        server = subprocess.Popen(['php', '-S', '127.0.0.1:18991', 'tests/callback-router.php'], cwd=root, env=env, stdout=log, stderr=log)
        try:
            import socket
            for attempt in range(50):
                try:
                    with socket.create_connection(('127.0.0.1',18991),timeout=.2): break
                except OSError: time.sleep(.1)
            else: raise RuntimeError('PHP fixture failed to start')
            def callback(expected, changes=None, signature=True):
                payload = dict(merchant_ref='CI-1', reference='REF-1', status='PAID', total_amount=25000)
                payload.update(changes or {})
                body=json.dumps(payload).encode()
                sig=hmac.new(b'ci-private-key',body,hashlib.sha256).hexdigest() if signature else 'invalid'
                request=urllib.request.Request('http://127.0.0.1:18991/',data=body,headers={'X-Callback-Signature':sig,'X-Callback-Event':'payment_status'})
                try:
                    response=urllib.request.urlopen(request)
                    code=response.status
                except urllib.error.HTTPError as error: code=error.code
                assert code == expected, (payload,code,expected)
            callback(403,signature=False)
            callback(403,dict(reference='WRONG'))
            callback(400,dict(total_amount=24999))
            callback(400,dict(status='UNKNOWN'))
            assert json.loads(path.read_text())[0]['status']=='pending'
            (state/'fail-wa').touch()
            callback(503)
            assert json.loads(path.read_text())[0]['status']=='paid'
            (state/'fail-wa').unlink()
            callback(200)
            callback(200)
            assert (state/'wa-sent').read_text().splitlines()==['sent']
            callback(200,dict(status='EXPIRED'))
            assert json.loads(path.read_text())[0]['status']=='paid'
            callback(200,dict(status='REFUND'))
            callback(409)
            assert json.loads(path.read_text())[0]['status']=='refunded'
            print('PASS callback signature/reference/amount/status, WA retry, duplicate PAID and refund ordering')
        finally:
            server.terminate()
            server.wait(timeout=10)
