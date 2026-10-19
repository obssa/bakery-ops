"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useEffect, useState, type ReactNode } from "react";
import { useSession } from "@/lib/session";

/** Client-side state for every page: the query cache and the device's sign-in (read from storage once). */
export function Providers({ children }: { children: ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: { retry: 1, refetchOnWindowFocus: false },
          mutations: { retry: false },
        },
      }),
  );
  const status = useSession((s) => s.status);
  const init = useSession((s) => s.init);

  useEffect(() => {
    if (status === "unknown") void init();
  }, [status, init]);

  // signing out (or a 401) must not leave another user's lists in the cache
  useEffect(() => {
    if (status === "signed-out") client.clear();
  }, [status, client]);

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
