document.addEventListener('DOMContentLoaded', function () {
  const pincodeBtn = document.getElementById('bsg-pincode-check-btn');
  const pincodeInput = document.getElementById('bsg-pincode-input');
  const resultDiv = document.getElementById('bsg-pincode-result');
  if (!pincodeBtn || !pincodeInput || !resultDiv) return;

  // Delivery date = today + 5 business days (Sundays skipped), the top of
  // the 3-5 day estimate shown in Shipping & Exchanges.
  function estimateDate(days) {
    const d = new Date();
    let added = 0;
    while (added < days) {
      d.setDate(d.getDate() + 1);
      if (d.getDay() !== 0) added++;
    }
    return d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
  }

  function check() {
    const pin = pincodeInput.value.trim();
    resultDiv.classList.remove('is-ok', 'is-error');

    if (/^[1-9][0-9]{5}$/.test(pin)) {
      resultDiv.classList.add('is-ok');
      resultDiv.textContent = '✓ Delivery by ' + estimateDate(5) + ' to ' + pin + ' · Cash on Delivery available';
    } else {
      resultDiv.classList.add('is-error');
      resultDiv.textContent = 'Please enter a valid 6-digit pincode.';
    }
  }

  pincodeBtn.addEventListener('click', check);
  pincodeInput.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      check();
    }
  });
});
