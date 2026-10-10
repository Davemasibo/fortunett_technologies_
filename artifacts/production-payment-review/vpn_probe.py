import json, subprocess, time, socket
def wg(field):
    result = subprocess.run(['wg','show','all',field],capture_output=True,text=True)
    return [line.split() for line in result.stdout.splitlines()]
allowed = wg('allowed-ips')
handshakes = {(r[0],r[1]):int(r[2]) for r in wg('latest-handshakes')}
transfers = {(r[0],r[1]):[int(r[2]),int(r[3])] for r in wg('transfer')}
now=int(time.time())
for r in allowed:
    if '10.200.200.19/32' not in r[2]: continue
    stamp=handshakes.get((r[0],r[1]),0)
    print(json.dumps({'interface':r[0],'peer_vpn':r[2],'handshake_age_seconds':now-stamp if stamp else None,'transfer_bytes':transfers.get((r[0],r[1]))}))
for attempt in range(3):
    start=time.monotonic()
    try:
        with socket.create_connection(('10.200.200.19',8728),4):
            result={'tcp_connected':True}
    except OSError as exc:
        result={'tcp_connected':False,'error':str(exc)}
    result.update({'attempt':attempt+1,'duration_seconds':round(time.monotonic()-start,2)})
    print(json.dumps(result),flush=True)
print(subprocess.run(['ip','route','get','10.200.200.19'],capture_output=True,text=True).stdout)
print(subprocess.run(['ping','-c','3','-W','2','10.200.200.19'],capture_output=True,text=True).stdout)
