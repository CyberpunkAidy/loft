/**
 * What a member types to find their studio: its web address ("loftstudio.app/skyline",
 * "https://loftstudio.app/skyline/classes/4"), or just the name in it ("skyline", "Skyline").
 * Returns the slug, or null if it can't be one.
 */
export function studioSlugFrom(input: string): string | null {
  let value = input.trim().toLowerCase();
  if (!value) return null;
  value = value.replace(/^[a-z]+:\/\//, '');
  const parts = value.split(/[/?#]/);
  const host = parts[0];
  const slug = host.includes('.') ? (parts[1] ?? '') : host;
  return /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(slug) ? slug : null;
}
