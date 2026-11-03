<?php
/**
 * Authorization login page (Apple HIG look: grouped inset form, systemBlue, light / dark).
 * Captcha refreshes via fetch(/oauth/captcha) without reloading the page (script-src + connect-src
 * allowed in CSP). Opt-in "trust this browser" writes an HttpOnly remember cookie (TTL configurable).
 *
 * @var array<string, mixed> $v
 * @var Closure(mixed): string $e   HTML escape
 * @var Closure(string, array<string, mixed>=): string $t   translate (request locale)
 */
$theme = in_array($v['theme'], ['light', 'dark'], true) ? $v['theme'] : '';
$remembered = is_array($v['remembered'] ?? null) ? $v['remembered'] : null;
$trustAsk = is_array($v['trust_ask'] ?? null) ? $v['trust_ask'] : null;
?>
<!doctype html>
<html lang="<?= $e($v['locale']) ?>"<?= $theme !== '' ? ' class="' . $e($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="<?= $theme !== '' ? $e($theme) : 'light dark' ?>">
<title><?= $e($t('登录')) ?> · <?= $e($t('统一用户身份管理系统（UUIMS）')) ?></title>
<style>
  :root{--bg:#f5f5f7;--card:rgba(255,255,255,.78);--group:#fff;--fg:#1d1d1f;--sub:#6e6e73;--sep:rgba(60,60,67,.18);--blue:#007aff;--blue-press:#0062cc;
    --red:#ff3b30;--red-bg:rgba(255,59,48,.1);--seg:rgba(118,118,128,.12);--seg-on:#fff;--shadow:0 10px 30px rgba(0,0,0,.08),0 1px 3px rgba(0,0,0,.06);
    --glow1:rgba(0,122,255,.18);--glow2:rgba(175,82,222,.14)}
  html.dark{--bg:#000;--card:rgba(28,28,30,.72);--group:#2c2c2e;--fg:#f5f5f7;--sub:#98989d;--sep:rgba(84,84,88,.6);--blue:#0a84ff;--blue-press:#409cff;
    --red:#ff453a;--red-bg:rgba(255,69,58,.16);--seg:rgba(118,118,128,.24);--seg-on:#636366;--shadow:0 10px 30px rgba(0,0,0,.5);--glow1:rgba(10,132,255,.25);--glow2:rgba(191,90,242,.2)}
  @media (prefers-color-scheme:dark){html:not(.light){--bg:#000;--card:rgba(28,28,30,.72);--group:#2c2c2e;--fg:#f5f5f7;--sub:#98989d;--sep:rgba(84,84,88,.6);--blue:#0a84ff;
    --blue-press:#409cff;--red:#ff453a;--red-bg:rgba(255,69,58,.16);--seg:rgba(118,118,128,.24);--seg-on:#636366;--shadow:0 10px 30px rgba(0,0,0,.5);--glow1:rgba(10,132,255,.25);--glow2:rgba(191,90,242,.2)}}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 12px;color:var(--fg);
    background:radial-gradient(900px 500px at 15% 10%,var(--glow1),transparent 60%),radial-gradient(800px 500px at 90% 90%,var(--glow2),transparent 60%),var(--bg);
    font:15px/1.4 -apple-system,BlinkMacSystemFont,"SF Pro Text","PingFang SC","PingFangSC","Helvetica Neue","Microsoft YaHei",system-ui,sans-serif;-webkit-font-smoothing:antialiased}
  .card{width:380px;max-width:100%;background:var(--card);-webkit-backdrop-filter:saturate(180%) blur(20px);backdrop-filter:saturate(180%) blur(20px);
    border:.5px solid var(--sep);border-radius:22px;padding:28px 24px 22px;box-shadow:var(--shadow)}
  .logo{width:52px;height:52px;border-radius:13px;margin:0 auto 14px;background:linear-gradient(135deg,#0a84ff,#5e5ce6);color:#fff;display:flex;align-items:center;justify-content:center;
    font-weight:700;font-size:24px;letter-spacing:-.5px;box-shadow:0 4px 12px rgba(10,132,255,.35)}
  h1{font-size:22px;font-weight:600;letter-spacing:-.3px;margin:0;text-align:center}
  p.sub{margin:4px 0 20px;color:var(--sub);font-size:13px;text-align:center}
  .group{background:var(--group);border-radius:12px;overflow:hidden;border:.5px solid var(--sep)}
  .cell{display:flex;align-items:center;min-height:46px;padding:0 14px;position:relative}
  .cell+.cell::before{content:"";position:absolute;top:0;left:14px;right:0;border-top:.5px solid var(--sep)}
  .cell label{width:84px;flex:none;font-size:15px}
  .cell input{flex:1;min-width:0;height:44px;border:0;background:transparent;color:var(--fg);font:inherit;outline:none}
  .cell input::placeholder{color:var(--sub);opacity:.7}
  .cell img{height:32px;border-radius:7px;margin-left:8px;background:#fff;cursor:pointer}
  .cell img.busy{opacity:.5;pointer-events:none}
  .ask-title{font-size:22px;font-weight:700;letter-spacing:-.02em;margin:8px 0 0;text-align:center}
  .ask-desc{font-size:14px;color:var(--sub);margin:10px 0 0;line-height:1.45;text-align:center}
  .actions{display:flex;gap:10px;margin-top:22px;align-items:stretch}
  .actions button,.actions-gap{flex:1 1 0;min-width:72px}
  .actions-gap{visibility:hidden;pointer-events:none}
  .actions button{margin-top:0;width:auto;font-size:14px;padding:12px 10px}
  button.outline{background:transparent;color:var(--blue);border:.5px solid var(--blue);font-weight:600;box-shadow:none}
  button.outline:active{background:rgba(10,132,255,.08)}
  .err{margin-top:14px;padding:10px 12px;border-radius:10px;background:var(--red-bg);color:var(--red);font-size:13px}
  button{margin-top:18px;width:100%;height:46px;border:0;border-radius:12px;background:var(--blue);color:#fff;font:600 16px/1 inherit;font-family:inherit;cursor:pointer}
  button:active{background:var(--blue-press)}
  button.secondary{margin-top:10px;background:transparent;color:var(--blue);border:.5px solid var(--sep);font-weight:500;font-size:15px}
  button.secondary:active{background:var(--seg)}
  .account{display:flex;align-items:center;gap:14px;padding:16px 14px}
  .avatar{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#0a84ff,#5e5ce6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;flex:none}
  .account .meta{min-width:0;flex:1}
  .account .name{font-size:16px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .account .subname{font-size:12px;color:var(--sub);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .seg{display:flex;gap:2px;padding:2px;border-radius:9px;background:var(--seg);margin:18px auto 0;width:max-content}
  .seg a{display:block;padding:5px 14px;border-radius:7px;font-size:12px;color:var(--fg);text-decoration:none}
  .seg a.on{background:var(--seg-on);box-shadow:0 1px 3px rgba(0,0,0,.12),0 0 0 .5px rgba(0,0,0,.04);font-weight:600}
  .foot{margin-top:12px;font-size:11px;color:var(--sub);text-align:center}
</style>
</head>
<body>
<form class="card" method="post" action="" autocomplete="off">
  <div class="logo" aria-hidden="true">L</div>
<?php if ($trustAsk === null): ?>
  <h1><?= $e($t('统一用户身份管理系统（UUIMS）')) ?></h1>
  <p class="sub"><?= $e($t('登录以继续访问 {client}', ['client' => $v['client']])) ?></p>
<?php endif; ?>
<?php foreach ($v['params'] as $k => $val): ?>
  <input type="hidden" name="<?= $e($k) ?>" value="<?= $e($val) ?>">
<?php endforeach; ?>
  <input type="hidden" name="ui_locales" value="<?= $e($v['locale']) ?>">
<?php if ($theme !== ''): ?>
  <input type="hidden" name="ui_theme" value="<?= $e($theme) ?>">
<?php endif; ?>
<?php if ($remembered !== null): ?>
  <input type="hidden" name="continue_nonce" value="<?= $e($remembered['continue_nonce']) ?>">
  <div class="group">
    <div class="account">
      <div class="avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr((string) $remembered['username'], 0, 1))) ?></div>
      <div class="meta">
        <div class="name"><?= $e($remembered['username']) ?></div>
<?php if (($remembered['nickname'] ?? '') !== '' && $remembered['nickname'] !== $remembered['username']): ?>
        <div class="subname"><?= $e($remembered['nickname']) ?></div>
<?php else: ?>
        <div class="subname"><?= $e($t('已信任此浏览器')) ?></div>
<?php endif; ?>
      </div>
    </div>
  </div>
<?php if ($v['error'] !== ''): ?>
  <div class="err" role="alert"><?= $e($v['error']) ?></div>
<?php endif; ?>
  <button type="submit"><?= $e($t('继续授权')) ?></button>
  <button type="submit" name="switch_account" value="1" class="secondary"><?= $e($t('切换账号')) ?></button>
<?php elseif ($trustAsk !== null): ?>
  <input type="hidden" name="trust_nonce" value="<?= $e($trustAsk['trust_nonce']) ?>">
  <h2 class="ask-title"><?= $e($t('信任此浏览器？')) ?></h2>
  <p class="ask-desc"><?= $e($t('若信任此浏览器，下次从此浏览器进入授权页时将不再要求输入账号密码（可继续授权 / 切换账号）。')) ?></p>
<?php if ($v['error'] !== ''): ?>
  <div class="err" role="alert"><?= $e($v['error']) ?></div>
<?php endif; ?>
  <div class="actions" role="group" aria-label="<?= $e($t('信任此浏览器？')) ?>">
    <button type="submit" name="trust_choice" value="cancel" class="outline"><?= $e($t('取消')) ?></button>
    <span class="actions-gap" aria-hidden="true"></span>
    <button type="submit" name="trust_choice" value="untrust" class="outline"><?= $e($t('不信任')) ?></button>
    <button type="submit" name="trust_choice" value="trust"><?= $e($t('信任')) ?></button>
  </div>
<?php else: ?>
  <input type="hidden" name="login_nonce" id="login_nonce" value="<?= $e($v['nonce']) ?>">
  <div class="group">
    <div class="cell"><label for="username"><?= $e($t('用户名')) ?></label><input id="username" name="username" value="<?= $e($v['username']) ?>" placeholder="<?= $e($t('请输入用户名')) ?>" required autofocus></div>
    <div class="cell"><label for="password"><?= $e($t('密码')) ?></label><input id="password" name="password" type="password" placeholder="<?= $e($t('请输入密码')) ?>" required autocomplete="current-password"></div>
    <div class="cell"><label for="captcha"><?= $e($t('验证码')) ?></label><input id="captcha" name="captcha" maxlength="4" placeholder="<?= $e($t('4 位字符')) ?>" required autocomplete="off"><img id="captcha-img" src="<?= $e($v['captcha']) ?>" alt="<?= $e($t('验证码')) ?>" title="<?= $e($t('看不清？点击换一张')) ?>" width="120" height="40"></div>
  </div>
<?php if ($v['error'] !== ''): ?>
  <div class="err" role="alert"><?= $e($v['error']) ?></div>
<?php endif; ?>
  <button type="submit"><?= $e($t('登录')) ?></button>
<?php endif; ?>
  <nav class="seg" aria-label="<?= $e($t('语言')) ?>">
<?php foreach ($v['languages'] as $lang): ?>
    <a href="<?= $e($lang['href']) ?>" hreflang="<?= $e($lang['locale']) ?>" lang="<?= $e($lang['locale']) ?>"<?= $lang['locale'] === $v['locale'] ? ' class="on" aria-current="true"' : '' ?>><?= $e($lang['name']) ?></a>
<?php endforeach; ?>
  </nav>
  <div class="foot"><?= $e($t('OAuth 2.1 · 授权码 + PKCE')) ?></div>
</form>
<?php if ($remembered === null && $trustAsk === null): ?>
<script>
(function () {
  var url = <?= json_encode((string) ($v['captcha_url'] ?? '/oauth/captcha'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var img = document.getElementById('captcha-img');
  var nonce = document.getElementById('login_nonce');
  var input = document.getElementById('captcha');
  var busy = false;
  function refresh() {
    if (busy || !img || !nonce) return;
    busy = true;
    img.classList.add('busy');
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.json(); })
      .then(function (d) {
        if (d && d.login_nonce && d.captcha) {
          nonce.value = d.login_nonce;
          img.src = d.captcha;
          if (input) input.value = '';
        }
      })
      .catch(function () { /* keep current captcha on failure */ })
      .finally(function () { busy = false; img.classList.remove('busy'); });
  }
  if (img) img.addEventListener('click', function (e) { e.preventDefault(); refresh(); });
})();
</script>
<?php endif; ?>
</body>
</html>
