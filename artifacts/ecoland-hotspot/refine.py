from pathlib import Path
p=Path('api/mikrotik/coexistence_check.php');s=p.read_text(encoding='utf-8');a=s.index('        $hasIp   =');b=s.index('\n    } else {',a)
s=s[:a]+'''        $ts = $pdo->prepare('SELECT subdomain FROM tenants WHERE id=?'); $ts->execute([$tenantId]);
        $domain = 'fortunetttech.site';
        try { $domain = $pdo->query("SELECT setting_value FROM platform_settings WHERE setting_key='platform_domain'")->fetchColumn() ?: $domain; } catch (Throwable $e) {}
        $portalHost = $ts->fetchColumn() . '.' . $domain;
        $snapshot = hotspotDiagnosticSnapshot(['garden'=>$wgHostRows, 'garden_ip'=>$wgIpRows], $portalHost, gethostbynamel($portalHost) ?: []);
        foreach ($snapshot['checks'] as $portalCheck) if ($portalCheck['id']==='portal') {
            $check('walled_garden', 'Portal HTTP + HTTPS allow rules', $portalCheck['level'], $portalCheck['detail'] . ($portalCheck['level']==='fail' ? ' Use Repair portal access in the main hotspot checks.' : ''));
        }
''' +s[b:];p.write_text(s,encoding='utf-8')
p=Path('hotspot_diagnostics.php');s=p.read_text(encoding='utf-8');s=s.replace("AND connection_type='hotspot'", "AND COALESCE(NULLIF(connection_type,''),'hotspot')='hotspot'")
import re
s=re.sub(r'(<div class="hd-auto">\s*<h3><i class="fas fa-bolt[^<]*</i>)[^<]*(</h3>)',r'\1Payment &amp; access records\2',s)
s=s.replace("d.error || 'The API port is not reachable from the server.'", "d.error || d.message || 'The API port is not reachable from the server.'")
s=s.replace("const verdict = document.getElementById('auto-verdict');\n    const groups", "const verdict = document.getElementById('auto-verdict');\n    if (!verdict) return;\n    const groups")
s=s.replace("body.innerHTML = (d.checks || []).map(c =>", "if ((d.checks || []).some(c => c.level === 'fail')) body.closest('details').open = true;\n            body.innerHTML = (d.checks || []).map(c =>")
s=s.replace("checks.innerHTML = '';", "checks.innerHTML = '';\n    document.getElementById('portal-repair-' + routerId).hidden = true;",1)
p.write_text(s,encoding='utf-8')
