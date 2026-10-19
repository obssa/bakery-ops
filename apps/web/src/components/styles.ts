// Shared class names, in a plain module so server components can use them too (an export of a "use client" module
// is a client reference on the server, not the string).

/** Always-visible keyboard focus (workflows-and-screens.md: clear focus states on a tablet). */
export const focusRing =
  "focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-600/40 focus-visible:ring-offset-1";
