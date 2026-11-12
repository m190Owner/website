(() => {
  'use strict';
  const list = document.getElementById('completed-list');
  const render = () => {
  list.replaceChildren();
  const ids = window.SignalProgress.read();
  const completed = ids.map(id => window.SignalCases.find(c => c.id === id)).filter(Boolean);
  document.getElementById('collection-count').textContent = completed.length + (completed.length === 1 ? ' completed case' : ' completed cases');
  document.getElementById('empty-library').hidden = completed.length > 0;
  document.getElementById('storage-warning').hidden = window.SignalProgress.available();
  completed.forEach(c => {
    const item = document.createElement('li');
    const heading = document.createElement('h2');
    heading.textContent = 'Case ' + c.id.padStart(3, '0') + ' / ' + c.title;
    const badge = document.createElement('p');
    badge.textContent = c.callsign + ' recovered · Signal Finder';
    const link = document.createElement('a');
    link.className = 'button';
    link.href = '/signal/?replay=' + c.id;
    link.textContent = 'Replay case ' + c.id + ' ↗';
    item.append(heading, badge, link); list.append(item);
  });
  };
  render();
  window.addEventListener('pageshow', render);
})();
