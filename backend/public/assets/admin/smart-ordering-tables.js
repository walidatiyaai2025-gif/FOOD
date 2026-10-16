(() => {
  const message = window.FoodexRestaurantTables ?? {};
  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

  document.querySelectorAll('[data-so-table-tile][data-draggable="1"]').forEach((tile) => {
    const canvas = tile.closest('[data-so-floor-canvas]');
    const form = tile.querySelector('[data-so-position-form]');
    if (!canvas || !form) return;

    let pointerId = null;
    let startClientX = 0;
    let startClientY = 0;
    let startLeft = 0;
    let startTop = 0;
    let moved = false;

    const setStatus = (value) => {
      const host = canvas.closest('.so-floor-card')?.querySelector('[data-so-save-status]');
      if (host) host.textContent = value ?? '';
    };

    tile.addEventListener('pointerdown', (event) => {
      if (event.button !== 0 || event.target.closest('button,a,input,select,textarea,label,form')) return;
      const tileRect = tile.getBoundingClientRect();
      const canvasRect = canvas.getBoundingClientRect();
      pointerId = event.pointerId;
      startClientX = event.clientX;
      startClientY = event.clientY;
      startLeft = tileRect.left - canvasRect.left;
      startTop = tileRect.top - canvasRect.top;
      moved = false;
      tile.classList.add('is-dragging');
      tile.setPointerCapture(pointerId);
      event.preventDefault();
    });

    tile.addEventListener('pointermove', (event) => {
      if (pointerId !== event.pointerId) return;
      const dx = event.clientX - startClientX;
      const dy = event.clientY - startClientY;
      if (Math.abs(dx) > 3 || Math.abs(dy) > 3) moved = true;
      if (!moved) return;

      const maxLeft = Math.max(0, canvas.clientWidth - tile.offsetWidth);
      const maxTop = Math.max(0, canvas.clientHeight - tile.offsetHeight);
      const left = clamp(startLeft + dx, 0, maxLeft);
      const top = clamp(startTop + dy, 0, maxTop);
      tile.style.insetInlineStart = `${(left / Math.max(1, canvas.clientWidth)) * 100}%`;
      tile.style.top = `${(top / Math.max(1, canvas.clientHeight)) * 100}%`;
    });

    const finish = async (event) => {
      if (pointerId !== event.pointerId) return;
      tile.classList.remove('is-dragging');
      try { tile.releasePointerCapture(pointerId); } catch {}
      pointerId = null;
      if (!moved) return;

      const tileRect = tile.getBoundingClientRect();
      const canvasRect = canvas.getBoundingClientRect();
      const left = clamp(tileRect.left - canvasRect.left, 0, canvas.clientWidth);
      const top = clamp(tileRect.top - canvasRect.top, 0, canvas.clientHeight);
      const x = Math.round((left / Math.max(1, canvas.clientWidth)) * 1000);
      const y = Math.round((top / Math.max(1, canvas.clientHeight)) * 1000);
      const xInput = form.querySelector('[name="position_x"]');
      const yInput = form.querySelector('[name="position_y"]');
      if (!xInput || !yInput) return;
      xInput.value = String(x);
      yInput.value = String(y);

      setStatus(message.saving ?? '');
      tile.dataset.saving = '1';
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        tile.style.setProperty('--so-x', String(x));
        tile.style.setProperty('--so-y', String(y));
        setStatus(message.saved ?? '');
      } catch (error) {
        setStatus(message.error ?? '');
        window.setTimeout(() => window.location.reload(), 500);
      } finally {
        delete tile.dataset.saving;
      }
    };

    tile.addEventListener('pointerup', finish);
    tile.addEventListener('pointercancel', finish);
  });

  document.querySelectorAll('[data-so-open-dialog]').forEach((button) => {
    button.addEventListener('click', () => {
      const id = button.getAttribute('data-so-open-dialog');
      const dialog = id ? document.getElementById(id) : null;
      if (dialog instanceof HTMLDialogElement) dialog.showModal();
    });
  });

  document.querySelectorAll('[data-so-close-dialog]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog')?.close());
  });
})();
