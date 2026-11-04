let csrfToken = '';

export class ApiError extends Error {
  constructor(message, errors = {}, status = 0) {
    super(message);
    this.errors = errors;
    this.status = status;
  }
}

export async function request(path, options = {}, retry = true) {
  let response;
  try {
    response = await fetch(`/api${path}`, {
      ...options,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
        ...options.headers,
      },
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
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
  if (!response.ok) throw new ApiError(data.message || 'The request could not be completed.', data.errors, response.status);
  return data;
}
