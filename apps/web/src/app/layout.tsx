import type { ReactNode } from "react";

// The real root layout is [locale]/layout.tsx: it renders <html lang="en|am">. This pass-through exists only because
// the start page (/app/) and the bare 404 live outside [locale]; each of them renders its own <html>.
export default function RootLayout({ children }: { children: ReactNode }) {
  return children;
}
