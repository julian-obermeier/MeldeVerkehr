(() => {
  'use strict';

  const DB_NAME = 'meldeverkehr-offline';
  const DB_VERSION = 1;
  const STORE = 'drafts';
  let current = null;
  let conflict = null;

  const $ = id => document.getElementById(id);
  const message = (text, type = 'ok') => {
    $('message').innerHTML = text ? '<div class="' + type + '">' + escapeHtml(text) + '</div>' : '';
  };
  const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  })[char]);

  const openDb = () => new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE)) {
        db.createObjectStore(STORE, {keyPath: 'id'});
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });

  const put = async draft => {
    const db = await openDb();
    await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).put(draft);
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error);
    });
    db.close();
  };

  const all = async () => {
    const db = await openDb();
    const rows = await new Promise((resolve, reject) => {
      const request = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
      request.onsuccess = () => resolve(request.result || []);
      request.onerror = () => reject(request.error);
    });
    db.close();
    return rows.sort((a,b) => String(b.last_modified).localeCompare(String(a.last_modified)));
  };

  const remove = async id => {
    const db = await openDb();
    await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).delete(id);
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error);
    });
    db.close();
  };

  const newDraft = () => ({
    id: crypto.randomUUID(),
    local_version: 1,
    server_case_id: null,
    server_version: null,
    last_modified: new Date().toISOString(),
    data: {
      vehicle: {},
      location: {traffic_space_type:'UNKNOWN', access_type:'UNCLEAR', country:'DE'},
      observation: {}
    },
    photos: []
  });

  const readForm = () => {
    current.data = {
      vehicle: {
        license_plate: $('licensePlate').value.trim(),
        vehicle_type: $('vehicleType').value,
      },
      location: {
        street: $('street').value.trim(),
        house_number: $('houseNumber').value.trim(),
        postal_code: $('postalCode').value.trim(),
        city: $('city').value.trim(),
        latitude: $('latitude').value.trim(),
        longitude: $('longitude').value.trim(),
        traffic_space_type: $('trafficSpace').value,
        access_type: $('accessType').value,
        country: 'DE',
      },
      observation: {
        observed_from: $('observedFrom').value,
        observed_until: $('observedUntil').value,
        obstruction: $('obstruction').checked ? '1' : '0',
        endangerment: $('endangerment').checked ? '1' : '0',
        damage: $('damage').checked ? '1' : '0',
      }
    };
  };

  const fillForm = () => {
    const vehicle = current?.data?.vehicle || {};
    const location = current?.data?.location || {};
    const observation = current?.data?.observation || {};
    $('licensePlate').value = vehicle.license_plate || '';
    $('vehicleType').value = vehicle.vehicle_type || '';
    $('street').value = location.street || '';
    $('houseNumber').value = location.house_number || '';
    $('postalCode').value = location.postal_code || '';
    $('city').value = location.city || '';
    $('latitude').value = location.latitude || '';
    $('longitude').value = location.longitude || '';
    $('trafficSpace').value = location.traffic_space_type || 'UNKNOWN';
    $('accessType').value = location.access_type || 'UNCLEAR';
    $('observedFrom').value = observation.observed_from || '';
    $('observedUntil').value = observation.observed_until || '';
    $('obstruction').checked = observation.obstruction === '1';
    $('endangerment').checked = observation.endangerment === '1';
    $('damage').checked = observation.damage === '1';
    renderPhotos();
  };

  const persist = async (increment = true) => {
    if (!current) current = newDraft();
    readForm();
    if (increment) current.local_version = Math.max(1, Number(current.local_version || 1) + 1);
    current.last_modified = new Date().toISOString();
    await put(current);
    await renderDrafts();
  };

  const renderPhotos = () => {
    const root = $('photos');
    root.innerHTML = '';
    for (const photo of current?.photos || []) {
      const div = document.createElement('div');
      div.className = 'photo';
      const status = photo.uploaded ? 'hochgeladen' : 'nur lokal';
      div.innerHTML = '<strong>' + escapeHtml(photo.name) + '</strong> · ' + escapeHtml(status)
        + '<label>Kategorie<select data-photo-category="' + escapeHtml(photo.id) + '">'
        + ['OVERVIEW','LICENSE_PLATE','VEHICLE','VEHICLE_POSITION','TRAFFIC_SIGN','SUPPLEMENTARY_SIGN','OBSTRUCTION','ENDANGERMENT','DAMAGE','CONTEXT','OTHER']
          .map(cat => '<option value="' + cat + '"' + (photo.category === cat ? ' selected' : '') + '>' + cat + '</option>').join('')
        + '</select></label>'
        + '<button type="button" class="danger" data-photo-remove="' + escapeHtml(photo.id) + '">Entfernen</button>';
      root.appendChild(div);
    }
  };

  const renderDrafts = async () => {
    const rows = await all();
    const root = $('draftList');
    root.innerHTML = '';
    if (!rows.length) {
      root.innerHTML = '<p class="muted">Noch keine lokalen Entwürfe.</p>';
      return;
    }
    for (const draft of rows) {
      const div = document.createElement('div');
      div.className = 'draft';
      div.innerHTML =
        '<strong>' + escapeHtml(draft.server_case_id ? 'Server-Entwurf ' + draft.server_case_id : 'Nur lokal') + '</strong>'
        + '<br><small>lokale Version ' + escapeHtml(draft.local_version) + ' · ' + escapeHtml(draft.last_modified) + '</small>'
        + '<div class="actions"><button type="button" data-load="' + escapeHtml(draft.id) + '">Öffnen</button>'
        + '<button type="button" class="danger" data-delete="' + escapeHtml(draft.id) + '">Lokal löschen</button></div>';
      root.appendChild(div);
    }
  };

  const loadDraft = async id => {
    const rows = await all();
    current = rows.find(row => row.id === id) || null;
    conflict = null;
    $('conflictCard').hidden = true;
    if (current) {
      fillForm();
      message('Lokaler Entwurf geöffnet.');
    }
  };

  const getCsrf = async () => {
    const response = await fetch('/offline-drafts/csrf', {credentials:'same-origin', cache:'no-store'});
    const json = await response.json();
    if (!response.ok || !json.success) {
      throw new Error(json.errors?.[0]?.message || 'Online-Anmeldung erforderlich.');
    }
    return json.data.csrf;
  };

  const sync = async resolution => {
    if (!navigator.onLine) {
      message('Keine Netzwerkverbindung. Der Entwurf bleibt lokal gespeichert.', 'warn');
      return;
    }
    await persist(false);
    const csrf = await getCsrf();
    const response = await fetch('/offline-drafts/sync', {
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({
        local_id:current.id,
        local_version:current.local_version,
        server_case_id:current.server_case_id,
        server_version:current.server_version,
        resolution:resolution || 'NONE',
        data:current.data
      })
    });
    const json = await response.json();

    if (response.status === 409 && json.data?.conflict) {
      conflict = json.data;
      $('conflictCard').hidden = false;
      message('Versionskonflikt erkannt. Wähle ausdrücklich eine Fassung.', 'warn');
      return;
    }

    if (!response.ok || !json.success) {
      throw new Error(json.errors?.[0]?.message || 'Synchronisierung fehlgeschlagen.');
    }

    current.server_case_id = json.data.case_id;
    current.server_version = json.data.server_version;
    current.last_modified = new Date().toISOString();
    await put(current);
    conflict = null;
    $('conflictCard').hidden = true;
    await renderDrafts();

    const link = json.data.case_url;
    message(
      'Grunddaten synchronisiert. Öffne jetzt den Server-Vorgang für Tatbestand und Review. '
      + 'Lokale Fotos bleiben erhalten, bis der Vorgang die Beweiserfassung freigibt.'
    );
    const a = document.createElement('a');
    a.className = 'button';
    a.href = link;
    a.textContent = 'Server-Vorgang öffnen';
    $('message').appendChild(a);
  };

  const uploadPhotos = async () => {
    if (!current?.server_case_id) {
      message('Synchronisiere zuerst die Grunddaten mit dem Server.', 'warn');
      return;
    }
    if (!navigator.onLine) {
      message('Keine Netzwerkverbindung. Fotos bleiben lokal.', 'warn');
      return;
    }

    const csrf = await getCsrf();
    let uploaded = 0;

    for (const photo of current.photos || []) {
      if (photo.uploaded) continue;

      const form = new FormData();
      form.append('_csrf', csrf);
      form.append('category', photo.category || 'OVERVIEW');
      form.append('evidence', photo.blob, photo.name || 'offline-photo.jpg');

      const response = await fetch('/offline-drafts/' + encodeURIComponent(current.server_case_id) + '/evidence', {
        method:'POST',
        credentials:'same-origin',
        cache:'no-store',
        body:form
      });
      const json = await response.json();

      if (!response.ok || !json.success) {
        message(
          json.errors?.[0]?.message
          || 'Foto-Upload ist noch nicht möglich. Bestätige zuerst Tatbestand und Grunddaten-Review im Server-Vorgang.',
          'warn'
        );
        await put(current);
        renderPhotos();
        return;
      }

      photo.uploaded = true;
      photo.evidence_id = json.data.evidence_id;
      uploaded++;
    }

    current.last_modified = new Date().toISOString();
    await put(current);
    renderPhotos();
    await renderDrafts();
    message(uploaded ? uploaded + ' Foto(s) hochgeladen.' : 'Alle lokalen Fotos sind bereits hochgeladen.');
  };

  $('newDraft').addEventListener('click', async () => {
    current = newDraft();
    await put(current);
    fillForm();
    await renderDrafts();
    message('Neuer lokaler Entwurf angelegt.');
  });

  $('saveDraft').addEventListener('click', async () => {
    await persist(true);
    message('Entwurf lokal gespeichert.');
  });

  $('syncDraft').addEventListener('click', () => sync('NONE').catch(err => message(err.message, 'error')));
  $('useServer').addEventListener('click', () => {
    if (conflict) current.server_version = conflict.server_version;
    sync('USE_SERVER').catch(err => message(err.message, 'error'));
  });
  $('useLocal').addEventListener('click', () => sync('USE_LOCAL').catch(err => message(err.message, 'error')));
  $('uploadPhotos').addEventListener('click', () => uploadPhotos().catch(err => message(err.message, 'error')));

  $('photoInput').addEventListener('change', async event => {
    if (!current) current = newDraft();
    for (const file of Array.from(event.target.files || [])) {
      if (!['image/jpeg','image/png','image/webp'].includes(file.type)) {
        message('Nur JPEG, PNG und WebP werden lokal angenommen.', 'warn');
        continue;
      }
      if (file.size > 20 * 1024 * 1024) {
        message('Ein Foto überschreitet 20 MB und wurde nicht übernommen.', 'warn');
        continue;
      }
      current.photos.push({
        id:crypto.randomUUID(),
        name:file.name || 'offline-photo',
        type:file.type,
        size:file.size,
        blob:file,
        category:'OVERVIEW',
        uploaded:false
      });
    }
    event.target.value = '';
    await persist(true);
    renderPhotos();
  });

  $('photos').addEventListener('change', async event => {
    const id = event.target.dataset.photoCategory;
    if (!id || !current) return;
    const photo = current.photos.find(item => item.id === id);
    if (photo) {
      photo.category = event.target.value;
      await persist(true);
    }
  });

  $('photos').addEventListener('click', async event => {
    const id = event.target.dataset.photoRemove;
    if (!id || !current) return;
    current.photos = current.photos.filter(photo => photo.id !== id);
    await persist(true);
    renderPhotos();
  });

  $('draftList').addEventListener('click', async event => {
    const loadId = event.target.dataset.load;
    const deleteId = event.target.dataset.delete;
    if (loadId) await loadDraft(loadId);
    if (deleteId) {
      await remove(deleteId);
      if (current?.id === deleteId) {
        current = newDraft();
        await put(current);
        fillForm();
      }
      await renderDrafts();
    }
  });

  const updateNetwork = () => {
    $('networkState').textContent = navigator.onLine
      ? 'Online – Synchronisierung ist möglich.'
      : 'Offline – Änderungen bleiben ausschließlich lokal auf diesem Gerät.';
  };
  addEventListener('online', updateNetwork);
  addEventListener('offline', updateNetwork);
  updateNetwork();

  (async () => {
    const rows = await all();
    current = rows[0] || newDraft();
    if (!rows.length) await put(current);
    fillForm();
    await renderDrafts();
  })().catch(err => message('IndexedDB konnte nicht geöffnet werden: ' + err.message, 'error'));
})();