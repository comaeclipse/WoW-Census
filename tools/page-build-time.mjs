// A page's generated time records the last change to that page's HTML.
export function renderWithPageTime(previous, builtAt, render) {
  const oldTime = previous?.match(/Page generated: (\d{4}-\d\d-\d\d \d\d:\d\dZ)/)?.[1];
  if (oldTime && render(oldTime.replace(" ", "T")) === previous) return previous;
  return render(builtAt);
}
