import { API_URL } from '../config';

/** An error from the API, with its stable code ("waiver_required", "needs_plan"…) and extra fields. */
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    message: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** Validation messages by field (code validation_failed). */
  get fields(): Record<string, string[]> {
    return (this.details.fields as Record<string, string[]>) ?? {};
  }

  /** The first validation message for $field, or the whole message. */
  fieldMessage(field: string): string | undefined {
    return this.fields[field]?.[0];
  }
}

let token: string | null = null;
let onUnauthorized: (() => void) | null = null;

/** The signed-in member's token (set by SessionProvider). */
export function setToken(value: string | null): void {
  token = value;
}

/** Called when the API says the token is no longer valid: the app signs out. */
export function setUnauthorizedHandler(handler: (() => void) | null): void {
  onUnauthorized = handler;
}

type Options = {
  method?: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined>;
  /** Sent as Idempotency-Key: a retry with the same key can't book or charge twice. */
  idempotencyKey?: string;
  /** Sign the request even when signed out is fine (public endpoints that add the member's own data). */
  auth?: boolean;
};

export async function request<T>(path: string, options: Options = {}): Promise<T> {
  const query = Object.entries(options.query ?? {})
    .filter(([, v]) => v !== null && v !== undefined && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    .join('&');
  const url = `${API_URL}/${path.replace(/^\/+/, '')}${query ? `?${query}` : ''}`;
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (token && options.auth !== false) headers.Authorization = `Bearer ${token}`;
  if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;

  let response: Response;
  try {
    response = await fetch(url, {
      method: options.method ?? 'GET',
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    });
  } catch {
    throw new ApiError(0, 'network', 'Couldn’t reach Loft. Check your connection and try again.');
  }

  if (response.status === 204) {
    return undefined as T;
  }
  let json: any = null;
  try {
    json = await response.json();
  } catch {
    // not JSON (a proxy error page, say)
  }
  if (!response.ok) {
    const error = json?.error;
    if (response.status === 401 && token) {
      onUnauthorized?.();
    }
    if (error?.code) {
      const { code, message, ...details } = error;
      throw new ApiError(response.status, code, message ?? 'Something went wrong.', details);
    }
    throw new ApiError(response.status, 'server_error', 'Something went wrong on our side. Please try again in a moment.');
  }

  return json as T;
}
