document.addEventListener('click', function (event) {
  const button = event.target.closest('.geo-aero-filter button');
  if (!button) return;
  const explorer = button.closest('.geo-aero-explorer');
  if (!explorer) return;
  const type = button.dataset.geoFilter;
  explorer.querySelectorAll('.geo-aero-filter button').forEach(other =>
    other.setAttribute('aria-pressed', String(other === button)));
  explorer.querySelectorAll('.geo-aero-row').forEach(row => {
    row.hidden = type !== 'All locations' && row.dataset.geoType !== type;
  });
});
