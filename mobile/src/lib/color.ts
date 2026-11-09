/** A studio's brand color made readable, the same way the website does it. */
function channels(hex: string): [number, number, number] {
  const h = /^#?([0-9a-f]{6})$/i.exec(hex)?.[1] ?? '5b4bf5';
  return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) as [number, number, number];
}

function luminance([r, g, b]: [number, number, number]): number {
  const lin = (c: number) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
}

function contrast(a: [number, number, number], b: [number, number, number]): number {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (l1 + 0.05) / (l2 + 0.05);
}

function hex([r, g, b]: [number, number, number]): string {
  return `#${[r, g, b].map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')}`;
}

export function validAccent(color: string | null | undefined): string {
  return color && /^#[0-9a-f]{6}$/i.test(color) ? color : '#5b4bf5';
}

/** Text on the accent: white, or near-black for light colors. */
export function inkOn(accent: string): string {
  const c = channels(accent);
  return contrast(c, [255, 255, 255]) >= contrast(c, [23, 20, 31]) ? '#ffffff' : '#17141f';
}

/** The accent as text on a light background: darkened until it reads (4.5:1). */
export function textOn(accent: string, background: [number, number, number] = [255, 255, 255]): string {
  let c = channels(accent);
  for (let i = 0; i < 20 && contrast(c, background) < 4.5; i++) {
    c = c.map((v) => v * 0.9) as [number, number, number];
  }
  return hex(c);
}

/** The accent mixed into white ($amount 0–1), for soft backgrounds. */
export function tint(accent: string, amount: number): string {
  const c = channels(accent);
  return hex(c.map((v) => 255 + (v - 255) * amount) as [number, number, number]);
}

/** $color mixed into $base ($amount of $color, 0–1), e.g. the accent faintly over a dark surface. */
export function mix(color: string, base: string, amount: number): string {
  const a = channels(color);
  const b = channels(base);
  return hex(a.map((v, i) => b[i] + (v - b[i]) * amount) as [number, number, number]);
}
