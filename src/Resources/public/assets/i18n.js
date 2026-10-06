// The server translates these messages with the same catalog as its views.
let dictionary;
export function t(message, parameters = {}) {
  if (!dictionary) {
    const node = globalThis.document?.getElementById('nafinity-browser-words');
    dictionary = node ? JSON.parse(node.textContent) : {};
  }
  const translated = dictionary[message] ?? message;
  return translated.replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, (token, key) =>
    Object.hasOwn(parameters, key) ? String(parameters[key]) : token,
  );
}
