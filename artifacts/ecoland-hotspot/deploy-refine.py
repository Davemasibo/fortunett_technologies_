from pathlib import Path
p=Path('artifacts/ecoland-hotspot/deploy.sh');s=p.read_text();s=s.replace('assets/diagnostics.css)', 'assets/diagnostics.css super_admin/diagnostics.php diag_router.php)');p.write_bytes(s.strip().replace('\r\n','\n').encode()+b'\n')
