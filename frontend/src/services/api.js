let csrfToken = '';
let expectedIdentity = null;
const authenticationListeners = new Set();

export function bindSessionIdentity(user) {
  expectedIdentity = user ? { id: String(user.id), email: user.email } : null;
  if (!user) csrfToken = '';
}

export function onAuthenticationRequired(listener) {
  authenticationListeners.add(listener);
  return () => authenticationListeners.delete(listener);
}

function requireAuthentication(reason = 'expired') {
  for (const listener of authenticationListeners) listener({ reason });
  return new ApiError('This action was paused because the session ended or the signed-in account changed. After signing in with the original account, retry this action. Your form entries are still here.', {}, 401);
}

export async function checkSessionIdentity() {
  if (!expectedIdentity) return;
  const expected = expectedIdentity.id;
  const result = await request('/session');
  if (!result.user || String(result.user.id) !== expected) throw requireAuthentication(result.user ? 'changed' : 'expired');
}

export class ApiError extends Error {
  constructor(message, errors = {}, status = 0) {
    super(message);
    this.errors = errors;
    this.status = status;
  }
}

export async function request(path, options = {}, retry = true) {
  const publicRequest = ['/session', '/login', '/logout'].includes(path);
  if (!publicRequest && expectedIdentity && !['GET', 'HEAD', 'OPTIONS'].includes((options.method || 'GET').toUpperCase())) {
    await checkSessionIdentity();
  }
  let response;
  const multipart = options.body instanceof FormData;
  try {
    response = await fetch(`/api${path}`, {
      ...options,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(!multipart ? { 'Content-Type': 'application/json' } : {}),
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
        ...(!publicRequest && expectedIdentity ? { 'X-Workspace-User': expectedIdentity.id } : {}),
        ...options.headers,
      },
      body: multipart || options.body === undefined ? options.body : JSON.stringify(options.body),
    });
  } catch {
    throw new ApiError('The server could not be reached. Check that the Laravel API is running.');
  }
  if (response.status === 419 && retry) {
    await request('/session', {}, false);
    return request(path, options, false);
  }
  const data = await response.json().catch(() => ({}));
  if (data.csrf_token) csrfToken = data.csrf_token;
  if (response.status === 401 && !publicRequest) throw requireAuthentication();
  if (!response.ok) throw new ApiError(data.message || 'The request could not be completed.', data.errors, response.status);
  return data;
}
