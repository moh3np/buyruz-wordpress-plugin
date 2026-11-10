/* ==========================================================================
   Buyruz Admin Panel - Material Design 3 JavaScript
   هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید
   ========================================================================== */

(function () {
  'use strict';

  var settings = window.brzSettings || {};
  if (!settings.ajaxUrl) {
    return;
  }

  /* ==========================================================================
     1. TOAST NOTIFICATIONS (Snackbar)
     ========================================================================== */

  var toast = document.getElementById('brz-snackbar');
  var toastTimer;

  function showToast(message, type) {
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'brz-snackbar';
      toast.className = 'brz-snackbar';
      document.body.appendChild(toast);
    }

    toast.textContent = message;
    toast.classList.remove('is-error', 'is-success');

    if (type === 'error') {
      toast.style.background = '#d93025';
    } else if (type === 'success') {
      toast.style.background = '#1e8e3e';
    } else if (type === 'warning') {
      toast.style.background = '#f59e0b';
    } else {
      toast.style.background = '#202124';
    }

    toast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toast.classList.remove('is-visible');
    }, 3500);
  }

  window.brzToast = showToast;
  window.alert = function (msg) {
    showToast(msg, 'info');
  };

  /* ==========================================================================
     2. BRAND COLOR SYSTEM
     ========================================================================== */

  function applyBrandColor(color) {
    if (!color) return;

    var wrap = document.querySelector('.brz-admin-wrap');
    if (wrap) {
      wrap.style.setProperty('--md-primary', color);
      wrap.style.setProperty('--brz-brand', color);

      // Generate hover color (darker)
      var hoverColor = shadeColor(color, -15);
      wrap.style.setProperty('--md-primary-hover', hoverColor);

      // Generate container color (lighter)
      var containerColor = shadeColor(color, 85);
      wrap.style.setProperty('--md-primary-container', containerColor);
    }
  }

  function shadeColor(color, percent) {
    var num = parseInt(color.replace('#', ''), 16);
    var amt = Math.round(2.55 * percent);
    var R = (num >> 16) + amt;
    var G = (num >> 8 & 0x00FF) + amt;
    var B = (num & 0x0000FF) + amt;

    R = Math.max(Math.min(255, R), 0);
    G = Math.max(Math.min(255, G), 0);
    B = Math.max(Math.min(255, B), 0);

    return '#' + (
      0x1000000 +
      R * 0x10000 +
      G * 0x100 +
      B
    ).toString(16).slice(1);
  }

  /* ==========================================================================
     3. BUTTON STATES
     ========================================================================== */

  function setBusy(btn, isBusy) {
    if (!btn) return;

    var isInput = btn.tagName === 'INPUT';

    if (isBusy) {
      if (!btn.dataset.originalText) {
        btn.dataset.originalText = isInput ? btn.value : btn.textContent;
      }
      var loadingText = settings.savingText || '⏳';
      if (isInput) {
        btn.value = loadingText;
      } else {
        btn.textContent = loadingText;
      }
      btn.classList.add('is-loading');
      btn.disabled = true;
    } else {
      var original = btn.dataset.originalText;
      if (original) {
        if (isInput) {
          btn.value = original;
        } else {
          btn.textContent = original;
        }
      }
      btn.classList.remove('is-loading');
      btn.disabled = false;
    }
  }

  /* ==========================================================================
     4. SAVE STATE INDICATOR
     ========================================================================== */

  function updateSaveState(el, text, isError) {
    if (!el) return;

    el.textContent = text;
    el.classList.toggle('is-error', !!isError);
    el.classList.toggle('is-success', !isError);

    // Auto-hide after 3 seconds
    setTimeout(function () {
      el.textContent = '';
      el.classList.remove('is-error', 'is-success');
    }, 3000);
  }

  /* ==========================================================================
     5. MODULE CARD STATE
     ========================================================================== */

  function showCriticalConfirm(label, onConfirm) {
    var existing = document.getElementById('brz-critical-confirm');
    if (existing) existing.remove();

    var backdrop = document.createElement('div');
    backdrop.id = 'brz-critical-confirm';
    backdrop.className = 'brz-confirm-backdrop';
    backdrop.setAttribute('role', 'dialog');
    backdrop.setAttribute('aria-modal', 'true');

    var box = document.createElement('div');
    box.className = 'brz-confirm-modal';

    var title = document.createElement('h3');
    title.className = 'brz-confirm-modal__title';
    title.textContent = 'تایید غیرفعال‌سازی ماژول حیاتی';

    var desc = document.createElement('p');
    desc.className = 'brz-confirm-modal__desc';
    desc.textContent = 'ماژول «' + label + '» برای عملکرد یا امنیت این سایت حیاتی است. آیا از خاموش کردن آن مطمئن هستید؟';

    var actions = document.createElement('div');
    actions.className = 'brz-confirm-modal__actions';

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'brz-confirm-btn brz-confirm-btn--cancel';
    cancelBtn.textContent = 'انصراف (حفظ امنیت)';

    var confirmBtn = document.createElement('button');
    confirmBtn.type = 'button';
    confirmBtn.className = 'brz-confirm-btn brz-confirm-btn--danger';
    confirmBtn.textContent = 'تایید و غیرفعال‌سازی';

    function closeModal() {
      document.removeEventListener('keydown', onKey);
      backdrop.remove();
    }

    function onKey(e) {
      if (e.key === 'Escape') closeModal();
    }

    cancelBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeModal();
    });
    confirmBtn.addEventListener('click', function () {
      closeModal();
      onConfirm();
    });

    actions.appendChild(cancelBtn);
    actions.appendChild(confirmBtn);
    box.appendChild(title);
    box.appendChild(desc);
    box.appendChild(actions);
    backdrop.appendChild(box);
    document.body.appendChild(backdrop);
    document.addEventListener('keydown', onKey);
    cancelBtn.focus();
  }

  function updateCardState(card, newState) {
    var toggle = card.querySelector('.brz-toggle-switch');
    var label = card.querySelector('.brz-toggle-label');
    var hiddenState = card.querySelector('input[name="state"]');
    var icon = card.querySelector('.brz-module-card__icon');

    if (newState) {
      card.classList.add('is-active');
      card.classList.remove('is-inactive');
      if (toggle) {
        toggle.classList.add('is-on');
        toggle.classList.remove('is-off');
        toggle.setAttribute('aria-checked', 'true');
      }
      if (label) label.textContent = 'روشن';
      if (hiddenState) hiddenState.value = '0';
    } else {
      card.classList.remove('is-active');
      card.classList.add('is-inactive');
      if (toggle) {
        toggle.classList.remove('is-on');
        toggle.classList.add('is-off');
        toggle.setAttribute('aria-checked', 'false');
      }
      if (label) label.textContent = 'خاموش';
      if (hiddenState) hiddenState.value = '1';
    }

    // Add animation
    if (icon) {
      icon.style.transform = 'scale(1.1)';
      setTimeout(function () {
        icon.style.transform = '';
      }, 200);
    }
  }

  /* ==========================================================================
     6. TOGGLE MODULE HANDLER
     ========================================================================== */

  var toggleForms = document.querySelectorAll('.brz-toggle-form');

  function dispatchToggle(form, forceFlag) {
    var card = form.closest('.brz-module-card');
    var moduleSlug = form.dataset.module || (card && card.dataset.module) || '';
    var hiddenState = form.querySelector('input[name="state"]');
    var nonceField = form.querySelector('[name="' + (settings.nonceField || '_wpnonce') + '"]');
    var sendState = hiddenState ? hiddenState.value : '';
    var submitBtn = form.querySelector('.brz-toggle-switch');

    if (!moduleSlug || sendState === '') {
      showToast(settings.failText || 'خطا در داده‌ها', 'error');
      return;
    }

    // Visual feedback
    if (submitBtn) submitBtn.style.opacity = '0.5';

    var payload = new FormData();
    payload.append('action', 'brz_toggle_module');
    payload.append('module', moduleSlug);
    payload.append('state', sendState);
    if (forceFlag) {
      payload.append('force', '1');
    }
    if (nonceField) {
      payload.append(settings.nonceField || '_wpnonce', nonceField.value);
    }

    fetch(settings.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: payload
    })
      .then(function (response) {
        return response.json().catch(function () {
          return { success: false, data: { message: settings.failText || 'تغییر وضعیت انجام نشد' } };
        }).then(function (json) {
          return { ok: response.ok, json: json };
        });
      })
      .then(function (res) {
        if (!res.ok || !res.json || !res.json.success || !res.json.data) {
          var errMsg = (res.json && res.json.data && res.json.data.message) || settings.failText || 'تغییر وضعیت انجام نشد';
          throw new Error(errMsg);
        }

        var newState = res.json.data.state ? 1 : 0;

        if (card) {
          updateCardState(card, newState);
        } else {
          window.location.reload();
        }

        showToast(
          newState
            ? (settings.successOn || 'ماژول فعال شد')
            : (settings.successOff || 'ماژول غیرفعال شد'),
          'success'
        );
      })
      .catch(function (err) {
        showToast((err && err.message) || settings.failText || 'تغییر وضعیت انجام نشد', 'error');
      })
      .finally(function () {
        if (submitBtn) submitBtn.style.opacity = '';
      });
  }

  toggleForms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var card = form.closest('.brz-module-card');
      var hiddenState = form.querySelector('input[name="state"]');
      var sendState = hiddenState ? hiddenState.value : '';
      var isCritical = (form.dataset.critical === '1') || (card && card.dataset.critical === '1');

      if (sendState === '0' && isCritical) {
        var modLabel = form.dataset.label || form.dataset.module || 'حیاتی';
        showCriticalConfirm(modLabel, function () {
          dispatchToggle(form, true);
        });
        return;
      }

      dispatchToggle(form, false);
    });
  });

  /* ==========================================================================
     7. AJAX FORM SAVE HANDLER
     ========================================================================== */

  var settingsForms = document.querySelectorAll('.brz-settings-form[data-context]');

  settingsForms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      // Allow native form submission if not AJAX
      if (!form.dataset.ajax && !settings.saveNonce) return;

      event.preventDefault();

      var submitBtn = form.querySelector('[type="submit"]');
      var stateEl = form.querySelector('.brz-save-state') ||
        form.closest('.brz-card')?.querySelector('.brz-save-state');

      setBusy(submitBtn, true);

      var payload = new FormData(form);
      payload.append('action', 'brz_save_settings');
      payload.append('security', settings.saveNonce || '');

      fetch(settings.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: payload
      })
        .then(function (response) {
          if (!response.ok) throw new Error('bad_status');
          return response.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            throw new Error('bad_response');
          }

          if (json.data && json.data.accent) {
            applyBrandColor(json.data.accent);
          }

          showToast(settings.savedText || 'تنظیمات ذخیره شد', 'success');
          updateSaveState(stateEl, settings.savedText || 'ذخیره شد', false);
        })
        .catch(function () {
          showToast(settings.saveFailText || 'ذخیره انجام نشد', 'error');
          updateSaveState(stateEl, settings.saveFailText || 'خطا', true);
        })
        .finally(function () {
          setBusy(submitBtn, false);
        });
    });
  });

  /* ==========================================================================
     8. RIPPLE EFFECT FOR BUTTONS
     ========================================================================== */

  function createRipple(event) {
    var button = event.currentTarget;

    var circle = document.createElement('span');
    var diameter = Math.max(button.clientWidth, button.clientHeight);
    var radius = diameter / 2;

    var rect = button.getBoundingClientRect();

    circle.style.width = circle.style.height = diameter + 'px';
    circle.style.left = (event.clientX - rect.left - radius) + 'px';
    circle.style.top = (event.clientY - rect.top - radius) + 'px';
    circle.classList.add('brz-ripple');

    var existingRipple = button.querySelector('.brz-ripple');
    if (existingRipple) {
      existingRipple.remove();
    }

    button.appendChild(circle);

    setTimeout(function () {
      circle.remove();
    }, 600);
  }

  // Add ripple to primary buttons
  document.querySelectorAll('.brz-button, .button-primary').forEach(function (btn) {
    btn.style.position = 'relative';
    btn.style.overflow = 'hidden';
    btn.addEventListener('click', createRipple);
  });

  // Add ripple styles
  var rippleStyle = document.createElement('style');
  rippleStyle.textContent = `
    .brz-ripple {
      position: absolute;
      border-radius: 50%;
      transform: scale(0);
      animation: brz-ripple-animation 0.6s linear;
      background: rgba(255, 255, 255, 0.4);
      pointer-events: none;
    }
    @keyframes brz-ripple-animation {
      to {
        transform: scale(4);
        opacity: 0;
      }
    }
  `;
  document.head.appendChild(rippleStyle);

  /* ==========================================================================
     9. SMOOTH SCROLL FOR NAVIGATION
     ========================================================================== */

  document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener('click', function (e) {
      var targetId = this.getAttribute('href');
      if (targetId === '#') return;

      var target = document.querySelector(targetId);
      if (target) {
        e.preventDefault();
        target.scrollIntoView({
          behavior: 'smooth',
          block: 'start'
        });
      }
    });
  });

  /* ==========================================================================
     10. CARD ENTRANCE ANIMATIONS
     ========================================================================== */

  function animateOnScroll() {
    var cards = document.querySelectorAll('.brz-module-card, .brz-card');

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1 });

    cards.forEach(function (card) {
      card.style.opacity = '0';
      card.style.transform = 'translateY(20px)';
      card.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
      observer.observe(card);
    });
  }

  // Initialize animations after DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', animateOnScroll);
  } else {
    // Small delay to ensure CSS is applied
    setTimeout(animateOnScroll, 50);
  }

  /* ==========================================================================
     11. KEYBOARD NAVIGATION
     ========================================================================== */

  document.querySelectorAll('.brz-toggle-switch').forEach(function (toggle) {
    toggle.setAttribute('tabindex', '0');
    toggle.setAttribute('role', 'switch');

    toggle.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        this.click();
      }
    });
  });

  /* ==========================================================================
     12. INITIALIZE
     ========================================================================== */

  // Apply brand color on load if set
  var wrap = document.querySelector('.brz-admin-wrap');
  if (wrap) {
    var brandColor = getComputedStyle(wrap).getPropertyValue('--brz-brand').trim();
    if (brandColor && brandColor !== '#1a73e8') {
      applyBrandColor(brandColor);
    }
  }

})();
