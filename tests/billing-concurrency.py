import pathlib, subprocess, tempfile
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as directory:
    target=str(pathlib.Path(directory)/'invoices.json')
    jobs=[subprocess.Popen(['php','tests/billing-capacity.php',target],cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(4)]
    for job in jobs:
        output,error=job.communicate(timeout=60)
        assert job.returncode==0,(output,error)
        print(output.strip())
print('PASS four concurrent generators, 1000 customers, exactly 1000 invoices')
