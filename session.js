(() => {
  const API = 'api/index.php';
  const SIGNAL_KEY = 'rcmp_logout_signal';
  let lastIdentity = null;
  let checking = false;
  const logoutChannel = 'BroadcastChannel' in window ? new BroadcastChannel('rcmp-account-session') : null;

  function localSession() {
    try {
      const session = JSON.parse(localStorage.getItem('egga_session') || 'null');
      if (session && session.token) return session;
    } catch (error) {}
    const token = sessionStorage.getItem('rcmp_at');
    return token ? { token, role: sessionStorage.getItem('rcmp_ar') || '' } : null;
  }

  function clearSession(target = {}) {
    const session = localSession();
    const knownEmail = (session && session.email) || (lastIdentity && lastIdentity.email) || '';
    if (target.email && knownEmail && knownEmail !== target.email) return false;
    const tabToken = sessionStorage.getItem('rcmp_at');
    let stored = null;
    try { stored = JSON.parse(localStorage.getItem('egga_session') || 'null'); } catch (error) {}
    const localMatches = stored && (target.email ? stored.email === target.email : !target.token || stored.token === target.token);
    const tabMatches = !!tabToken && (target.email ? knownEmail === target.email || tabToken === target.token : !target.token || tabToken === target.token);
    if (localMatches) localStorage.removeItem('egga_session');
    if (tabMatches) {
      sessionStorage.removeItem('rcmp_at');
      sessionStorage.removeItem('rcmp_ar');
    }
    return !!localMatches || tabMatches || !!(target.email && lastIdentity && lastIdentity.email === target.email);
  }

  function notifyOtherTabs(target) {
    const message = { ...target, at: Date.now() };
    localStorage.setItem(SIGNAL_KEY, JSON.stringify(message));
    if (logoutChannel) logoutChannel.postMessage(message);
  }

  function addLogoutControl() {
    if (!localSession() || document.getElementById('userChip') || document.getElementById('loginBox') || document.getElementById('loginCard')) return;
    const style = document.createElement('style');
    style.textContent = '.global-session-exit{position:fixed;top:12px;left:12px;z-index:9998;border:1px solid #b84b3a;background:#fff;color:#842f24;padding:9px 12px;font:700 13px Tahoma,Arial,sans-serif;cursor:pointer;box-shadow:0 2px 9px #0002}.global-session-exit:hover{background:#fff1ed}@media(max-width:600px){.global-session-exit{top:auto;bottom:calc(68px + env(safe-area-inset-bottom));left:8px;padding:8px 10px}}';
    document.head.appendChild(style);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'global-session-exit';
    button.textContent = 'خروج من كل الصفحات';
    button.setAttribute('aria-label', 'تسجيل الخروج من كل صفحات هذا الحساب');
    button.addEventListener('click', window.logoutEverywhere);
    document.body.appendChild(button);
  }

  async function validateSession() {
    if (checking) return;
    const session = localSession();
    if (!session || !session.token) return;
    checking = true;
    try {
      const response = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ action: 'me', token: session.token })
      });
      const result = await response.json();
      if (!result.ok) {
        const target = { email: session.email || (lastIdentity && lastIdentity.email), token: session.token };
        if (clearSession(target)) {
          notifyOtherTabs(target);
          location.reload();
        }
        return;
      }
      lastIdentity = { email: result.email, token: session.token };
      if (!session.email) {
        localStorage.setItem('egga_session', JSON.stringify({ ...result, token: session.token }));
      }
    } catch (error) {
      // A temporary network failure must not log an otherwise valid user out.
    } finally {
      checking = false;
    }
  }

  window.logoutEverywhere = async function () {
    const session = localSession();
    if (!session || !session.token) {
      location.reload();
      return;
    }
    let email = session.email || (lastIdentity && lastIdentity.email) || '';
    try {
      const response = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        cache: 'no-store',
        body: JSON.stringify({ action: 'logout', token: session.token })
      });
      const result = await response.json();
      if (!response.ok || !result.ok || !result.email) throw new Error('logout failed');
      email = result.email || email;
    } catch (error) {
      window.alert('تعذر الاتصال بالخادم؛ لم يتم إنهاء الجلسات الأخرى. تحقق من الاتصال ثم أعد المحاولة.');
      return;
    }
    const target = { email, token: session.token };
    clearSession(target);
    notifyOtherTabs(target);
    location.reload();
  };

  window.addEventListener('storage', event => {
    if (event.key !== SIGNAL_KEY || !event.newValue) return;
    try {
      const target = JSON.parse(event.newValue);
      if (clearSession(target)) location.reload();
    } catch (error) {}
  });
  if (logoutChannel) logoutChannel.addEventListener('message', event => {
    if (clearSession(event.data || {})) location.reload();
  });

  window.addEventListener('focus', validateSession);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') validateSession();
  });
  window.setInterval(validateSession, 30000);
  addLogoutControl();
  validateSession();
})();
