"use client";

import { useEffect, useSyncExternalStore } from "react";
import type { FeedSnapshot, LiveFeed } from "./feed";

const noSubscribe = () => () => {};
const noSnapshot = () => null;

/** Runs a feed while the component is mounted and returns its latest snapshot (null while there is no feed). */
export function useLiveFeed<Page, State>(feed: LiveFeed<Page, State> | null): FeedSnapshot<State> | null {
  useEffect(() => {
    if (!feed) return;
    feed.start();
    return () => feed.stop();
  }, [feed]);
  return useSyncExternalStore(feed ? feed.subscribe : noSubscribe, feed ? feed.getSnapshot : noSnapshot, noSnapshot);
}
