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
  const DRAFT_STORE = 'drafts';
  const EVIDENCE_STORE = 'evidenceQueue';

  const openDb = () => new Promise((resolve, reject) => {
    if (!('indexedDB' in window)) {
      reject(new Error('IndexedDB unavailable'));
      return;
    }

    const request = indexedDB.open(DB_NAME, 2);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(DRAFT_STORE)) {
        db.createObjectStore(DRAFT_STORE, { keyPath: 'key' });
      }
      if (!db.objectStoreNames.contains(EVIDENCE_STORE)) {
        const store = db.createObjectStore(EVIDENCE_STORE, { keyPath: 'id' });
        store.createIndex('caseId', 'caseId', { unique: false });
        store.createIndex('expiresAt', 'expiresAt', { unique: false });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });

  const withNamedStore = async (storeName, mode, callback) => {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(storeName, mode);
      const store = tx.objectStore(storeName);
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
      const tx = db.transaction(DRAFT_STORE, 'readonly');
      const request = tx.objectStore(DRAFT_STORE).get(key);
      request.onsuccess = () => resolve(request.result || null);
      request.onerror = () => reject(request.error);
    });
  };

  const putDraft = draft => withNamedStore(DRAFT_STORE, 'readwrite', store => store.put(draft));
  const deleteDraft = key => withNamedStore(DRAFT_STORE, 'readwrite', store => store.delete(key));

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
    box.className = 'mv-offline-notice';

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
    discard.classList.add('mv-button-gap');
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
        message.className = 'mv-offline-alert';
        message.textContent = 'Keine Verbindung: Der Stand wurde nur lokal als Entwurf gespeichert und nicht an den Server gesendet.';
        form.parentElement?.insertBefore(message, form);
      });
    }
  };

  const EVIDENCE_MAX_FILE_BYTES = 20 * 1024 * 1024;
  const EVIDENCE_MAX_QUEUE_BYTES = 100 * 1024 * 1024;
  const EVIDENCE_MAX_QUEUE_ITEMS = 10;
  const EVIDENCE_QUEUE_TTL_MS = 24 * 60 * 60 * 1000;
  const EVIDENCE_MIME = new Set(['image/jpeg', 'image/png', 'image/webp']);

  const evidenceQueueAll = async () => {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(EVIDENCE_STORE, 'readonly');
      const request = tx.objectStore(EVIDENCE_STORE).getAll();
      request.onsuccess = () => resolve(Array.isArray(request.result) ? request.result : []);
      request.onerror = () => reject(request.error);
    });
  };

  const putEvidenceQueueItem = item =>
    withNamedStore(EVIDENCE_STORE, 'readwrite', store => store.put(item));

  const deleteEvidenceQueueItem = id =>
    withNamedStore(EVIDENCE_STORE, 'readwrite', store => store.delete(id));

  const queueUuid = () => {
    if (crypto.randomUUID) return crypto.randomUUID();

    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
    return [
      hex.slice(0, 8),
      hex.slice(8, 12),
      hex.slice(12, 16),
      hex.slice(16, 20),
      hex.slice(20),
    ].join('-');
  };

  const sha256Blob = async blob => {
    if (!crypto.subtle) throw new Error('SHA-256 ist in diesem Browser nicht verfügbar.');
    const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
    return [...new Uint8Array(digest)]
      .map(value => value.toString(16).padStart(2, '0'))
      .join('');
  };

  const purgeExpiredEvidence = async () => {
    const now = Date.now();
    const rows = await evidenceQueueAll();
    let removed = 0;

    for (const row of rows) {
      if (Number(row.expiresAt || 0) <= now) {
        await deleteEvidenceQueueItem(row.id);
        removed++;
      }
    }

    return removed;
  };

  const evidenceQueueForCase = async caseId => {
    await purgeExpiredEvidence();
    return (await evidenceQueueAll())
      .filter(row => String(row.caseId) === String(caseId))
      .sort((a, b) => Number(a.queuedAt || 0) - Number(b.queuedAt || 0));
  };

  const setEvidenceQueueStatus = text => {
    const node = document.querySelector('[data-evidence-queue-status]');
    if (node) node.textContent = text;
  };

  const refreshEvidenceQueueStatus = async caseId => {
    const rows = await evidenceQueueForCase(caseId);
    const bytes = rows.reduce((sum, row) => sum + Number(row.size || 0), 0);
    const errors = rows.filter(row => row.state === 'ERROR' || row.state === 'BLOCKED').length;
    const mib = (bytes / 1024 / 1024).toFixed(1);

    setEvidenceQueueStatus(
      rows.length === 0
        ? 'Keine lokalen Bildnachweise in der Queue.'
        : `${rows.length} lokale Datei(en), ${mib} MB${errors ? ` · ${errors} benötigt/benötigen Aufmerksamkeit` : ''}.`
    );
  };

  const queueEvidenceFile = async (caseId, category, file) => {
    if (!file || !(file instanceof Blob)) {
      throw new Error('Bitte eine Bilddatei auswählen.');
    }
    if (!EVIDENCE_MIME.has(String(file.type).toLowerCase())) {
      throw new Error('Offline sind nur JPEG, PNG und WebP zulässig.');
    }
    if (file.size < 1 || file.size > EVIDENCE_MAX_FILE_BYTES) {
      throw new Error('Die Bilddatei muss zwischen 1 Byte und 20 MB groß sein.');
    }

    await purgeExpiredEvidence();
    const existing = await evidenceQueueAll();
    const totalBytes = existing.reduce((sum, row) => sum + Number(row.size || 0), 0);

    if (existing.length >= EVIDENCE_MAX_QUEUE_ITEMS) {
      throw new Error('Die Offline-Queue ist voll (maximal 10 Dateien).');
    }
    if (totalBytes + file.size > EVIDENCE_MAX_QUEUE_BYTES) {
      throw new Error('Die Offline-Queue würde 100 MB überschreiten.');
    }

    const queuedAt = Date.now();
    const item = {
      id: queueUuid(),
      caseId: String(caseId),
      category: String(category),
      name: String(file.name || 'offline-bild'),
      type: String(file.type || ''),
      size: Number(file.size || 0),
      lastModified: Number(file.lastModified || queuedAt),
      sha256: await sha256Blob(file),
      blob: file,
      queuedAt,
      expiresAt: queuedAt + EVIDENCE_QUEUE_TTL_MS,
      state: 'QUEUED',
      attempts: 0,
      lastError: '',
    };

    try {
      await putEvidenceQueueItem(item);
    } catch (error) {
      if (error?.name === 'QuotaExceededError') {
        throw new Error('Der Browser hat nicht genug lokalen Speicher für diesen Bildnachweis.');
      }
      throw error;
    }

    return item;
  };

  const offlineEvidenceToken = async caseId => {
    const response = await fetch(
      '/cases/' + encodeURIComponent(caseId) + '/evidence/offline-token',
      { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } }
    );

    if (response.redirected) {
      throw new Error('Die Sitzung ist abgelaufen. Bitte erneut anmelden.');
    }

    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
      throw new Error(payload?.errors?.[0]?.message || 'Offline-Upload ist derzeit nicht möglich.');
    }
    if (!payload.data?.can_upload) {
      throw new Error('Der Beweissatz ist inzwischen nicht mehr bearbeitbar.');
    }

    return payload.data;
  };

  const uploadEvidenceQueueItem = async item => {
    const token = await offlineEvidenceToken(item.caseId);
    const form = new FormData();
    form.append('_csrf', token.csrf);
    form.append('client_upload_id', item.id);
    form.append('client_sha256', item.sha256);
    form.append('category', item.category);

    const uploadFile = typeof File === 'function'
      ? new File([item.blob], item.name, { type: item.type, lastModified: item.lastModified })
      : item.blob;
    form.append('evidence', uploadFile, item.name);

    item.state = 'UPLOADING';
    item.attempts = Number(item.attempts || 0) + 1;
    item.lastError = '';
    await putEvidenceQueueItem(item);

    let response;
    try {
      response = await fetch(
        '/cases/' + encodeURIComponent(item.caseId) + '/evidence/offline-upload',
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' },
          body: form,
        }
      );
    } catch (error) {
      item.state = 'QUEUED';
      item.lastError = 'Netzwerkfehler';
      await putEvidenceQueueItem(item);
      throw error;
    }

    if (response.redirected) {
      item.state = 'QUEUED';
      item.lastError = 'Sitzung abgelaufen';
      await putEvidenceQueueItem(item);
      throw new Error('Die Sitzung ist abgelaufen. Bitte erneut anmelden.');
    }

    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.success) {
      const message = payload?.errors?.[0]?.message || 'Server hat den Offline-Upload abgelehnt.';
      item.state = response.status === 409 ? 'QUEUED' : 'ERROR';
      item.lastError = message;
      await putEvidenceQueueItem(item);
      throw new Error(message);
    }

    if (
      payload.data?.receipt_status !== 'DONE'
      || String(payload.data?.sha256 || '').toLowerCase() !== String(item.sha256).toLowerCase()
    ) {
      item.state = 'ERROR';
      item.lastError = 'Serverbestätigung stimmt nicht mit dem lokalen SHA-256 überein.';
      await putEvidenceQueueItem(item);
      throw new Error(item.lastError);
    }

    await deleteEvidenceQueueItem(item.id);
    return payload.data;
  };

  const flushEvidenceQueue = async caseId => {
    if (!navigator.onLine) {
      setEvidenceQueueStatus('Offline: lokale Bildnachweise bleiben geschützt in diesem Browser gespeichert.');
      return { sent: 0, failed: 0 };
    }

    const rows = await evidenceQueueForCase(caseId);
    let sent = 0;
    let failed = 0;

    for (const item of rows) {
      try {
        setEvidenceQueueStatus(`Offline-Queue wird gesendet (${sent + failed + 1}/${rows.length}) …`);
        await uploadEvidenceQueueItem(item);
        sent++;
      } catch (_) {
        failed++;
      }
    }

    await refreshEvidenceQueueStatus(caseId);
    return { sent, failed };
  };

  const attachOfflineEvidence = async () => {
    const form = document.querySelector('form[data-offline-evidence="1"]');
    if (!form) return;

    const caseId = form.dataset.caseId || '';
    const fileInput = form.querySelector('input[type="file"][name="evidence"]');
    const category = form.querySelector('select[name="category"]');
    const syncButton = document.querySelector('[data-evidence-queue-sync]');
    const clearButton = document.querySelector('[data-evidence-queue-clear]');

    if (!caseId || !fileInput || !category) return;

    await refreshEvidenceQueueStatus(caseId).catch(() => {
      setEvidenceQueueStatus('Lokaler Browser-Speicher ist nicht verfügbar.');
    });

    form.addEventListener('submit', async event => {
      event.preventDefault();
      const file = fileInput.files?.[0];

      try {
        setEvidenceQueueStatus('Bild wird lokal geprüft und SHA-256 berechnet …');
        await queueEvidenceFile(caseId, category.value, file);
        fileInput.value = '';
        await refreshEvidenceQueueStatus(caseId);

        if (navigator.onLine) {
          const result = await flushEvidenceQueue(caseId);
          if (result.sent > 0 && result.failed === 0) {
            window.location.reload();
          }
        } else {
          setEvidenceQueueStatus('Offline gespeichert. Der Upload startet, sobald MeldeVerkehr wieder online geöffnet ist.');
        }
      } catch (error) {
        setEvidenceQueueStatus(error?.message || 'Bild konnte nicht in die Offline-Queue aufgenommen werden.');
      }
    });

    syncButton?.addEventListener('click', async () => {
      const result = await flushEvidenceQueue(caseId);
      if (result.sent > 0 && result.failed === 0) window.location.reload();
    });

    clearButton?.addEventListener('click', async () => {
      const rows = await evidenceQueueForCase(caseId);
      for (const row of rows) await deleteEvidenceQueueItem(row.id);
      await refreshEvidenceQueueStatus(caseId);
    });

    window.addEventListener('online', () => {
      flushEvidenceQueue(caseId)
        .then(result => {
          if (result.sent > 0 && result.failed === 0) window.location.reload();
        })
        .catch(() => {});
    });

    if (navigator.onLine) {
      const rows = await evidenceQueueForCase(caseId);
      if (rows.length > 0) {
        flushEvidenceQueue(caseId)
          .then(result => {
            if (result.sent > 0 && result.failed === 0) window.location.reload();
          })
          .catch(() => {});
      }
    }
  };

  const attachPrivacyEditor = () => {
    document.querySelectorAll('[data-privacy-region="1"]').forEach(region => {
      const x = Number(region.dataset.x || 0);
      const y = Number(region.dataset.y || 0);
      const width = Number(region.dataset.width || 0);
      const height = Number(region.dataset.height || 0);
      region.style.left = Math.max(0, Math.min(100, x)) + '%';
      region.style.top = Math.max(0, Math.min(100, y)) + '%';
      region.style.width = Math.max(0, Math.min(100, width)) + '%';
      region.style.height = Math.max(0, Math.min(100, height)) + '%';
    });

    const stage = document.getElementById('privacyStage');
    const img = document.getElementById('privacyImage');
    const selection = document.getElementById('selection');
    if (!stage || !img || !selection) return;

    const values = {
      x: document.getElementById('xPct'),
      y: document.getElementById('yPct'),
      w: document.getElementById('wPct'),
      h: document.getElementById('hPct'),
    };
    if (!values.x || !values.y || !values.w || !values.h) return;

    let start = null;
    const point = event => {
      const rect = img.getBoundingClientRect();
      return {
        x: Math.max(0, Math.min(rect.width, event.clientX - rect.left)),
        y: Math.max(0, Math.min(rect.height, event.clientY - rect.top)),
        rect,
      };
    };

    stage.addEventListener('pointerdown', event => {
      if (event.button !== 0) return;
      start = point(event);
      stage.setPointerCapture(event.pointerId);
      selection.hidden = false;
    });

    stage.addEventListener('pointermove', event => {
      if (!start) return;
      const current = point(event);
      const x = Math.min(start.x, current.x);
      const y = Math.min(start.y, current.y);
      const width = Math.abs(current.x - start.x);
      const height = Math.abs(current.y - start.y);
      selection.style.left = (x / current.rect.width * 100) + '%';
      selection.style.top = (y / current.rect.height * 100) + '%';
      selection.style.width = (width / current.rect.width * 100) + '%';
      selection.style.height = (height / current.rect.height * 100) + '%';
    });

    stage.addEventListener('pointerup', event => {
      if (!start) return;
      const current = point(event);
      const x = Math.min(start.x, current.x);
      const y = Math.min(start.y, current.y);
      const width = Math.abs(current.x - start.x);
      const height = Math.abs(current.y - start.y);
      values.x.value = (x / current.rect.width * 100).toFixed(2);
      values.y.value = (y / current.rect.height * 100).toFixed(2);
      values.w.value = (width / current.rect.width * 100).toFixed(2);
      values.h.value = (height / current.rect.height * 100).toFixed(2);
      start = null;
    });
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
        link.className = 'mv-skip-link';
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
    attachPrivacyEditor();
    attachGps();
    attachOfflineDrafts().catch(() => {});
    attachOfflineEvidence().catch(() => {});
    attachPushSettings();
  });
})();
