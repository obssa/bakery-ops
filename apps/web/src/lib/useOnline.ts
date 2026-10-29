"use client";

import { useEffect, useState } from "react";

/**
 * Whether the browser believes it is connected (navigator.onLine and its online/offline events). "Online" can still fail (a dead Wi-Fi
 * behind a live link), so a write treats a network failure as such; "offline" is certain enough to say so before trying (step 4.5b: the
 * pay sheet said the till is offline; since step 5.2a the outbox queues its payment, and online-only buttons say they need a connection).
 */
export function useOnline(): boolean {
  const [online, setOnline] = useState(true);
  useEffect(() => {
    const update = () => setOnline(typeof navigator === "undefined" || navigator.onLine !== false);
    update();
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    return () => {
      window.removeEventListener("online", update);
      window.removeEventListener("offline", update);
    };
  }, []);
  return online;
}
