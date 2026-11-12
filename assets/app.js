// Plain JavaScript: client side checks and table filter. The server repeats all checks.
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) { if (!confirm(f.dataset.confirm)) ev.preventDefault(); });
  });
  document.querySelectorAll('input[data-reg]').forEach(function (i) {
    i.addEventListener('input', function () { i.value = i.value.toUpperCase(); });
    i.form.addEventListener('submit', function (ev) {
      if (i.value && !/^K[A-Z]{2} ?\d{3}[A-Z]$/.test(i.value.trim())) { alert('Registration format example: KDA 123X'); ev.preventDefault(); }
    });
  });
  document.querySelectorAll('input[data-filter]').forEach(function (i) {
    i.addEventListener('input', function () {
      var t = document.getElementById(i.dataset.filter), v = i.value.toLowerCase();
      t.querySelectorAll('tbody tr').forEach(function (r) { r.style.display = r.textContent.toLowerCase().indexOf(v) < 0 ? 'none' : ''; });
    });
  });
  var m = document.getElementById('method'), c = document.getElementById('codebox');
  if (m && c) { var s = function () { c.style.display = m.value === 'MPESA' ? 'block' : 'none'; }; m.addEventListener('change', s); s(); }
});
