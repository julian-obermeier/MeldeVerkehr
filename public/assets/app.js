(() => {
  'use strict';

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/service-worker.js').catch(() => {
        // Service worker failure must not break the application.
      });
    });
  }

  const DB_NAME = 'meldeverkehr-offline-v1';
  const STORE = 'drafts';

  const openDb = () => new Promise((resolve, reject) => {
    if (!('indexedDB' in window)) {
      reject(new Error('IndexedDB unavailable'));
      return;
    }

    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, { keyPath: 'key' });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });

  const withStore = async (mode, callback) => {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, mode);
      const store = tx.objectStore(STORE);
      let result;
      try {
        result = callback(store);
      } catch (error) {
        reject(error);
        return;
      }
      tx.oncomplete = () => resolve(result);
      tx.onerror = () => reject(tx.error);
    });
  };

  const getDraft = async key => {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readonly');
      const request = tx.objectStore(STORE).get(key);
      request.onsuccess = () => resolve(request.result || null);
      request.onerror = () => reject(request.error);
    });
  };

  const putDraft = draft => withStore('readwrite', store => store.put(draft));
  const deleteDraft = key => withStore('readwrite', store => store.delete(key));

  const serializeForm = form => {
    const fields = {};
    for (const element of form.elements) {
      if (!element.name || element.name === '_csrf' || element.disabled) continue;
      if (element.type === 'file') continue;

      if (element.type === 'checkbox' || element.type === 'radio') {
        fields[element.name] = element.checked ? String(element.value || '1') : '';
      } else {
        fields[element.name] = String(element.value ?? '');
      }
    }
    return fields;
  };

  const fieldsEqual = (a, b) => {
    const keys = [...new Set([...Object.keys(a || {}), ...Object.keys(b || {})])];
    return keys.every(key => String((a || {})[key] ?? '') === String((b || {})[key] ?? ''));
  };

  const applyFields = (form, fields) => {
    for (const element of form.elements) {
      if (!element.name || !Object.prototype.hasOwnProperty.call(fields, element.name)) continue;
      if (element.name === '_csrf' || element.type === 'file') continue;

      const value = String(fields[element.name] ?? '');
      if (element.type === 'checkbox' || element.type === 'radio') {
        element.checked = value !== '' && String(element.value || '1') === value;
      } else {
        element.value = value;
      }
      element.dispatchEvent(new Event('change', { bubbles: true }));
    }
  };

  const draftNotice = (form, draft, conflict) => {
    const existing = form.parentElement?.querySelector('[data-offline-draft-notice]');
    if (existing) existing.remove();

    const box = document.createElement('div');
    box.dataset.offlineDraftNotice = '1';
    box.setAttribute('role', 'status');
    box.style.margin = '12px 0';
    box.style.padding = '12px';
    box.style.border = '1px solid #d6b36b';
    box.style.borderRadius = '8px';
    box.style.background = '#fffaf0';

    const title = document.createElement('strong');
    title.textContent = conflict
      ? 'Lokaler Entwurf kollidiert mit einer neueren Serverversion.'
      : 'Lokaler Entwurf vorhanden.';
    box.appendChild(title);

    const text = document.createElement('p');
    text.textContent = conflict
      ? 'Nichts wurde automatisch überschrieben. Du kannst den lokalen Stand ausdrücklich übernehmen oder verwerfen.'
      : 'Der lokale Stand wurde nicht automatisch übernommen.';
    box.appendChild(text);

    const apply = document.createElement('button');
    apply.type = 'button';
    apply.textContent = conflict ? 'Lokalen Stand trotzdem übernehmen' : 'Lokalen Entwurf übernehmen';
    apply.addEventListener('click', () => {
      applyFields(form, draft.fields || {});
      box.remove();
    });
    box.appendChild(apply);

    const discard = document.createElement('button');
    discard.type = 'button';
    discard.textContent = 'Lokalen Entwurf verwerfen';
    discard.style.marginLeft = '8px';
    discard.addEventListener('click', async () => {
      await deleteDraft(draft.key);
      box.remove();
    });
    box.appendChild(discard);

    form.parentElement?.insertBefore(box, form);
  };

  const attachOfflineDrafts = async () => {
    const forms = [...document.querySelectorAll('form[data-offline-draft="1"]')];

    for (const form of forms) {
      const key = form.dataset.draftKey;
      const serverVersion = form.dataset.serverVersion || '';

      if (!key) continue;

      let existing = null;
      try {
        existing = await getDraft(key);
      } catch (_) {
        continue;
      }

      const serverFields = serializeForm(form);

      if (existing) {
        if (fieldsEqual(existing.fields || {}, serverFields)) {
          await deleteDraft(key);
          existing = null;
        } else {
          const conflict = String(existing.serverVersion || '') !== String(serverVersion);
          draftNotice(form, existing, conflict);
        }
      }

      let timer = null;
      const save = async () => {
        const previous = await getDraft(key).catch(() => null);
        await putDraft({
          key,
          path: form.action,
          serverVersion,
          localVersion: Number(previous?.localVersion || 0) + 1,
          modifiedAt: new Date().toISOString(),
          fields: serializeForm(form),
        });
      };

      const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(() => save().catch(() => {}), 300);
      };

      form.addEventListener('input', schedule);
      form.addEventListener('change', schedule);

      form.addEventListener('submit', async event => {
        if (navigator.onLine) return;

        event.preventDefault();
        await save().catch(() => {});

        const message = document.createElement('div');
        message.setAttribute('role', 'alert');
        message.style.margin = '12px 0';
        message.style.padding = '12px';
        message.style.border = '1px solid #d6b36b';
        message.style.borderRadius = '8px';
        message.textContent = 'Keine Verbindung: Der Stand wurde nur lokal als Entwurf gespeichert und nicht an den Server gesendet.';
        form.parentElement?.insertBefore(message, form);
      });
    }
  };

  const attachGps = () => {
    const button = document.getElementById('useGps');
    if (!button) return;

    const status = document.getElementById('gpsStatus');
    button.addEventListener('click', () => {
      if (!navigator.geolocation) {
        if (status) status.textContent = ' Geolocation wird von diesem Browser nicht unterstützt.';
        return;
      }

      button.disabled = true;
      if (status) status.textContent = ' Standort wird ermittelt …';

      navigator.geolocation.getCurrentPosition(
        pos => {
          const lat = document.getElementById('latitude');
          const lon = document.getElementById('longitude');
          if (lat) lat.value = pos.coords.latitude.toFixed(7);
          if (lon) lon.value = pos.coords.longitude.toFixed(7);
          if (status) status.textContent = ' Standort übernommen.';
          button.disabled = false;
        },
        () => {
          if (status) status.textContent = ' Standort konnte nicht ermittelt werden.';
          button.disabled = false;
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 30000 }
      );
    });
  };

  const enhanceAccessibility = () => {
    const main = document.querySelector('main');

    if (main) {
      if (!main.id) main.id = 'main-content';

      if (!document.querySelector('[data-skip-link]')) {
        const link = document.createElement('a');
        link.href = '#' + main.id;
        link.dataset.skipLink = '1';
        link.textContent = 'Zum Hauptinhalt';
        link.style.position = 'fixed';
        link.style.left = '12px';
        link.style.top = '-80px';
        link.style.zIndex = '9999';
        link.style.padding = '10px 14px';
        link.style.background = '#ffffff';
        link.style.color = '#172033';
        link.style.border = '2px solid #172033';
        link.style.borderRadius = '8px';
        link.addEventListener('focus', () => { link.style.top = '12px'; });
        link.addEventListener('blur', () => { link.style.top = '-80px'; });
        document.body.prepend(link);
      }
    }

    document.querySelectorAll('.error').forEach(node => {
      if (!node.hasAttribute('role')) node.setAttribute('role', 'alert');
    });

    document.querySelectorAll('.message').forEach(node => {
      if (!node.hasAttribute('role')) node.setAttribute('role', 'status');
      if (!node.hasAttribute('aria-live')) node.setAttribute('aria-live', 'polite');
    });

    const style = document.createElement('style');
    style.textContent =
      ':focus-visible{outline:3px solid currentColor;outline-offset:3px}' +
      '[aria-disabled="true"]{cursor:not-allowed}';
    document.head.appendChild(style);
  };

  const base64UrlBytes = value => {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map(char => char.charCodeAt(0)));
  };

  const attachPushSettings = () => {
    const enable = document.querySelector('[data-push-enable]');
    const disable = document.querySelector('[data-push-disable]');
    const status = document.querySelector('[data-push-status]');

    if (!enable && !disable) return;

    const setStatus = message => {
      if (status) status.textContent = message;
    };

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      setStatus('Dieser Browser unterstützt Web Push nicht.');
      if (enable) enable.disabled = true;
      if (disable) disable.disabled = true;
      return;
    }

    enable?.addEventListener('click', async () => {
      try {
        setStatus('Push wird aktiviert …');
        const keyResponse = await fetch('/notifications/push/key', {
          credentials: 'same-origin',
          cache: 'no-store',
        });
        const keyPayload = await keyResponse.json();
        if (!keyResponse.ok || !keyPayload?.data?.public_key) {
          throw new Error('Push ist serverseitig nicht konfiguriert.');
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
          throw new Error('Benachrichtigungsberechtigung wurde nicht erteilt.');
        }

        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
          subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlBytes(keyPayload.data.public_key),
          });
        }

        const json = subscription.toJSON();
        const body = new URLSearchParams({
          _csrf: enable.dataset.csrf || '',
          endpoint: subscription.endpoint,
          p256dh: json.keys?.p256dh || '',
          auth: json.keys?.auth || '',
        });

        const response = await fetch('/notifications/push/subscriptions', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body,
        });
        if (!response.ok) {
          throw new Error('Push-Abonnement konnte nicht gespeichert werden.');
        }

        setStatus('Push ist auf diesem Gerät aktiviert.');
      } catch (error) {
        setStatus(error?.message || 'Push konnte nicht aktiviert werden.');
      }
    });

    disable?.addEventListener('click', async () => {
      try {
        setStatus('Push wird deaktiviert …');
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) await subscription.unsubscribe();

        const response = await fetch('/notifications/push/unsubscribe', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
          body: new URLSearchParams({ _csrf: disable.dataset.csrf || '' }),
        });
        if (!response.ok) {
          throw new Error('Push-Registrierung konnte serverseitig nicht deaktiviert werden.');
        }

        setStatus('Push ist auf diesem Gerät deaktiviert.');
      } catch (error) {
        setStatus(error?.message || 'Push konnte nicht deaktiviert werden.');
      }
    });
  };

  window.addEventListener('DOMContentLoaded', () => {
    enhanceAccessibility();
    attachGps();
    attachOfflineDrafts().catch(() => {});
    attachPushSettings();
  });
})();
