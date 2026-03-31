document.addEventListener('click', function (event) {
  var target = event.target.closest('[data-confirm]');
  if (!target) {
    return;
  }

  var message = target.getAttribute('data-confirm');
  if (message && !window.confirm(message)) {
    event.preventDefault();
    event.stopPropagation();
  }
});

document.addEventListener('submit', function (event) {
  var form = event.target;
  if (!form || !form.matches('form')) {
    return;
  }

  if (form.dataset.wgcSubmitting === '1') {
    event.preventDefault();
    return;
  }

  form.dataset.wgcSubmitting = '1';
  window.setTimeout(function () {
    var buttons = form.querySelectorAll('button, input[type="submit"]');
    buttons.forEach(function (button) {
      if (button.disabled) {
        return;
      }
      button.disabled = true;
      button.classList.add('is-loading');
      if (button.tagName === 'BUTTON') {
        button.textContent = 'Working...';
      } else {
        button.value = 'Working...';
      }
    });
  }, 0);
});