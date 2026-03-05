document.addEventListener('DOMContentLoaded', () => {
  console.log("app.js loaded (debug)");

  // Delegacja: działa nawet jeśli linki są generowane dynamicznie
  document.addEventListener('click', (ev) => {
    const a = ev.target.closest('.js-assign');
    if (!a) return;

    const phoneId = a.getAttribute('data-phone-id') || '';
    const phoneNumber = a.getAttribute('data-phone-number') || '—';
    const currentLabel = (a.getAttribute('data-current-label') || '').trim();

    const modalPhoneId = document.getElementById('modalPhoneId');
    const modalPhoneNumber = document.getElementById('modalPhoneNumber');
    const modalCurrentLabel = document.getElementById('modalCurrentLabel');

    console.log("CLICK js-assign", { phoneId, phoneNumber, currentLabel });

    if (modalPhoneId) {
      modalPhoneId.value = phoneId;
      console.log("SET modalPhoneId.value =", modalPhoneId.value);
    } else {
      console.warn("NO #modalPhoneId element found!");
    }

    if (modalPhoneNumber) modalPhoneNumber.textContent = phoneNumber;
    if (modalCurrentLabel) modalCurrentLabel.textContent = currentLabel !== '' ? currentLabel : 'brak przypisania';
  });

  // Debug submit: co faktycznie idzie w POST
  const form = document.querySelector('#assignModal form');
  if (form) {
    form.addEventListener('submit', () => {
      const fd = new FormData(form);
      console.log("SUBMIT FormData:", Object.fromEntries(fd.entries()));
    });
  } else {
    console.warn("NO modal form found!");
  }
});