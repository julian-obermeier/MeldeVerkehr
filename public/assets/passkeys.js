(() => {
  'use strict';

  const fromB64url = value => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/')
      + '='.repeat((4 - value.length % 4) % 4);
    const binary = atob(base64);
    return Uint8Array.from(binary, c => c.charCodeAt(0));
  };

  const toB64url = buffer => {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    bytes.forEach(byte => { binary += String.fromCharCode(byte); });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  };

  const loginButton = document.querySelector('[data-passkey-login]');
  if (loginButton && window.PublicKeyCredential) {
    loginButton.hidden = false;
    loginButton.addEventListener('click', async () => {
      const output = document.querySelector('[data-passkey-login-error]');
      try {
        loginButton.disabled = true;
        if (output) output.textContent = '';

        const response = await fetch('/passkey/login/options', {credentials: 'same-origin'});
        const envelope = await response.json();
        const options = envelope.data;
        options.challenge = fromB64url(options.challenge);

        const credential = await navigator.credentials.get({publicKey: options});
        if (!credential) throw new Error('Kein Passkey ausgewählt.');

        const payload = {
          rawId: toB64url(credential.rawId),
          clientDataJSON: toB64url(credential.response.clientDataJSON),
          authenticatorData: toB64url(credential.response.authenticatorData),
          signature: toB64url(credential.response.signature),
          userHandle: credential.response.userHandle ? toB64url(credential.response.userHandle) : null
        };

        const body = new URLSearchParams({payload: JSON.stringify(payload)});
        const finish = await fetch('/passkey/login', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
          body
        });
        const result = await finish.json();

        if (!result.success) throw new Error(result.errors?.[0] || 'Passkey-Anmeldung fehlgeschlagen.');
        window.location.href = result.data.redirect;
      } catch (error) {
        if (output) output.textContent = error instanceof Error ? error.message : 'Passkey-Anmeldung fehlgeschlagen.';
      } finally {
        loginButton.disabled = false;
      }
    });
  }

  const registerButton = document.querySelector('[data-passkey-register]');
  if (registerButton && window.PublicKeyCredential) {
    registerButton.addEventListener('click', async () => {
      const output = document.querySelector('[data-passkey-register-error]');
      try {
        registerButton.disabled = true;
        if (output) output.textContent = '';

        const label = document.querySelector('[data-passkey-label]')?.value || 'Passkey';
        const csrf = registerButton.dataset.csrf || '';

        const response = await fetch('/settings/security/passkeys/options', {credentials: 'same-origin'});
        const envelope = await response.json();
        if (!envelope.success) throw new Error(envelope.errors?.[0] || 'Passkey konnte nicht vorbereitet werden.');

        const options = envelope.data;
        options.challenge = fromB64url(options.challenge);
        options.user.id = fromB64url(options.user.id);

        const credential = await navigator.credentials.create({publicKey: options});
        if (!credential) throw new Error('Passkey-Erstellung wurde abgebrochen.');

        const payload = {
          rawId: toB64url(credential.rawId),
          clientDataJSON: toB64url(credential.response.clientDataJSON),
          attestationObject: toB64url(credential.response.attestationObject),
          transports: typeof credential.response.getTransports === 'function'
            ? credential.response.getTransports()
            : []
        };

        const body = new URLSearchParams({
          _csrf: csrf,
          label,
          payload: JSON.stringify(payload)
        });

        const finish = await fetch('/settings/security/passkeys/register', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
          body
        });
        const result = await finish.json();

        if (!result.success) throw new Error(result.errors?.[0] || 'Passkey konnte nicht gespeichert werden.');
        window.location.href = result.data.redirect;
      } catch (error) {
        if (output) output.textContent = error instanceof Error ? error.message : 'Passkey konnte nicht erstellt werden.';
      } finally {
        registerButton.disabled = false;
      }
    });
  }
})();
