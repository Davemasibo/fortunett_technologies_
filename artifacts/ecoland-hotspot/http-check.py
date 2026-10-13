import urllib.request
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,req,fp,code,msg,headers,newurl): return None
opener=urllib.request.build_opener(NoRedirect)
for path in ['hotspot_diagnostics.php','assets/diagnostics.css?v=20261013','diag_router.php']:
 try:
  with opener.open('https://ecolandattic.fortunetttech.site/'+path,timeout=15) as r: print(path,r.status)
 except urllib.error.HTTPError as e: print(path,e.code,e.headers.get('Location',''))
