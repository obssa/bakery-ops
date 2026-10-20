"use client";

import { useEffect, useState } from "react";

/** The device clock, re-read every `everyMs` (timers on tickets and queues share one tick per screen). */
export function useNow(everyMs = 1000): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), everyMs);
    return () => clearInterval(timer);
  }, [everyMs]);
  return now;
}
