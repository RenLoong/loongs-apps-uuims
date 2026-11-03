<?php

declare(strict_types=1);

/**
 * en-US / login.php — OAuth sign-in page and WeChat messages.
 * Keys are the Chinese source texts; {name} placeholders are filled by loongs/language.
 * Files in this directory are merged by filename; a later file overrides the same key (README §19).
 */

return [
    '统一用户身份管理系统（UUIMS）' => '统一用户身份管理系统（UUIMS）',
    '登录以继续访问 {client}' => 'Sign in to continue to {client}',
    '验证码' => 'Captcha',
    '看不清？点击换一张' => 'Hard to read? Tap for a new one',
    'OAuth 2.1 · 授权码 + PKCE' => 'OAuth 2.1 · Authorization code + PKCE',
    '请输入用户名' => 'Enter username',
    '请输入密码' => 'Enter password',
    '4 位字符' => '4 characters',
    '语言' => 'Language',
    '页面已过期，请重新输入' => 'The page has expired, please try again',
    '请输入用户名和密码' => 'Please enter username and password',
    '登录失败次数过多，请 {minutes} 分钟后再试' => 'Too many failed attempts, try again in {minutes} minutes',
    '验证码错误' => 'Wrong captcha',
    '用户名或密码错误（还可尝试 {left} 次）' => 'Wrong username or password ({left} attempts left)',
    '登录失败次数过多，账号已临时锁定' => 'Too many failed attempts, the account is temporarily locked',
    '账号已停用' => 'The account is disabled',
    '简体中文' => '简体中文',
    '繁體中文' => '繁體中文',
    '日本語' => '日本語',
    '微信登录凭证' => 'WeChat login code',
    '已绑定微信，下次可直接微信登录' => 'WeChat bound: next time you can sign in with WeChat',
    '此微信已绑定当前账号' => 'This WeChat is already bound to this account',
    '已解绑微信' => 'WeChat unbound',
    '当前账号未绑定微信' => 'This account has no WeChat bound',
    '不能操作超级管理员' => 'The super administrator cannot be changed',
    '该管理员未绑定微信' => 'This administrator has no WeChat bound',
    '服务器未配置微信小程序 AppID / AppSecret（WECHAT_MINI_APPID / WECHAT_MINI_SECRET）' => 'The server has no WeChat mini program AppID / AppSecret (WECHAT_MINI_APPID / WECHAT_MINI_SECRET)',
    '微信登录凭证无效，请重试' => 'Invalid WeChat login code, please retry',
    '微信服务暂时不可用，请稍后重试' => 'WeChat is temporarily unavailable, please try again later',
    '微信登录凭证无效或已使用，请重试' => 'The WeChat login code is invalid or used, please retry',
    '微信登录过于频繁，请稍后再试' => 'Too many WeChat sign-ins, please try again later',
    '该微信账号存在风险，已被微信拦截' => 'This WeChat account was blocked by WeChat for risk reasons',
    '微信登录失败（errcode {errcode}）' => 'WeChat sign-in failed (errcode {errcode})',
    '微信服务返回异常（缺少 openid）' => 'Unexpected WeChat response (no openid)',
    '该微信已绑定其他管理员账号，请先在该账号中解绑' => 'This WeChat is bound to another administrator; unbind it there first',
    '该微信未绑定管理员账号，请先使用账号密码登录，登录后将自动绑定此微信' => 'This WeChat is not bound to an administrator. Sign in with username and password first; this WeChat will then be bound automatically',
    '缺少 code（wx.login 返回的登录凭证）' => 'Missing code (the login code returned by wx.login)',
    '继续授权' => 'Continue',
    '切换账号' => 'Switch account',
    '登录状态已失效，请重新登录' => 'Sign-in expired, please sign in again',
    '账号已停用或不存在' => 'Account disabled or missing',
    '信任此浏览器？' => 'Trust this browser?',
    '若信任此浏览器，下次从此浏览器进入授权页时将不再要求输入账号密码（可继续授权 / 切换账号）。' => 'If you trust this browser, the next time you open the authorize page from it you will not need to enter username and password (you can continue authorizing or switch accounts).',
    '信任' => 'Trust',
    '取消' => 'Cancel',
    '不信任' => 'Do not trust',
    '已信任此浏览器' => 'Trusted browser',
];
