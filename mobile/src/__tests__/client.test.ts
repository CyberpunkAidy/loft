import { ApiError, request, setToken, setUnauthorizedHandler } from '../api/client';

type Call = { url: string; init: RequestInit };
let calls: Call[] = [];

function respond(status: number, body?: unknown) {
  globalThis.fetch = jest.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
    calls.push({ url: String(url), init: init ?? {} });
    return {
      ok: status >= 200 && status < 300,
      status,
      json: async () => {
        if (body === undefined) throw new Error('no body');
        return body;
      },
    } as Response;
  }) as typeof fetch;
}

beforeEach(() => {
  calls = [];
  setToken(null);
  setUnauthorizedHandler(null);
});

it('signs requests with the token, sends JSON, and builds the query', async () => {
  respond(200, { data: { ok: true } });
  setToken('1|loft_abc');
  await request('studios/skyline/sessions', { query: { from: '2026-11-09', days: 7, program_id: null } });
  expect(calls[0].url).toBe('https://loftstudio.app/api/v1/studios/skyline/sessions?from=2026-11-09&days=7');
  const headers = calls[0].init.headers as Record<string, string>;
  expect(headers.Authorization).toBe('Bearer 1|loft_abc');
  expect(headers.Accept).toBe('application/json');
});

it('sends an Idempotency-Key and a JSON body when asked, and no token for auth: false', async () => {
  respond(201, { data: { id: 1 } });
  setToken('1|loft_abc');
  await request('auth/tokens', { method: 'POST', body: { email: 'a@b.c' }, idempotencyKey: 'key-1', auth: false });
  const headers = calls[0].init.headers as Record<string, string>;
  expect(headers['Idempotency-Key']).toBe('key-1');
  expect(headers['Content-Type']).toBe('application/json');
  expect(headers.Authorization).toBeUndefined();
  expect(calls[0].init.body).toBe('{"email":"a@b.c"}');
});

it('turns the error envelope into an ApiError with its code, message and details', async () => {
  respond(422, { error: { code: 'waiver_required', message: 'Leo needs to sign “Release”.', attendee_id: 12, waiver_id: 3 } });
  const error = (await request('x').catch((e: unknown) => e)) as ApiError;
  expect(error).toBeInstanceOf(ApiError);
  expect([error.status, error.code, error.message, error.details]).toEqual([422, 'waiver_required', 'Leo needs to sign “Release”.', { attendee_id: 12, waiver_id: 3 }]);

  respond(422, { error: { code: 'validation_failed', message: 'The email field is required.', fields: { email: ['The email field is required.'] } } });
  const invalid = (await request('x').catch((e: unknown) => e)) as ApiError;
  expect(invalid.fieldMessage('email')).toBe('The email field is required.');
  expect(invalid.fieldMessage('password')).toBeUndefined();
});

it('signs out when a signed-in request is refused, but not when signed out', async () => {
  const unauthorized = jest.fn();
  setUnauthorizedHandler(unauthorized);
  respond(401, { error: { code: 'unauthenticated', message: 'Sign in again.' } });
  await request('me').catch(() => null);
  expect(unauthorized).not.toHaveBeenCalled();
  setToken('1|loft_old');
  await request('me').catch(() => null);
  expect(unauthorized).toHaveBeenCalledTimes(1);
});

it('explains network failures and pages that aren’t JSON', async () => {
  globalThis.fetch = jest.fn(async () => {
    throw new TypeError('Network request failed');
  }) as typeof fetch;
  await expect(request('me')).rejects.toMatchObject({ code: 'network', status: 0 });

  respond(502);
  await expect(request('me')).rejects.toMatchObject({ code: 'server_error', status: 502 });

  respond(204);
  await expect(request('x', { method: 'DELETE' })).resolves.toBeUndefined();
});
