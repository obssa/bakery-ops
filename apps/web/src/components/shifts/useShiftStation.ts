"use client";

import { useEffect, useMemo, useState } from "react";
import type { WorkStation, StationCode } from "@/lib/api/types";
import type { FeedSnapshot, LiveFeed } from "@/lib/live/feed";
import { createShiftFeed, type ShiftState } from "@/lib/live/shifts";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useSession } from "@/lib/session";
import { chooseStation, stationsFor } from "@/lib/shifts";
import { useActiveStation } from "@/lib/station";
import type { Shift, ShiftCurrentPage } from "@/lib/api/types";

// The station a screen works at and the shift running there (step 3.7a). A screen names the station it already chose (the kitchen
// display's picker) or the kinds of station it serves (the till; production: cake room and bakery) and gets the station, the
// choices when there are several, and the live shift. It also makes the station the one the API client sends as X-Station-Id.

export interface ShiftStation {
  /** the station the screen works at, or null when this person works at none of the kind */
  station: WorkStation | null;
  /** the stations of the kinds this person may choose between (more than one: the bar shows a picker) */
  choices: WorkStation[];
  choose: (stationId: number) => void;
  /** null for a station that tracks no shifts */
  feed: LiveFeed<ShiftCurrentPage, ShiftState> | null;
  snapshot: FeedSnapshot<ShiftState> | null;
  shift: Shift | null;
  /** the shift feed has answered at least once */
  loaded: boolean;
}

export function useShiftStation(options: { kinds?: readonly StationCode[]; stationId?: number | null }): ShiftStation {
  const workStations = useSession((s) => s.me?.work_stations);
  const [chosen, setChosen] = useState<number | null>(null);

  const choices = useMemo(() => stationsFor(workStations, options.kinds ?? []), [workStations, options.kinds]);
  const station = useMemo(() => {
    if (options.stationId !== undefined) return (workStations ?? []).find((s) => s.station_id === options.stationId) ?? null;
    return chooseStation(choices, chosen);
  }, [options.stationId, workStations, choices, chosen]);

  const stationId = station?.station_id ?? null;
  const tracks = station?.tracks_shifts === true;
  const feed = useMemo(() => (stationId !== null && tracks ? createShiftFeed(stationId) : null), [stationId, tracks]);
  const snapshot = useLiveFeed(feed);

  // every call this screen makes names the station it works at
  useEffect(() => {
    useActiveStation.getState().set(stationId);
    return () => useActiveStation.getState().set(null);
  }, [stationId]);

  return {
    station,
    choices,
    choose: setChosen,
    feed,
    snapshot,
    shift: snapshot?.state.shift ?? null,
    loaded: !!snapshot?.loaded,
  };
}
