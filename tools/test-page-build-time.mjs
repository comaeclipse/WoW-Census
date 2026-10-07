import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renderWithPageTime } from './page-build-time.mjs';

const render = (body) => (at) =>
  `<main>${body}</main><footer>Page generated: ${at.slice(0, 16).replace('T', ' ')}Z</footer>`;

test('identical page keeps its own timestamp and bytes', () => {
  const previous = render('unchanged')('2026-10-06T12:30:00Z');
  assert.equal(renderWithPageTime(previous, '2026-10-07T14:00:00Z', render('unchanged')), previous);
});

test('changed page receives the current build timestamp', () => {
  const previous = render('old')('2026-10-06T12:30:00Z');
  assert.equal(renderWithPageTime(previous, '2026-10-07T14:00:00Z', render('new')),
    render('new')('2026-10-07T14:00:00Z'));
});

test('new page receives the current build timestamp', () => {
  assert.equal(renderWithPageTime(null, '2026-10-07T14:00:00Z', render('new')),
    render('new')('2026-10-07T14:00:00Z'));
});
