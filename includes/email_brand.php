<?php
function fortunettEmailEscape($value): string {
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function fortunettEmailButton(string $label,string $url): string {
    if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower(parse_url($url,PHP_URL_SCHEME) ?? ''),['https','http'],true)) throw new InvalidArgumentException('Invalid email action URL');
    $label=fortunettEmailEscape(html_entity_decode($label,ENT_QUOTES,'UTF-8')); $url=fortunettEmailEscape($url);
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;"><tr><td bgcolor="#2563eb" style="border-radius:8px;mso-padding-alt:16px 28px;"><a href="'.$url.'" style="background:#2563eb;border:1px solid #2563eb;border-radius:8px;color:#ffffff;display:inline-block;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;line-height:22px;padding:16px 28px;text-align:center;text-decoration:none;">'.$label.'</a></td></tr></table>';
}
function fortunettEmailSummary(array $rows): string {
    $html='<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f6fb;border:1px solid #e2e8f0;border-radius:10px;margin:20px 0;">';
    foreach ($rows as $label=>$value) $html.='<tr><td style="padding:12px 16px;border-bottom:1px solid #e2e8f0;font-size:13px;color:#64748b;vertical-align:top;width:42%;">'.fortunettEmailEscape($label).'</td><td style="padding:12px 16px;border-bottom:1px solid #e2e8f0;font-size:14px;color:#14243b;font-weight:700;word-break:break-word;">'.fortunettEmailEscape($value).'</td></tr>';
    return $html.'</table>';
}
/** Content is app-authored HTML; all variable text must be escaped by the caller. */
function fortunettEmail(string $title,string $content,array $options=[]): string {
    $title=fortunettEmailEscape($title);
    $preheader=fortunettEmailEscape($options['preheader'] ?? mb_substr(html_entity_decode(strip_tags($content),ENT_QUOTES,'UTF-8'),0,160));
    $category=fortunettEmailEscape($options['category'] ?? 'Account update');
    $sender=fortunettEmailEscape($options['sender'] ?? 'FortuNett Technologies');
    $support=$options['support_email'] ?? 'support@fortunetttech.site';
    $supportLink=filter_var($support,FILTER_VALIDATE_EMAIL) ? '<a href="mailto:'.fortunettEmailEscape($support).'" style="color:#2563eb;text-decoration:underline;">'.fortunettEmailEscape($support).'</a>' : 'Contact your service provider through your account.';
    $action='';
    if (!empty($options['action_url'])) {
        $action=fortunettEmailButton($options['action_label'] ?? 'Open your account',$options['action_url']);
        $url=fortunettEmailEscape($options['action_url']);
        $action.='<p style="font-size:12px;line-height:19px;color:#64748b;margin:0 0 24px;">Button not working? Open this link:<br><a href="'.$url.'" style="color:#2563eb;word-break:break-all;">'.$url.'</a></p>';
    }
    if (str_contains($content,'<!-- email-action -->')) {
        $content=str_replace('<!-- email-action -->',$action,$content);
        $action='';
    }
    return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$title}</title>
<style>@media only screen and (max-width:620px){.email-outer{padding:12px!important}.email-content{padding:24px!important}.email-card{width:100%!important}} p{margin:0 0 16px} a{color:#2563eb} li{margin-bottom:10px}</style></head>
<body data-fortunett-email="v1" style="margin:0;padding:0;background:#eef2f7;color:#334155;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;font-size:1px;line-height:1px;color:#eef2f7;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{$preheader}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef2f7"><tr><td class="email-outer" align="center" style="padding:36px 16px;">
<table class="email-card" role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dce4ef;border-radius:16px;">
<tr><td bgcolor="#14243b" style="padding:28px 32px;border-radius:16px 16px 0 0;border-bottom:4px solid #60a5fa;">
<span style="font-size:25px;line-height:32px;font-weight:800;letter-spacing:-1px;color:#ffffff;">Fortu<span style="color:#60a5fa;">Nett</span></span><br><span style="color:#a8bdd8;font-size:10px;letter-spacing:3px;line-height:22px;">TECHNOLOGIES</span></td></tr>
<tr><td class="email-content" style="padding:32px;font-size:15px;line-height:25px;">
<p style="margin:0 0 12px;font-size:11px;line-height:18px;letter-spacing:1.6px;text-transform:uppercase;font-weight:700;color:#2563eb;">{$category}</p>
<h1 style="margin:0 0 24px;color:#14243b;font-size:28px;line-height:35px;font-weight:700;">{$title}</h1>
{$content}{$action}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid #e2e8f0;padding-top:20px;font-size:13px;line-height:21px;color:#64748b;">Need help? {$supportLink}</td></tr></table>
</td></tr><tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 32px;border-radius:0 0 16px 16px;font-size:11px;line-height:19px;color:#64748b;">Sent by {$sender}<br>Powered by FortuNett Technologies &middot; Network management made simple</td></tr>
</table></td></tr></table></body></html>
HTML;
}
function fortunettEmailEnsureBrand(string $subject,string $body,array $options=[]): string {
    if (str_contains($body,'data-fortunett-email="v1"')) return $body;
    $styles='';
    if (preg_match_all('~<style\b[^>]*>.*?</style>~is',$body,$matches)) $styles=implode('',$matches[0]);
    if (preg_match('~<body\b[^>]*>(.*?)</body>~is',$body,$match)) $body=$match[1];
    if (!preg_match('~<[a-z][^>]*>~i',$body)) $body='<p>'.nl2br(fortunettEmailEscape($body)).'</p>';
    return fortunettEmail($subject,$styles.$body,$options);
}
function fortunettEmailPlainText(string $html): string {
    $html=preg_replace('~<(style|script)\b[^>]*>.*?</\1>~is','',$html);
    $html=preg_replace_callback('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is',fn($m)=>strip_tags($m[2]).' ('.html_entity_decode($m[1],ENT_QUOTES,'UTF-8').')',$html);
    $html=preg_replace('~</(p|h[1-6]|tr|div|li|table)>|<br\s*/?>~i',"\n",$html);
    $html=preg_replace('~</td>~i','  ',$html);
    return trim(preg_replace('/\n[ \t]*\n(?:[ \t]*\n)+/',"\n\n",html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8')));
}
