// SPDX-License-Identifier: AGPL-3.0-or-later
(function () {
  const b64u = {
    enc: buf => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''),
    dec: s => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), c => c.charCodeAt(0)),
  };
  const csrf = document.querySelector('meta[name="csrf"]').content;

  async function post(path, body) {
    for (let i = 0; i < 4; i++) {
      const r = await fetch(window.KO_BASE + path, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
        body: JSON.stringify(body || {}),
      });
      if (r.status === 415) { await new Promise(res => setTimeout(res, 800 * (i + 1))); continue; }
      const data = await r.json().catch(() => ({ error: 'Unexpected response from the server (' + r.status + ').' }));
      if (!r.ok || data.error) throw new Error(data.error || ('Server error ' + r.status));
      return data;
    }
    throw new Error('The server kept refusing the request. Reload the page and try again.');
  }

  function supported() { return !!(window.PublicKeyCredential && navigator.credentials); }

  async function signIn() {
    if (!supported()) throw new Error('This browser does not support passkeys. Use the email link instead.');
    const o = await post('/passkey/login-options');
    const cred = await navigator.credentials.get({ publicKey: { ...o, challenge: b64u.dec(o.challenge) } });
    return post('/passkey/login', {
      id: b64u.enc(cred.rawId),
      clientDataJSON: b64u.enc(cred.response.clientDataJSON),
      authenticatorData: b64u.enc(cred.response.authenticatorData),
      signature: b64u.enc(cred.response.signature),
    });
  }

  function friendly(e) {
    if (e && e.name === 'NotAllowedError') return 'The passkey request was cancelled or timed out.';
    return (e && e.message) || String(e);
  }

  document.addEventListener('click', async ev => {
    const btn = ev.target.closest('[data-passkey="login"]');
    if (!btn) return;
    ev.preventDefault();
    const out = document.getElementById(btn.dataset.out || 'pk-msg');
    btn.disabled = true;
    if (out) { out.className = ''; out.textContent = 'Waiting for your passkey…'; }
    try {
      const res = await signIn();
      location.href = res.redirect || (window.KO_BASE + '/');
    } catch (e) {
      if (out) { out.className = 'err'; out.textContent = friendly(e); }
      btn.disabled = false;
    }
  });
})();
